<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use OCA\CareCheck\Constants;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\ExportJob;
use OCA\CareCheck\Db\ExportJobMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;

/**
 * Billing-adjacent exports (US-207). Hard rules:
 * - allow-list of billing fields ONLY — no clinical notes, no free text (NN-02)
 * - CSV formula-injection escaping on every cell (family rule)
 * - every export is journaled + access-audited
 * - deterministic rows: same scope+period → byte-identical content hash
 * - unsigned visits marked UNSIGNED — never fabricated, never hidden
 */
class ExportService
{
	/** The complete export allow-list. Nothing else leaves the system. */
	private const CSV_FIELDS = ['date', 'checkin', 'checkout', 'duration_min', 'task_codes', 'task_done', 'task_total', 'signature_state'];

	public function __construct(
		private IDBConnection $db,
		private ClientMapper $clients,
		private ExportJobMapper $exports,
		private ClientService $clientService,
		private AclService $acl,
		private JournalService $journal,
	) {
	}

	/**
	 * Monthly Leistungsnachweis CSV for one client.
	 * @return array{filename:string,mime:string,content:string}
	 */
	public function monthlyCsv(string $actorRef, int $clientId, string $period): array
	{
		$this->acl->assertBuchhaltungOrStaff($actorRef);
		if (!self::validPeriod($period)) {
			throw ApiException::validation('Validation failed.', ['period' => 'invalid']);
		}
		$client = $this->clients->find($clientId);
		if ($client === null) {
			throw ApiException::notFound();
		}

		$rows = $this->visitRows($clientId, $period . '-01', (new \DateTime($period . '-01'))->format('Y-m-t'));
		$csv = $this->toCsv($rows);
		$hash = hash('sha256', $csv);

		$this->record('monthly_csv', $actorRef, $clientId, $period, $hash);
		return [
			'filename' => 'leistungsnachweis-' . $clientId . '-' . $period . '.csv',
			'mime' => 'text/csv; charset=utf-8',
			'content' => $csv,
		];
	}

