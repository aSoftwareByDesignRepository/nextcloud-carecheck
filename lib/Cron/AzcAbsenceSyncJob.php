<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\AzcAbsenceAdapter;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\TourService;
use OCA\CareCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * NH-13 / F19 — daily pull of approved AZC absences into cck_absence.
 * Idempotent (adapter dedupes), fail closed (AZC absent → no-op).
 * Window: today → +60 days covers roster horizon; past absences are
 * irrelevant for coverage.
 */
class AzcAbsenceSyncJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private AzcAbsenceAdapter $azc,
		private TourService $tours,
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
				'AZC absence sync',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'CareCheck: skipping AZC absence sync — upgrade backup/restore in progress.',
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
		$this->settings->touchCron('azc_sync');
		$tz = new \DateTimeZone($this->tours->providerTz());
		$from = (new \DateTime('today', $tz))->format('Y-m-d');
		$to = (new \DateTime('today', $tz))->modify('+60 days')->format('Y-m-d');
		$this->azc->import($from, $to);
	}
}
