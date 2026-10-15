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
}