	/**
	 * Monthly printable PDF for countersignature — same allow-listed rows,
	 * unsigned visits marked UNSIGNED.
	 * @return array{filename:string,mime:string,content:string}
	 */
	public function monthlyPdf(string $actorRef, int $clientId, string $period): array
	{
		$this->acl->assertBuchhaltungOrStaff($actorRef);
		if (!self::validPeriod($period)) {
			throw ApiException::validation('Validation failed.', ['period' => 'invalid']);
		}
		$client = $this->clients->find($clientId);
		if ($client === null) {
			throw ApiException::notFound();
		}
		$name = $this->clientService->hydrated($client)['payload']['name'] ?? '';

		$rows = $this->visitRows($clientId, $period . '-01', (new \DateTime($period . '-01'))->format('Y-m-t'));
		$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$trs = '';
		foreach ($rows as $r) {
			$trs .= '<tr><td>' . $e($r['date']) . '</td><td>' . $e($r['checkin']) . '</td><td>' . $e($r['checkout'])
				. '</td><td>' . $e($r['duration_min']) . '</td><td>' . $e($r['task_codes']) . '</td>'
				. '<td>' . $e($r['task_done'] . '/' . $r['task_total']) . '</td><td>' . $e($r['signature_state']) . '</td></tr>';
		}
		$html = '<!doctype html><html><head><meta charset="utf-8"><style>
			body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111}
			h1{font-size:16px} table{border-collapse:collapse;width:100%}
			th,td{border:1px solid #999;padding:4px 6px;text-align:left}
			th{background:#eee} .foot{margin-top:24px;font-size:9px;color:#555}
			.sig{margin-top:40px;border-top:1px solid #333;padding-top:4px;width:45%;display:inline-block}
		</style></head><body>'
			. '<h1>Leistungsnachweis / Service record — ' . $e($name) . ' — ' . $e($period) . '</h1>'
			. '<table><thead><tr><th>Datum / Date</th><th>Beginn / Start</th><th>Ende / End</th><th>Min.</th>'
			. '<th>Leistungen / Codes</th><th>Aufgaben / Tasks</th><th>Signatur / Signature</th></tr></thead><tbody>'
			. $trs . '</tbody></table>'
			. '<div class="sig">Ort, Datum / Place, date</div><div class="sig" style="float:right">Unterschrift / Signature</div>'
			. '<p class="foot">CareCheck export — dokumentierte Einsätze. / documented visits.</p>'
			. '</body></html>';

		$options = new Options();
		$options->set('isRemoteEnabled', false);
		$options->set('defaultFont', 'DejaVu Sans');
		$dompdf = new Dompdf($options);
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper('A4', 'landscape');
		$dompdf->render();
		$pdf = $dompdf->output();
		if (!is_string($pdf) || $pdf === '') {
			throw new \RuntimeException('pdf_empty');
		}
		$this->record('monthly_pdf', $actorRef, $clientId, $period, hash('sha256', $pdf));
		return ['filename' => 'leistungsnachweis-' . $clientId . '-' . $period . '.pdf', 'mime' => 'application/pdf', 'content' => $pdf];
	}

	/**
	 * Per-visit PDF report — the signed proof for one visit.
	 * @return array{filename:string,mime:string,content:string}
	 */
	public function visitPdf(string $actorRef, int $visitId): array
	{
		// uniform gate first: non-staff get FORBIDDEN for every id —
		// running find() first would make missing-vs-existing distinguishable.
		$this->acl->assertBuchhaltungOrStaff($actorRef);
		$visit = $this->visitRow($visitId);
		if ($visit === null) {
			throw ApiException::notFound();
		}
		$clientId = (int)$visit['client_id'];
		$client = $this->clients->find($clientId);
		$name = $client !== null ? ($this->clientService->hydrated($client)['payload']['name'] ?? '') : '';

		$tasks = $this->taskRows($visitId);
		$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$trs = '';
		foreach ($tasks as $t) {
			$trs .= '<tr><td>' . $e($t['code']) . '</td><td>' . $e($t['status']) . '</td><td>' . $e($t['reason']) . '</td></tr>';
		}
		$sig = (string)$visit['sig_state'];
		$sigLabel = $sig === 'none' ? 'UNSIGNED' : strtoupper($sig);
		$html = '<!doctype html><html><head><meta charset="utf-8"><style>
			body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#111}
			h1{font-size:16px} table{border-collapse:collapse;width:100%}
			th,td{border:1px solid #999;padding:5px 7px;text-align:left} th{background:#eee}
		</style></head><body>'
			. '<h1>Besuchsbericht / Visit report — ' . $e($name) . '</h1>'
			. '<p>' . $e((string)$visit['checkin_ts']) . ' → ' . $e((string)$visit['checkout_ts'])
			. ' · Signatur / Signature: <strong>' . $e($sigLabel) . '</strong>'
			. ($visit['signer_name'] !== null ? ' (' . $e((string)$visit['signer_name']) . ')' : '') . '</p>'
			. '<table><thead><tr><th>Aufgabe / Task</th><th>Status</th><th>Grund / Reason</th></tr></thead><tbody>'
			. $trs . '</tbody></table></body></html>';

		$options = new Options();
		$options->set('isRemoteEnabled', false);
		$options->set('defaultFont', 'DejaVu Sans');
		$dompdf = new Dompdf($options);
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper('A4', 'portrait');
		$dompdf->render();
		$pdf = $dompdf->output();
		if (!is_string($pdf) || $pdf === '') {
			throw new \RuntimeException('pdf_empty');
		}
		$this->record('visit_pdf', $actorRef, $clientId, 'visit:' . $visitId, hash('sha256', $pdf));
		return ['filename' => 'visit-' . $visitId . '.pdf', 'mime' => 'application/pdf', 'content' => $pdf];
	}

	/**
	 * NH-07: generic structured billing interchange (JSON or XML) for
	 * Abrechnungsdienstleister / billing software intake. Same allow-list as
	 * CSV — no clinical data. Deliberately NOT a claims format (NN-06):
	 * this feeds the partner's import, it is never a Kassen claim record.
	 * @return array{filename:string,mime:string,content:string}
	 */
	public function structuredBilling(string $actorRef, int $clientId, string $period, string $fmt): array
	{
		$this->acl->assertBuchhaltungOrStaff($actorRef);
		if (!self::validPeriod($period)) {
			throw ApiException::validation('Validation failed.', ['period' => 'invalid']);
		}
		if ($fmt !== 'json' && $fmt !== 'xml') {
			throw ApiException::validation('Validation failed.', ['format' => 'invalid']);
		}
		$client = $this->clients->find($clientId);
		if ($client === null) {
			throw ApiException::notFound();
		}
		$name = $this->clientService->hydrated($client)['payload']['name'] ?? '';
		$rows = $this->visitRows($clientId, $period . '-01', (new \DateTime($period . '-01'))->format('Y-m-t'));

		$doc = [
			'format' => 'cck-leistungsnachweis',
			'version' => 1,
			'client' => ['id' => $clientId, 'name' => $name],
			'period' => $period,
			'visits' => $rows,
		];

		if ($fmt === 'json') {
			$content = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
			$mime = 'application/json; charset=utf-8';
			$ext = 'json';
		} else {
			$content = $this->toXml($doc);
			$mime = 'application/xml; charset=utf-8';
			$ext = 'xml';
		}
		$this->record('billing_' . $fmt, $actorRef, $clientId, $period, hash('sha256', $content));
		return [
			'filename' => 'leistungsnachweis-' . $clientId . '-' . $period . '.' . $ext,
			'mime' => $mime,
			'content' => $content,
		];
	}

	/** Deterministic XML serialization of the billing doc — escaped, stable order. */
	private function toXml(array $doc): string
	{
		$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<leistungsnachweis format="' . $e((string)$doc['format']) . '" version="1">' . "\n"
			. '  <client id="' . (int)$doc['client']['id'] . '" name="' . $e((string)$doc['client']['name']) . '"/>' . "\n"
			. '  <period>' . $e((string)$doc['period']) . '</period>' . "\n"
			. '  <visits>' . "\n";
		foreach ($doc['visits'] as $r) {
			$out .= '    <visit';
			foreach (self::CSV_FIELDS as $k) {
				$out .= ' ' . $k . '="' . $e((string)($r[$k] ?? '')) . '"';
			}
			$out .= '/>' . "\n";
		}
		return $out . '  </visits>' . "\n" . '</leistungsnachweis>' . "\n";
	}

	// ---- internals ----

	/** @return list<array<string,string>> deterministic order: date, then visit id */
	/** YYYY-MM with a real month — \d{4}-\d{2} alone accepts "2026-99". */
	private static function validPeriod(string $period): bool
	{
		return preg_match('/^(\d{4})-(\d{2})$/', $period, $m) === 1
			&& checkdate((int)$m[2], 1, (int)$m[1]);
	}

	private function visitRows(int $clientId, string $from, string $to): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'checkin_ts', 'checkout_ts', 'duration_sec', 'sig_state')
			->from('cck_visit')
			->where($qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['done', 'done_corrected'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('checkin_ts', $qb->createNamedParameter($from . ' 00:00:00')))
			->andWhere($qb->expr()->lte('checkin_ts', $qb->createNamedParameter($to . ' 23:59:59')))
			->orderBy('checkin_ts', 'ASC')->addOrderBy('id', 'ASC');
		$rows = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			$tasks = $this->taskRows((int)$r['id']);
			$done = 0;
			$codes = [];
			foreach ($tasks as $t) {
				$codes[] = $t['code'];
				if ($t['status'] === 'done') {
					$done++;
				}
			}
			$sig = (string)$r['sig_state'];
			$rows[] = [
				'date' => substr((string)$r['checkin_ts'], 0, 10),
				'checkin' => substr((string)$r['checkin_ts'], 11, 5),
				'checkout' => substr((string)$r['checkout_ts'], 11, 5),
				'duration_min' => $r['duration_sec'] !== null ? (string)intdiv((int)$r['duration_sec'], 60) : '',
				'task_codes' => implode('|', $codes),
				'task_done' => (string)$done,
				'task_total' => (string)count($tasks),
				'signature_state' => $sig === 'none' ? 'UNSIGNED' : strtoupper($sig),
			];
		}
		return $rows;
	}

	/** @return list<array{code:string,status:string,reason:string}> */
	private function taskRows(int $visitId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('task_code', 'status', 'reason_code')->from('cck_vtask')
			->where($qb->expr()->eq('visit_id', $qb->createNamedParameter($visitId)))
			->orderBy('id', 'ASC');
		$out = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			$out[] = ['code' => (string)$r['task_code'], 'status' => (string)$r['status'], 'reason' => (string)($r['reason_code'] ?? '')];
		}
		return $out;
	}

