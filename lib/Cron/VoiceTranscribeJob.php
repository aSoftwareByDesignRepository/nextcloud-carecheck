<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\VoiceNoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

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
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		$this->settings->touchCron('voice_transcribe');
		$this->notes->transcribePending();
	}
}
