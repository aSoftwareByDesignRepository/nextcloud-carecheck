<?php

declare(strict_types=1);

/**
 * CareCheck wiring for the shared transfer engine.
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (AclService::assertAppAdmin — system admin, carecheck-admin group,
 *    or a named uid in the app_admins list, DS-2).
 *  - validateValue/normalizeValue delegate to the SAME normaliser the
 *    settings API uses — SettingsService::normalizeForWrite, which
 *    ApiController::saveSettings also runs (validator parity, NN-08).
 *  - Access door is app-local appconfig (no platform restriction mirror)
 *    → no mirrored keys; writeMirrored stays a NO_MIRRORED_KEYS guard.
 *  - createSafetySnapshot delegates to UpgradeBackupService for the
 *    mandatory pre-replace snapshot (FS-2).
 */

namespace OCA\CareCheck\ConfigTransfer;

use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\UpgradeBackupService;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	public function __construct(
		private readonly AclService $acl,
		private readonly UpgradeBackupService $backups,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		$this->acl->assertAppAdmin($uid);
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	private function normalize(string $key, string $value): string
	{
		try {
			return SettingsService::normalizeForWrite($key, $value);
		} catch (\InvalidArgumentException $e) {
			if ($e->getMessage() !== 'UNKNOWN_SETTING') {
				throw $e;
			}
			// Not a settings key — size guard only.
			return strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG');
		}
	}

	public function validateMirrored(array $keyValues): ?string
	{
		return $keyValues === [] ? null : 'NO_MIRRORED_KEYS';
	}

	public function writeMirrored(array $keyValues): void
	{
		if ($keyValues !== []) {
			throw new \InvalidArgumentException('NO_MIRRORED_KEYS');
		}
	}

	public function securityRelevantKeys(): array
	{
		return [
			'access_mode', 'allowed_users', 'allowed_groups', 'app_admins',
			'gps_enabled', 'retention_years', 'breakglass_hours_max',
			'pin_min_len', 'wipe_attempts', 'pair_code_ttl_min',
			'search_rate_per_min', 'device_rate_per_min',
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			// No data to back up yet (fresh instance) — not an error, but no
			// snapshot id either; the apply must not be silently unprotected
			// when tables DO exist, so rethrow real failures on non-empty DBs.
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match (true) {
			in_array($key, ['access_mode', 'allowed_users', 'allowed_groups', 'app_admins'], true) => 'access',
			in_array($key, ['seat_limit', 'pin_min_len', 'wipe_attempts', 'pair_code_ttl_min',
				'search_rate_per_min', 'device_rate_per_min', 'min_companion_version', 'tsa_url'], true) => 'companion',
			default => 'general',
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		try {
			// cck_audit.detail is varchar(190) — cap the serialised context.
			$this->acl->audit(
				'uid:' . ($actor === '' ? 'cli' : $actor),
				'config_transfer_' . $action,
				null,
				mb_substr(
					(string)json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
					0,
					190,
				),
			);
		} catch (\Throwable $e) {
			// audit must never fail the transfer itself
			$this->logger->warning('carecheck: config transfer audit event failed', [
				'app' => 'carecheck',
				'action' => $action,
				'exception' => $e,
			]);
		}
	}
}
