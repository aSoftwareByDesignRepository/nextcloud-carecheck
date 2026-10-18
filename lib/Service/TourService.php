<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Db\Tour;
use OCA\CareCheck\Db\TourMapper;
use OCA\CareCheck\Db\TourStop;
use OCA\CareCheck\Db\TourStopMapper;
use OCA\CareCheck\Db\TourTemplateMapper;
use OCA\CareCheck\Db\TtStopMapper;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Db\Client;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\PlanVersionMapper;
use OCP\IDBConnection;

/**
 * Tour templates → daily instances. Generation is idempotent
 * (UNIQUE(template_id,date)), published-only, and resync is stop-granular:
 * only stops without in_progress/final visits are rewritten.
 */
class TourService
{
	public function __construct(
		private IDBConnection $db,
		private TourMapper $tours,
		private TourStopMapper $stops,
		private TourTemplateMapper $templates,
		private TtStopMapper $ttStops,
		private AclService $acl,
		private SettingsService $settings,
		private ?PlanVersionMapper $plans = null,
		private ?CryptoService $crypto = null,
		private ?AbsenceService $absences = null,
		private ?ClientMapper $clients = null,
	) {
	}

	/** Materialize instances for published templates for [today, today+horizon). */
	public function generate(int $horizonDays): int
	{
		$created = 0;
		$tz = new \DateTimeZone($this->providerTz());
		for ($d = 0; $d <= $horizonDays; $d++) {
			$date = (new \DateTime('today', $tz))->modify("+{$d} days");
			$weekday = (int)$date->format('N'); // 1=Mon
			foreach ($this->templates->findAll() as $tpl) {
				if ($tpl->getStatus() !== 'published') {
					continue;
				}
				$days = array_map('intval', explode(',', (string)$tpl->getWeekdaySet()));
				if (!in_array($weekday, $days, true)) {
					continue;
				}
				$dateStr = $date->format('Y-m-d');
				if ($this->tourForTemplateDate((int)$tpl->getId(), $dateStr) !== null) {
					continue; // UNIQUE(template_id,date) — idempotent re-run
				}
				$created += $this->materialize((int)$tpl->getId(), $dateStr, (string)$tpl->getLeadRef());
			}
		}
		return $created;
	}

	/** Provider-local timezone — tour dates/windows are provider-local, not UTC. */
	public function providerTz(): string
	{
		$tz = $this->settings->get('provider_tz');
		return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Europe/Berlin';
	}

	/** Provider-local "today" — every tour date derives from this. */
	public function today(): string
	{
		return (new \DateTime('today', new \DateTimeZone($this->providerTz())))->format('Y-m-d');
	}

