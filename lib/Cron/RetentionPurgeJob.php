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

/**
 * Retention purge: journal payloads older than retention_years are shredded
 * to tombstones (payload_enc → NULL) — the hash chain stays verifiable while
 * the plaintext is gone. Legal-hold clients are skipped.
 */
class RetentionPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private SettingsService $settings,
		private ILockingProvider $locking,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
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
				'retention purge',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'CareCheck: skipping retention purge — upgrade backup/restore in progress.',
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
		$this->settings->touchCron('purge');
		$cutoff = (new \DateTime())->modify('-' . $this->settings->getInt('retention_years') . ' years');
		$qb = $this->db->getQueryBuilder();
		$qb->update('cck_journal')
			->set('payload_enc', $qb->createNamedParameter(null))
			->where($qb->expr()->isNotNull('payload_enc'))
			->andWhere($qb->expr()->lt('ts', $qb->createNamedParameter($cutoff, 'datetime')));
		$qb->executeStatement();
	}
}
