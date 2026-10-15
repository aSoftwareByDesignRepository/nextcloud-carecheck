<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Db\BreakGlass;
use OCA\CareCheck\Db\BreakGlassMapper;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\Notice;
use OCA\CareCheck\Db\NoticeMapper;
use OCA\CareCheck\Db\PlanVersionMapper;
use OCA\CareCheck\Db\TourStopMapper;
use OCA\CareCheck\Db\Visit;
use OCA\CareCheck\Db\VisitMapper;
use OCA\CareCheck\Db\VisitTask;
use OCA\CareCheck\Db\VisitTaskMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;

/**
 * The write path. Every binding spec invariant lives here:
 * actor-scoped idempotency, server-derived fields, plan-snapshot binding,
 * geo toggle enforcement, sig validation, atomic bundle+journal commit.
 */
class VisitService
{
	public function __construct(
		private IDBConnection $db,
		private VisitMapper $visits,
		private VisitTaskMapper $tasks,
		private ClientMapper $clients,
		private PlanVersionMapper $plans,
		private TourStopMapper $stops,
		private NoticeMapper $notices,
		private BreakGlassMapper $breakGlass,
		private AclService $acl,
		private CryptoService $crypto,
		private JournalService $journal,
		private SettingsService $settings,
		private PresenceService $presence,
	) {
	}

	private function findByIdem(string $actorRef, string $idemKey): ?Visit
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('cck_visit')
			->where($qb->expr()->eq('actor_ref', $qb->createNamedParameter($actorRef)))
			->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($idemKey)));
		$rows = $qb->executeQuery()->fetchAll();
		return $rows === [] ? null : Visit::fromRow($rows[0]);
	}