	public function tourForTemplateDate(int $templateId, string $date): ?Tour
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('cck_tour')
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId)))
			->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($date)));
		$rows = $qb->executeQuery()->fetchAll();
		return $rows === [] ? null : Tour::fromRow($rows[0]);
	}

	private function materialize(int $templateId, string $date, ?string $leadRef): int
	{
		$t = new Tour();
		$t->setTemplateId($templateId);
		$t->setDate(new \DateTime($date));
		$t->setExecutorRef($leadRef);
		$t->setStatus('open');
		$t->setRevision(1);
		$t->setCreatedAt(new \DateTime());
		$t = $this->tours->insert($t);

		$n = 0;
		foreach ($this->ttStops->findAll() as $s) {
			if ((int)$s->getTemplateId() !== $templateId) {
				continue;
			}
			$st = new TourStop();
			$st->setTourId((int)$t->getId());
			$st->setOrderHint((int)$s->getOrderHint());
			$st->setWinStart((int)$s->getWinStart());
			$st->setWinEnd((int)$s->getWinEnd());
			$st->setKind((string)$s->getKind());
			$st->setClientId($s->getClientId() !== null ? (int)$s->getClientId() : null);
			$st->setTimeKind((string)($s->getTimeKind() ?: 'window'));
			$st->setToleranceMin($s->getToleranceMin() !== null ? (int)$s->getToleranceMin() : null);
			$st->setLocText($s->getLocText());
			$exec = $s->getExecutorRef() !== null ? (string)$s->getExecutorRef() : $leadRef;
			// planned absence at generation time → stop lands in the
			// unassigned queue immediately (dispatcher fixes coverage
			// before the day, not after the first missed visit)
			$absent = $exec !== null && $exec !== '' && $this->absences !== null
				&& $this->absences->isAbsentOn($exec, new \DateTime($date));
			if ($exec === null || $exec === '' || $absent) {
				// unassigned or absent executor → unassigned queue, never dropped
				$st->setExecutorRef($absent ? null : ($exec ?: null));
				$st->setStatus('uncovered');
			} else {
				$st->setExecutorRef($exec);
				$st->setStatus('pending');
			}
			$this->stops->insert($st);
			$n++;
		}
		return $n;
	}

	/** Coverage board for a date: all tours + stops (staff) or actor's stops. */
	public function coverage(string $actorRef, string $date): array
	{
		$staff = $this->acl->isStaff($actorRef);
		// NH-11: team leads see stops on clients of the teams they lead.
		// Members keep executor-scoping; Zentrale keeps flat visibility.
		$leadTeams = $this->acl->isStaff($actorRef) ? null : $this->acl->leadTeamIds($actorRef);
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id AS tour_id', 't.template_id', 't.executor_ref AS tour_exec', 's.*')
			->from('cck_tour', 't')
			->leftJoin('t', 'cck_tstop', 's', 's.tour_id = t.id')
			->where($qb->expr()->eq('t.date', $qb->createNamedParameter($date)))
			->orderBy('s.order_hint', 'ASC');
		$tours = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$tid = (int)$row['tour_id'];
			if (!isset($tours[$tid])) {
				$tours[$tid] = ['id' => $tid, 'templateId' => $row['template_id'] !== null ? (int)$row['template_id'] : null,
					'executorRef' => $row['tour_exec'], 'executorLabel' => $this->acl->refLabel($row['tour_exec']), 'stops' => []];
			}
			if ($row['id'] === null) {
				continue;
			}
			if (!$staff) {
				if ($leadTeams !== null && $leadTeams !== []) {
					// team lead: stop's client must sit in a led team
					$cid = $row['client_id'] !== null ? (int)$row['client_id'] : null;
					if ($cid === null || $this->acl->clientTeamId($cid) === null
						|| !in_array($this->acl->clientTeamId($cid), $leadTeams, true)) {
						continue;
					}
				} elseif ($row['executor_ref'] !== $actorRef) {
					continue;
				}
			}
			$tours[$tid]['stops'][] = $this->serializeStopRow($row);
		}
		if (!$staff) {
			// leads/members never see tours whose every visible stop was
			// scoped out — an empty card would leak "a tour exists" shape
			$tours = array_filter($tours, static fn ($t) => $t['stops'] !== []);
		}
		return array_values($this->markReviewPending($tours));
	}

	/**
	 * NH-09 — station sign-off board: aggregate progress per executor for a
	 * date. Deliberately name/street-free (shared screen, no client scope).
	 * @return list<array{executorRef:string,tours:int,stops:int,done:int}>
	 */
	public function stationBoard(string $date): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.executor_ref', 's.tour_id', 's.status')
			->from('cck_tstop', 's')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->where($qb->expr()->eq('t.date', $qb->createNamedParameter($date)));
		$agg = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			$ref = (string)($r['executor_ref'] ?? '');
			if ($ref === '') {
				$ref = 'uncovered';
			}
			if (!isset($agg[$ref])) {
				$agg[$ref] = ['executorRef' => $ref, 'tours' => [], 'stops' => 0, 'done' => 0];
			}
			$agg[$ref]['tours'][(int)$r['tour_id']] = true;
			$agg[$ref]['stops']++;
			if (in_array($r['status'], ['done', 'skipped'], true)) {
				$agg[$ref]['done']++;
			}
		}
		$out = [];
		foreach ($agg as $a) {
			$out[] = ['executorRef' => $a['executorRef'], 'tours' => count($a['tours']), 'stops' => $a['stops'], 'done' => $a['done']];
		}
		return $out;
	}

