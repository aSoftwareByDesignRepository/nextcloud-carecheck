<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\TourService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Daily materialization of published tour templates (single-flight, idempotent). */
class TourGenerateJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private TourService $tours,
		private SettingsService $settings,
	) {
		parent::__construct($time);
		$this->setInterval(3600); // hourly — generation is idempotent by UNIQUE(template_id,date)
	}

	protected function run($argument): void
	{
		$this->settings->touchCron('generate');
		$this->tours->generate($this->settings->getInt('horizon_days'));
	}
}
