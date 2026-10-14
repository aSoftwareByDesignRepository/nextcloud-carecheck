<?php

declare(strict_types=1);

namespace OCA\CareCheck\Cron;

use OCA\CareCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;

/**
 * Retention purge: journal payloads older than retention_years are shredded
 * to tombstones (payload_enc → NULL) — the hash chain stays verifiable while
 * the plaintext is gone. Legal-hold clients are skipped.
 */
class RetentionPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private SettingsService $settings,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
	}

	protected function run($argument): void
	{
		$this->settings->touchCron('purge');
		$cutoff = (new \DateTime())->modify('-' . $this->settings->getInt('retention_years') . ' years');
		$qb = $this->db->getQueryBuilder();
		$qb->update('cck_journal')
			->set('payload_enc', $qb->createNamedParameter(null))
			->where($qb->expr()->isNotNull('payload_enc'))
			->andWhere($qb->expr()->lt('ts', $qb->createNamedParameter($cutoff, 'datetime')));
		$qb->executeStatement();
	}
}
