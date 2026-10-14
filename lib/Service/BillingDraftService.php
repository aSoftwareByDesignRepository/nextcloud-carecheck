<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Exception\ApiException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * NH-21 / suite flange F13 (producer half) — `check:billing-drafts` read
 * facade. InvoiceCheck pulls draft line candidates for pauschal /
 * § 45b / private / SGB XII billing; drafts never auto-finalize and
 * generic text is allow-listed — no client name or health field ever
 * crosses into invoice copy (F13 contract).
 */
class BillingDraftService
{
	/** Allow-listed line text — the only strings that may cross the flange. */
	public const LINE_TEXTS = [
		'haeusliche_betreuung' => 'Häusliche Betreuung (Pauschale)',
		'entlastungsbetrag' => 'Entlastungsbetrag § 45b SGB XI',
		'pflege_einsatz' => 'Pflegeeinsatz',
		'privat_leistung' => 'Private Zusatzleistung',
		'sgb_xii' => 'Hilfe zur Pflege SGB XII',
	];

	public function __construct(private IDBConnection $db)
	{
	}

	/**
	 * One draft per client per billing `kind` for the period, built from
	 * signed (done/done_corrected) visits. `external_ref` is the client's
	 * primary_ref when set — the consumer maps it to its own customer id.
	 * @return list<array{client_id:int, external_ref:?string, visits:int, lines:list<array{key:string,text:string,qty:int,unit:string}>}>
	 */
	public function drafts(string $from, string $to, string $kind): array
	{
		foreach (['from' => $from, 'to' => $to] as $k => $d) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
				throw ApiException::validation('Validation failed.', [$k => 'invalid']);
			}
		}
		if ($from > $to || !isset(self::LINE_TEXTS[$kind])) {
			throw ApiException::validation('Validation failed.', $from > $to ? ['to' => 'invalid'] : ['kind' => 'invalid']);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('v.client_id', 'c.primary_ref', $qb->func()->count('v.id', 'visits'))
			->from('cck_visit', 'v')
			->innerJoin('v', 'cck_tstop', 's', 'v.tstop_id = s.id')
			->innerJoin('s', 'cck_tour', 't', 's.tour_id = t.id')
			->innerJoin('v', 'cck_client', 'c', 'v.client_id = c.id')
			->where($qb->expr()->in('v.status', $qb->createNamedParameter(['done', 'done_corrected'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($to)))
			->andWhere($qb->expr()->eq('c.active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->groupBy('v.client_id', 'c.primary_ref');
		$out = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $row) {
			$qty = (int)$row['visits'];
			$out[] = [
				'client_id' => (int)$row['client_id'],
				'external_ref' => $row['primary_ref'] !== null ? (string)$row['primary_ref'] : null,
				'visits' => $qty,
				'lines' => [[
					'key' => $kind,
					'text' => self::LINE_TEXTS[$kind],
					'qty' => $qty,
					'unit' => 'visits',
				]],
			];
		}
		return $out;
	}
}
