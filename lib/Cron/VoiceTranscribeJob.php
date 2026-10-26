<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\UpgradeBackupService;
use OCA\CareCheck\Service\VoiceNoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * NH-01 — drive voice notes through TaskProcessing. Bounded per pass;
 * when the instance has no audio2text provider this is a cheap no-op
 * (the availability check short-circuits before any I/O).
 */
class VoiceTranscribeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private VoiceNoteService $notes,
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
				'voice transcription',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'CareCheck: skipping voice transcription — upgrade backup/restore in progress.',
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
		$this->settings->touchCron('voice_transcribe');
		$this->notes->transcribePending();
	}
}
