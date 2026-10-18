<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * NH-02 / suite flange F12 (consumer half) — DutyCheck's published roster
 * suggests who is on duty for a date, so the Zentrale builds tours around
 * real shifts instead of guessing. Read-only, fail closed: DutyCheck
 * absent or mid-upgrade → empty list, never an error. Only uid-linked
 * employees are actionable (tours assign to actor refs), but unlinked
 * employees still surface so staffing gaps are visible.
 *
 * The feed only crosses when the DutyCheck admin opted in — the same
 * `mc_onduty_hook_enabled` flag that gates the `check.on-duty` endpoint
 * (MaintenanceCheckOnDutyReader) upstream.
 */
class DutyRosterAdapter
{
	private const DC_ONDUTY_FLAG = 'mc_onduty_hook_enabled';

	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private IConfig $config,
	) {
	}

	public function installed(): bool
	{
		try {
			return $this->appManager->isEnabledForUser('dutycheck');
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * On-duty employees for `date` (published/closed periods only — a draft
	 * roster must not leak into tour planning).
	 * @return array{available:bool, rows:list<array{employeeId:int,displayName:string,actorRef:?string,locationName:string,startTime:string,endTime:string}>}
	 */
	public function onDuty(string $date): array
	{
		if (!$this->installed()) {
			return ['available' => false, 'rows' => []];
		}
		// Owner-side consent: no roster data crosses while the DutyCheck
		// on-duty hook is off — regardless of this app's own settings.
		if ($this->config->getAppValue('dutycheck', self::DC_ONDUTY_FLAG, '0') !== '1') {
			return ['available' => false, 'rows' => []];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('a.employee_id', 'a.start_time', 'a.end_time', 'e.display_name', 'e.linked_user_id', 'l.name AS location_name')
				->from('dc_assignments', 'a')
				->innerJoin('a', 'dc_periods', 'p', 'a.period_id = p.id')
				->leftJoin('a', 'dc_employees', 'e', 'a.employee_id = e.id')
				->leftJoin('a', 'dc_locations', 'l', 'a.location_id = l.id')
				->where($qb->expr()->eq('a.duty_date', $qb->createNamedParameter($date)))
				->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(['published', 'closed'], IQueryBuilder::PARAM_STR_ARRAY)))
				->orderBy('a.start_time', 'ASC');
			$rows = [];
			foreach ($qb->executeQuery()->iterateAssociative() as $r) {
				$uid = trim((string)($r['linked_user_id'] ?? ''));
				$rows[] = [
					'employeeId' => (int)$r['employee_id'],
					'displayName' => (string)($r['display_name'] ?? ''),
					'actorRef' => $uid !== '' ? 'uid:' . $uid : null,
					'locationName' => (string)($r['location_name'] ?? ''),
					'startTime' => (string)$r['start_time'],
					'endTime' => (string)$r['end_time'],
				];
			}
			return ['available' => true, 'rows' => $rows];
		} catch (\Throwable) {
			return ['available' => false, 'rows' => []];
		}
	}
}
