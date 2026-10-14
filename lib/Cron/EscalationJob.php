<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;

/** Unacknowledged incidents older than the SLA escalate (state → escalated). */
class EscalationJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private SettingsService $settings,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void
	{
		$this->settings->touchCron('escalation');
		$cutoff = (new \DateTime())->modify('-' . $this->settings->getInt('sla_minutes') . ' minutes');
		$qb = $this->db->getQueryBuilder();
		// keep state=open (still needs ack) — the escalated flag carries the SLA breach;
		// where escalated=0 makes re-runs idempotent
		$qb->update('cck_notice')
			->set('escalated', $qb->createNamedParameter(1))
			->where($qb->expr()->eq('state', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->eq('escalated', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('incident')))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, 'datetime')));
		$qb->executeStatement();
	}
}
