<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Exception\ApiException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * NH-20 — client CSV import (Klientenstamm onboarding for switchers).
 *
 * Two-phase: `preview()` parses + dedup-checks without writing;
 * `commit()` re-parses and re-validates everything server-side (preview
 * output is never trusted as commit input). Duplicates are detected on
 * normalized name + street against ALL existing clients — decryption
 * stays inside CryptoService like every other client read.
 *
 * Defensive: delimiter autodetect (; or ,), UTF-8, header aliases in
 * de+en, row cap, per-field length caps, unknown-header tolerance.
 */
class ClientImportService
{
	public const MAX_ROWS = 2000;
	private const HEADER_ALIASES = [
		'name' => ['name', 'klient', 'klientin', 'klientname', 'client', 'nachname'],
		'street' => ['street', 'strasse', 'straße', 'adresse', 'address'],
		'zip' => ['zip', 'plz', 'postcode', 'postal'],
		'city' => ['city', 'stadt', 'ort', 'wohnort'],
		'accessNote' => ['accessnote', 'zugang', 'zugangshinweis', 'access', 'hinweis'],
		'phone' => ['phone', 'telefon', 'tel', 'kontakt'],
	];

	public function __construct(
		private IDBConnection $db,
		private ClientService $clients,
		private CryptoService $crypto,
		private AclService $acl,
	) {
	}

	/**
	 * Parse + annotate a CSV. Returns rows with status ok|duplicate|error.
	 * @return array{rows:list<array{row:int,name:string,street:string,zip:string,city:string,accessNote:string,status:string,error:?string}>, error:?string}
	 */
	public function preview(string $actorRef, string $csv): array
	{
		$this->acl->assertStaff($actorRef);
		$parsed = $this->parse($csv);
		if (isset($parsed['error'])) {
			return ['rows' => [], 'error' => $parsed['error']];
		}
		$existing = $this->existingKeys();
		$out = [];
		foreach ($parsed['rows'] as $i => $r) {
			$row = ['row' => $i + 2 /* +1 header, +1 one-based */,
				'name' => $r['name'], 'street' => $r['street'], 'zip' => $r['zip'],
				'city' => $r['city'], 'accessNote' => $r['accessNote'],
				'status' => 'ok', 'error' => null];
			if (mb_strlen($r['name']) > 190 || mb_strlen($r['street']) > 190
				|| mb_strlen($r['zip']) > 20 || mb_strlen($r['city']) > 100
				|| mb_strlen($r['accessNote']) > 1000) {
				$row['status'] = 'error';
				$row['error'] = 'field_too_long';
			} else {
				$key = $this->dupKey($r['name'], $r['street']);
				if (isset($existing[$key])) {
					$row['status'] = 'duplicate';
				}
			}
			$out[] = $row;
		}
		return ['rows' => $out, 'error' => null];
	}

	/**
	 * Commit import — re-parses the CSV itself (never trusts client rows),
	 * creates all non-duplicate rows, returns per-row outcomes.
	 * @return array{created:int, skipped:int, errors:list<int>}
	 */
	public function commit(string $actorRef, string $csv): array
	{
		$this->acl->assertStaff($actorRef);
		$parsed = $this->parse($csv);
		if (isset($parsed['error'])) {
			throw ApiException::validation('Validation failed.', ['csv' => $parsed['error']]);
		}
		$existing = $this->existingKeys();
		$created = 0;
		$skipped = 0;
		$errors = [];
		foreach ($parsed['rows'] as $i => $r) {
			$rowNo = $i + 2;
			if (mb_strlen($r['name']) > 190 || mb_strlen($r['street']) > 190
				|| mb_strlen($r['zip']) > 20 || mb_strlen($r['city']) > 100) {
				$errors[] = $rowNo;
				continue;
			}
			$key = $this->dupKey($r['name'], $r['street']);
			if (isset($existing[$key])) {
				$skipped++;
				continue;
			}
			$payload = [
				'name' => $r['name'],
				'street' => $r['street'],
				'zip' => $r['zip'],
				'city' => $r['city'],
			];
			if ($r['accessNote'] !== '') {
				$payload['accessNote'] = $r['accessNote'];
			}
			if ($r['phone'] !== '') {
				$payload['contacts'] = [['label' => 'phone', 'value' => $r['phone']]];
			}
			try {
				$this->clients->create($actorRef, $payload);
				$existing[$key] = true;
				$created++;
			} catch (ApiException) {
				$errors[] = $rowNo;
			}
		}
		return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
	}

	/** @return array{rows?:list<array<string,string>>, error?:string} */
	private function parse(string $csv): array
	{
		// strip UTF-8 BOM
		$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
		if (trim($csv) === '') {
			return ['error' => 'empty'];
		}
		$fh = fopen('php://temp', 'r+');
		fwrite($fh, $csv);
		rewind($fh);
		$header = fgetcsv($fh, 0, ';', '"', '');
		$delim = ';';
		if ($header === false || count($header) < 2) {
			rewind($fh);
			$header = fgetcsv($fh, 0, ',', '"', '');
			$delim = ',';
		}
		if ($header === false || count($header) < 1) {
			fclose($fh);
			return ['error' => 'no_header'];
		}
		$map = [];
		foreach ($header as $i => $h) {
			$h = mb_strtolower(trim((string)$h));
			foreach (self::HEADER_ALIASES as $field => $aliases) {
				if (in_array($h, $aliases, true)) {
					$map[$field] = $i;
				}
			}
		}
		if (!isset($map['name'])) {
			fclose($fh);
			return ['error' => 'missing_name_column'];
		}
		$rows = [];
		while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
			if (count($rows) >= self::MAX_ROWS) {
				fclose($fh);
				return ['error' => 'too_many_rows'];
			}
			if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) {
				continue; // blank line
			}
			$get = static fn(string $f): string => isset($map[$f]) ? trim((string)($r[$map[$f]] ?? '')) : '';
			$name = $get('name');
			if ($name === '') {
				continue; // nameless rows are skipped silently in preview/commit
			}
			$rows[] = [
				'name' => $name,
				'street' => $get('street'),
				'zip' => $get('zip'),
				'city' => $get('city'),
				'accessNote' => $get('accessNote'),
				'phone' => $get('phone'),
			];
		}
		fclose($fh);
		if ($rows === []) {
			return ['error' => 'no_rows'];
		}
		return ['rows' => $rows];
	}

	/** @return array<string, true> normalized name+street dup index */
	private function existingKeys(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'payload_enc')->from('cck_client')
			->where($qb->expr()->eq('active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$idx = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			try {
				$p = json_decode($this->crypto->decrypt((string)$r['payload_enc']), true) ?? [];
				$idx[$this->dupKey((string)($p['name'] ?? ''), (string)($p['street'] ?? ''))] = true;
			} catch (\Throwable) {
				// undecryptable row (key rotation edge) — skip, never fail the import
			}
		}
		return $idx;
	}

	private function dupKey(string $name, string $street): string
	{
		return $this->crypto->normalizeName($name) . '|' . $this->crypto->normalizeName($street);
	}
}
