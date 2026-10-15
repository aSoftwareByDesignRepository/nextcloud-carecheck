<?php

declare(strict_types=1);

namespace OCA\CareCheck\Controller;

use OCA\CareCheck\AppInfo\Application;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\ClientService;
use OCA\CareCheck\Service\LicenseService;
use OCA\CareCheck\Service\PairService;
use OCA\CareCheck\Service\RateLimitService;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\TourService;
use OCA\CareCheck\Service\VisitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * companionApi — dual auth: `X-CCK-Device-Token` (paired member) OR an
 * authenticated NC session / app-password (worker mode). All methods are
 * PublicPage+NoCSRFRequired because member auth happens inside actorRef();
 * NC-authenticated callers resolve to uid:{uid} automatically.
 */
class CompanionApiController extends Controller
{
	use ApiControllerTrait;

	public function __construct(
		IRequest $request,
		private AclService $acl,
		private VisitService $visits,
		private TourService $tours,
		private ClientService $clients,
		private PairService $pairs,
		private LicenseService $license,
		private RateLimitService $rateLimit,
		private SettingsService $settings,
		private \OCA\CareCheck\Service\MobilityAdapter $mobility,
		private \OCA\CareCheck\Service\ClientDocService $clientDocs,
		private \OCA\CareCheck\Service\PhotoService $photos,
		private \OCA\CareCheck\Service\VoiceNoteService $voiceNotes,
		private \OCA\CareCheck\Service\GroupSessionService $groupSessions,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function actor(): string
	{
		$token = $this->request->getHeader('X-CCK-Device-Token');
		return $this->acl->actorRef($token !== '' ? $token : null);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->acl->audit($actor, 'tour_pull', null, 'bootstrap');
			return $this->ok([
				'actorRef' => $actor,
				'serverVersion' => '0.1.0',
				'providerTz' => $this->tours->providerTz(),
				'minCompanionVersion' => $this->settings->get('min_companion_version'),
				'license' => $this->license->companionBlock($this->pairs->seatsUsed()),
				'flags' => [
					'gps' => $this->settings->getBool('gps_enabled'),
					'slaMinutes' => $this->settings->getInt('sla_minutes'),
					'pinMinLen' => $this->settings->getInt('pin_min_len'),
					'wipeAttempts' => $this->settings->getInt('wipe_attempts'),
					'breakglassHoursMax' => $this->settings->getInt('breakglass_hours_max'),
					// NH-08: companion shows the Fahrtenbuch hint only when the
					// sibling app exists — capability, not a config flag.
					'mobilityInstalled' => $this->mobility->installed(),
				],
				'scope' => $this->scopeManifest($actor),
			]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	private function scopeManifest(string $actor): array
	{
		$ids = [];
		foreach ($this->acl->stopsForActor($actor) as $stop) {
			$ids[] = (int)$stop->getClientId();
		}
		return array_values(array_unique($ids));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function toursForDate(string $date): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$tours = $this->tours->actorStopsForDate($actor, $date);
			$this->acl->audit($actor, 'tour_pull', null, $date);
			return $this->ok([
				'date' => $date,
				'tours' => $tours,
				// NH-08: pool-vehicle badge — null unless MobilityCheck has an
				// approved booking for this worker today.
				'vehicle' => $this->mobility->vehicleFor($actor, $date),
			]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function openTours(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$today = $this->tours->today();
			return $this->ok([
				'tours' => $this->tours->actorOpenStops($actor),
				'vehicle' => $this->mobility->vehicleFor($actor, $today),
			]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/** `GET /api/v1/tours/today` — open/incomplete tours regardless of date. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function toursToday(): JSONResponse
	{
		return $this->openTours();
	}

	/**
	 * `GET /api/v1/clients/{id}` — scoped client payload for the visit screen:
	 * name/street + briefing + the current plan task list. Outside scope → the
	 * uniform 404 (NN-07), never an existence oracle.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function clientPayload(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$client = $this->clients->getForActor($actor, $id);
			$this->acl->audit($actor, 'read', $id, 'client_payload');
			$h = $this->clients->hydrated($client);
			$payload = $h['payload'];
			return $this->ok([
				'client' => [
					'id' => $id,
					'name' => (string)($payload['name'] ?? ''),
					'street' => (string)($payload['street'] ?? ''),
					'zip' => (string)($payload['zip'] ?? ''),
					'city' => (string)($payload['city'] ?? ''),
					'risks' => $payload['risks'] ?? [],
					'accessNote' => (string)($payload['accessNote'] ?? ''),
					'contacts' => $payload['contacts'] ?? [],
					'requiresQual' => $h['requiresQual'],
					'photoConsent' => !empty($payload['photo_consent']),
					'isDemo' => $h['isDemo'],
					'documents' => $this->clientDocs->list($actor, $id),
				],
			]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * `GET /api/v1/station/board` — NH-09 sign-off board for a shared
	 * station tablet. Aggregate progress only: executor + done/total per
	 * tour — deliberately NO client names/streets (a shared screen is not
	 * a scope-checked client surface).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function stationBoard(): JSONResponse
	{
		try {
			$actor = $this->actor();
			if (!str_starts_with($actor, 'station:')) {
				throw ApiException::notFound(); // uniform — station surface is invisible to other actors
			}
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$tz = new \DateTimeZone($this->tours->providerTz());
			$today = (new \DateTime('today', $tz))->format('Y-m-d');
			return $this->ok(['date' => $today, 'board' => $this->tours->stationBoard($today)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * `POST /api/v1/station/signoff` — worker confirms their tour at the
	 * station tablet. Sign-off is an auditable marker, not a visit state
	 * change: the visit itself stays owned by the worker's own device.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function stationSignoff(): JSONResponse
	{
		try {
			$actor = $this->actor();
			if (!str_starts_with($actor, 'station:')) {
				throw ApiException::notFound();
			}
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$in = $this->request->getParams();
			$ref = (string)($in['worker_ref'] ?? '');
			if (!$this->acl->validRef($ref)) {
				throw ApiException::validation('Validation failed.', ['worker_ref' => 'invalid']);
			}
			$this->acl->audit($actor, 'signoff', null, 'worker=' . $ref);
			return $this->ok(['signed' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * `GET /api/v1/clients/{id}/docs` — NH-19 document links for a
	 * scoped client (metadata only; file bytes via the file endpoint).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function clientDocs(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			return $this->ok(['documents' => $this->clientDocs->list($actor, $id)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * `GET /api/v1/clients/{id}/docs/{docId}/file` — stream an attached
	 * provider document. Scope-checked; the proxy read is deliberate:
	 * provider docs are shared with assigned caregivers by design.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function clientDocFile(int $id, int $docId): JSONResponse|DataDownloadResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$file = $this->clientDocs->fileFor($actor, $id, $docId);
			$this->acl->audit($actor, 'read', $id, 'client_doc');
			return new DataDownloadResponse($file->getContent(), $file->getName(), $file->getMimetype());
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-04 — consent-gated clinical photo upload. JSON body:
	 * `{mime, data_b64, visit_id?}`. The server re-sniffs the real type,
	 * enforces the size cap, and refuses unless the client has
	 * `photo_consent` — the uploader can never claim consent into being.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function addPhoto(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$p = $this->request->getParams();
			$b64 = (string)($p['data_b64'] ?? '');
			if (strlen($b64) > 7_000_000) {
				throw ApiException::validation('Validation failed.', ['photo' => 'size']);
			}
			$data = base64_decode($b64, true);
			if ($data === false) {
				throw ApiException::validation('Validation failed.', ['photo' => 'invalid']);
			}
			return $this->ok(['photo' => $this->photos->store(
				$actor, $id,
				isset($p['visit_id']) && $p['visit_id'] !== '' ? (int)$p['visit_id'] : null,
				(string)($p['mime'] ?? ''),
				$data,
			)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function clientPhotos(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			return $this->ok(['photos' => $this->photos->list($actor, $id)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function clientPhotoFile(int $id, int $photoId): JSONResponse|DataDownloadResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$f = $this->photos->fileFor($actor, $id, $photoId);
			return new DataDownloadResponse($f['bytes'], 'photo-' . $photoId . '.bin', $f['mime']);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-01 — voice note upload. JSON body `{mime, data_b64, visit_id?}`.
	 * The blob is sealed immediately; transcription is async via cron +
	 * TaskProcessing and lands in the journal when done.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function addVoiceNote(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$p = $this->request->getParams();
			$b64 = (string)($p['data_b64'] ?? '');
			if (strlen($b64) > 23_000_000) {
				throw ApiException::validation('Validation failed.', ['voicenote' => 'size']);
			}
			$data = base64_decode($b64, true);
			if ($data === false) {
				throw ApiException::validation('Validation failed.', ['voicenote' => 'invalid']);
			}
			return $this->ok(['voicenote' => $this->voiceNotes->store(
				$actor, $id,
				isset($p['visit_id']) && $p['visit_id'] !== '' ? (int)$p['visit_id'] : null,
				(string)($p['mime'] ?? ''),
				$data,
			)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function clientVoiceNotes(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			return $this->ok(['voicenotes' => $this->voiceNotes->list($actor, $id)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * NH-12 — group session (1 worker : N clients). Every client_id is
	 * scope-checked; idempotent on idem_key for offline retries.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function openGroupSession(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
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
			return $this->ok(['session' => $this->groupSessions->open(
				$actor, (string)($p['title'] ?? ''), $clientIds, (string)($p['idem_key'] ?? ''),
			)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function groupSession(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			return $this->ok(['session' => $this->groupSessions->list($actor, $id)]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function setGroupAttended(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$p = $this->request->getParams();
			$this->groupSessions->setAttended($actor, $id, (int)($p['client_id'] ?? 0),
				($p['attended'] ?? '1') === '1' || ($p['attended'] ?? null) === true);
			return $this->ok(['updated' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function closeGroupSession(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$this->groupSessions->close($actor, $id);
			return $this->ok(['closed' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/** `GET /api/v1/license` — seat/entitlement readback for the device. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function licenseState(): JSONResponse
	{
		try {
			$this->actor(); // any valid credential may read back its own license state
			return $this->ok(['license' => $this->license->companionBlock($this->pairs->seatsUsed())]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * `DELETE /api/v1/pair` — self-unpair: the device revokes its own token.
	 * The revoke takes effect on the NEXT request; the seat frees immediately.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function unpair(): JSONResponse
	{
		try {
			$token = $this->request->getHeader('X-CCK-Device-Token');
			if ($token === '') {
				throw ApiException::notFound();
			}
			$this->pairs->revokeByToken($token);
			return $this->ok(['revoked' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function visitCheckin(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			return $this->ok($this->visits->checkin($actor, $this->request->getParams()));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function submitVisit(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			return $this->ok($this->visits->submitBundle($actor, $this->request->getParams()));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function skipStop(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			return $this->ok($this->visits->skipStop(
				$actor, $id, (string)$this->request->getParam('reason_code', '')));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function submitNotice(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			return $this->ok($this->visits->submitNotice($actor, $this->request->getParams()));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/** Own notices incl. ack state — caregiver sees Zentrale confirmations (US-204 AC-3). */
	#[PublicPage]
	#[NoCSRFRequired]
	public function myNotices(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$out = [];
			foreach ($this->visits->noticesFor($actor) as $n) {
				$out[] = $n;
			}
			return $this->ok(['notices' => $out]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * "Versehentlich ausgelöst" (UX-13): author retracts an open notice.
	 * The audit trail keeps the notice; the Zentrale sees it as resolved.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function resolveNoticeError(int $id): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			$this->visits->resolveNoticeError($actor, $id);
			return $this->ok(['resolved' => true]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/**
	 * Übungstour for member mode (MH-15): a member may mint a practice
	 * tour for THEMSELVES only — the executor ref is always the caller's
	 * own actor ref, never a request parameter, so no privilege escalation
	 * is possible. Worker-mode (uid:) callers get their Übungstour from
	 * the web UI instead.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function practiceTour(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			if (!str_starts_with($actor, 'member:')) {
				throw ApiException::validation('Validation failed.', ['member_id' => 'invalid']);
			}
			return $this->ok($this->tours->createPracticeTour($actor, $actor), 201);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function searchClients(string $q = ''): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->license->requireMobileLicense();
			return $this->ok(['results' => $this->clients->searchMinimal(
				$actor, $q, $this->settings->getInt('search_rate_per_min'))]);
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function requestBreakGlass(): JSONResponse
	{
		try {
			$actor = $this->actor();
			$this->rateLimit->hit('companion', $actor, $this->settings->getInt('device_rate_per_min'));
			$this->license->requireMobileLicense();
			return $this->ok($this->visits->requestBreakGlass($actor, $this->request->getParams()));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}

	/** Pair-code redemption is unauthenticated by definition — it mints the token. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function redeemPairCode(): JSONResponse
	{
		try {
			$p = $this->request->getParams();
			$code = trim((string)($p['code'] ?? ''));
			$label = isset($p['device_label']) ? (string)$p['device_label'] : null;
			if ($code === '') {
				// Worker mode (Family A): no code — the NC session/app-password
				// is the credential; the device registers under that uid.
				$uid = $this->acl->currentUid();
				if ($uid === null) {
					throw new ApiException(\OCA\CareCheck\Constants::ERR_AUTH, 'Unauthorized.', [], 401);
				}
				return $this->ok($this->pairs->registerWorkerDevice($uid, $label), 201);
			}
			return $this->ok($this->pairs->redeem($code, $label));
		} catch (ApiException $e) {
			return $this->error($e);
		}
	}
}
