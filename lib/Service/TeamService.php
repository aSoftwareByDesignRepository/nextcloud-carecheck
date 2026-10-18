<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\MemberMapper;
use OCA\CareCheck\Db\Team;
use OCA\CareCheck\Db\TeamLead;
use OCA\CareCheck\Db\TeamLeadMapper;
use OCA\CareCheck\Db\TeamMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;
use OCA\CareCheck\Constants;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * NH-11 — team/regional scoping. Zentrale/admin manage teams and keep
 * flat visibility; a team lead (`carecheck-teamlead` group + cck_tlead
 * row) sees only clients and stops of the teams they lead. null team_id
 * means unscoped — visible to full staff only, never to team leads
 * (a lead must not see an unassigned client "by default").
 */
class TeamService
{
	public function __construct(
		private IDBConnection $db,
		private TeamMapper $teams,
		private TeamLeadMapper $leads,
		private ClientMapper $clients,
		private MemberMapper $members,
		private AclService $acl,
		private IUserManager $users,
		private IGroupManager $groups,
	) {
	}

	/** @return list<array<string,mixed>> teams with leads + counts */
	public function list(string $actorRef): array
	{
		$this->acl->assertStaff($actorRef);
		$out = [];
		foreach ($this->teams->findAll() as $t) {
			$leads = array_map(static fn (TeamLead $l) => (string)$l->getUid(), $this->leads->findByTeam((int)$t->getId()));
			$out[] = [
				'id' => (int)$t->getId(),
				'name' => (string)$t->getName(),
				'leads' => $leads,
				// chips must show names, never raw uids — parallel to `leads`
				'leadLabels' => array_map(fn (string $u) => $this->acl->refLabel('uid:' . $u) ?? $u, $leads),
				'clients' => $this->countIn('cck_client', (int)$t->getId()),
				'members' => $this->countIn('cck_member', (int)$t->getId()),
			];
		}
		return $out;
	}

	public function create(string $actorRef, string $name): array
	{
		$this->acl->assertStaff($actorRef);
		$name = trim($name);
		if ($name === '') {
			throw ApiException::validation('Validation failed.', ['name' => 'required']);
		}
		foreach ($this->teams->findAll() as $t) {
			if (mb_strtolower((string)$t->getName()) === mb_strtolower($name)) {
				throw ApiException::validation('Validation failed.', ['name' => 'exists']);
			}
		}
		$t = new Team();
		$t->setName($name);
		$t->setCreatedAt(new \DateTime());
		$this->teams->insert($t);
		$this->acl->audit($actorRef, 'team_manage', null, 'create:' . $name);
		return ['id' => (int)$t->getId(), 'name' => $name];
	}

	public function assignLead(string $actorRef, int $teamId, string $uid): void
	{
		$this->acl->assertStaff($actorRef);
		$team = $this->teams->find($teamId);
		// uid must exist AND carry the teamlead group — a lead row for a
		// user who never got the group would silently scope to nothing.
		if ($team === null || !$this->users->userExists($uid)) {
			throw ApiException::notFound();
		}
		if (!$this->groups->isInGroup($uid, Constants::GROUP_TEAMLEAD)) {
			throw ApiException::validation('Validation failed.', ['uid' => 'not_teamlead']);
		}
		foreach ($this->leads->findByTeam($teamId) as $l) {
			if ((string)$l->getUid() === $uid) {
				return; // idempotent
			}
		}
		$l = new TeamLead();
		$l->setTeamId($teamId);
		$l->setUid($uid);
		try {
			$this->leads->insert($l);
		} catch (\OCP\DB\Exception $e) {
			// Concurrent addLead for the same (team, uid): loser collides on
			// cck_tlead_uq — the operation is already applied, replay is a no-op.
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return;
			}
			throw $e;
		}
		$this->acl->audit($actorRef, 'team_manage', null, 'lead:' . $teamId . ':' . $uid);
	}

	public function removeLead(string $actorRef, int $teamId, string $uid): void
	{
		$this->acl->assertStaff($actorRef);
		foreach ($this->leads->findByTeam($teamId) as $l) {
			if ((string)$l->getUid() === $uid) {
				$this->leads->delete($l);
				$this->acl->audit($actorRef, 'team_manage', null, 'unlead:' . $teamId . ':' . $uid);
				return;
			}
		}
		throw ApiException::notFound();
	}

	public function assignClient(string $actorRef, int $clientId, ?int $teamId): void
	{
		$this->acl->assertStaff($actorRef);
		if ($teamId !== null && $this->teams->find($teamId) === null) {
			throw ApiException::notFound();
		}
		$c = $this->clients->find($clientId);
		if ($c === null) {
			throw ApiException::notFound();
		}
		$c->setTeamId($teamId);
		$this->clients->update($c);
		$this->acl->audit($actorRef, 'team_manage', $clientId, 'team:' . ($teamId ?? 'none'));
	}

	public function assignMember(string $actorRef, int $memberId, ?int $teamId): void
	{
		$this->acl->assertStaff($actorRef);
		if ($teamId !== null && $this->teams->find($teamId) === null) {
			throw ApiException::notFound();
		}
		$m = $this->members->find($memberId);
		if ($m === null) {
			throw ApiException::notFound();
		}
		$m->setTeamId($teamId);
		$this->members->update($m);
		$this->acl->audit($actorRef, 'team_manage', null, 'member:' . $memberId . ':' . ($teamId ?? 'none'));
	}

	/** @return list<int> */
	public function leadTeamIds(string $uid): array
	{
		return $this->leads->teamIdsFor($uid);
	}

	private function countIn(string $table, int $teamId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($table)
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
