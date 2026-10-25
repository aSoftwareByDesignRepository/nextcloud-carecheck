<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after carecheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=carecheck
 */
namespace OCA\CareCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'cck_absence',
		'cck_anchor',
		'cck_audit',
		'cck_brkglass',
		'cck_client',
		'cck_clientdoc',
		'cck_export',
		'cck_gpart',
		'cck_gsession',
		'cck_journal',
		'cck_license',
		'cck_member',
		'cck_notice',
		'cck_pair',
		'cck_photo',
		'cck_plan_v',
		'cck_rate',
		'cck_share',
		'cck_task_cat',
		'cck_team',
		'cck_tlead',
		'cck_tour',
		'cck_tour_tpl',
		'cck_tstop',
		'cck_ttstop',
		'cck_visit',
		'cck_vnote',
		'cck_vtask',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [

	];
}
