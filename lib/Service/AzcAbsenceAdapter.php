<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * NH-13 / suite flange F19 (consumer half) — ArbeitszeitCheck approved
 * absences become `cck_absence` rows so tour generation and the coverage
 * board see them automatically (manual marking stays the fallback).
 *
 * Contract: status ONLY crosses the flange. The AZC absence `type`
 * (vacation, sick, …) and `reason` are privacy-boundary data — they are
 * deliberately mapped to the generic kind `other`, and rejected/pending
 * rows are never imported. Fails soft: AZC absent, mid-upgrade schema
 * drift, or a query error yields a clean no-op report, never a 500.
 *
 * Idempotent: a row is skipped when an identical (actor, from, until,
 * source='azc') absence already exists, so the sync can re-run freely.
 * Rows created manually are never touched.
 */
class AzcAbsenceAdapter
{
	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
	) {
	}

	public function installed(): bool
	{
		try {
			return $this->appManager->isEnabledForUser('arbeitszeitcheck');
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Import approved absences overlapping [from, until] as `source='azc'`
	 * staff_absence rows. Only `uid:` actors can match — AZC user_id is a
	 * Nextcloud uid, and member identities have none.
	 *
	 * @return array{scanned:int, imported:int, skipped:int, available:bool}
	 */
	public function import(string $from, string $until): array
	{
		$report = ['scanned' => 0, 'imported' => 0, 'skipped' => 0, 'available' => false];
		if (!$this->installed()) {
			return $report;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('user_id', 'start_date', 'end_date')
				->from('at_absences')
				->where($qb->expr()->eq('status', $qb->createNamedParameter('approved')))
				->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($until)))
				->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($from)));
			$rows = $qb->executeQuery()->fetchAll();
		} catch (\Throwable) {
			// AZC table absent / schema drift → fail closed, report stays honest.
			return $report;
		}
		$report['available'] = true;

		$existing = $this->azcSourceIndex();
		foreach ($rows as $r) {
			$uid = trim((string)$r['user_id']);
			if ($uid === '') {
				continue;
			}
			$report['scanned']++;
			$ref = 'uid:' . $uid;
			$f = substr((string)$r['start_date'], 0, 10);
			$u = substr((string)$r['end_date'], 0, 10);
			if (isset($existing[$ref . '|' . $f . '|' . $u])) {
				$report['skipped']++;
				continue;
			}
			$iqb = $this->db->getQueryBuilder();
			$iqb->insert('cck_absence')
				->values([
					'actor_ref' => $iqb->createNamedParameter($ref),
					'from_date' => $iqb->createNamedParameter(new \DateTime($f), IQueryBuilder::PARAM_DATE),
					'until_date' => $iqb->createNamedParameter(new \DateTime($u), IQueryBuilder::PARAM_DATE),
					'kind' => $iqb->createNamedParameter('other'),
					'source' => $iqb->createNamedParameter('azc'),
					'marked_by' => $iqb->createNamedParameter('azc'),
					'created_at' => $iqb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE),
				])
				->executeStatement();
			$existing[$ref . '|' . $f . '|' . $u] = true;
			$report['imported']++;
		}
		return $report;
	}

	/** @return array<string, true> */
	private function azcSourceIndex(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('actor_ref', 'from_date', 'until_date')
			->from('cck_absence')
			->where($qb->expr()->eq('source', $qb->createNamedParameter('azc')));
		$idx = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$idx[(string)$row['actor_ref'] . '|' . substr((string)$row['from_date'], 0, 10)
				. '|' . substr((string)$row['until_date'], 0, 10)] = true;
		}
		return $idx;
	}
}
