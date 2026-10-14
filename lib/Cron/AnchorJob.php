<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\AnchorService;
use OCA\CareCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;

/** Daily provider-global journal anchor (yesterday's chain heads → TSA). */
class AnchorJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private AnchorService $anchors,
		private IDBConnection $db,
		private SettingsService $settings,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
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
