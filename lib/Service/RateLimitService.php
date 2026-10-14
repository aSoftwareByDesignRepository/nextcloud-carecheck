<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Db\RateBucket;
use OCA\CareCheck\Db\RateBucketMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;

/**
 * DB-backed fixed-window rate limiter. Buckets survive across PHP workers
 * (memcache-free deployments work too) and expire by window, not cleanup —
 * a stale row is simply a closed window, never a leak.
 *
 * Window key = sha256 of (scope, subject) so no raw IP/actor is persisted.
 */
class RateLimitService
{
	public function __construct(
		private IDBConnection $db,
		private RateBucketMapper $buckets,
	) {
	}

	/**
	 * Consume one unit of $subject's allowance in $scope's fixed window.
	 * Throws ERR_RATE_LIMITED (429) once the count reaches $max.
	 */
	public function hit(string $scope, string $subject, int $max, int $windowSec = 60): void
	{
		$bucketKey = hash('sha256', $scope . ':' . $subject);
		$now = new \DateTime();
		$winStart = (new \DateTime())->modify('-' . $windowSec . ' seconds');

		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from('cck_rate')
				->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucketKey)))
				->forUpdate();
			$row = $qb->executeQuery()->fetch();

			$count = 0;
			if ($row !== false) {
				$ws = new \DateTime((string)$row['win_start']);
				$count = $ws < $winStart ? 0 : (int)$row['cnt'];
			}
			$count++;
			if ($row === false) {
				$b = new RateBucket();
				$b->setBucket($bucketKey);
				$b->setCnt($count);
				$b->setWinStart($now);
				$this->buckets->insert($b);
			} else {
				$b = RateBucket::fromRow($row);
				if ($ws < $winStart) {
					$b->setWinStart($now);
				}
				$b->setCnt($count);
				$this->buckets->update($b);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($e instanceof \OCP\DB\Exception
				&& $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
			) {
				// raced first insert — treat as allowed; the loser's window is
				// the winner's row, within bound of max+1 anyway
				return;
			}
			throw $e;
		}
		if ($count > $max) {
			throw new ApiException(Constants::ERR_RATE_LIMITED, 'Too many requests.', [], 429);
		}
	}
}
