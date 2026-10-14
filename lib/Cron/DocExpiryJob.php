<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\ClientDocService;
use OCA\CareCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** NH-15 — daily client-document expiry warnings (deduped by idem key). */
class DocExpiryJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private ClientDocService $docs,
		private SettingsService $settings,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		$this->settings->touchCron('doc_expiry');
		$this->docs->expirySweep();
	}
}
