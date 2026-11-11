<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCP\IAppConfig;

class SettingsService
{
	public const DEFAULTS = [
		'seat_limit' => 10,
		'gps_enabled' => 0,
		'pin_min_len' => 4,
		'wipe_attempts' => 10,
		'breakglass_hours_max' => 24,
		'sla_minutes' => 30,
		'horizon_days' => 7,
		'retention_years' => 10,
		'pair_code_ttl_min' => 15,
		'search_rate_per_min' => 20,
		'device_rate_per_min' => 120,
		'provider_tz' => 'Europe/Berlin',
		'min_companion_version' => '0.1.0',
		'tsa_url' => '',
		// DS-2 access door: 'open' = every NC user, 'restricted' = allow-list below
		'access_mode' => 'open',
		// Tageszeit presets (§7.2) — "HHMM-HHMM" minute ranges the dispatcher picks by name.
		'preset_early' => '360-540',
		'preset_morning' => '540-720',
		'preset_noon' => '720-840',
		'preset_afternoon' => '840-1020',
		'preset_evening' => '1020-1200',
	];

	/** Keys whose values are JSON lists (allowed_users/groups, app_admins). */
	public const LIST_KEYS = ['allowed_users', 'allowed_groups', 'app_admins'];

	/** @return list<string> */
	public function getList(string $key): array
	{
		$raw = $this->config->getValueString(Constants::APP_ID, $key, '[]');
		$v = json_decode($raw, true);
		return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
	}

	/** @param list<string> $values */
	public function setList(string $key, array $values): void
	{
		$this->config->setValueString(Constants::APP_ID, $key, json_encode(array_values($values), JSON_THROW_ON_ERROR));
	}

	/** Cron liveness: jobs touch their key on every run; the board flags starvation. */
	public function touchCron(string $job): void
	{
		$this->config->setValueString(Constants::APP_ID, 'cron_last_' . $job, (string)time());
	}

	/** Unix ts of the job's last run, or 0 when it never ran. */
	public function cronLastRun(string $job): int
	{
		return (int)$this->config->getValueString(Constants::APP_ID, 'cron_last_' . $job, '0');
	}


	public function __construct(private IAppConfig $config)
	{
	}

	public function get(string $key): string
	{
		return $this->config->getValueString(Constants::APP_ID, $key, (string)(self::DEFAULTS[$key] ?? ''));
	}

	public function getInt(string $key): int
	{
		$v = $this->config->getValueString(Constants::APP_ID, $key, '');
		return $v === '' ? (int)self::DEFAULTS[$key] : max(0, (int)$v);
	}

	public function getBool(string $key): bool
	{
		return $this->getInt($key) === 1;
	}

	public function set(string $key, string $value): void
	{
		$this->config->setValueString(Constants::APP_ID, $key, $value);
	}

	/**
	 * Single source of truth for "what the settings API would persist".
	 * Used by ApiController::saveSettings AND the config-transfer import
	 * pipeline (NN-08 — a file must never bypass form validation; the
	 * stored value must be byte-identical to a form write).
	 *
	 * Returns the canonical storable string; throws InvalidArgumentException
	 * for values the settings form would reject, UNKNOWN_SETTING for keys
	 * that are not a settings key at all.
	 *
	 * Static on purpose: validation rules carry no instance state, and the
	 * real rules must execute even when SettingsService is doubled in tests
	 * (a stubbed normaliser would silently weaken the form contract).
	 *
	 * List keys normalise to a de-duplicated plain-id JSON list (typed
	 * 'uid:x'/'group:g' picker tokens are stripped, matching saveSettings);
	 * existence of the ids themselves is the caller's job — the form checks
	 * userExists/groupExists, the transfer engine resolves `refs`.
	 */
	public static function normalizeForWrite(string $key, string $value): string
	{
		if (in_array($key, self::LIST_KEYS, true)) {
			return self::normalizeIdList($value);
		}
		if (!array_key_exists($key, self::DEFAULTS)) {
			throw new \InvalidArgumentException('UNKNOWN_SETTING');
		}
		if (str_starts_with($key, 'preset_')) {
			// "HHMM-HHMM" minute ranges — a typo must not silently break the
			// dispatcher's Tageszeit presets.
			if (preg_match('/^\d{1,4}-\d{1,4}$/', $value) === 1) {
				return $value;
			}
			throw new \InvalidArgumentException('INVALID_VALUE');
		}
		if ($key === 'provider_tz') {
			if (in_array($value, \DateTimeZone::listIdentifiers(), true)) {
				return $value;
			}
			throw new \InvalidArgumentException('INVALID_VALUE');
		}
		if ($key === 'access_mode') {
			if (in_array($value, ['open', 'restricted'], true)) {
				return $value;
			}
			throw new \InvalidArgumentException('INVALID_VALUE');
		}
		// Fail closed on type mismatch — silent coercion hides typos
		// ("true" → 0 = OFF is a real bug class, see form-bool rule).
		if ($key === 'gps_enabled') {
			$low = strtolower($value);
			if (in_array($low, ['1', '0', 'true', 'false', 'on', 'off'], true)) {
				return in_array($low, ['1', 'true', 'on'], true) ? '1' : '0';
			}
			throw new \InvalidArgumentException('INVALID_VALUE');
		}
		if (is_int(self::DEFAULTS[$key])) {
			if (preg_match('/^\d+$/', $value) === 1) {
				return $value;
			}
			throw new \InvalidArgumentException('INVALID_VALUE');
		}
		if ($key === 'min_companion_version') {
			if (preg_match('/^\d+\.\d+\.\d+$/', $value) === 1) {
				return $value;
			}
			throw new \InvalidArgumentException('INVALID_VALUE');
		}
		// Remaining free-form defaults (tsa_url).
		return $value;
	}

	/**
	 * Canonical storable form for a list key: JSON array of non-empty plain
	 * ids, picker 'uid:'/'group:' prefixes stripped, de-duplicated.
	 *
	 * @throws \JsonException|\InvalidArgumentException malformed payload
	 */
	private static function normalizeIdList(string $value): string
	{
		$list = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$clean = [];
		foreach ($list as $id) {
			if (!is_string($id)) {
				throw new \InvalidArgumentException('INVALID_ID_LIST');
			}
			$id = preg_replace('/^(uid|group):/', '', $id) ?? '';
			if ($id === '' || str_contains($id, ':')) {
				throw new \InvalidArgumentException('INVALID_ID_LIST');
			}
			$clean[] = $id;
		}
		return json_encode(array_values(array_unique($clean)), JSON_THROW_ON_ERROR);
	}
}
