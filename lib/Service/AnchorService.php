<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\Anchor;
use OCA\CareCheck\Db\AnchorMapper;
use OCP\IDBConnection;

/**
 * Daily provider-global anchor: one digest over ALL client chain heads → one
 * external attestation per day regardless of client count (bounded cost).
 * With a configured RFC 3161 TSA the digest is timestamped externally;
 * without one the row is flagged `unanchored` and surfaced to admins —
 * never silently unanchored.
 */
class AnchorService
{
	public function __construct(
		private IDBConnection $db,
		private AnchorMapper $anchors,
		private JournalService $journal,
		private SettingsService $settings,
	) {
	}

	public function anchorDay(?\DateTime $day = null): Anchor
	{
		$day ??= new \DateTime('yesterday');
		$dateStr = $day->format('Y-m-d');
		$heads = $this->journal->clientHeadHashes();
		ksort($heads);
		$digest = hash('sha256', json_encode($heads, JSON_THROW_ON_ERROR));

		$a = new Anchor();
		$a->setDate($day);
		$a->setHeadsDigest($digest);
		$a->setStatus('unanchored');
		$a->setCreatedAt(new \DateTime());

		$tsaUrl = $this->settings->get('tsa_url');
		if ($tsaUrl !== '') {
			$token = $this->requestTimestamp($tsaUrl, $digest);
			if ($token !== null) {
				$a->setAnchorToken(base64_encode($token));
				$a->setStatus('anchored');
			}
		}
		return $this->anchors->insert($a);
	}

	/** RFC 3161 timestamp request → raw TSR token, or null when the TSA is unreachable. */
	private function requestTimestamp(string $tsaUrl, string $digest): ?string
	{
		$tsq = $this->buildTsq($digest);
		$ctx = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => "Content-Type: application/timestamp-query\r\n",
			'content' => $tsq,
			'timeout' => 10,
		]]);
		$resp = @file_get_contents($tsaUrl, false, $ctx);
		return $resp === false || $resp === '' ? null : $resp;
	}

	/** Minimal DER-encoded TimeStampReq (SHA-256, certReq=true). */
	private function buildTsq(string $hexDigest): string
	{
		$digest = hex2bin($hexDigest);
		if ($digest === false || strlen($digest) !== 32) {
			throw new \RuntimeException('invalid SHA-256 digest');
		}
		// SEQUENCE { INTEGER 1, SEQUENCE { SEQUENCE { OID sha256 }, OCTET STRING digest }, TRUE }
		$oid = "\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01";
		$algId = "\x30\x0b" . $oid . "\x05\x00";
		$msgImprint = "\x30" . pack('C', strlen($algId) + strlen($digest) + 4) . $algId . "\x04" . pack('C', strlen($digest)) . $digest;
		$body = "\x02\x01\x01" . $msgImprint . "\x01\x01\xff";
		return "\x30" . pack('C', strlen($body)) . $body;
	}

	public function latestStatus(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('cck_anchor')->orderBy('date', 'DESC')->setMaxResults(1);
		$row = $qb->executeQuery()->fetch();
		return $row === false ? ['status' => 'never'] : ['date' => $row['date'], 'status' => $row['status']];
	}
}
