<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * cck_tlead.created_at — when the lead grant was made. Besides being useful
 * audit metadata, it gives the pure key-mapping table one non-key column:
 * the config-transfer merge upsert updates every column outside the natural
 * key, and a table whose only columns are (id, team_id, uid) would generate
 * an UPDATE with an empty SET list (SQL syntax error, import rolls back).
 * Nullable — rows granted before this migration have no recorded timestamp.
 */
class Version1014Date20261110000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('cck_tlead')) {
			$table = $schema->getTable('cck_tlead');
			if (!$table->hasColumn('created_at')) {
				$table->addColumn('created_at', 'datetime', ['notnull' => false]);
			}
		}
		return $schema;
	}
}
