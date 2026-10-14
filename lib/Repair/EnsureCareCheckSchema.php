<?php

declare(strict_types=1);

namespace OCA\CareCheck\Repair;

use OCA\CareCheck\AppInfo\Application;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Guard: verifies the cck_* schema exists post-migration (install safety). */
class EnsureCareCheckSchema implements IRepairStep
{
	private const TABLES = [
		'cck_client', 'cck_plan_v', 'cck_task_cat', 'cck_tour_tpl', 'cck_ttstop',
		'cck_tour', 'cck_tstop', 'cck_visit', 'cck_vtask', 'cck_notice',
		'cck_member', 'cck_pair', 'cck_journal', 'cck_anchor', 'cck_audit',
		'cck_brkglass', 'cck_absence', 'cck_export', 'cck_license', 'cck_rate',
		'cck_clientdoc', 'cck_share', 'cck_photo', 'cck_vnote', 'cck_gsession', 'cck_gpart', 'cck_team', 'cck_tlead',
	];

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure CareCheck schema exists';
	}

	public function run(IOutput $output): void
	{
		$missing = [];
		foreach (self::TABLES as $t) {
			if (!$this->db->tableExists($t)) {
				$missing[] = $t;
			}
		}
		if ($missing !== []) {
			$output->warning('Missing tables: ' . implode(', ', $missing) . ' — run occ upgrade');
			return;
		}
		$this->config->setAppValue(Application::APP_ID, 'schema_ok', '1');
		$output->info('CareCheck schema OK (' . count(self::TABLES) . ' tables)');
	}
}
