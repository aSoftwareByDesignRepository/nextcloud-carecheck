<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Notice resolution marker: 'none' = open/normal, 'acked' = confirmed by
 * Zentrale, 'error' = author retracted ("sent in error" — UX-13, the
 * notice itself stays in the audit trail, it is just no longer live).
 */
class Version1004Date20261013040000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('cck_notice')) {
			$t = $schema->getTable('cck_notice');
			if (!$t->hasColumn('resolution')) {
				// 'none' sentinel — Oracle treats an empty-string default as NULL.
				$t->addColumn('resolution', 'string', ['notnull' => true, 'length' => 16, 'default' => 'none']);
			}
		}
		return $schema;
	}
}
