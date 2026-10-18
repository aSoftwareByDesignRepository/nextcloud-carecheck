<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Db\Audit;
use OCA\CareCheck\Db\AuditMapper;
use OCA\CareCheck\Db\BreakGlassMapper;
use OCA\CareCheck\Db\Member;
use OCA\CareCheck\Db\MemberMapper;
use OCA\CareCheck\Db\Pair;
use OCA\CareCheck\Db\PairMapper;
use OCA\CareCheck\Db\TourStop;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Actor resolution + scope checks.
 *
 * Actors are typed refs: `uid:{nc-uid}` (worker via session/app-password) or
 * `member:{id}` (paired device). Scope = executor on the stop/tour, or an
 * active break-glass grant, or a Zentrale-class role on the web.
 * Every out-of-scope probe collapses to the uniform 404 payload.
 */
class AclService
{
	public function __construct(
		private IDBConnection $db,
		private IUserSession $session,
		private IGroupManager $groups,
		private IUserManager $users,
		private PairMapper $pairs,
		private MemberMapper $members,
		private BreakGlassMapper $breakGlass,
		private AuditMapper $audit,
		private SettingsService $settings,
		private \OCA\CareCheck\Db\TeamLeadMapper $teamLeads,
	) {
	}

	/** Session uid or null (companion member actors have none). */
	public function currentUid(): ?string
	{
		return $this->session->getUser()?->getUID();
	}

	/**
	 * Delegated app admin (DS-2): NC admin, carecheck-admin group, or a named
	 * user in the app_admins setting — layered under the carecheck roles.
	 */
	public function isAppAdmin(?string $uid = null): bool
	{
		$uid ??= $this->currentUid();
		if ($uid === null) {
			return false;
		}
		return $this->groups->isAdmin($uid)
			|| $this->groups->isInGroup($uid, Constants::GROUP_APP_ADMIN)
			|| in_array($uid, $this->settings->getList('app_admins'), true);
	}

	/** Dispatch role (Zentrale) — sidebar role badge + coarse capability check. */
	public function isZentrale(?string $uid = null): bool
	{
		$uid ??= $this->currentUid();
		return $uid !== null && $this->groups->isInGroup($uid, Constants::GROUP_ZENTRALE);
	}

	/** Back-office role (Buchhaltung) — sidebar role badge. */
	public function isBuchhaltung(?string $uid = null): bool
	{
		$uid ??= $this->currentUid();
		return $uid !== null && $this->groups->isInGroup($uid, Constants::GROUP_BUCHHALTUNG);
	}

	/**
	 * DS-2 access door. 'open' → any authenticated user. 'restricted' →
	 * app admins, explicit allowed users/groups, or any carecheck role
	 * group (role membership implies intended base access).
	 */
	public function hasAppAccess(?string $uid = null): bool
	{
		$uid ??= $this->currentUid();
		if ($uid === null) {
			return false;
		}
		if ($this->settings->get('access_mode') !== 'restricted') {
			return true;
		}
		if ($this->isAppAdmin($uid)) {
			return true;
		}
		if (in_array($uid, $this->settings->getList('allowed_users'), true)) {
			return true;
		}
		foreach ($this->settings->getList('allowed_groups') as $gid) {
			if ($this->groups->isInGroup($uid, $gid)) {
				return true;
			}
		}
		foreach ([Constants::GROUP_ZENTRALE, Constants::GROUP_BUCHHALTUNG, Constants::GROUP_APP_ADMIN] as $g) {
			if ($this->groups->isInGroup($uid, $g)) {
				return true;
			}
		}
		return false;
	}

	/** Gate for page/API entry — uniform 404, no "app exists but you can't" leak. */
	public function assertAppAccess(): string
	{
		$uid = $this->currentUid();
		if ($uid === null || !$this->hasAppAccess($uid)) {
			throw ApiException::notFound();
		}
		return $uid;
	}


