<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Unacknowledged incidents older than the SLA escalate (state → escalated). */
class EscalationJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private SettingsService $settings,
		private ILockingProvider $locking,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
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
				'escalation sweep',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'CareCheck: skipping escalation sweep — upgrade backup/restore in progress.',
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
		$this->settings->touchCron('escalation');
		$cutoff = (new \DateTime())->modify('-' . $this->settings->getInt('sla_minutes') . ' minutes');
		$qb = $this->db->getQueryBuilder();
		// keep state=open (still needs ack) — the escalated flag carries the SLA breach;
		// where escalated=0 makes re-runs idempotent
		$qb->update('cck_notice')
			->set('escalated', $qb->createNamedParameter(1))
			->where($qb->expr()->eq('state', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->eq('escalated', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('incident')))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')));
		$qb->executeStatement();
	}
}
