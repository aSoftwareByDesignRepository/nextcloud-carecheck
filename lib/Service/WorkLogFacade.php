<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Exception\ApiException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * NH-03 / suite flange F19 (producer half) — `check:work-log` read facade.
 *
 * AZC pulls visit-time segments and turns them into DRAFT stamps the
 * worker confirms (BetrVG boundary: never a covert stamp, never auto-
 * counted). Pull-model like F11: CareCheck exposes, the suite consumes.
 * Fields are allow-listed — actor ref, timestamps, visit ref only. No
 * client identity, no note, no task data ever crosses this flange.
 */
class WorkLogFacade
{
	public const FACADE_VERSION = 1;

	public function __construct(private IDBConnection $db)
	{
	}

	/**
	 * @return list<array{entry_id:string, actor:string, date:string,
	 *   start_ts:string, end_ts:string, duration_sec:int, source_ref:string}>
	 */
	public function segments(string $from, string $to): array
	{
		foreach (['from' => $from, 'to' => $to] as $k => $d) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
				throw ApiException::validation('Validation failed.', [$k => 'invalid']);
			}
		}
		if ($from > $to) {
			throw ApiException::validation('Validation failed.', ['to' => 'invalid']);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('v.id', 'v.actor_ref', 'v.checkin_ts', 'v.checkout_ts', 'v.duration_sec', 't.date')
			->from('cck_visit', 'v')
			->innerJoin('v', 'cck_tstop', 's', 'v.tstop_id = s.id')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->where($qb->expr()->in('v.status', $qb->createNamedParameter(['done', 'done_corrected'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('v.checkin_ts'))
			->andWhere($qb->expr()->isNotNull('v.checkout_ts'))
			->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($to)))
			->orderBy('t.date', 'ASC');
		$out = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$start = strtotime((string)$row['checkin_ts']);
			$end = strtotime((string)$row['checkout_ts']);
			if ($start === false || $end === false || $end < $start) {
				continue; // implausible timestamps → skip, never fabricate
			}
			$vid = (int)$row['id'];
			$out[] = [
				// deterministic id → consumer-side idempotency key
				'entry_id' => 'cck-wl-' . $vid,
				'actor' => (string)$row['actor_ref'],
				'date' => (string)$row['date'],
				'start_ts' => date(\DateTimeInterface::ATOM, $start),
				'end_ts' => date(\DateTimeInterface::ATOM, $end),
				'duration_sec' => (int)($row['duration_sec'] ?? ($end - $start)),
				'source_ref' => 'visit:' . $vid,
			];
		}
		return $out;
	}
}
