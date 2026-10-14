<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Absence metadata: kind flag only (never reason text — Betriebsrat rule). */
class Version1003Date20261013030000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('cck_absence')) {
			$t = $schema->getTable('cck_absence');
			if (!$t->hasColumn('kind')) {
				$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'sick']);
			}
			if (!$t->hasColumn('source')) {
				$t->addColumn('source', 'string', ['notnull' => true, 'length' => 16, 'default' => 'manual']);
			}
			if (!$t->hasColumn('marked_by')) {
				$t->addColumn('marked_by', 'string', ['notnull' => false, 'length' => 96]);
			}
		}

		return $schema;
	}
}
