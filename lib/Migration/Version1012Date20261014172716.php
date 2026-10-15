<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1012Date20261014172716 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Backstop for the publish CAS: (client_id, revision) is naturally
		// unique, so a racing second insert on the same revision fails loudly
		// instead of leaving two published plans.
		if ($schema->hasTable('cck_plan_v') && !$schema->getTable('cck_plan_v')->hasIndex('cck_pv_cl_rev_uq')) {
			$schema->getTable('cck_plan_v')->addUniqueIndex(['client_id', 'revision'], 'cck_pv_cl_rev_uq');
		}

		return $schema;
	}
}