	/** Resolve the caller to a typed actor ref, or throw 401. */
	public function actorRef(?string $deviceToken): string
	{
		if ($deviceToken !== null && $deviceToken !== '') {
			$pair = $this->pairForToken($deviceToken);
			if ($pair !== null) {
				// touch last-seen for the diagnostics view (NH-10) — throttled to
				// one write per device per minute; a failed write must never
				// break the request
				try {
					$seen = $pair->getLastSeen();
					if ($seen === null || $seen < (new \DateTime())->modify('-60 seconds')) {
						$pair->setLastSeen(new \DateTime());
						$this->pairs->update($pair);
					}
				} catch (\Throwable) {
				}
				if ($pair->getKind() === 'station') {
					// NH-09: station tokens get their own ref — sign-off scope
					// only, never client scope (assertClientScope can't pass:
					// no stop is ever executed by 'station:{id}').
					return 'station:' . (int)$pair->getId();
				}
				if ($pair->getUid() !== null && $pair->getUid() !== '') {
					return 'uid:' . $pair->getUid();
				}
				return 'member:' . (int)$pair->getMemberId();
			}
		}
		$user = $this->session->getUser();
		if ($user !== null) {
			return 'uid:' . $user->getUID();
		}
		throw new ApiException(Constants::ERR_AUTH, 'Unauthorized.', [], 401);
	}

	/**
	 * Typed actor/staff ref, format AND existence.
	 * member:{id} — row must exist and be active; uid:{uid} — account must
	 * still exist (deleted users leave stale refs everywhere). Anything else
	 * is rejected: refs are never bare ids or free text.
	 */
	public function validRef(string $ref): bool
	{
		if (str_starts_with($ref, 'member:')) {
			$id = substr($ref, 7);
			if (preg_match('/^\d+$/', $id) !== 1) {
				return false;
			}
			$m = $this->members->find((int)$id);
			return $m !== null && (int)$m->getActive() === 1;
		}
		if (str_starts_with($ref, 'uid:')) {
			$uid = substr($ref, 4);
			return $uid !== '' && $this->users->userExists($uid);
		}
		return false;
	}

	/**
	 * Human display name for a typed ref — UI chips must never show the raw
	 * `member:3` / `uid:x` / `group:y` wire token. Returns null when the ref
	 * is malformed or points at a deleted principal.
	 */
	public function refLabel(?string $ref): ?string
	{
		if ($ref === null || $ref === '') {
			return null;
		}
		if (str_starts_with($ref, 'member:')) {
			$id = substr($ref, 7);
			if (preg_match('/^\d+$/', $id) !== 1) {
				return null;
			}
			$m = $this->members->find((int)$id);
			return $m !== null ? (string)$m->getDisplayName() : null;
		}
		if (str_starts_with($ref, 'uid:')) {
			$uid = substr($ref, 4);
			$u = $this->users->get($uid);
			return $u !== null ? ($u->getDisplayName() ?: $uid) : null;
		}
		if (str_starts_with($ref, 'group:')) {
			$gid = substr($ref, 6);
			$g = $this->groups->get($gid);
			return $g !== null ? ($g->getDisplayName() ?: $gid) : null;
		}
		return null;
	}

	public function pairForToken(string $token): ?Pair
	{
		return $this->pairs->findActiveByTokenHash(hash('sha256', $token));
	}

	/** Can this actor document a stop? executor on it, or an active grant, or staff role. */
	public function canTouchClient(string $actorRef, int $clientId): bool
	{
		if ($this->isStaff($actorRef)) {
			return true;
		}
		// NH-11: team lead touches clients assigned to the teams they lead.
		// Unscoped clients (team_id null) stay Zentrale-only — a lead never
		// inherits an unassigned client "by default".
		$leadTeams = $this->leadTeamIds($actorRef);
		if ($leadTeams !== [] && $this->clientTeamId($clientId) !== null
			&& in_array($this->clientTeamId($clientId), $leadTeams, true)) {
			return true;
		}
		// executor on any tour stop for this client
		foreach ($this->stopsForActor($actorRef) as $stop) {
			if ((int)$stop->getClientId() === $clientId) {
				return true;
			}
		}
		return $this->hasActiveGrant($actorRef, $clientId);
	}

	public function assertClientScope(string $actorRef, int $clientId): void
	{
		if (!$this->canTouchClient($actorRef, $clientId)) {
			throw ApiException::notFound(); // uniform 404 — no existence oracle
		}
	}

