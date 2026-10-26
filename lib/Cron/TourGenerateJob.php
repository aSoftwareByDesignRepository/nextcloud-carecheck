<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\TourService;
use OCA\CareCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Daily materialization of published tour templates (single-flight, idempotent). */
class TourGenerateJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private TourService $tours,
		private SettingsService $settings,
		private ILockingProvider $locking,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600); // hourly — generation is idempotent by UNIQUE(template_id,date)
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
				'tour generation',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'CareCheck: skipping tour generation — upgrade backup/restore in progress.',
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
		$this->settings->touchCron('generate');
		$this->tours->generate($this->settings->getInt('horizon_days'));
	}
}
