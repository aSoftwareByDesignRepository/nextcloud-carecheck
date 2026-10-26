<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Db\Notice;
use OCA\CareCheck\Db\NoticeMapper;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Marks stops past their window as overdue (stays completable — OVERDUE is a
 * flag state surfaced on the coverage board; notice dedupe on stop+date).
 */
class OverdueDetectionJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private NoticeMapper $notices,
		private SettingsService $settings,
		private ILockingProvider $locking,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300); // every 5 min — pinned cadence
	}

	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — app-table
		// scans/writes must not interleave with a mid-restore
		// truncate+reinsert. A skip is not a failure: TimedJobs re-run
		// on their interval.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'overdue detection',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'CareCheck: skipping overdue detection — upgrade backup/restore in progress.',
				['app' => 'carecheck'],
			);
			return;
		}
		try {
			$this->doRun();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function doRun(): void
	{
		$this->settings->touchCron('overdue');
		$now = new \DateTime();
		$minutesNow = (int)$now->format('H') * 60 + (int)$now->format('i');
		$today = $now->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.id', 's.client_id', 's.executor_ref')->from('cck_tstop', 's')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->where($qb->expr()->eq('t.date', $qb->createNamedParameter($today)))
			->andWhere($qb->expr()->in('s.time_kind', $qb->createNamedParameter(['fixed', 'deadline'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('s.status', $qb->createNamedParameter('pending')))
			->andWhere($qb->expr()->lt('s.win_end', $qb->createNamedParameter($minutesNow)));
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			// one overdue notice per stop — the UNIQUE(actor_ref, idem_key) index
			// makes this atomic, so overlapping cron runs can never double-notify
			$n = new Notice();
			$n->setClientId((int)$row['client_id']);
			$n->setActorRef('system');
			$n->setKind('incident');
			$n->setPriority('high');
			$n->setNoteEnc(null); // machine notice — no client data needed
			$n->setState('open');
			$n->setIdemKey('od:' . (int)$row['id']);
			$n->setCreatedAt(new \DateTime());
			try {
				$this->notices->insert($n);
			} catch (\OCP\DB\Exception $e) {
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
		}
	}


}
