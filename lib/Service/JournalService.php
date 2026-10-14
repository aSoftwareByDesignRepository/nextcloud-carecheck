<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\JournalEntry;
use OCA\CareCheck\Db\JournalEntryMapper;
use OCP\IDBConnection;

/**
 * Append-only, per-client hash-chained journal.
 *
 * Concurrency: the client's chain head is row-locked (`SELECT … FOR UPDATE`
 * on the client row) inside the capture transaction before reading prev_hash —
 * simultaneous writers (Zweierpflege) serialize instead of forking the chain.
 * Callers must invoke append() inside an already-open transaction.
 */
class JournalService
{
	private const GENESIS = 'GENESIS';

	public function __construct(
		private IDBConnection $db,
		private JournalEntryMapper $entries,
		private CryptoService $crypto,
	) {
	}

	/**
	 * Append an entry. MUST be called inside $this->db->beginTransaction().
	 * The caller controls the atomic boundary (visit + tasks + stop + journal
	 * commit together — spec invariant).
	 */
	public function append(int $clientId, string $type, string $actorRef, array $payload): JournalEntry
	{
		if (!$this->db->inTransaction()) {
			throw new \LogicException('journal append requires an open transaction');
		}
		// Serialize on the client row — one lock order for every writer of this
		// chain, so no two appends can race or deadlock in opposite order.
		$lock = $this->db->prepare('SELECT id FROM `*PREFIX*cck_client` WHERE `id` = :id FOR UPDATE');
		$lock->execute(['id' => $clientId]);

		$qb = $this->db->getQueryBuilder();
		// Locking read: under InnoDB REPEATABLE READ a plain SELECT would use a
		// snapshot possibly established before a concurrent append committed —
		// producing a duplicate seq (unique-violation rollback). FOR UPDATE reads
		// the latest committed row while we hold the client lock.
		$qb->select('seq', 'payload_hash')->from('cck_journal')
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
			->orderBy('seq', 'DESC')->setMaxResults(1)->forUpdate();
		$head = $qb->executeQuery()->fetch();
		$seq = $head === false ? 1 : ((int)$head['seq'] + 1);
		$prevHash = $head === false ? hash('sha256', self::GENESIS . ':' . $clientId) : (string)$head['payload_hash'];

		$enc = $this->crypto->encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE));
		$payloadHash = hash('sha256', $prevHash . '|' . $enc);

		$e = new JournalEntry();
		$e->setClientId($clientId);
		$e->setSeq($seq);
		$e->setPrevHash($prevHash);
		$e->setPayloadHash($payloadHash);
		$e->setType($type);
		$e->setActorRef($actorRef);
		$e->setPayloadEnc($enc);
		$e->setTs(new \DateTime());
		return $this->entries->insert($e);
	}

	/**
	 * Chronological entries for the client log view (US-205). Payloads are
	 * decrypted for scoped staff readers — the read itself is audited by
	 * the controller (journal_view).
	 * @return list<JournalEntry>
	 */
	public function entriesFor(int $clientId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('cck_journal')
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
			->orderBy('seq', 'ASC');
		$out = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$out[] = \OCA\CareCheck\Db\JournalEntry::fromRow($row);
		}
		return $out;
	}

	/** Decrypt a journal payload for display; null when tombstoned. */
	public function payloadOf(JournalEntry $e): ?array
	{
		if ($e->getPayloadEnc() === null) {
			return null;
		}
		return json_decode($this->crypto->decrypt((string)$e->getPayloadEnc()), true);
	}

	/** Walk a client's chain; returns the first bad seq or 0 when intact. */
	public function verify(int $clientId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('cck_journal')
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
			->orderBy('seq', 'ASC');
		$prev = hash('sha256', self::GENESIS . ':' . $clientId);
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			if ($row['prev_hash'] !== $prev) {
				return (int)$row['seq'];
			}
			// tombstoned rows (payload purged by retention) verify linkage only —
			// the stored hash remains the chain link, payload can no longer be re-derived
			if ($row['payload_enc'] !== null) {
				$expect = hash('sha256', $prev . '|' . (string)$row['payload_enc']);
				if ($row['payload_hash'] !== $expect) {
					return (int)$row['seq'];
				}
			}
			$prev = (string)$row['payload_hash'];
		}
		return 0;
	}

	/** Current per-client head hashes, deterministic order — input to the daily anchor. */
	public function clientHeadHashes(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('client_id')->selectAlias($qb->func()->max('seq'), 'mx')
			->from('cck_journal')->groupBy('client_id');
		$heads = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$q = $this->db->getQueryBuilder();
			$q->select('payload_hash')->from('cck_journal')
				->where($q->expr()->eq('client_id', $q->createNamedParameter((int)$row['client_id'])))
				->andWhere($q->expr()->eq('seq', $q->createNamedParameter((int)$row['mx'])));
			$h = $q->executeQuery()->fetchOne();
			if ($h !== false) {
				$heads[(int)$row['client_id']] = (string)$h;
			}
		}
		ksort($heads);
		return $heads;
	}
}
