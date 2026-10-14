<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Actor-scoped idempotency keys on notices — retried companion POSTs and
 * overlapping overdue-cron runs must never duplicate a notice.
 */
class Version1001Date20261012240000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('cck_notice')) {
			$t = $schema->getTable('cck_notice');
			if (!$t->hasColumn('idem_key')) {
				$t->addColumn('idem_key', 'string', ['notnull' => false, 'length' => 64]);
			}
			if (!$t->hasIndex('cck_n_idem_uq')) {
				$t->addUniqueIndex(['actor_ref', 'idem_key'], 'cck_n_idem_uq');
			}
		}

		return $schema;
	}
}
