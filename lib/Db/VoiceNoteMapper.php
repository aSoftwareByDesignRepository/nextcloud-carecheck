<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<VoiceNote> */
class VoiceNoteMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'cck_vnote', VoiceNote::class);
	}

	public function find(int $id): ?VoiceNote
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<VoiceNote> */
	public function findByClient(int $clientId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** Rows waiting for a provider to accept them. */
	public function findPending(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')));
		return $this->findEntities($qb);
	}

	/** Rows handed to TaskProcessing, awaiting a result. */
	public function findSubmitted(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('submitted')))
			->andWhere($qb->expr()->isNotNull('task_id'));
		return $this->findEntities($qb);
	}
}