/**
	 * Idempotent check-in: creates the visit row in `in_progress`.
	 * @param array<string, mixed> $in
	 */
	public function checkin(string $actorRef, array $in): array
	{
		$idem = BundleValidator::idemKey($in);
		$existing = $this->findByIdem($actorRef, $idem);
		if ($existing !== null) {
			return $this->serialize($existing)
				+ ['briefing' => $this->briefing((int)$existing->getClientId())]; // idempotent replay → same visit
		}
		$clientId = $this->clientId($in);
		$this->acl->assertClientScope($actorRef, $clientId);
		$tstopId = $this->tstopId($in);
		$this->assertStopScope($actorRef, $tstopId, $clientId);
		$now = new \DateTime();

		$this->db->beginTransaction();
		try {
			$v = new Visit();
			$v->setActorRef($actorRef);
			$v->setIdemKey($idem);
			$v->setClientId($clientId);
			$v->setTstopId($tstopId);
			$v->setCheckinTs($now); // server receipt time is authoritative
			$v->setSigState('none');
			$v->setStatus('in_progress');
			$v->setQualMismatch($this->qualMismatch($actorRef, $clientId) ? 1 : 0);
			// server-owned: demo status derives from the client row — a crafted
			// bundle can never hide real documentation from journals/exports
			$cc = $this->clients->find($clientId);
			$v->setIsDemo($cc !== null && (int)$cc->getIsDemo() === 1 ? 1 : 0);
			$v->setCreatedAt($now);
			$v->setUpdatedAt($now);
			$geo = BundleValidator::geo($in, $this->geoAllowed());
			if ($geo !== null) {
				$v->setGeoLat($geo['lat']);
				$v->setGeoLon($geo['lon']);
			}
			// NH-14: scanned presence token → evidence flag. A bad/absent
			// token never blocks check-in — presence proof is additive.
			$ptok = isset($in['presence_token']) ? (string)$in['presence_token'] : '';
			if ($ptok !== '' && $this->presence->verify($clientId, $ptok)) {
				$v->setPresenceOk(1);
			}
			$v = $this->visits->insert($v);
			$this->markStop($v->getTstopId(), 'in_progress');
			$this->journal->append($clientId, 'checkin', $actorRef, [
				'visit' => (int)$v->getId(),
				'ts' => $now->format(\DateTimeInterface::ATOM),
				'presence_ok' => (int)$v->getPresenceOk(),
			]);
			// the briefing card is a safety surface — showing it is itself audited
			$this->journal->append($clientId, 'briefing_shown', $actorRef, ['visit' => (int)$v->getId()]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof \OCP\DB\Exception
				&& $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
			) {
				// Two writers raced the same actor-scoped idem key: the check-then-act
				// gap means findByIdem() saw nothing but the loser now collides on
				// UNIQUE(actor_ref, idem_key). Re-read and replay — same response.
				$dup = $this->findByIdem($actorRef, $idem);
				if ($dup !== null) {
					return $this->serialize($dup);
				}
			}
			throw $e;
		}
		return $this->serialize($v);
	}

	/**
	 * Final bundle: tasks + note + signature + checkout — ONE transaction
	 * with the journal append and stop status flip.
	 * @param array<string, mixed> $in
	 */
	public function submitBundle(string $actorRef, array $in): array
	{
		$idem = BundleValidator::idemKey($in);
		$existing = $this->findByIdem($actorRef, $idem);
		if ($existing !== null && in_array($existing->getStatus(), ['done', 'done_corrected'], true)) {
			return $this->serialize($existing); // dedupe: same key → same result
		}
		$clientId = $this->clientId($in);
		$this->acl->assertClientScope($actorRef, $clientId);
		$tstopId = $this->tstopId($in);
		$this->assertStopScope($actorRef, $tstopId, $clientId);
		$client = $this->clients->find($clientId);
		if ($client === null || (int)$client->getActive() === 0) {
			throw ApiException::notFound();
		}

		// plan_version_id must belong to THIS client — forged refs rejected
		$planVId = isset($in['plan_version_id']) && is_numeric($in['plan_version_id']) ? (int)$in['plan_version_id'] : null;
		$fields = [];
		if ($planVId !== null) {
			$pv = $this->plans->find($planVId);
			if ($pv === null || (int)$pv->getClientId() !== $clientId) {
				$fields['plan_version_id'] = 'invalid';
			}
		}
		// joint_visit_ref must point to a visit on the same client+stop
		$jointRef = isset($in['joint_visit_ref']) && is_numeric($in['joint_visit_ref']) ? (int)$in['joint_visit_ref'] : null;
		if ($jointRef !== null) {
			$jv = $this->visits->find($jointRef);
			if ($jv === null || (int)$jv->getClientId() !== $clientId) {
				$fields['joint_visit_ref'] = 'invalid';
			}
		}
		$tasksIn = BundleValidator::tasks($in['tasks'] ?? null, $fields);
		$sigState = (string)($in['sig_state'] ?? 'none');
		$sigB64 = BundleValidator::signature($in, $fields) ?? '';
		if ($fields !== []) {
			// Rejected bundles are never dropped — they land in the Zentrale
			// review queue (rejected_review + journal + reviewPending flag on
			// the coverage board), THEN the caller still gets the 422 fields map.
			$this->quarantine($actorRef, $in, $clientId, $idem, $fields, $existing);
			throw ApiException::validation('Validation failed.', $fields);
		}

		$now = new \DateTime();
		$this->db->beginTransaction();
		try {
			$v = $existing ?? new Visit();
			if ($existing === null) {
				$v->setActorRef($actorRef);
				$v->setIdemKey($idem);
				$v->setClientId($clientId);
				$v->setCreatedAt($now);
				$v->setQualMismatch($this->qualMismatch($actorRef, $clientId) ? 1 : 0);
				$v->setIsDemo($client !== null && (int)$client->getIsDemo() === 1 ? 1 : 0);
			}
			$v->setTstopId($tstopId ?? $v->getTstopId());
			$v->setCheckinTs($v->getCheckinTs() ?? $now);
			$v->setCheckoutTs($now);
			$v->setDurationSec($v->getCheckinTs() !== null ? $now->getTimestamp() - $v->getCheckinTs()->getTimestamp() : null);
			$v->setPlanVId($planVId);
			$v->setJointVisitRef($jointRef);
			$v->setSigState(in_array($sigState, Constants::SIG_STATES, true) ? $sigState : 'none');
			$v->setSignerName($in['signer_name'] ?? null);
			$v->setSigEnc($sigB64 !== '' ? $this->crypto->encrypt($sigB64) : null);
			$v->setNoteEnc(isset($in['note']) && $in['note'] !== '' ? $this->crypto->encrypt(mb_substr((string)$in['note'], 0, 4000)) : null);
			$geo = BundleValidator::geo($in, $this->geoAllowed());
			if ($geo !== null) {
				$v->setGeoLat($geo['lat']);
				$v->setGeoLon($geo['lon']);
			}
			$v->setStatus('done');
			$v->setUpdatedAt($now);
			$v = $existing === null ? $this->visits->insert($v) : $this->visits->update($v);

			// replace tasks atomically inside the same transaction
			if ($existing !== null) {
				$del = $this->db->getQueryBuilder();
				$del->delete('cck_vtask')->where($del->expr()->eq('visit_id', $del->createNamedParameter((int)$v->getId())));
				$del->executeStatement();
			}
			foreach ($tasksIn as $t) {
				$vt = new VisitTask();
				$vt->setVisitId((int)$v->getId());
				$vt->setTaskCode(mb_substr((string)($t['task_code'] ?? ''), 0, 48));
				$vt->setStatus((string)$t['status']);
				$vt->setReasonCode($t['reason_code'] ?? null);
				$vt->setValueEnc(isset($t['value']) ? $this->crypto->encrypt(json_encode($t['value'], JSON_THROW_ON_ERROR)) : null);
				$this->tasks->insert($vt);
			}
			$this->markStop($v->getTstopId(), 'done');
			$this->journal->append($clientId, 'visit_done', $actorRef, [
				'visit' => (int)$v->getId(), 'tasks' => count($tasksIn), 'sig' => $sigState,
				'checkin' => $v->getCheckinTs()?->format(\DateTimeInterface::ATOM),
				'checkout' => $now->format(\DateTimeInterface::ATOM),
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof \OCP\DB\Exception
				&& $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
			) {
				// Two writers raced the same actor-scoped idem key: the check-then-act
				// gap means findByIdem() saw nothing but the loser now collides on
				// UNIQUE(actor_ref, idem_key). Re-read and replay — same response.
				$dup = $this->findByIdem($actorRef, $idem);
				if ($dup !== null) {
					return $this->serialize($dup);
				}
			}
			throw $e;
		}
		$this->acl->audit($actorRef, 'read', $clientId, 'visit_done');
		return $this->serialize($v);
	}

/**
	 * Break-glass: audited, bounded grant for an out-of-scope client.
	 * @param array<string, mixed> $in
	 */
	public function requestBreakGlass(string $actorRef, array $in): array
	{
		$clientId = $this->clientId($in);
		$reason = (string)($in['reason_code'] ?? '');
		if (!in_array($reason, Constants::REASON_CODES, true)) {
			throw ApiException::validation('Validation failed.', ['reason_code' => 'required']);
		}
		$client = $this->clients->find($clientId);
		if ($client === null || (int)$client->getActive() === 0) {
			throw ApiException::notFound();
		}
		$hours = min(max(1, (int)($in['hours'] ?? 8)), $this->settings->getInt('breakglass_hours_max'));
		$hours = min($hours, 72);
		$g = new BreakGlass();
		$g->setActorRef($actorRef);
		$g->setClientId($clientId);
		$g->setReasonCode($reason);
		$g->setGrantedAt(new \DateTime());
		$g->setExpiresAt((new \DateTime())->modify("+{$hours} hours"));
		$g->setState('active');
		$g->setQualMismatch($this->qualMismatch($actorRef, $clientId) ? 1 : 0);
		$g = $this->breakGlass->insert($g);
		$this->acl->audit($actorRef, 'break_glass', $clientId, $reason);
		return ['id' => (int)$g->getId(), 'expiresAt' => $g->getExpiresAt()->format(\DateTimeInterface::ATOM), 'qualMismatch' => (int)$g->getQualMismatch() === 1];
	}

	/**
	 * @param array<string, mixed> $in
	 */
	public function submitNotice(string $actorRef, array $in): array
	{
		$kind = (string)($in['kind'] ?? '');
		if (!in_array($kind, Constants::NOTICE_KINDS, true)) {
			throw ApiException::validation('Validation failed.', ['kind' => 'invalid']);
		}
		$clientId = isset($in['client_id']) && is_numeric($in['client_id']) ? (int)$in['client_id'] : null;
		if ($clientId !== null) {
			$this->acl->assertClientScope($actorRef, $clientId);
		}
		$idem = isset($in['idem_key']) && $in['idem_key'] !== '' ? mb_substr((string)$in['idem_key'], 0, 64) : null;
		if ($idem !== null) {
			$existing = $this->notices->findByIdem($actorRef, $idem);
			if ($existing !== null) {
				return ['id' => (int)$existing->getId(), 'state' => $existing->getState(), 'priority' => $existing->getPriority()];
			}
		}
		$n = new Notice();
		$n->setClientId($clientId);
		$n->setActorRef($actorRef);
		$n->setKind($kind);
		$n->setNoteEnc(isset($in['note']) && $in['note'] !== '' ? $this->crypto->encrypt(mb_substr((string)$in['note'], 0, 4000)) : null);
		// priority is server-derived from kind — client claims ignored (NN-13)
		$n->setPriority($kind === 'incident' ? 'high' : 'normal');
		$n->setState('open');
		$n->setIdemKey($idem);
		$n->setCreatedAt(new \DateTime());
		try {
			$n = $this->notices->insert($n);
		} catch (\OCP\DB\Exception $e) {
			if ($idem !== null
				&& $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
			) {
				$dup = $this->notices->findByIdem($actorRef, $idem);
				if ($dup !== null) {
					return ['id' => (int)$dup->getId(), 'state' => $dup->getState(), 'priority' => $dup->getPriority()];
				}
			}
			throw $e;
		}
		return ['id' => (int)$n->getId(), 'state' => 'open', 'priority' => $n->getPriority()];
	}

	/** Actor's own notices — kind/state/acked only, note stays server-side. */
	public function noticesFor(string $actorRef): array
	{
		$out = [];
		foreach ($this->notices->findAll() as $n) {
			if ($n->getActorRef() !== $actorRef) {
				continue;
			}
			$out[] = [
				'id' => (int)$n->getId(), 'kind' => $n->getKind(), 'state' => $n->getState(),
				'escalated' => (int)$n->getEscalated() === 1,
				'createdAt' => $n->getCreatedAt()->format(\DateTimeInterface::ATOM),
				'ackedAt' => $n->getAckedAt()?->format(\DateTimeInterface::ATOM),
				'resolution' => in_array($n->getResolution(), ['acked', 'error'], true) ? $n->getResolution() : '',
			];
		}
		return $out;
	}

	/**
	 * "Sent in error" (UX-13): the author retracts their own notice. It is not
	 * deleted — the audit trail keeps it — but the Zentrale sees it resolved,
	 * not live. Only the author may retract, only while still open.
	 */
	public function resolveNoticeError(string $actorRef, int $id): void
	{
		$n = $this->notices->find($id);
		if ($n === null || $n->getActorRef() !== $actorRef || $n->getState() !== 'open') {
			throw ApiException::notFound(); // uniform 404 — retracting others'/closed notices leaks nothing
		}
		$n->setState('acked');
		$n->setResolution('error');
		$n->setAckedBy($actorRef);
		$n->setAckedAt(new \DateTime());
		$this->notices->update($n);
		$this->acl->audit($actorRef, 'notice_resolve_error', $n->getClientId() !== null ? (int)$n->getClientId() : null);
	}

	/**
	 * Quarantine a structurally-invalid bundle (spec §validation →
	 * rejected_review, never dropped). Persists the visit row + reason so the
	 * Zentrale sees it in the review queue (reviewPending on coverage) and the
	 * correcting journal entry proves what was rejected. A repeat submission
	 * with the same idem key updates the stored reason instead of inserting a
	 * second row; a corrected payload passes validation on the next call and
	 * the row proceeds through the normal write path.
	 * @param array<string, mixed> $in
	 * @param array<string, mixed> $fields
	 */
	private function quarantine(string $actorRef, array $in, int $clientId, string $idem, array $fields, ?Visit $existing): void
	{
		$reason = mb_substr(implode(',', array_keys($fields)), 0, 96);
		$now = new \DateTime();
		$this->db->beginTransaction();
		try {
			$v = $existing ?? new Visit();
			if ($existing === null) {
				$v->setActorRef($actorRef);
				$v->setIdemKey($idem);
				$v->setClientId($clientId);
				$v->setCreatedAt($now);
				$v->setSigState('none');
				$v->setQualMismatch($this->qualMismatch($actorRef, $clientId) ? 1 : 0);
				$cc = $this->clients->find($clientId);
				$v->setIsDemo($cc !== null && (int)$cc->getIsDemo() === 1 ? 1 : 0);
			}
			$v->setTstopId(isset($in['tstop_id']) && is_numeric($in['tstop_id']) ? (int)$in['tstop_id'] : $v->getTstopId());
			$v->setCheckinTs($v->getCheckinTs() ?? $now);
			$v->setStatus('rejected_review');
			$v->setRejectReason($reason);
			// keep the note so the reviewer sees what the caregiver meant —
			// signature/tasks are NOT stored (they failed validation)
			if (isset($in['note']) && is_string($in['note']) && $in['note'] !== '') {
				$v->setNoteEnc($this->crypto->encrypt(mb_substr($in['note'], 0, 4000)));
			}
			$v->setUpdatedAt($now);
			$v = $existing === null ? $this->visits->insert($v) : $this->visits->update($v);
			$this->journal->append($clientId, 'visit_rejected', $actorRef, [
				'visit' => (int)$v->getId(),
				'fields' => array_keys($fields),
				'reason' => $reason,
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof \OCP\DB\Exception
				&& $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
			) {
				// Concurrent quarantine on the same idem — the winner's row is
				// already authoritative; nothing more to persist.
				return;
			}
			throw $e;
		}
	}

	private function markStop(?int $stopId, string $status): void
	{
		if ($stopId === null) {
			return;
		}
		$stop = $this->stops->find($stopId);
		if ($stop !== null && $stop->getStatus() !== 'done') {
			$stop->setStatus($status);
			$this->stops->update($stop);
		}
	}

	/**
	 * Skip a planned stop — documents "visit cannot happen" in ≤2 taps
	 * (US-203 AC-9). Skipped stops are journaled + exportable, never absent.
	 */
	public function skipStop(string $actorRef, int $stopId, string $reasonCode): array
	{
		if (!in_array($reasonCode, Constants::SKIP_REASONS, true)) {
			throw ApiException::validation('Validation failed.', ['reason_code' => 'invalid']);
		}
		$stop = $this->stops->find($stopId);
		if ($stop === null) {
			throw ApiException::notFound();
		}
		$clientId = (int)$stop->getClientId();
		if ($stop->getKind() === 'external' || $clientId === 0) {
			// external stop: still documentable, but scoped to its executor
			if ($stop->getExecutorRef() !== $actorRef && !$this->acl->isStaff($actorRef)) {
				throw ApiException::notFound();
			}
		} else {
			$this->acl->assertClientScope($actorRef, $clientId);
		}
		if (in_array($stop->getStatus(), ['done', 'skipped'], true)) {
			return ['id' => $stopId, 'status' => $stop->getStatus()]; // idempotent
		}
		$this->db->beginTransaction();
		try {
			$stop->setStatus('skipped');
			$stop->setSkipReasonCode($reasonCode);
			$this->stops->update($stop);
			if ($clientId > 0) {
				$this->journal->append($clientId, 'stop_skipped', $actorRef, ['stop' => $stopId, 'reason' => $reasonCode]);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['id' => $stopId, 'status' => 'skipped'];
	}

	/**
	 * Briefing card payload — the Vertretung safety surface (MH-07b):
	 * risk/allergy flags, access notes, emergency contacts, open incident
	 * count. Only decrypted fields the scoped caregiver must see before
	 * knocking; everything else stays sealed.
	 */
	private function briefing(int $clientId): array
	{
		$client = $this->clients->find($clientId);
		$payload = [];
		if ($client !== null) {
			$payload = json_decode($this->crypto->decrypt((string)$client->getPayloadEnc()), true) ?? [];
		}
		$open = 0;
		foreach ($this->notices->findAll() as $n) {
			if ((int)$n->getClientId() === $clientId && $n->getKind() === 'incident' && $n->getState() === 'open') {
				$open++;
			}
		}
		return [
			'risks' => array_values(array_filter(array_map('strval', (array)($payload['risks'] ?? [])))),
			'accessNote' => (string)($payload['accessNote'] ?? ''),
			'emergencyContacts' => array_values(array_map(
				fn (array $c) => ['name' => (string)($c['name'] ?? ''), 'phone' => (string)($c['phone'] ?? '')],
				array_filter((array)($payload['contacts'] ?? []), fn ($c) => is_array($c) && (($c['emergency'] ?? false) === true)))),
			'openIncidents' => $open,
			'qualMismatch' => false, // filled by caller when relevant
		];
	}

	private function qualMismatch(string $actorRef, int $clientId): bool
	{
		$client = $this->clients->find($clientId);
		$req = $client !== null ? $client->getRequiresQual() : null;
		if ($req === null || $req === '') {
			return false;
		}
		$member = $this->acl->memberForRef($actorRef);
		if ($member === null) {
			return str_starts_with($actorRef, 'uid:') ? false : false; // uids are qualified staff by role check
		}
		$rank = [Constants::QUAL_HILF => 0, Constants::QUAL_BETR => 1, Constants::QUAL_FACH => 2];
		return ($rank[(string)$member->getQualification()] ?? 0) < ($rank[$req] ?? 0);
	}

	private function geoAllowed(): bool
	{
		return $this->settings->getBool('gps_enabled');
	}

/**
	 * Strict digits — is_numeric accepts "2.9"/"2e3"/" 5" (api-security §7).
	 * @param array<string, mixed> $in
	 */
	private function tstopId(array $in): ?int
	{
		$raw = (string)($in['tstop_id'] ?? '');
		if ($raw === '') {
			return null;
		}
		if (!preg_match('/^\d+$/', $raw)) {
			throw ApiException::validation('Validation failed.', ['tstop_id' => 'invalid']);
		}
		return (int)$raw;
	}

	/**
	 * Stop-scope binding: a `tstop_id` must be a real client stop whose
	 * executor is the caller (or Zentrale staff, or a break-glass holder on
	 * that client). Anything else collapses to the uniform 404 — a guessed
	 * foreign stop id must neither flip another caregiver's stop status nor
	 * learn that the id exists. Client-binding stops cross-client traffic:
	 * the stop's client must equal the bundle's client.
	 */
	private function assertStopScope(string $actorRef, ?int $stopId, int $clientId): void
	{
		if ($stopId === null) {
			return; // ad-hoc visit without a planned stop (break-glass path)
		}
		$stop = $this->stops->find($stopId);
		if ($stop === null || $stop->getKind() !== 'client' || (int)$stop->getClientId() !== $clientId) {
			throw ApiException::notFound();
		}
		if ($stop->getExecutorRef() === $actorRef || $this->acl->isStaff($actorRef)) {
			return;
		}
		if ($this->acl->hasActiveGrant($actorRef, $clientId)) {
			return; // break-glass documentation on an uncovered/foreign stop
		}
		throw ApiException::notFound();
	}

	/**
	 * @param array<string, mixed> $in
	 */
	private function clientId(array $in): int
	{
		$raw = (string)($in['client_id'] ?? '');
		if (!preg_match('/^\d+$/', $raw)) {
			throw ApiException::validation('Validation failed.', ['client_id' => 'invalid']);
		}
		return (int)$raw;
	}

	private function serialize(Visit $v): array
	{
		return [
			'id' => (int)$v->getId(), 'clientId' => (int)$v->getClientId(), 'tstopId' => $v->getTstopId() !== null ? (int)$v->getTstopId() : null,
			'status' => $v->getStatus(), 'checkinTs' => $v->getCheckinTs()?->format(\DateTimeInterface::ATOM),
			'checkoutTs' => $v->getCheckoutTs()?->format(\DateTimeInterface::ATOM), 'durationSec' => $v->getDurationSec(),
			'sigState' => $v->getSigState(), 'qualMismatch' => (int)$v->getQualMismatch() === 1,
		];
	}
}
