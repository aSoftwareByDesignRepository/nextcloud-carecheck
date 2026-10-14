<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;

/**
 * NH-08 / suite flange F11 — `check:drive-segments` read facade.
 *
 * MobilityCheck pulls these and turns them into Fahrtenbuch DRAFTS the
 * driver confirms (km/Tachostand are never auto-final). A segment exists
 * only between two consecutive COMPLETED stops on the same tour —
 * departure = check-out of stop N, arrival = check-in of stop N+1.
 * Movement outside the tour (commutes, skipped stops) is never inferred.
 * Fields are allow-listed: no client identity ever crosses the flange.
 */
class DriveSegmentService
{
	/** Generic purpose text — spec-pinned, never client-identifying. */
	public const PURPOSE = 'Dienstfahrt Tour';

	public function __construct(private IDBConnection $db)
	{
	}

	/**
	 * @return list<array{segment_id:string, date:string, executor:string,
	 *   departure_ts:string, arrival_ts:string, generic_purpose:string}>
	 */
	public function segments(string $from, string $to): array
	{
		$this->assertDate($from);
		$this->assertDate($to);
		if ($from > $to) {
			throw ApiException::validation('Validation failed.', ['to' => 'invalid']);
		}

		// Completed visits with their stop's seq + tour, in tour order.
		$qb = $this->db->getQueryBuilder();
		$qb->select('v.id AS visit_id', 'v.checkout_ts', 'v.checkin_ts', 'v.actor_ref AS caregiver_ref',
				's.id AS stop_id', 's.order_hint', 't.id AS tour_id', 't.date')
			->from('cck_visit', 'v')
			->innerJoin('v', 'cck_tstop', 's', 'v.tstop_id = s.id')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			// 'done' + 'done_corrected' only — a rejected_review visit must
			// NEVER mint a drive segment (the Zentrale judged it invalid).
			->where($qb->expr()->in('v.status', $qb->createNamedParameter(['done', 'done_corrected'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('v.checkout_ts'))
			->andWhere($qb->expr()->isNotNull('v.checkin_ts'))
			->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($to)))
			->orderBy('t.id', 'ASC')
			->addOrderBy('s.order_hint', 'ASC');

		$byTour = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$byTour[(int)$row['tour_id']][] = $row;
		}

		$out = [];
		foreach ($byTour as $tourId => $rows) {
			// Consecutive completed legs only — a gap (skipped/incomplete stop)
			// breaks the chain, the driver logs that movement themselves.
			for ($i = 0; $i < count($rows) - 1; $i++) {
				$a = $rows[$i];
				$b = $rows[$i + 1];
				// adjacency in tour order, not just in the filtered list:
				// a skipped/rejected stop between two completions means the
				// caregiver's movement wasn't a clean leg — never emit a
				// segment spanning it (the driver logs that by hand).
				if ((int)$b['order_hint'] !== (int)$a['order_hint'] + 1) {
					continue;
				}
				$dep = strtotime((string)$a['checkout_ts']);
				$arr = strtotime((string)$b['checkin_ts']);
				if ($dep === false || $arr === false || $arr < $dep) {
					continue; // implausible timestamps → skip, never fabricate
				}
				$out[] = [
					// Deterministic id for consumer-side dedupe
					// (F11: idempotency on (source_app, segment_id)).
					'segment_id' => 'cck-' . $tourId . '-' . (int)$a['stop_id'] . '-' . (int)$b['stop_id'],
					'date' => (string)$a['date'],
					'executor' => (string)$a['caregiver_ref'],
					'departure_ts' => date(\DateTimeInterface::ATOM, $dep),
					'arrival_ts' => date(\DateTimeInterface::ATOM, $arr),
					'generic_purpose' => self::PURPOSE,
				];
			}
		}
		return $out;
	}

	private function assertDate(string $d): void
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
			throw ApiException::validation('Validation failed.', ['from' => 'invalid']);
		}
	}
}