/**
	 * Shared stop serializer for coverage + companion pulls.
	 * @param array<string, mixed> $row
	 */
	private function serializeStopRow(array $row): array
	{
		$cid = $row['client_id'] !== null ? (int)$row['client_id'] : null;
		return [
			'id' => (int)$row['id'], 'clientId' => $cid, 'orderHint' => (int)$row['order_hint'],
			'winStart' => (int)$row['win_start'], 'winEnd' => (int)$row['win_end'], 'kind' => $row['kind'],
			'timeKind' => $row['time_kind'] ?? 'window', 'toleranceMin' => $row['tolerance_min'] !== null ? (int)$row['tolerance_min'] : null,
			'locText' => $row['loc_text'] ?? null,
			'clientName' => $cid !== null ? ($this->clientName($cid)) : null,
			// street is needed for companion navigation — in-scope
			// member data, decrypted server-side, never cached to disk
			// by the app (outbox stores bundles, not stop lists).
			'clientStreet' => $cid !== null ? $this->clientStreet($cid) : null,
			'isDemo' => $cid !== null && $this->isDemoClient($cid),
			'executorRef' => $row['executor_ref'], 'status' => $row['status'], 'skipReasonCode' => $row['skip_reason_code'],
			// UX-13: a stop whose visit bundle sits in the Zentrale
			// review queue renders distinctly — never identical to a
			// synced stop, never silently absent.
			'reviewPending' => false,
		];
	}

	/**
	 * UX-13 flag: stops whose visit bundle sits in the rejected_review
	 * quarantine queue. Shared by coverage() and actorOpenStops().
	 * @param array<array-key, array<string, mixed>> $tours
	 */
	private function markReviewPending(array $tours): array
	{
		$stopIds = [];
		foreach ($tours as $t) {
			foreach ($t['stops'] as $st) {
				$stopIds[] = (int)$st['id'];
			}
		}
		if ($stopIds === []) {
			return $tours;
		}
		$vqb = $this->db->getQueryBuilder();
		$vqb->select('tstop_id')->from('cck_visit')
			->where($vqb->expr()->eq('status', $vqb->createNamedParameter('rejected_review')))
			->andWhere($vqb->expr()->in('tstop_id', $vqb->createNamedParameter($stopIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$pending = array_flip(array_map('intval', array_column($vqb->executeQuery()->fetchAll(), 'tstop_id')));
		foreach ($tours as &$t) {
			foreach ($t['stops'] as &$st) {
				$st['reviewPending'] = isset($pending[(int)$st['id']]);
			}
			unset($st);
		}
		unset($t);
		return $tours;
	}

	/**
	 * Every OPEN (not done) stop assigned to the actor, across all dates —
	 * `/api/v1/tours/open` + `/api/v1/tours/today` per the companion spec:
	 * yesterday's unfinished tour stays reachable until resolved.
	 * Only executor-assigned rows qualify — uncovered stops never leak.
	 */
	public function actorOpenStops(string $actorRef): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id AS tour_id', 't.template_id', 't.executor_ref AS tour_exec', 't.date AS tour_date', 's.*')
			->from('cck_tstop', 's')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->where($qb->expr()->eq('s.executor_ref', $qb->createNamedParameter($actorRef)))
			->andWhere($qb->expr()->in('s.status', $qb->createNamedParameter(['pending', 'in_progress'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('t.date', 'ASC')
			->addOrderBy('s.order_hint', 'ASC');
		$tours = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$tid = (int)$row['tour_id'];
			if (!isset($tours[$tid])) {
				$tours[$tid] = ['id' => $tid, 'templateId' => $row['template_id'] !== null ? (int)$row['template_id'] : null,
					'executorRef' => $row['tour_exec'], 'executorLabel' => $this->acl->refLabel($row['tour_exec']),
					'date' => (string)$row['tour_date'], 'stops' => []];
			}
			$tours[$tid]['stops'][] = $this->serializeStopRow($row);
		}
		$tours = $this->markReviewPending($tours);
		$taskCache = [];
		foreach ($tours as &$t) {
			foreach ($t['stops'] as &$s) {
				$cid = $s['clientId'] !== null ? (int)$s['clientId'] : 0;
				if (!array_key_exists($cid, $taskCache)) {
					$taskCache[$cid] = $this->tasksForClient($cid);
				}
				$s['tasks'] = $taskCache[$cid];
			}
			unset($s);
		}
		unset($t);
		return array_values($tours);
	}

	/** Reassign a stop to a new executor (Zentrale). */
	public function assignStop(string $actorRef, int $stopId, string $executorRef): TourStop
	{
		$this->acl->assertStaff($actorRef);
		if (!$this->acl->validRef($executorRef)) {
			throw ApiException::validation('Validation failed.', ['executor_ref' => 'invalid']);
		}
		$stop = $this->stops->find($stopId);
		if ($stop === null) {
			throw ApiException::notFound();
		}
		if ($stop->getStatus() === 'done') {
			throw ApiException::conflict('Stop already completed.');
		}
		$stop->setExecutorRef($executorRef);
		if ($stop->getStatus() === 'uncovered' || $stop->getStatus() === 'pending') {
			$stop->setStatus('pending');
		}
		return $this->stops->update($stop);
	}

	/** Actor-scoped stops for a date (companion tour pull). */
	public function actorStopsForDate(string $actorRef, string $date): array
	{
		$tours = $this->coverage($actorRef, $date);
		$taskCache = [];
		foreach ($tours as &$t) {
			$stops = array_values(array_filter(
				$t['stops'],
				// executor only — uncovered stops (executorRef === null) are
				// Zentrale-queue items; leaking them would expose client
				// name/street to every paired member.
				fn (array $s) => $s['executorRef'] === $actorRef
			));
			foreach ($stops as &$s) {
				// Companion needs the plan's task list to render the visit
				// checklist — decrypt server-side and pass the labels through.
				$cid = (int)$s['clientId'];
				if (!array_key_exists($cid, $taskCache)) {
					$taskCache[$cid] = $this->tasksForClient($cid);
				}
				$s['tasks'] = $taskCache[$cid];
			}
			unset($s);
			// US-202 AC-9: order by deadline-criticality — done/skipped sink,
			// then deadline < fixed < window, earliest due first
			usort($stops, function (array $a, array $b): int {
				$ad = in_array($a['status'], ['done', 'skipped'], true) ? 1 : 0;
				$bd = in_array($b['status'], ['done', 'skipped'], true) ? 1 : 0;
				if ($ad !== $bd) { return $ad <=> $bd; }
				$rank = ['deadline' => 0, 'fixed' => 1, 'window' => 2];
				$ra = $rank[$a['timeKind'] ?? 'window'] ?? 2;
				$rb = $rank[$b['timeKind'] ?? 'window'] ?? 2;
				if ($ra !== $rb) { return $ra <=> $rb; }
				if ($a['winEnd'] !== $b['winEnd']) { return $a['winEnd'] <=> $b['winEnd']; }
				return $a['orderHint'] <=> $b['orderHint'];
			});
			$t['stops'] = $stops;
		}
		unset($t);
		return array_values(array_filter($tours, fn (array $t) => $t['stops'] !== []));
	}

	private array $payloadCache = [];
	private array $demoCache = [];

	private function clientPayload(int $clientId): ?array
	{
		if (!array_key_exists($clientId, $this->payloadCache)) {
			$this->payloadCache[$clientId] = null;
			if ($this->crypto !== null) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('payload_enc')->from('cck_client')->where($qb->expr()->eq('id', $qb->createNamedParameter($clientId)));
				$row = $qb->executeQuery()->fetch();
				if ($row !== false) {
					$this->payloadCache[$clientId] = json_decode($this->crypto->decrypt((string)$row['payload_enc']), true) ?: null;
				}
			}
		}
		return $this->payloadCache[$clientId];
	}

	private function clientName(int $clientId): ?string
	{
		$p = $this->clientPayload($clientId);
		return $p !== null ? (string)($p['name'] ?? '') : null;
	}

	private function clientStreet(int $clientId): ?string
	{
		$p = $this->clientPayload($clientId);
		$v = $p !== null ? trim((string)($p['street'] ?? '')) : '';
		return $v !== '' ? $v : null;
	}

	/**
	 * Übungstour (MH-15): system-owned demo client + one ad-hoc tour for
	 * today. The demo client is created WITHOUT a staff assertion — it is a
	 * server-owned flag carrier, and members may mint practice tours for
	 * themselves only (the executor ref is always the caller). tour+stop
	 * insert atomically — a mid-insert failure must not leave a stop-less
	 * tour or an orphaned demo client half-flagged.
	 *
	 * ponytail: two racing first-callers could each create a demo client
	 * (no natural unique key exists for a flag); duplicate Übungsklienten
	 * are cosmetic-only, never data corruption.
	 */
	public function createPracticeTour(string $actor, string $executorRef): array
	{
		if ($this->clients === null || $this->crypto === null) {
			throw new ApiException('SERVER_ERROR', 'Practice tours unavailable.', [], 500);
		}
		$this->db->beginTransaction();
		try {
			$demo = null;
			foreach ($this->clients->findAll() as $c) {
				if ((int)$c->getIsDemo() === 1) {
					$demo = $c;
					break;
				}
			}
			if ($demo === null) {
				$payload = ['name' => 'Übungsklientin', 'street' => '—'];
				$demo = new Client();
				$demo->setNameHmac($this->crypto->nameHmac($this->crypto->normalizeName('Übungsklientin')));
				$demo->setPayloadEnc($this->crypto->encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
				$demo->setIsDemo(1); // server-owned flag — never client-settable
				$demo->setActive(1);
				$demo->setRevision(1);
				$now = new \DateTime();
				$demo->setCreatedAt($now);
				$demo->setUpdatedAt($now);
				$demo = $this->clients->insert($demo);
			}
			$t = new Tour();
			$t->setTemplateId(null);
			$t->setDate(new \DateTime($this->today()));
			$t->setExecutorRef($executorRef);
			$t->setStatus('open');
			$t->setRevision(1);
			$t->setCreatedAt(new \DateTime());
			$t = $this->tours->insert($t);
			$s = new TourStop();
			$s->setTourId((int)$t->getId());
			$s->setClientId((int)$demo->getId());
			$s->setOrderHint(0);
			$s->setWinStart(0);
			$s->setWinEnd(1439);
			$s->setKind('client');
			$s->setTimeKind('window');
			$s->setExecutorRef($executorRef);
			$s->setStatus('pending');
			$this->stops->insert($s);
			$this->db->commit();
			return ['tour' => (int)$t->getId(), 'client' => (int)$demo->getId()];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function isDemoClient(int $clientId): bool
	{
		if (!array_key_exists($clientId, $this->demoCache)) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('is_demo')->from('cck_client')->where($qb->expr()->eq('id', $qb->createNamedParameter($clientId)));
			$this->demoCache[$clientId] = (int)($qb->executeQuery()->fetchOne() ?: 0) === 1;
		}
		return $this->demoCache[$clientId];
	}

	/** Latest published plan snapshot's task list for a client ([] when none). */
	private function tasksForClient(int $clientId): array
	{
		if ($this->plans === null || $this->crypto === null) {
			return [];
		}
		$latest = null;
		foreach ($this->plans->findAll() as $pv) {
			if ((int)$pv->getClientId() === $clientId && $pv->getStatus() === 'published') {
				$latest = $pv;
			}
		}
		if ($latest === null) {
			return [];
		}
		$decoded = json_decode($this->crypto->decrypt((string)$latest->getSnapshotEnc()), true);
		return is_array($decoded) && isset($decoded['tasks']) && is_array($decoded['tasks']) ? $decoded['tasks'] : [];
	}
}
