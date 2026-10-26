<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\AnchorService;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Daily provider-global journal anchor (yesterday's chain heads → TSA). */
class AnchorJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private AnchorService $anchors,
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
				'journal anchoring',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'CareCheck: skipping journal anchoring — upgrade backup/restore in progress.',
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
		$this->settings->touchCron('anchor');
		// idempotent: skip if today's anchor row already exists
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('cck_anchor')
			->where($qb->expr()->eq('date', $qb->createNamedParameter((new \DateTime('yesterday'))->format('Y-m-d'))))
			->setMaxResults(1);
		if ($qb->executeQuery()->fetch() !== false) {
			return;
		}
		$this->anchors->anchorDay();
	}
}
