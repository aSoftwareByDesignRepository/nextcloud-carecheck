<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\AzcAbsenceAdapter;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\TourService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

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
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		$this->settings->touchCron('azc_sync');
		$tz = new \DateTimeZone($this->tours->providerTz());
		$from = (new \DateTime('today', $tz))->format('Y-m-d');
		$to = (new \DateTime('today', $tz))->modify('+60 days')->format('Y-m-d');
		$this->azc->import($from, $to);
	}
}
