<?php

declare(strict_types=1);

namespace OCA\CareCheck\Controller;

use OCA\CareCheck\AppInfo\Application;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\Member;
use OCA\CareCheck\Db\MemberMapper;
use OCA\CareCheck\Db\NoticeMapper;
use OCA\CareCheck\Db\PlanVersion;
use OCA\CareCheck\Db\PlanVersionMapper;
use OCA\CareCheck\Db\TourTemplate;
use OCA\CareCheck\Db\TourTemplateMapper;
use OCA\CareCheck\Db\TtStop;
use OCA\CareCheck\Db\TtStopMapper;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Constants;
use OCA\CareCheck\Service\AbsenceService;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\ClientService;
use OCA\CareCheck\Service\CryptoService;
use OCA\CareCheck\Service\ExportService;
use OCA\CareCheck\Service\PairService;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\TourService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;

/** Zentrale web API — session + CSRF (NC default), staff-role scoped. */
class ApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		IRequest $request,
		private IDBConnection $db,
		private AclService $acl,
		private ClientMapper $clientMapper,
		private ClientService $clients,
		private TourService $tours,
		private PairService $pairs,
		private SettingsService $settings,
		private CryptoService $crypto,
		private MemberMapper $members,
		private NoticeMapper $notices,
		private PlanVersionMapper $plans,
		private TourTemplateMapper $templates,
		private TtStopMapper $ttStops,
		private \OCA\CareCheck\Db\TourMapper $tourMapper,
		private \OCA\CareCheck\Db\TourStopMapper $tstopMapper,
		private \OCA\CareCheck\Db\VisitMapper $visitMapper,
		private AbsenceService $absences,
		private ExportService $exporter,
		private \OCA\CareCheck\Db\TaskCatalogMapper $catalog,
		private \OCA\CareCheck\Service\JournalService $journal,
		private \OCP\IGroupManager $groups,
		private \OCP\IUserManager $users,
		private \OCA\CareCheck\Service\DriveSegmentService $driveSegments,
		private \OCA\CareCheck\Service\LicenseService $license,
		private \OCP\App\IAppManager $appManager,
		private \OCA\CareCheck\Service\AzcAbsenceAdapter $azcAbsences,
		private \OCA\CareCheck\Service\DutyRosterAdapter $dutyRoster,
		private \OCA\CareCheck\Service\WorkLogFacade $workLog,
		private \OCA\CareCheck\Service\BillingDraftService $billingDrafts,
		private \OCA\CareCheck\Service\QmService $qm,
		private \OCA\CareCheck\Service\ClientDocService $clientDocs,
		private \OCA\CareCheck\Service\ClientImportService $clientImport,
		private \OCA\CareCheck\Service\PresenceService $presence,
		private \OCA\CareCheck\Service\ShareService $shares,
		private \OCA\CareCheck\Service\DigestService $digest,
		private \OCA\CareCheck\Service\PhotoService $photos,
		private \OCA\CareCheck\Service\VoiceNoteService $voiceNotes,
		private \OCA\CareCheck\Service\TeamService $teams,
		private \OCA\CareCheck\Service\GroupSessionService $groupSessions,
		private \OCA\CareCheck\Service\PrivacyProposalService $privacyProposals,
		private \OCA\CareCheck\Service\CrmLinkService $crmLinks,
		private \OCA\CareCheck\Service\InvoiceDraftPushService $invoiceDraftPush,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function actor(): string
	{
		// DS-2 access door for session actors: restricted mode must refuse at
		// the boundary, not deep inside per-route checks. Device-token actors
		// never reach this path (companion controller resolves separately).
		$this->acl->assertAppAccess();
		return $this->acl->actorRef(null); // session only
	}

	#[NoAdminRequired]
	public function coverage(string $date): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertDispatch($actor);
			return $this->ok([
				'date' => $date,
				'tours' => $this->tours->coverage($actor, $date),
				'cronStale' => $this->cronStale(),
			]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function listClients(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertDispatch($actor);
			$this->acl->audit($actor, 'list');
			// NH-11: team leads see only clients of teams they lead
			$leadTeams = $this->acl->leadTeamIds($actor);
			$full = $this->acl->isStaff($actor);
			$out = [];
			foreach ($this->clientMapper->findAll() as $c) {
				if ((int)$c->getActive() === 0) {
					continue;
				}
				if (!$full) {
					$tid = $c->getTeamId();
					if ($tid === null || !in_array((int)$tid, $leadTeams, true)) {
						continue;
					}
				}
				$h = $this->clients->hydrated($c);
				$out[] = ['id' => $h['id'], 'name' => $h['payload']['name'] ?? '', 'street' => $h['payload']['street'] ?? '', 'requiresQual' => $h['requiresQual']];
			}
			return $this->ok(['clients' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function createClient(): JSONResponse
	{
		try {
			$p = $this->request->getParams();
			$c = $this->clients->create($this->actor(), $p, $p['requires_qual'] ?? null);
			return $this->ok(['id' => (int)$c->getId()], 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function archiveClient(int $id): JSONResponse
	{
		try {
			$this->clients->archive($this->actor(), $id);
			return $this->ok(['id' => $id]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function publishPlan(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$client = $this->clients->getForActor($actor, $id);
			$p = $this->request->getParams();
			$tasks = $p['tasks'] ?? null;
			if (!is_array($tasks)) {
				throw ApiException::validation('Validation failed.', ['tasks' => 'required']);
			}
			// CAS: expected revision guards concurrent edits
			if (!isset($p['base_revision']) || !preg_match('/^\d+$/', (string)$p['base_revision'])) {
				throw ApiException::conflict('Reload before publishing.');
			}
			// Serialize on the client row — same lock order as JournalService,
			// so concurrent publishes queue instead of racing the CAS check.
			$this->db->beginTransaction();
			try {
				$lock = $this->db->prepare('SELECT id FROM `*PREFIX*cck_client` WHERE `id` = :id FOR UPDATE');
				$lock->execute(['id' => $id]);
				$existing = null;
				foreach ($this->plans->findAll() as $pv) {
					if ((int)$pv->getClientId() === $id && $pv->getStatus() === 'published') {
						$existing = $pv;
					}
				}
				if ($existing !== null && (int)$existing->getRevision() !== (int)$p['base_revision']) {
					throw ApiException::conflict('Plan changed — reload.');
				}
				if ($existing !== null) {
					$existing->setStatus('draft');
					$this->plans->update($existing);
				}
				$pv = new PlanVersion();
				$pv->setClientId($id);
				$pv->setSnapshotEnc($this->crypto->encrypt(json_encode(['tasks' => $tasks], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
				$pv->setStatus('published');
				$pv->setPublishedBy($actor);
				$pv->setPublishedAt(new \DateTime());
				$pv->setRevision(($existing !== null ? (int)$existing->getRevision() : 0) + 1);
				$pv->setCreatedAt(new \DateTime());
				$pv = $this->plans->insert($pv);
				$this->db->commit();
			} catch (ApiException $e) {
				$this->db->rollBack();
				throw $e;
			} catch (\Throwable $th) {
				$this->db->rollBack();
				if ($th instanceof \OCP\DB\Exception
					&& $th->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw ApiException::conflict('Plan changed — reload.');
				}
				throw $th;
			}
			return $this->ok(['id' => (int)$pv->getId(), 'revision' => (int)$pv->getRevision()], 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * Normalize + validate template params — shared by create and update so a
	 * template edit can never be laxer than a template create. Throws
	 * ApiException::validation on failure; returns the normalized payload.
	 * @param array<string, mixed> $p
	 * @return array{name:string, weekdaysCsv:string, status:string, leadRef:?string, stops:list<array<string, mixed>>}
	 */
	private function templateParams(array $p): array
	{
		$name = trim((string)($p['name'] ?? ''));
		$weekdays = array_values(array_filter(array_map('intval', (array)($p['weekday_set'] ?? [])), fn (int $d) => $d >= 1 && $d <= 7));
		$stops = array_values((array)($p['stops'] ?? []));
		$status = (string)($p['status'] ?? 'draft');
		$fields = [];
		if ($name === '') { $fields['name'] = 'required'; }
		if ($weekdays === []) { $fields['weekday_set'] = 'required'; }
		if (!in_array($status, Constants::TPL_STATUSES, true)) { $fields['status'] = 'invalid'; }
		$leadRef = trim((string)($p['lead_ref'] ?? ''));
		if ($leadRef !== '' && !$this->acl->validRef($leadRef)) { $fields['lead_ref'] = 'invalid'; }
		// per-stop: strict ids + referenced entities must exist — no FKs to
		// save us (api-security §7), and window bounds must be sane
		foreach ($stops as $s) {
			$skind = $s['kind'] ?? 'client';
			if ($skind === 'external') {
				if (trim((string)($s['loc_text'] ?? '')) === '') { $fields['stops'] = 'invalid'; }
			} else {
				$cid = (string)($s['client_id'] ?? '');
				if (preg_match('/^\d+$/', $cid) !== 1) { $fields['stops'] = 'invalid'; continue; }
				$cl = $this->clientMapper->find((int)$cid);
				if ($cl === null || (int)$cl->getActive() !== 1) { $fields['stops'] = 'invalid'; }
			}
			$ws = $s['win_start'] ?? null; $we = $s['win_end'] ?? null;
			if (!is_numeric($ws) || !is_numeric($we)
				|| (int)$ws < 0 || (int)$ws > 1439 || (int)$we < 0 || (int)$we > 1439
				|| (int)$ws >= (int)$we) {
				$fields['stops'] = 'invalid';
			}
			$eref = trim((string)($s['executor_ref'] ?? ''));
			if ($eref !== '' && !$this->acl->validRef($eref)) { $fields['stops'] = 'invalid'; }
		}
		if ($fields !== []) {
			throw ApiException::validation('Validation failed.', $fields);
		}
		return [
			'name' => $name,
			'weekdaysCsv' => implode(',', $weekdays),
			'status' => $status,
			'leadRef' => $leadRef !== '' ? $leadRef : null,
			'stops' => $stops,
		];
	}

	/** @param list<array<string, mixed>> $stops */
	private function insertTemplateStops(int $templateId, array $stops): void
	{
		foreach ($stops as $i => $s) {
			$ts = new TtStop();
			$ts->setTemplateId($templateId);
			$skind = in_array($s['kind'] ?? '', Constants::STOP_KINDS, true) ? $s['kind'] : 'client';
			$tkind = in_array($s['time_kind'] ?? '', Constants::TIME_KINDS, true) ? $s['time_kind'] : 'window';
			$ts->setKind($skind);
			$ts->setTimeKind($tkind);
			$ts->setClientId($skind === 'external' ? null : (int)$s['client_id']);
			$ts->setLocText($skind === 'external' ? mb_substr(trim((string)($s['loc_text'] ?? '')), 0, 190) : null);
			$ts->setWinStart((int)($s['win_start'] ?? 0));
			$ts->setWinEnd((int)($s['win_end'] ?? 0));
			$ts->setToleranceMin(isset($s['tolerance_min']) && is_numeric($s['tolerance_min']) ? max(0, (int)$s['tolerance_min']) : null);
			$ts->setOrderHint((int)($s['order_hint'] ?? $i));
			$eref = trim((string)($s['executor_ref'] ?? ''));
			$ts->setExecutorRef($eref !== '' ? $eref : null);
			$this->ttStops->insert($ts);
		}
	}

	#[NoAdminRequired]
	public function saveTourTemplate(): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$n = $this->templateParams($this->request->getParams());
			$t = new TourTemplate();
			$t->setName($n['name']);
			$t->setWeekdaySet($n['weekdaysCsv']);
			$t->setLeadRef($n['leadRef']);
			$t->setStatus($n['status']);
			$t->setRevision(1);
			$now = new \DateTime();
			$t->setCreatedAt($now);
			$t->setUpdatedAt($now);
			// template + all stops commit atomically — never a half-written tour
			$this->db->beginTransaction();
			try {
				$t = $this->templates->insert($t);
				$this->insertTemplateStops((int)$t->getId(), $n['stops']);
				$this->db->commit();
			} catch (\Throwable $th) {
				$this->db->rollBack();
				throw $th;
			}
			return $this->ok(['id' => (int)$t->getId()], 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function updateTourTemplate(int $id): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$n = $this->templateParams($this->request->getParams());
			$p = $this->request->getParams();
			// CAS: expected revision guards concurrent edits — fail closed on
			// missing/non-integer tokens, never silently merge (api-security §3).
			if (!isset($p['base_revision']) || !preg_match('/^\d+$/', (string)$p['base_revision'])) {
				throw ApiException::conflict('Reload before saving.');
			}
			$this->db->beginTransaction();
			try {
				// Serialize on the template row so concurrent edits queue on the
				// lock instead of racing the CAS check (lost-update class).
				$lock = $this->db->prepare('SELECT id FROM `*PREFIX*cck_tour_tpl` WHERE `id` = :id FOR UPDATE');
				$lock->execute(['id' => $id]);
				$t = $this->templates->find($id);
				if ($t === null) {
					throw ApiException::notFound();
				}
				if ((int)$t->getRevision() !== (int)$p['base_revision']) {
					throw ApiException::conflict('Template changed — reload.');
				}
				$t->setName($n['name']);
				$t->setWeekdaySet($n['weekdaysCsv']);
				$t->setLeadRef($n['leadRef']);
				$t->setStatus($n['status']);
				$t->setRevision((int)$t->getRevision() + 1);
				$t->setUpdatedAt(new \DateTime());
				$this->templates->update($t);
				// Replace stops atomically inside the same transaction — a
				// half-written stop set must never reach generate().
				$this->ttStops->deleteForTemplate($id);
				$this->insertTemplateStops($id, $n['stops']);
				$this->db->commit();
			} catch (\Throwable $th) {
				$this->db->rollBack();
				throw $th;
			}
			return $this->ok(['id' => $id, 'revision' => (int)$t->getRevision()]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function deleteTourTemplate(int $id): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$this->db->beginTransaction();
			try {
				$lock = $this->db->prepare('SELECT id FROM `*PREFIX*cck_tour_tpl` WHERE `id` = :id FOR UPDATE');
				$lock->execute(['id' => $id]);
				$t = $this->templates->find($id);
				if ($t === null) {
					throw ApiException::notFound();
				}
				// Generated day tours are materialized snapshots — they stay
				// valid; deleting only stops future generation.
				$this->ttStops->deleteForTemplate($id);
				$this->templates->delete($t);
				$this->db->commit();
			} catch (\Throwable $th) {
				$this->db->rollBack();
				throw $th;
			}
			return $this->ok(['id' => $id]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function generateTours(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$n = $this->tours->generate($this->settings->getInt('horizon_days'));
			return $this->ok(['generated_stops' => $n]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function assignStop(int $id): JSONResponse
	{
		try {
			$stop = $this->tours->assignStop($this->actor(), $id, (string)$this->request->getParam('executor_ref', ''));
			return $this->ok(['id' => (int)$stop->getId(), 'executorRef' => $stop->getExecutorRef()]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function listNotices(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$out = [];
			foreach ($this->notices->findAll() as $n) {
				$out[] = [
					'id' => (int)$n->getId(), 'clientId' => $n->getClientId(),
					'actorRef' => $n->getActorRef(), 'actorLabel' => $this->acl->refLabel($n->getActorRef()),
					'kind' => $n->getKind(), 'priority' => $n->getPriority(), 'state' => $n->getState(),
					'resolution' => in_array($n->getResolution(), ['acked', 'error'], true) ? $n->getResolution() : '', 'escalated' => (int)$n->getEscalated() === 1,
					'note' => $n->getNoteEnc() !== null ? $this->crypto->decrypt((string)$n->getNoteEnc()) : null,
					'createdAt' => $n->getCreatedAt()->format(\DateTimeInterface::ATOM),
				];
			}
			return $this->ok(['notices' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function ackNotice(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$n = $this->notices->find($id);
			if ($n === null) {
				throw ApiException::notFound();
			}
			if ($n->getState() === 'open' || $n->getState() === 'escalated') {
				$n->setState('acked');
				$n->setResolution('acked');
				$n->setAckedBy($actor);
				$n->setAckedAt(new \DateTime());
				$this->notices->update($n);
			}
			return $this->ok(['id' => $id, 'state' => $n->getState()]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function listMembers(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$out = [];
			foreach ($this->members->findAll() as $m) {
				$out[] = ['id' => (int)$m->getId(), 'displayName' => $m->getDisplayName(), 'qualification' => $m->getQualification(), 'active' => (int)$m->getActive() === 1, 'teamId' => $m->getTeamId() !== null ? (int)$m->getTeamId() : null];
			}
			return $this->ok(['members' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function saveMember(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$p = $this->request->getParams();
			$name = trim((string)($p['display_name'] ?? ''));
			$qual = (string)($p['qualification'] ?? Constants::QUAL_HILF);
			$fields = [];
			if ($name === '') { $fields['display_name'] = 'required'; }
			if (!in_array($qual, Constants::QUALS, true)) { $fields['qualification'] = 'invalid'; }
			if ($fields !== []) {
				throw ApiException::validation('Validation failed.', $fields);
			}
			$m = new Member();
			$m->setDisplayName($name);
			$m->setQualification($qual);
			$m->setActive(1);
			$m->setCreatedAt(new \DateTime());
			$m = $this->members->insert($m);
			return $this->ok(['id' => (int)$m->getId()], 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function updateMember(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$p = $this->request->getParams();
			$name = trim((string)($p['display_name'] ?? ''));
			$qual = (string)($p['qualification'] ?? Constants::QUAL_HILF);
			$fields = [];
			if ($name === '') { $fields['display_name'] = 'required'; }
			if (!in_array($qual, Constants::QUALS, true)) { $fields['qualification'] = 'invalid'; }
			if ($fields !== []) {
				throw ApiException::validation('Validation failed.', $fields);
			}
			$m = $this->members->find($id);
			if ($m === null || (int)$m->getActive() !== 1) {
				throw ApiException::notFound();
			}
			$m->setDisplayName($name);
			$m->setQualification($qual);
			$this->members->update($m);
			return $this->ok(['id' => (int)$m->getId()]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function deactivateMember(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$m = $this->members->find($id);
			if ($m === null) {
				throw ApiException::notFound();
			}
			$m->setActive(0);
			$this->members->update($m);
			// cascade: today/future stops of member:{id} → uncovered queue,
			// deactivation can never silently orphan clients (spec edge rule)
			$ref = 'member:' . $id;
			$qb = $this->db->getQueryBuilder();
			$qb->update('cck_tstop')
				->set('status', $qb->createNamedParameter('uncovered'))
				->set('executor_ref', $qb->createNamedParameter(null))
				->where($qb->expr()->eq('executor_ref', $qb->createNamedParameter($ref)))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'in_progress'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere('tour_id IN (SELECT id FROM `*PREFIX*cck_tour` WHERE `date` >= :today)')
				->setParameter('today', $this->tours->today());
			$qb->executeStatement();
			// their devices lose access on the next call and seats free for
			// re-licensing (US-209 AC-2) — deactivation must never leave a
			// live token bound to a dead identity.
			$this->pairs->revokeByMember($id);
			return $this->ok(['id' => $id, 'active' => false]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function createPairCode(int $id): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			return $this->ok($this->pairs->issueCode($id), 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function revokeDevice(int $id): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$this->pairs->revokeDevice($id);
			return $this->ok(['id' => $id, 'revoked' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function getSettings(): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$out = [];
			foreach (SettingsService::DEFAULTS as $k => $_) {
				$out[$k] = $this->settings->get($k);
			}
			foreach (SettingsService::LIST_KEYS as $k) {
				$items = $this->settings->getList($k);
				$out[$k] = $items;
				// Display labels ride along — raw uids/gids must never be
				// the visible text of a chip.
				$out[$k . '_labels'] = array_map(fn (string $v) => $this->listEntryLabel($k, $v), $items);
			}
			$out['is_app_admin'] = $this->acl->isAppAdmin() ? '1' : '0';
			return $this->ok(['settings' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/** Display label for an access-list entry (plain uid or gid). */
	private function listEntryLabel(string $key, string $id): string
	{
		if ($key === 'allowed_groups') {
			return ($this->groups->get($id)?->getDisplayName()) ?: $id;
		}
		return ($this->users->get($id)?->getDisplayName()) ?: $id;
	}

	#[NoAdminRequired]
	public function saveSettings(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$fields = [];
			$validated = [];
			$lists = [];
			$accessKeys = ['access_mode', ...SettingsService::LIST_KEYS];
			foreach ([...array_keys(SettingsService::DEFAULTS), ...SettingsService::LIST_KEYS] as $k) {
				$v = $this->request->getParam($k);
				if ($v === null) {
					continue;
				}
				// The access door is a security boundary — only app admins may
				// open/restrict the app or grant delegated administration.
				if (in_array($k, $accessKeys, true) && !$this->acl->isAppAdmin()) {
					$fields[$k] = 'invalid';
					continue;
				}
				$raw = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
				if (in_array($k, SettingsService::LIST_KEYS, true)) {
					$items = is_array($v) ? $v : (json_decode($raw, true) ?? null);
					if (!is_array($items)) {
						$fields[$k] = 'invalid';
						continue;
					}
					// Ghost-proof the lists: every uid/group must still exist.
					// Pickers submit typed refs (uid:x / group:g) — normalize to
					// the plain ids these lists store, else every UI save fails.
					$bad = false;
					$norm = [];
					foreach ($items as $item) {
						if (!is_string($item)) {
							$bad = true;
							break;
						}
						$item = preg_replace('/^(uid|group):/', '', $item) ?? '';
						if ($item === '' || str_contains($item, ':')) {
							$bad = true;
							break;
						}
						$exists = $k === 'allowed_groups'
							? $this->groups->groupExists($item)
							: $this->users->userExists($item);
						if (!$exists) {
							$bad = true;
							break;
						}
						$norm[] = $item;
					}
					if ($bad) {
						$fields[$k] = 'invalid';
					} else {
						$lists[$k] = $norm;
					}
					continue;
				}
				if (str_starts_with($k, 'preset_')) {
					// "HHMM-HHMM" minute ranges — validated so a typo can't
					// silently break the dispatcher's Tageszeit presets.
					if (preg_match('/^\d{1,4}-\d{1,4}$/', $raw) === 1) {
						$validated[$k] = $raw;
					} else {
						$fields[$k] = 'invalid';
					}
					continue;
				}
				if ($k === 'provider_tz') {
					if (in_array($raw, \DateTimeZone::listIdentifiers(), true)) {
						$validated[$k] = $raw;
					} else {
						$fields[$k] = 'invalid';
					}
					continue;
				}
				if ($k === 'access_mode') {
					if (in_array($raw, ['open', 'restricted'], true)) {
						$validated[$k] = $raw;
					} else {
						$fields[$k] = 'invalid';
					}
					continue;
				}
				// Fail closed on type mismatch — silent coercion hides typos
				// ("true" → 0 = OFF is a real bug class, see form-bool rule).
				if ($k === 'gps_enabled') {
					$low = strtolower($raw);
					if (in_array($low, ['1', '0', 'true', 'false', 'on', 'off'], true)) {
						$validated[$k] = in_array($low, ['1', 'true', 'on'], true) ? '1' : '0';
					} else {
						$fields[$k] = 'invalid';
					}
				} elseif (is_int(SettingsService::DEFAULTS[$k])) {
					if (preg_match('/^\d+$/', $raw)) {
						$validated[$k] = $raw;
					} else {
						$fields[$k] = 'invalid';
					}
				} elseif ($k === 'min_companion_version') {
					if (preg_match('/^\d+\.\d+\.\d+$/', $raw)) {
						$validated[$k] = $raw;
					} else {
						$fields[$k] = 'invalid';
					}
				} else {
					$validated[$k] = $raw;
				}
			}
			if ($fields !== []) {
				throw ApiException::validation('Validation failed.', $fields);
			}
			foreach ($validated as $k => $v) {
				$this->settings->set($k, $v);
			}
			foreach ($lists as $k => $v) {
				$this->settings->setList($k, $v);
			}
			// Policy mutations get an audit record (api-security §7) — the
			// access door and admin delegation are security boundaries. Keys
			// only in detail: values may carry uids/gids.
			if ($validated !== [] || $lists !== []) {
				$this->acl->audit($actor, 'settings_save', null,
					implode(',', [...array_keys($validated), ...array_keys($lists)]));
			}
			return $this->getSettings();
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * F15: PrivacyCheck VVT proposal state for the settings surface.
	 * Staff may read the state (it reveals nothing beyond "proposed: yes/no");
	 * minting the draft is admin-gated below.
	 */
	#[NoAdminRequired]
	public function privacyProposalState(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			return $this->ok(['proposal' => $this->privacyProposals->state()]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * F15: create the draft register entry (+ seeded DPIA) in PrivacyCheck.
	 * Admin-only like the access door — a VVT entry is a provider-level
	 * compliance artifact. Idempotent: the stored activity id makes repeat
	 * clicks return the existing draft instead of minting another.
	 */
	#[NoAdminRequired]
	public function proposePrivacyActivity(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			if (!$this->acl->isAppAdmin()) {
				throw ApiException::notFound();
			}
			$res = $this->privacyProposals->propose($actor);
			$this->acl->audit($actor, 'privacy_proposal', null,
				$res['ok'] ? 'ok' : ('failed:' . ($res['code'] ?? 'unknown')));
			return $this->ok(['proposal' => array_merge(
				$this->privacyProposals->state(),
				['ok' => $res['ok'], 'already' => $res['already'] ?? false,
				 'code' => $res['code'] ?? null, 'message' => $res['message'] ?? null]
			)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * Rejected-review resolution (spec UX-13): a Zentrale reviewer marks a
	 * quarantined bundle as corrected. The status flip and the correcting
	 * journal entry commit atomically — the journal proves the human decision
	 * and re-hashes the chain head.
	 */
	#[NoAdminRequired]
	public function resolveRejected(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$v = $this->visitMapper->find($id);
			if ($v === null) {
				throw ApiException::notFound();
			}
			if ($v->getStatus() !== 'rejected_review') {
				throw ApiException::conflict('Visit is not in review.');
			}
			$resolution = trim((string)$this->request->getParam('resolution', ''));
			$now = new \DateTime();
			$this->db->beginTransaction();
			try {
				$v->setStatus('done_corrected');
				$v->setUpdatedAt($now);
				$this->visitMapper->update($v);
				$this->journal->append((int)$v->getClientId(), 'visit_corrected', $actor, [
					'visit' => $id,
					'reject_reason' => $v->getRejectReason(),
					'resolution' => $resolution !== '' ? mb_substr($resolution, 0, 190) : null,
				]);
				$this->db->commit();
			} catch (\Throwable $t) {
				$this->db->rollBack();
				throw $t;
			}
			return $this->ok(['id' => $id, 'state' => 'done_corrected']);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- Client detail & update (US-201, Bezugspflege) ----

	#[NoAdminRequired]
	public function getClient(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$this->acl->audit($actor, 'read', $id);
			$c = $this->clientMapper->find($id);
			if ($c === null || (int)$c->getActive() === 0) {
				throw ApiException::notFound();
			}
			$h = $this->clients->hydrated($c);
			// Chips must show names, never raw member:/uid: wire refs.
			$h['primaryRefLabel'] = $this->acl->refLabel($h['primaryRef']);
			$h['subRefLabel'] = $this->acl->refLabel($h['subRef']);
			// F14: resolved soft link — name:null with a stored id renders a
			// muted "no longer available" (company archived, CUC offline, or
			// actor lost access); the relation is never silently dropped.
			$h['crm'] = $this->crmLinks->linkInfo($actor, $h['crmCompanyId']);
			$h['crm']['canEdit'] = $this->acl->isAppAdmin();
			// published plan rides along — the editor needs revision for CAS
			// and the current task selection to precheck.
			$plan = null;
			foreach ($this->plans->findAll() as $pv) {
				if ((int)$pv->getClientId() === $id && $pv->getStatus() === 'published') {
					$plan = $pv;
				}
			}
			if ($plan !== null) {
				$snap = json_decode($this->crypto->decrypt((string)$plan->getSnapshotEnc()), true);
				$h['plan'] = ['revision' => (int)$plan->getRevision(),
					'tasks' => is_array($snap) && isset($snap['tasks']) ? $snap['tasks'] : []];
			}
			return $this->ok(['client' => $h]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function updateClient(int $id): JSONResponse
	{
		try {
			$p = $this->request->getParams();
			$rev = isset($p['base_revision']) && preg_match('/^\d+$/', (string)$p['base_revision']) ? (int)$p['base_revision'] : null;
			$c = $this->clients->update($this->actor(), $id, $p, $rev);
			return $this->ok(['id' => $id, 'revision' => (int)$c->getRevision()]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- NH-04 photos — consent-gated, staff-managed ----

	#[NoAdminRequired]
	public function clientPhotos(int $id): JSONResponse
	{
		try {
			return $this->ok(['photos' => $this->photos->list($this->actor(), $id)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function addClientPhoto(int $id): JSONResponse
	{
		try {
			$p = $this->request->getParams();
			$data = base64_decode((string)($p['data_b64'] ?? ''), true);
			if ($data === false) {
				throw ApiException::validation('Validation failed.', ['photo' => 'invalid']);
			}
			return $this->ok(['photo' => $this->photos->store(
				$this->actor(), $id,
				isset($p['visit_id']) && $p['visit_id'] !== '' ? (int)$p['visit_id'] : null,
				(string)($p['mime'] ?? ''),
				$data,
			)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function clientPhotoFile(int $id, int $photoId): JSONResponse|DataDownloadResponse
	{
		try {
			$f = $this->photos->fileFor($this->actor(), $id, $photoId);
			return new DataDownloadResponse($f['bytes'], 'photo-' . $photoId . '.bin', $f['mime']);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function deleteClientPhoto(int $id, int $photoId): JSONResponse
	{
		try {
			$this->photos->remove($this->actor(), $id, $photoId);
			return $this->ok(['deleted' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- NH-01 voice notes — transcripts read surface ----

	#[NoAdminRequired]
	public function clientVoiceNotes(int $id): JSONResponse
	{
		try {
			return $this->ok(['voicenotes' => $this->voiceNotes->list($this->actor(), $id)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- NH-11 teams (Zentrale-managed) ----

	#[NoAdminRequired]
	public function listTeams(): JSONResponse
	{
		try {
			return $this->ok(['teams' => $this->teams->list($this->actor())]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function createTeam(): JSONResponse
	{
		try {
			return $this->ok(['team' => $this->teams->create($this->actor(), (string)$this->request->getParam('name', ''))]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function setTeamLead(int $id): JSONResponse
	{
		try {
			$this->teams->assignLead($this->actor(), $id, (string)$this->request->getParam('uid', ''));
			return $this->ok(['assigned' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function removeTeamLead(int $id, string $uid): JSONResponse
	{
		try {
			$this->teams->removeLead($this->actor(), $id, $uid);
			return $this->ok(['removed' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function setClientTeam(int $id): JSONResponse
	{
		try {
			$t = $this->request->getParam('team_id');
			$this->teams->assignClient($this->actor(), $id, $t === null || $t === '' ? null : (int)$t);
			return $this->ok(['assigned' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * F14: picker source — CustomerCheck companies for the client→CRM
	 * soft link. Staff-readable; `available:false` when the sibling is
	 * absent so the UI can render the muted state instead of an error.
	 */
	#[NoAdminRequired]
	public function crmCompanies(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			// Admin-only like setClientCrmLink — the picker exists solely to
			// serve the link action, and the whole book stays unenumerable.
			if (!$this->acl->isAppAdmin()) {
				throw ApiException::notFound();
			}
			$q = $this->request->getParam('q');
			return $this->ok($this->crmLinks->companies($actor, is_string($q) ? $q : null));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * F14: set/clear the client's CRM soft link. Admin-only like the access
	 * door — the link is a provider-level billing relation, and setting it
	 * wrong silently routes F13 drafts to the wrong company.
	 */
	#[NoAdminRequired]
	public function setClientCrmLink(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			if (!$this->acl->isAppAdmin()) {
				throw ApiException::notFound();
			}
			$raw = $this->request->getParam('crm_company_id');
			$companyId = null;
			if ($raw !== null && $raw !== '') {
				// strict int ids — "2.9"/"2e3" must not truncate silently
				if (!is_int($raw) && !is_string($raw) || (is_string($raw) && preg_match('/^\d+$/', $raw) !== 1)) {
					throw ApiException::validation('Validation failed.', ['crm_company_id' => 'invalid']);
				}
				$companyId = (int)$raw;
			}
			$this->crmLinks->setLink($actor, $id, $companyId);
			return $this->ok(['assigned' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * F13 push — send this client's signed-evidence billing draft to
	 * InvoiceCheck. Staff + client scope; the sibling decides the rest.
	 * Coded errors are user-actionable (link the client first, no signed
	 * visits, …) — never an existence oracle beyond the client id itself.
	 */
	#[NoAdminRequired]
	public function pushInvoiceDraft(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$this->acl->assertClientScope($actor, $id);
			$period = (string)$this->request->getParam('period', '');
			$kind = (string)$this->request->getParam('kind', 'haeusliche_betreuung');
			$r = $this->invoiceDraftPush->push($actor, $id, $period, $kind);
			if (!$r['ok']) {
				// Domain-state rejections are 409 (user-actionable), not 502 —
				// reserving 5xx for genuine upstream failure keeps monitoring
				// and client retry semantics honest.
				$map = [
					'bad_request' => [400, 'VALIDATION_FAILED'],
					'not_linked' => [409, 'NOT_LINKED'],
					'no_pc_customer' => [409, 'NO_PC_CUSTOMER'],
					'no_visits' => [409, 'NO_VISITS'],
					'sibling_unavailable' => [502, 'SIBLING_UNAVAILABLE'],
					'sibling_error' => [502, 'SIBLING_ERROR'],
				];
				$messages = [
					'bad_request' => 'Validation failed.',
					'not_linked' => 'Link a CustomerCheck company to this client first.',
					'no_pc_customer' => 'The linked company has no billing customer.',
					'no_visits' => 'No signed visits in this period.',
					'sibling_unavailable' => 'InvoiceCheck is not enabled.',
					'sibling_error' => 'InvoiceCheck rejected the draft.',
				];
				$code = (string)$r['code'];
				if ($code === 'not_found') {
					// Byte-identical to the authz-deny envelope — a missing or
					// inactive client must not be distinguishable from a
					// client the caller may not see.
					return new JSONResponse(Constants::notFoundPayload(), 404);
				}
				[$status, $wire] = $map[$code] ?? [502, 'SIBLING_ERROR'];
				return new JSONResponse([
					'success' => false,
					'error' => $wire,
					'message' => $messages[$code] ?? $messages['sibling_error'],
					'fields' => $code === 'bad_request' ? ['period' => 'invalid'] : new \stdClass(),
				], $status);
			}
			return $this->ok($r);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function setMemberTeam(int $id): JSONResponse
	{
		try {
			$t = $this->request->getParam('team_id');
			$this->teams->assignMember($this->actor(), $id, $t === null || $t === '' ? null : (int)$t);
			return $this->ok(['assigned' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- NH-12 group sessions (web: open + Teilnehmerliste export) ----

	#[NoAdminRequired]
	public function openGroupSession(): JSONResponse
	{
		try {
			// authz BEFORE payload validation — a role-less caller must get the
			// uniform 404, never a 400 that proves the endpoint parses input.
			$this->acl->assertStaff($this->actor());
			$p = $this->request->getParams();
			$ids = $p['client_ids'] ?? [];
			if (!is_array($ids)) {
				throw ApiException::validation('Validation failed.', ['client_ids' => 'invalid']);
			}
			$clientIds = [];
			foreach ($ids as $cid) {
				if (!is_int($cid) && !(is_string($cid) && preg_match('/^\d+$/', $cid) === 1)) {
					throw ApiException::validation('Validation failed.', ['client_ids' => 'invalid']);
				}
				$clientIds[] = (int)$cid;
			}
			$idm = (string)($p['idem_key'] ?? '');
			if ($idm === '') {
				$idm = bin2hex(random_bytes(16)); // web flow mints one
			}
			return $this->ok(['session' => $this->groupSessions->open($this->actor(), (string)($p['title'] ?? ''), $clientIds, $idm)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function groupSession(int $id): JSONResponse
	{
		try {
			return $this->ok(['session' => $this->groupSessions->list($this->actor(), $id)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function setGroupAttended(int $id): JSONResponse
	{
		try {
			$p = $this->request->getParams();
			$this->groupSessions->setAttended($this->actor(), $id, (int)($p['client_id'] ?? 0),
				($p['attended'] ?? '1') === '1' || ($p['attended'] ?? null) === true);
			return $this->ok(['updated' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function closeGroupSession(int $id): JSONResponse
	{
		try {
			$this->groupSessions->close($this->actor(), $id);
			return $this->ok(['closed' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function groupSessionCsv(int $id): JSONResponse|DataDownloadResponse
	{
		try {
			$r = $this->groupSessions->exportCsv($this->actor(), $id);
			return new DataDownloadResponse($r['content'], $r['filename'], $r['mime']);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- Tour templates for the editor ----

	#[NoAdminRequired]
	public function listTemplates(): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$out = [];
			foreach ($this->templates->findAll() as $t) {
				$stops = [];
				foreach ($this->ttStops->findAll() as $s) {
					if ((int)$s->getTemplateId() !== (int)$t->getId()) {
						continue;
					}
					$stops[] = ['clientId' => $s->getClientId() !== null ? (int)$s->getClientId() : null,
						'kind' => $s->getKind(), 'timeKind' => $s->getTimeKind(),
						'winStart' => (int)$s->getWinStart(), 'winEnd' => (int)$s->getWinEnd(),
						'toleranceMin' => $s->getToleranceMin(), 'locText' => $s->getLocText(),
						'executorRef' => $s->getExecutorRef(), 'orderHint' => (int)$s->getOrderHint()];
				}
				usort($stops, fn ($a, $b) => $a['orderHint'] <=> $b['orderHint']);
				$out[] = ['id' => (int)$t->getId(), 'name' => $t->getName(), 'weekdaySet' => $t->getWeekdaySet(),
					'leadRef' => $t->getLeadRef(), 'leadRefLabel' => $this->acl->refLabel($t->getLeadRef()),
					'status' => $t->getStatus(), 'revision' => (int)$t->getRevision(), 'stops' => $stops];
			}
			return $this->ok(['templates' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- Übungstour: server-owned practice client + stop (MH-15) ----

	#[NoAdminRequired]
	public function createPracticeTour(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$memberId = (string)$this->request->getParam('member_id', '');
			if (!preg_match('/^\d+$/', $memberId)) {
				throw ApiException::validation('Validation failed.', ['member_id' => 'invalid']);
			}
			$m = $this->members->find((int)$memberId);
			if ($m === null || (int)$m->getActive() !== 1) {
				throw ApiException::notFound();
			}
			return $this->ok($this->tours->createPracticeTour($actor, 'member:' . $memberId), 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- Absences (MH-03c) ----

	#[NoAdminRequired]
	public function listAbsences(): JSONResponse
	{
		try {
			return $this->ok(['absences' => $this->absences->list($this->actor())]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function markAbsence(): JSONResponse
	{
		try {
			$p = $this->request->getParams();
			return $this->ok($this->absences->mark(
				$this->actor(),
				(string)($p['staff_ref'] ?? ''),
				(string)($p['from'] ?? ''),
				(string)($p['until'] ?? ''),
				(string)($p['kind'] ?? 'sick')
			), 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function cancelAbsence(int $id): JSONResponse
	{
		try {
			$this->absences->cancel($this->actor(), $id);
			return $this->ok(['id' => $id, 'cancelled' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- Task catalog (US-201 AC-1, §7 plan editor) ----

	#[NoAdminRequired]
	public function listCatalog(): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$out = [];
			foreach ($this->catalog->findAll() as $t) {
				$out[] = ['id' => (int)$t->getId(), 'code' => $t->getCode(), 'label' => $t->getLabel(),
					'icon' => $t->getIcon(), 'kind' => $t->getKind(), 'requiresQual' => $t->getRequiresQual()];
			}
			return $this->ok(['tasks' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function saveCatalogTask(): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$p = $this->request->getParams();
			$code = trim((string)($p['code'] ?? ''));
			$label = trim((string)($p['label'] ?? ''));
			$fields = [];
			if ($code === '' || !preg_match('/^[A-Za-z0-9_.\-]{1,48}$/', $code)) { $fields['code'] = 'invalid'; }
			if ($label === '') { $fields['label'] = 'required'; }
			$rq = $p['requires_qual'] ?? null;
			if ($rq !== null && $rq !== '' && !in_array($rq, Constants::QUALS, true)) { $fields['requires_qual'] = 'invalid'; }
			if ($fields !== []) {
				throw ApiException::validation('Validation failed.', $fields);
			}
			foreach ($this->catalog->findAll() as $t) {
				if ($t->getCode() === $code) {
					throw ApiException::validation('Validation failed.', ['code' => 'taken']);
				}
			}
			$t = new \OCA\CareCheck\Db\TaskCatalog();
			$t->setCode($code);
			$t->setLabel(mb_substr($label, 0, 190));
			$t->setIcon(mb_substr((string)($p['icon'] ?? ''), 0, 48) ?: null);
			$t->setKind('fixed');
			$t->setRequiresQual($rq !== '' ? $rq : null);
			$t = $this->catalog->insert($t);
			return $this->ok(['id' => (int)$t->getId()], 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function updateCatalogTask(int $id): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$p = $this->request->getParams();
			$t = $this->catalog->find($id);
			if ($t === null) {
				throw ApiException::notFound();
			}
			$label = trim((string)($p['label'] ?? ''));
			$fields = [];
			if ($label === '') { $fields['label'] = 'required'; }
			$rq = $p['requires_qual'] ?? null;
			if ($rq !== null && $rq !== '' && !in_array($rq, Constants::QUALS, true)) { $fields['requires_qual'] = 'invalid'; }
			if ($fields !== []) {
				throw ApiException::validation('Validation failed.', $fields);
			}
			// code is immutable — published plans reference it (no FK, §7).
			$t->setLabel(mb_substr($label, 0, 190));
			$t->setIcon(mb_substr((string)($p['icon'] ?? ''), 0, 48) ?: null);
			$t->setRequiresQual($rq !== '' ? $rq : null);
			$this->catalog->update($t);
			return $this->ok(['id' => (int)$t->getId()]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- Client journal (US-205) ----

	#[NoAdminRequired]
	public function clientJournal(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$client = $this->clients->getForActor($actor, $id);
			if ($client === null) {
				throw ApiException::notFound();
			}
			$this->acl->audit($actor, 'journal_view', $id);
			$out = [];
			foreach ($this->journal->entriesFor($id) as $e) {
				$payload = $this->journal->payloadOf($e);
				$out[] = ['seq' => (int)$e->getSeq(), 'type' => $e->getType(), 'actorRef' => $e->getActorRef(),
					'actorLabel' => $this->acl->refLabel($e->getActorRef()),
					'text' => $payload['text'] ?? null,
					'ts' => $e->getTs()->format(\DateTimeInterface::ATOM)];
			}
			return $this->ok(['entries' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function addJournalEntry(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$this->clients->getForActor($actor, $id);
			$text = trim((string)$this->request->getParam('text', ''));
			if ($text === '') {
				throw ApiException::validation('Validation failed.', ['text' => 'required']);
			}
			$this->db->beginTransaction();
			try {
				// author identity is mandatory — append binds the actor, never anonymous (NN-08)
				$this->journal->append($id, 'manual_note', $actor, ['text' => mb_substr($text, 0, 4000)]);
				$this->db->commit();
			} catch (\Throwable $t) {
				$this->db->rollBack();
				throw $t;
			}
			return $this->ok(['id' => $id], 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- Exports (US-207) — DataDownloadResponse, never user-Files ----

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportMonthlyCsv(int $id, string $period = ''): JSONResponse|DataDownloadResponse
	{
		try {
			$r = $this->exporter->monthlyCsv($this->actor(), $id, $period !== '' ? $period : date('Y-m'));
			return new DataDownloadResponse($r['content'], $r['filename'], $r['mime']);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportBillingStructured(int $id, string $period = '', string $fmt = 'json'): JSONResponse|DataDownloadResponse
	{
		try {
			$r = $this->exporter->structuredBilling($this->actor(), $id, $period !== '' ? $period : date('Y-m'), $fmt);
			return new DataDownloadResponse($r['content'], $r['filename'], $r['mime']);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportMonthlyPdf(int $id, string $period = ''): JSONResponse|DataDownloadResponse
	{
		try {
			$r = $this->exporter->monthlyPdf($this->actor(), $id, $period !== '' ? $period : date('Y-m'));
			return new DataDownloadResponse($r['content'], $r['filename'], $r['mime']);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportVisitPdf(int $id): JSONResponse|DataDownloadResponse
	{
		try {
			$r = $this->exporter->visitPdf($this->actor(), $id);
			return new DataDownloadResponse($r['content'], $r['filename'], $r['mime']);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * DS-4 directory: combined uid+member search for staff pickers —
	 * typed refs out, never raw ID typing on the client.
	 */
	#[NoAdminRequired]
	public function directory(string $q = '', string $type = 'staff'): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			$q = trim($q);
			$out = [];
			if ($type === 'group') {
				// Access-door allow lists pick real NC groups.
				foreach ($this->groups->search($q, 25) as $g) {
					$out[] = ['ref' => 'group:' . $g->getGID(), 'label' => $g->getDisplayName() ?: $g->getGID(), 'kind' => 'group'];
				}
				return $this->ok(['results' => $out]);
			}
			// Members (paired-device staff) — name substring match, active only.
			foreach ($this->members->findAll() as $m) {
				if ((int)$m->getActive() !== 1) {
					continue;
				}
				if ($q !== '' && mb_stripos((string)$m->getDisplayName(), $q) === false) {
					continue;
				}
				$out[] = ['ref' => 'member:' . (int)$m->getId(), 'label' => (string)$m->getDisplayName(), 'kind' => 'member'];
				if (count($out) >= 25) {
					return $this->ok(['results' => $out]);
				}
			}
			foreach ($this->users->searchDisplayName($q, 25) as $u) {
				$out[] = ['ref' => 'uid:' . $u->getUID(), 'label' => $u->getDisplayName() ?: $u->getUID(), 'kind' => 'uid'];
				if (count($out) >= 25) {
					break;
				}
			}
			return $this->ok(['results' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-08 / suite flange F11: versioned drive-segments read facade.
	 * Tour-bound legs only (check-out N → check-in N+1), allow-listed fields,
	 * generic purpose — MobilityCheck turns them into confirmable drafts.
	 */
	#[NoAdminRequired]
	public function driveSegments(string $from = '', string $to = ''): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			return $this->ok(['version' => 1, 'segments' => $this->driveSegments->segments($from, $to !== '' ? $to : $from)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-13 / F19 — trigger an AZC absence sync for a range (staff).
	 * Status-only: AZC type/reason never cross. Idempotent by design.
	 */
	#[NoAdminRequired]
	public function syncAzcAbsences(string $from = '', string $to = ''): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
				$from = (new \DateTime('today', new \DateTimeZone($this->tours->providerTz())))->format('Y-m-d');
			}
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
				$to = (new \DateTime('+30 days', new \DateTimeZone($this->tours->providerTz())))->format('Y-m-d');
			}
			if ($from > $to) {
				throw ApiException::validation('Validation failed.', ['to' => 'invalid']);
			}
			return $this->ok($this->azcAbsences->import($from, $to));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-02 / F12 — DutyCheck on-duty roster for a date (staff, read-only).
	 * Published periods only; absent DutyCheck → available=false, no error.
	 */
	#[NoAdminRequired]
	public function dutyRoster(string $date = ''): JSONResponse
	{
		try {
			$this->acl->assertDispatch($this->actor());
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
				$date = (new \DateTime('today', new \DateTimeZone($this->tours->providerTz())))->format('Y-m-d');
			}
			$actorRef = $this->actor();
			$actorUid = str_starts_with($actorRef, 'uid:') ? substr($actorRef, 4) : null;
			return $this->ok(['date' => $date] + $this->dutyRoster->onDuty($date, $actorUid));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-03 / F19 — `check:work-log` pull facade (staff). Allow-listed
	 * fields: actor + timestamps + visit ref, never client data.
	 */
	#[NoAdminRequired]
	public function workLog(string $from = '', string $to = ''): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			return $this->ok(['version' => 1, 'entries' => $this->workLog->segments($from, $to !== '' ? $to : $from)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-21 / F13 — `check:billing-drafts` pull facade (staff). Generic
	 * allow-listed line text only — InvoiceCheck maps external_ref to its
	 * own customer and never auto-finalizes.
	 */
	#[NoAdminRequired]
	public function billingDrafts(string $from = '', string $to = '', string $kind = 'haeusliche_betreuung'): JSONResponse
	{
		try {
			$this->acl->assertStaff($this->actor());
			return $this->ok(['version' => 1, 'drafts' => $this->billingDrafts->drafts($from, $to !== '' ? $to : $from, $kind)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-17 — QM dashboard: incidents per client, overdue rate, unsigned
	 * rate, review-queue depth. Staff-only; computed on existing rows.
	 */
	#[NoAdminRequired]
	public function qmSummary(string $from = '', string $to = ''): JSONResponse
	{
		try {
			$tz = new \DateTimeZone($this->tours->providerTz());
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
				$from = (new \DateTime('first day of this month', $tz))->format('Y-m-d');
			}
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
				$to = (new \DateTime('today', $tz))->format('Y-m-d');
			}
			return $this->ok($this->qm->summary($this->actor(), $from, $to));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-18 — vital-value trend series for one client. Scope-checked like
	 * every client route; numeric values only, never raw payloads.
	 */
	#[NoAdminRequired]
	public function clientVitals(int $id, string $task = ''): JSONResponse
	{
		try {
			return $this->ok(['clientId' => $id, 'series' => $this->qm->vitals($this->actor(), $id, $task !== '' ? $task : null)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-20 — CSV import: preview parses + marks dupes without writing;
	 * commit re-parses server-side (preview output is never trusted).
	 */
	#[NoAdminRequired]
	public function previewClientImport(): JSONResponse
	{
		try {
			$csv = (string)($this->request->getParam('csv', ''));
			return $this->ok($this->clientImport->preview($this->actor(), $csv));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function commitClientImport(): JSONResponse
	{
		try {
			$csv = (string)($this->request->getParam('csv', ''));
			return $this->ok($this->clientImport->commit($this->actor(), $csv));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-16 — on-prem AI digest of a client's recent notes → journal.
	 * Staff-scoped; 503 when no TaskProcessing backend is configured.
	 */
	#[NoAdminRequired]
	public function clientDigest(int $id, int $days = 7): JSONResponse
	{
		try {
			return $this->ok(['digest' => $this->digest->digest($this->actor(), $id, $days)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-14 — mint a fresh presence token for QR print (staff). Returns
	 * the QR payload ONCE; only the hash persists. Rotation = remint.
	 */
	#[NoAdminRequired]
	public function mintPresence(int $id): JSONResponse
	{
		try {
			return $this->ok($this->presence->mint($this->actor(), $id));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-05 — Angehörigen share links. Staff manages; the public view is
	 * a minimal projection (never health data).
	 */
	#[NoAdminRequired]
	public function listShares(int $id): JSONResponse
	{
		try {
			return $this->ok(['shares' => $this->shares->list($this->actor(), $id)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function createShare(int $id): JSONResponse
	{
		try {
			$p = $this->request->getParams();
			return $this->ok($this->shares->create(
				$this->actor(),
				$id,
				isset($p['label']) ? (string)$p['label'] : null,
				isset($p['expires_at']) && $p['expires_at'] !== '' ? (string)$p['expires_at'] : null,
			));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function revokeShare(int $id, int $shareId): JSONResponse
	{
		try {
			$this->shares->revoke($this->actor(), $id, $shareId);
			return $this->ok(['revoked' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-09 — register a station/kiosk tablet (staff session). Consumes
	 * the `stations` license entitlement; sign-off scope only.
	 */
	#[NoAdminRequired]
	public function registerStation(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$p = $this->request->getParams();
			return $this->ok($this->pairs->registerStationDevice($actor, isset($p['device_label']) ? (string)$p['device_label'] : null));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-19 — client document links (staff-managed, scope-checked read).
	 */
	#[NoAdminRequired]
	public function listClientDocs(int $id): JSONResponse
	{
		try {
			return $this->ok(['documents' => $this->clientDocs->list($this->actor(), $id)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function addClientDoc(int $id): JSONResponse
	{
		try {
			$p = $this->request->getParams();
			return $this->ok($this->clientDocs->add(
				$this->actor(),
				$id,
				(string)($p['label'] ?? ''),
				(string)($p['file'] ?? ''),
				isset($p['expires_on']) && $p['expires_on'] !== '' ? (string)$p['expires_on'] : null,
			));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function removeClientDoc(int $id, int $docId): JSONResponse
	{
		try {
			$this->clientDocs->remove($this->actor(), $id, $docId);
			return $this->ok(['removed' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * Review queue (spec UX-13): visits whose bundles failed validation sit in
	 * `rejected_review` — the Zentrale sees them here, never lost. Minimal
	 * projection: id, client name, actor, reason, ts — the full record stays
	 * in the client journal (per-client view) so the queue can't become a
	 * bulk-export backdoor.
	 */
	#[NoAdminRequired]
	public function listReviewQueue(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$out = [];
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from('cck_visit')
				->where($qb->expr()->eq('status', $qb->createNamedParameter('rejected_review')))
				->orderBy('updated_at', 'DESC');
			foreach ($qb->executeQuery()->iterateAssociative() as $row) {
				$cid = (int)$row['client_id'];
				$name = '';
				$c = $this->clientMapper->find($cid);
				if ($c !== null) {
					$h = $this->clients->hydrated($c);
					$name = (string)($h['payload']['name'] ?? '');
				}
				$out[] = [
					'id' => (int)$row['id'],
					'clientId' => $cid,
					'clientName' => $name,
					'actorRef' => $row['actor_ref'],
					'actorLabel' => $this->acl->refLabel($row['actor_ref']),
					'rejectReason' => $row['reject_reason'],
					'checkinTs' => $row['checkin_ts'],
					'updatedAt' => $row['updated_at'],
				];
			}
			return $this->ok(['reviews' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	// ---- CCK2 license (companion seats; the web app is never gated) ----

	/** License summary for the settings page — app admin only. */
	#[NoAdminRequired]
	public function getLicense(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			if (!$this->acl->isAppAdmin()) {
				throw ApiException::notFound();
			}
			return $this->ok([
				'license' => $this->license->getLicenseSummary(),
				'seatsUsed' => $this->pairs->seatsUsed(),
			]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * Apply a `CCK2.*` key — app admin only, audit-logged (license mutations
	 * are policy changes, api-security §7: mint/mutate gets an audit record).
	 */
	#[NoAdminRequired]
	public function applyLicenseKey(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			if (!$this->acl->isAppAdmin()) {
				throw ApiException::notFound();
			}
			$key = (string)$this->request->getParam('license_key', '');
			if (trim($key) === '') {
				throw ApiException::validation('Validation failed.', ['license_key' => 'required']);
			}
			if (!$this->license->applyLicenseKey($key)) {
				$code = $this->license->getLastApplyErrorCode();
				$this->acl->audit($actor, 'license_apply', null, 'fail:' . $code);
				if ($code === 'license_busy') {
					throw ApiException::conflict('License update in progress.');
				}
				throw ApiException::validation('License key rejected.', ['license_key' => strtolower($code)]);
			}
			$this->acl->audit($actor, 'license_apply', null, 'ok');
			return $this->ok(['license' => $this->license->getLicenseSummary()]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[NoAdminRequired]
	public function clearLicenseKey(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			if (!$this->acl->isAppAdmin()) {
				throw ApiException::notFound();
			}
			$this->license->clearLicense();
			$this->acl->audit($actor, 'license_apply', null, 'cleared');
			return $this->ok(['license' => null]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * Cron health (spec §6): a starved job must surface, never silently
	 * produce an empty coverage board. Stale = last run older than twice
	 * the job interval (or never run once the app outlives the interval).
	 */
	/**
	 * NH-10 support diagnostics — instance/app state only. Absolutely no
	 * client-identifying fields: this is the "send us a screenshot" surface.
	 */
	#[NoAdminRequired]
	public function getDiagnostics(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->acl->assertStaff($actor);
			$qb = $this->db->getQueryBuilder();
			$paired = (int)$qb->select($qb->func()->count('id'))
				->from('cck_pair')
				->where($qb->expr()->isNotNull('token_hash'))
				->andWhere($qb->expr()->eq('revoked', $qb->createNamedParameter(0)))
				->executeQuery()->fetchOne();
			$qb2 = $this->db->getQueryBuilder();
			$openNotices = (int)$qb2->select($qb2->func()->count('id'))
				->from('cck_notice')
				->where($qb2->expr()->in('state', $qb2->createNamedParameter(['open', 'escalated'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
				->executeQuery()->fetchOne();
			$qb3 = $this->db->getQueryBuilder();
			$reviewQueue = (int)$qb3->select($qb3->func()->count('id'))
				->from('cck_visit')
				->where($qb3->expr()->eq('status', $qb3->createNamedParameter('rejected_review')))
				->executeQuery()->fetchOne();
			$summary = $this->license->getLicenseSummary();
			return $this->ok(['diagnostics' => [
				'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
				'cronOk' => !$this->cronStale(),
				'pairedDevices' => $paired,
				'openNotices' => $openNotices,
				'reviewQueue' => $reviewQueue,
				'licenseState' => $summary === null ? 'none' : ($summary['active'] ? 'active' : 'inactive'),
				'licenseSeats' => $summary === null ? 0 : $summary['mobileSeats'],
				'seatsUsed' => $this->pairs->seatsUsed(),
			]]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	private function cronStale(): bool
	{
		$jobs = [
			'generate' => 3600, 'overdue' => 300, 'escalation' => 300,
			'anchor' => 86400, 'purge' => 86400,
		];
		$installed = (int)$this->settings->get('installed_ts');
		if ($installed === 0) {
			$installed = time();
			$this->settings->set('installed_ts', (string)$installed);
		}
		$now = time();
		foreach ($jobs as $job => $interval) {
			$last = $this->settings->cronLastRun($job);
			$reference = $last > 0 ? $last : $installed;
			if ($now - $reference > 2 * $interval) {
				return true;
			}
		}
		return false;
	}
}
