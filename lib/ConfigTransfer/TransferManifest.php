<?php

declare(strict_types=1);

/**
 * CareCheck transfer manifest — every appconfig key and every cck_* table
 * classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * The 'transfer' settings section lives in the SPA settings chip bar —
 * CareCheck has no *SectionCatalog file; sections are enumerated in
 * templates/main.php ($settingsNav + the section foreach) and
 * js/carecheck-main.js (SETTINGS_SECTIONS + admin gating).
 */

namespace OCA\CareCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — app-local appconfig (no platform
			// restriction mirror → 'plain', the atomic write is a plain
			// setValueString; see TransferHooks::writeMirrored guard) ──
			'access_mode' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'allowed_users' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'allowed_groups' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'app_admins' => ['class' => 'plain', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// ── general settings (Settings → General) ──
			'provider_tz' => ['class' => 'plain', 'section' => 'general'],
			// Worker GPS evidence — switching this on changes what the
			// companion collects → security-relevant.
			'gps_enabled' => ['class' => 'plain', 'security' => true, 'section' => 'general'],
			'sla_minutes' => ['class' => 'plain', 'section' => 'general'],
			// GDPR retention window — security-relevant.
			'retention_years' => ['class' => 'plain', 'security' => true, 'section' => 'general'],
			'breakglass_hours_max' => ['class' => 'plain', 'security' => true, 'section' => 'general'],
			'horizon_days' => ['class' => 'plain', 'section' => 'general'],
			'preset_early' => ['class' => 'plain', 'section' => 'general'],
			'preset_morning' => ['class' => 'plain', 'section' => 'general'],
			'preset_noon' => ['class' => 'plain', 'section' => 'general'],
			'preset_afternoon' => ['class' => 'plain', 'section' => 'general'],
			'preset_evening' => ['class' => 'plain', 'section' => 'general'],
			// ── companion policy (Settings → Companion app) — device gate
			// values are security-relevant (PIN/wipe/pairing/rate limits) ──
			'seat_limit' => ['class' => 'plain', 'section' => 'companion'],
			'pin_min_len' => ['class' => 'plain', 'security' => true, 'section' => 'companion'],
			'wipe_attempts' => ['class' => 'plain', 'security' => true, 'section' => 'companion'],
			'pair_code_ttl_min' => ['class' => 'plain', 'security' => true, 'section' => 'companion'],
			'search_rate_per_min' => ['class' => 'plain', 'security' => true, 'section' => 'companion'],
			'device_rate_per_min' => ['class' => 'plain', 'security' => true, 'section' => 'companion'],
			'min_companion_version' => ['class' => 'plain', 'section' => 'companion'],
			// External RFC-3161 timestamp authority endpoint — operator config
			// that is normally identical on test and prod (not the instance's
			// own URL → not 'env'; the engine warns if it leaks a test URL).
			'tsa_url' => ['class' => 'plain', 'section' => 'companion'],
			// ── instance-bound bookkeeping — never transferred ──
			// Install timestamp written by the diagnostics probe.
			'installed_ts' => 'env',
			// PrivacyCheck VVT activity row id — references a row in another
			// app's table on THIS instance; meaningless anywhere else.
			'privacy_vvt_activity_id' => 'env',
			'schema_ok' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Foreign appconfig — DutyRosterAdapter reads dutycheck's
			// 'mc_onduty_hook_enabled' flag; never written by this app and
			// never ours to transfer.
			'mc_onduty_hook_enabled' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'cron_last_*'      cron liveness timestamps (SettingsService::touchCron)
			//   'carecheck/license_apply'  ILockingProvider lock name, not appconfig
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ transferable master data ══
			// Task catalog is plain reference data (code/label/icon) — no
			// encrypted columns, no refs.
			'cck_task_cat' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			// Tour templates are dispatcher-authored config. lead_ref is a
			// typed actor ref ('uid:x'/'member:N') — provenance, carried
			// verbatim like every *_ref below.
			'cck_tour_tpl' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			'cck_team' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name']],
			// Team leads: uid must exist on the target (D1 ghost rule).
			'cck_tlead' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['team_id', 'uid'],
				'fk' => ['team_id' => 'cck_team'], 'depends_on' => ['cck_team'],
				'uid_ref' => ['uid']],
			// Member roster (app-local staff entities referenced as
			// 'member:{id}'). Ids remap freely — nothing transferable keys
			// member rows by id.
			'cck_member' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['display_name'],
				'fk' => ['team_id' => 'cck_team'], 'depends_on' => ['cck_team']],

			// ══ ciphertext-keyed tables — NEVER transferred ══
			// payload_enc/*_enc blobs are AES-256-GCM under the per-instance
			// DEK in appdata keys/ (CryptoService) and name_hmac/presence_hash
			// are keyed by the per-instance hmac.key — ciphertext carried to a
			// target is undecryptable garbage, blind indexes never match, and
			// the pack has no appdata import path to move keys/. Transferring
			// these rows would be active harm, not a degraded copy.
			'cck_client' => ['class' => 'internal'],
			'cck_plan_v' => ['class' => 'internal'],
			'cck_ttstop' => ['class' => 'internal'], // fk client_id → cck_client
			'cck_visit' => ['class' => 'internal'],
			'cck_vtask' => ['class' => 'internal'],
			'cck_notice' => ['class' => 'internal'],
			'cck_journal' => ['class' => 'internal'], // + hash chain keyed to source rows
			'cck_vnote' => ['class' => 'internal'],  // + blob under appdata voicenotes/
			'cck_photo' => ['class' => 'internal'],  // blob_key under appdata photos/
			'cck_clientdoc' => ['class' => 'internal'], // NC filecache ids are env-bound
			// Daily tours/stops are generated by TourGenerateJob from the
			// templates on the target — derived state, and their stops would
			// point at untransferred clients anyway.
			'cck_tour' => ['class' => 'derived'],
			'cck_tstop' => ['class' => 'derived'],

			// ══ instance-bound operational/security state ══
			// Device pairing codes + bearer token hashes are secrets AND
			// instance/device-bound (spec AC-2).
			'cck_pair' => ['class' => 'internal'],
			// Share bearer tokens — secrets, instance-bound.
			'cck_share' => ['class' => 'internal'],
			// License state is signature-bound to this instance's id.
			'cck_license' => ['class' => 'internal'],
			// Time-bound break-glass grants — live security state.
			'cck_brkglass' => ['class' => 'internal'],
			// actor_ref carries 'member:N' — member ids remap across
			// instances, so rows would attach to the wrong staff member.
			'cck_absence' => ['class' => 'internal'],
			'cck_gsession' => ['class' => 'internal'],
			'cck_gpart' => ['class' => 'internal'],
			// TSA anchor attestation is bound to this instance's journal heads.
			'cck_anchor' => ['class' => 'internal'],
			// Rate-limit buckets — instance bookkeeping.
			'cck_rate' => ['class' => 'internal'],

			// ══ logs — never transferred (NN-14) ══
			'cck_audit' => ['class' => 'log'],
			'cck_export' => ['class' => 'log'],
		]);
	}
}
