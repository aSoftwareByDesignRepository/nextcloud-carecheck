<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-11 — team/regional scoping. `cck_team` is the org unit; leads are
 * NC uids in `cck_tlead`; clients and members each carry one nullable
 * team_id (null = unscoped → flat visibility as before). A team lead
 * (group carecheck-teamlead) sees only stops/clients of own teams;
 * Zentrale/admin keep flat visibility per spec §7.
 */
class Version1010Date20261018200000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('cck_team')) {
			$t = $schema->createTable('cck_team');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_team_pk');
		}
		if (!$schema->hasTable('cck_tlead')) {
			$t = $schema->createTable('cck_tlead');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('team_id', 'bigint', ['notnull' => true]);
			$t->addColumn('uid', 'string', ['notnull' => true, 'length' => 190]);
			$t->setPrimaryKey(['id'], 'cck_tlead_pk');
			$t->addIndex(['team_id'], 'cck_tlead_t_idx');
			$t->addUniqueIndex(['team_id', 'uid'], 'cck_tlead_uq');
		}
		if ($schema->hasTable('cck_client') && !$schema->getTable('cck_client')->hasColumn('team_id')) {
			$schema->getTable('cck_client')->addColumn('team_id', 'bigint', ['notnull' => false]);
			$schema->getTable('cck_client')->addIndex(['team_id'], 'cck_client_t_idx');
		}
		if ($schema->hasTable('cck_member') && !$schema->getTable('cck_member')->hasColumn('team_id')) {
			$schema->getTable('cck_member')->addColumn('team_id', 'bigint', ['notnull' => false]);
			$schema->getTable('cck_member')->addIndex(['team_id'], 'cck_member_t_idx');
		}
		return $schema;
	}
}