	/** @return array<string,mixed>|null */
	private function visitRow(int $visitId): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('cck_visit')->where($qb->expr()->eq('id', $qb->createNamedParameter($visitId)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	/** RFC-4180 CSV with formula-injection escaping on every cell. */
	private function toCsv(array $rows): string
	{
		$out = fopen('php://temp', 'r+');
		fputcsv($out, self::CSV_FIELDS, ';', '"', '');
		foreach ($rows as $r) {
			fputcsv($out, array_map(fn (string $k) => $this->csvSafe($r[$k] ?? ''), self::CSV_FIELDS), ';', '"', '');
		}
		rewind($out);
		return (string)stream_get_contents($out);
	}

	/** Prefix a literal ' when a cell could be interpreted as a formula. */
	private function csvSafe(string $v): string
	{
		if ($v !== '' && preg_match('/^[=+\-@\x09\x0D\x0B]/', $v) === 1) {
			return "'" . $v;
		}
		return $v;
	}

	/** Every export is itself journaled + access-audited (US-207 AC-3, MH-10). */
	private function record(string $kind, string $actorRef, int $clientId, string $scope, string $hash): void
	{
		$this->db->beginTransaction();
		try {
			$e = new ExportJob();
			$e->setKind($kind);
			$e->setScopeJson(json_encode(['client' => $clientId, 'scope' => $scope], JSON_UNESCAPED_UNICODE));
			$e->setActorRef($actorRef);
			$e->setHash($hash);
			$e->setCreatedAt(new \DateTime());
			$this->exports->insert($e);
			$this->journal->append($clientId, 'export', $actorRef, ['kind' => $kind, 'scope' => $scope, 'hash' => $hash]);
			$this->db->commit();
		} catch (\Throwable $t) {
			$this->db->rollBack();
			throw $t;
		}
		$this->acl->audit($actorRef, 'export', $clientId, $kind . ':' . $scope);
	}
}
