<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\ArbeitszeitCheck\Public\ApprovedAbsenceReadFacade;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * NH-13 / suite flange F19 (consumer half) — ArbeitszeitCheck approved
 * absences become `cck_absence` rows so tour generation and the coverage
 * board see them automatically (manual marking stays the fallback).
 *
 * Reads go through AZC's versioned `ApprovedAbsenceReadFacade` — never
 * direct `at_*` SQL. Contract: status ONLY crosses the flange. The AZC
 * absence `type` (vacation, sick, …) and `reason` are privacy-boundary
 * data — the facade's row shape cannot carry them, and they are
 * deliberately mapped to the generic kind `other` on import.
 * Rejected/pending rows never leave AZC. Fails soft: AZC absent, facade
 * missing/too old, or any error yields a clean no-op report, never a 500.
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
			return $this->appManager->isEnabledForUser('arbeitszeitcheck')
				&& class_exists(ApprovedAbsenceReadFacade::class)
				&& defined(ApprovedAbsenceReadFacade::class . '::FACADE_VERSION')
				&& (int)constant(ApprovedAbsenceReadFacade::class . '::FACADE_VERSION') >= 1;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Import approved absences overlapping [from, until] as `source='azc'`
	 * staff_absence rows. Only `uid:` actors can match — AZC user_id is a
	 * Nextcloud uid, and member identities have none.
	 *
	 * @return array{scanned:int, imported:int, skipped:int, errors:int, available:bool}
	 */
	public function import(string $from, string $until): array
	{
		$report = ['scanned' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'available' => false];
		if (!$this->installed()) {
			return $report;
		}
		try {
			$facade = \OCP\Server::get(ApprovedAbsenceReadFacade::class);
			$rows = $facade->listApprovedForRange($from, $until);
		} catch (\Throwable) {
			// Facade absent at runtime / drift → fail closed, report stays honest.
			return $report;
		}
		$report['available'] = true;

		try {
			$existing = $this->azcSourceIndex();
		} catch (\Throwable) {
			// Own dedupe index unreadable — importing blind would create
			// duplicates, so the run aborts instead.
			$report['errors']++;
			return $report;
		}
		foreach ($rows as $r) {
			$uid = trim((string)($r['userId'] ?? ''));
			if ($uid === '') {
				continue;
			}
			$report['scanned']++;
			$ref = 'uid:' . $uid;
			$f = substr((string)$r['startDate'], 0, 10);
			$u = substr((string)$r['endDate'], 0, 10);
			if (isset($existing[$ref . '|' . $f . '|' . $u])) {
				$report['skipped']++;
				continue;
			}
			try {
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
			} catch (\Throwable) {
				$report['errors']++;
				continue;
			}
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