	public function isStaff(string $actorRef): bool
	{
		if (!str_starts_with($actorRef, 'uid:')) {
			return false;
		}
		$uid = substr($actorRef, 4);
		return $this->groups->isAdmin($uid)
			|| $this->groups->isInGroup($uid, Constants::GROUP_ZENTRALE)
			|| $this->isAppAdmin($uid);
	}

	public function assertStaff(string $actorRef): void
	{
		if (!$this->isStaff($actorRef)) {
			throw ApiException::notFound();
		}
	}

	public function assertBuchhaltungOrStaff(string $actorRef): void
	{
		if ($this->isStaff($actorRef)) {
			return;
		}
		if (str_starts_with($actorRef, 'uid:') && $this->groups->isInGroup(substr($actorRef, 4), Constants::GROUP_BUCHHALTUNG)) {
			return;
		}
		throw ApiException::notFound();
	}

	public function hasActiveGrant(string $actorRef, int $clientId): bool
	{
		foreach ($this->breakGlass->findAll() as $g) {
			if ($g->getActorRef() === $actorRef
				&& (int)$g->getClientId() === $clientId
				&& $g->getState() === 'active'
				&& $g->getExpiresAt() > new \DateTime()) {
				return true;
			}
		}
		return false;
	}

	/** @return list<TourStop> */
	public function stopsForActor(string $actorRef): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.*')->from('cck_tstop', 's')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->where($qb->expr()->eq('s.executor_ref', $qb->createNamedParameter($actorRef)));
		$out = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$out[] = TourStop::fromRow($row);
		}
		return $out;
	}

	/**
	 * NH-11: team lead — uid in the teamlead group AND leading ≥1 team.
	 * The group alone is not enough (membership without a team assignment
	 * would scope to everything or nothing inconsistently).
	 */
	public function isTeamLead(string $actorRef): bool
	{
		if (!str_starts_with($actorRef, 'uid:')) {
			return false;
		}
		$uid = substr($actorRef, 4);
		if (!$this->groups->isInGroup($uid, Constants::GROUP_TEAMLEAD)) {
			return false;
		}
		return $this->teamLeads->teamIdsFor($uid) !== [];
	}

	/** @return list<int> team ids the actor leads (empty for non-leads). */
	public function leadTeamIds(string $actorRef): array
	{
		if (!str_starts_with($actorRef, 'uid:')) {
			return [];
		}
		$uid = substr($actorRef, 4);
		if (!$this->groups->isInGroup($uid, Constants::GROUP_TEAMLEAD)) {
			return [];
		}
		return $this->teamLeads->teamIdsFor($uid);
	}

	/** Dispatch read surfaces: full staff OR scoped team lead. */
	public function isDispatcher(string $actorRef): bool
	{
		return $this->isStaff($actorRef) || $this->isTeamLead($actorRef);
	}

	public function assertDispatch(string $actorRef): void
	{
		if (!$this->isDispatcher($actorRef)) {
			throw ApiException::notFound();
		}
	}

	/** Client's team_id via a narrow index read — no payload decrypt. */
	public function clientTeamId(int $clientId): ?int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('team_id')->from('cck_client')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($clientId)));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false || $v === null ? null : (int)$v;
	}

	/** Member's team_id — members scope tours a lead may watch. */
	public function memberTeamId(string $actorRef): ?int
	{
		$m = $this->memberForRef($actorRef);
		if ($m === null || $m->getTeamId() === null) {
			return null;
		}
		return (int)$m->getTeamId();
	}

	public function memberForRef(string $actorRef): ?Member
	{
		if (!str_starts_with($actorRef, 'member:')) {
			return null;
		}
		$m = $this->members->find((int)substr($actorRef, 7));
		return ($m !== null && (int)$m->getActive() === 1) ? $m : null;
	}

	public function audit(string $actorRef, string $action, ?int $clientId = null, ?string $detail = null): void
	{
		$a = new Audit();
		$a->setActorRef($actorRef);
		$a->setClientId($clientId);
		$a->setAction($action);
		$a->setDetail($detail);
		$a->setTs(new \DateTime());
		$this->audit->insert($a);
	}
}
