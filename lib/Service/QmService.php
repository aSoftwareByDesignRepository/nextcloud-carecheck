<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Exception\ApiException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * NH-17 QM dashboard — Expertenstandards-ready quality metrics computed
 * on existing rows (no extra write path, no double bookkeeping):
 *   - incidents per client in the period (notices kind='incident')
 *   - overdue rate (stops whose window ended while still pending)
 *   - unsigned-visit rate (done visits without a captured signature)
 *   - review-queue depth (rejected bundles awaiting correction)
 *
 * Staff-only surface. Per-client incident counts are a privacy leg — the
 * endpoint stays Zentrale-side (assertStaff), never companion-exposed.
 *
 * NH-18 lives here too: `vitals()` returns per-task numeric time series
 * for one client (value fields are encrypted — decryption stays inside
 * CryptoService, only numbers cross).
 */
class QmService
{
	public function __construct(
		private IDBConnection $db,
		private CryptoService $crypto,
		private AclService $acl,
		private TourService $tours,
	) {
	}

	private function assertRange(string &$from, string &$to): void
	{
		foreach (['from' => $from, 'to' => $to] as $k => $d) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
				throw ApiException::validation('Validation failed.', [$k => 'invalid']);
			}
		}
		if ($from > $to) {
			throw ApiException::validation('Validation failed.', ['to' => 'invalid']);
		}
	}

	/** @return array<string,mixed> */
	public function summary(string $actorRef, string $from, string $to): array
	{
		$this->acl->assertStaff($actorRef);
		$this->assertRange($from, $to);

		// --- visits by status ---
		$qb = $this->db->getQueryBuilder();
		$qb->select('v.status', $qb->func()->count('v.id', 'n'))
			->from('cck_visit', 'v')
			->innerJoin('v', 'cck_tstop', 's', 'v.tstop_id = s.id')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->where($qb->expr()->gte('t.date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($to)))
			->groupBy('v.status');
		$visitsByStatus = [];
		$doneTotal = 0;
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			$visitsByStatus[(string)$r['status']] = (int)$r['n'];
			if (in_array($r['status'], ['done', 'done_corrected'], true)) {
				$doneTotal += (int)$r['n'];
			}
		}

		// --- unsigned rate among completed visits ---
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('v.id', 'n'))
			->from('cck_visit', 'v')
			->innerJoin('v', 'cck_tstop', 's', 'v.tstop_id = s.id')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->where($qb->expr()->in('v.status', $qb->createNamedParameter(['done', 'done_corrected'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('v.sig_state', $qb->createNamedParameter('none')))
			->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($to)));
		$unsigned = (int)$qb->executeQuery()->fetchOne();

		// --- overdue rate: stops whose window already ended, still pending ---
		$tz = new \DateTimeZone($this->tours->providerTz());
		$now = new \DateTime('now', $tz);
		$today = $now->format('Y-m-d');
		$minutesNow = (int)$now->format('H') * 60 + (int)$now->format('i');
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.status', 's.win_end', 't.date')
			->from('cck_tstop', 's')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->where($qb->expr()->gte('t.date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($to)));
		$stops = 0;
		$overdue = 0;
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			$stops++;
			if ($r['status'] === 'pending'
				&& ((string)$r['date'] < $today
					|| ((string)$r['date'] === $today && (int)$r['win_end'] < $minutesNow))) {
				$overdue++;
			}
		}

		// --- incidents per client in period ---
		$qb = $this->db->getQueryBuilder();
		$qb->select('client_id', $qb->func()->count('id', 'n'))
			->from('cck_notice')
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('incident')))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($from . ' 00:00:00')))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($to . ' 23:59:59')))
			->groupBy('client_id');
		$incidentsPerClient = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			$incidentsPerClient[(int)$r['client_id']] = (int)$r['n'];
		}

		// --- review queue depth ---
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))
			->from('cck_visit')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('rejected_review')));
		$reviewQueue = (int)$qb->executeQuery()->fetchOne();

		return [
			'from' => $from,
			'to' => $to,
			'visitsByStatus' => $visitsByStatus,
			'doneVisits' => $doneTotal,
			'unsignedRate' => $doneTotal > 0 ? round($unsigned / $doneTotal, 4) : null,
			'stops' => $stops,
			'overdueStops' => $overdue,
			'overdueRate' => $stops > 0 ? round($overdue / $stops, 4) : null,
			'incidentsPerClient' => $incidentsPerClient,
			'reviewQueue' => $reviewQueue,
		];
	}

	/**
	 * NH-18 — per-task numeric time series for one client. Only completed
	 * visits count; non-numeric values are skipped (vitals are numbers —
	 * BP pairs arrive as {"sys":x,"dia":y} and split into two series).
	 * @return array<string, list<array{ts:string, v:float}>>
	 */
	public function vitals(string $actorRef, int $clientId, ?string $taskCode = null): array
	{
		$this->acl->assertClientScope($actorRef, $clientId);
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.task_code', 't.value_enc', 'v.checkin_ts')
			->from('cck_vtask', 't')
			->innerJoin('t', 'cck_visit', 'v', 't.visit_id = v.id')
			->where($qb->expr()->eq('v.client_id', $qb->createNamedParameter($clientId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('v.status', $qb->createNamedParameter(['done', 'done_corrected'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('t.value_enc'))
			->orderBy('v.checkin_ts', 'ASC');
		if ($taskCode !== null && $taskCode !== '') {
			if (preg_match('/^[a-z0-9_\-]{1,48}$/', $taskCode) !== 1) {
				throw ApiException::validation('Validation failed.', ['task' => 'invalid']);
			}
			$qb->andWhere($qb->expr()->eq('t.task_code', $qb->createNamedParameter($taskCode)));
		}
		$series = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			$raw = $this->crypto->decrypt((string)$r['value_enc']);
			$val = json_decode($raw, true);
			$ts = (string)$r['checkin_ts'];
			$code = (string)$r['task_code'];
			if (is_int($val) || is_float($val) || (is_string($val) && is_numeric($val))) {
				$series[$code][] = ['ts' => $ts, 'v' => (float)$val];
			} elseif (is_array($val)) {
				// structured vitals (e.g. BP {sys,dia}) → sub-series per key
				foreach ($val as $k => $v) {
					if (is_int($v) || is_float($v)) {
						$series[$code . '.' . $k][] = ['ts' => $ts, 'v' => (float)$v];
					}
				}
			}
		}
		return $series;
	}
}
