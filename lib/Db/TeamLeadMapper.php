<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<TeamLead> */
class TeamLeadMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'cck_tlead', TeamLead::class);
	}

	/** @return list<int> team ids this uid leads */
	public function teamIdsFor(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('team_id')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$out = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			$out[] = (int)$r['team_id'];
		}
		return $out;
	}

	/** @return list<TeamLead> */
	public function findByTeam(int $teamId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId)));
		return $this->findEntities($qb);
	}
}
