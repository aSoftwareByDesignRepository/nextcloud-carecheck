<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Exception\ApiException;

/**
 * Pure validation for companion write payloads — no DB, no IO. Testable in
 * isolation; the service layer supplies existence checks (client/plan/scope).
 */
final class BundleValidator
{
	/**
	 * @param array<string, mixed> $in
	 */
	public static function idemKey(array $in): string
	{
		$k = (string)($in['idem_key'] ?? '');
		if ($k === '' || strlen($k) > 96) {
			throw ApiException::validation('Validation failed.', ['idem_key' => 'required']);
		}
		return $k;
	}

	/**
	 * @param array<string, mixed> $in
	 */
	public static function strictId(array $in, string $field): ?int
	{
		$raw = $in[$field] ?? null;
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!preg_match('/^\d+$/', (string)$raw)) {
			throw ApiException::validation('Validation failed.', [$field => 'invalid']);
		}
		return (int)$raw;
	}

/**
	 * Validate task rows: status enum + reason_code for refused/not_done.
	 * @param array<string, mixed> $fields
	 */
	public static function tasks(mixed $raw, array &$fields): array
	{
		$tasks = is_array($raw) ? $raw : [];
		foreach ($tasks as $i => $t) {
			$st = (string)($t['status'] ?? '');
			if (!in_array($st, Constants::TASK_STATUSES, true)) {
				$fields["tasks.$i.status"] = 'invalid';
			} elseif (in_array($st, ['refused', 'not_done'], true) && trim((string)($t['reason_code'] ?? '')) === '') {
				$fields["tasks.$i.reason_code"] = 'required';
			} elseif (isset($t['reason_code']) && $t['reason_code'] !== ''
				&& !in_array((string)$t['reason_code'], Constants::REASON_CODES, true)) {
				$fields["tasks.$i.reason_code"] = 'invalid';
			}
		}
		return $tasks;
	}

/**
	 * Signature state/payload/signer consistency + content sniff + size cap.
	 * @param array<string, mixed> $in
	 * @param array<string, mixed> $fields
	 */
	public static function signature(array $in, array &$fields): ?string
	{
		$state = (string)($in['sig_state'] ?? 'none');
		if (!in_array($state, Constants::SIG_STATES, true)) {
			$fields['sig_state'] = 'invalid';
			return null;
		}
		$b64 = (string)($in['signature'] ?? '');
		if (in_array($state, ['signed', 'representative'], true)) {
			if ($b64 === '') {
				$fields['signature'] = 'required';
			} elseif (!self::looksLikeImage($b64)) {
				$fields['signature'] = 'invalid_format';
			}
		}
		if ($state === 'representative' && trim((string)($in['signer_name'] ?? '')) === '') {
			$fields['signer_name'] = 'required';
		}
		return $b64 !== '' ? $b64 : null;
	}

	/** PNG/JPEG magic bytes only — content sniffed, never client-declared. */
	public static function looksLikeImage(string $b64): bool
	{
		$raw = base64_decode($b64, true);
		if ($raw === false || strlen($raw) > Constants::SIG_MAX_BYTES || strlen($raw) < 8) {
			return false;
		}
		return str_starts_with($raw, "\x89PNG\r\n\x1a\n") || str_starts_with($raw, "\xff\xd8\xff");
	}

/**
	 * Client-supplied geo is honored only when the provider toggle is on.
	 * @param array<string, mixed> $in
	 */
	public static function geo(array $in, bool $allowed): ?array
	{
		if (!$allowed || !isset($in['geo']['lat'], $in['geo']['lon'])) {
			return null;
		}
		$lat = (float)$in['geo']['lat'];
		$lon = (float)$in['geo']['lon'];
		if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
			return null; // silently drop malformed coordinates, never trust
		}
		return ['lat' => $lat, 'lon' => $lon];
	}
}
