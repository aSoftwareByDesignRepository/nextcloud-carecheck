<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Spec round-2 additions:
 * - time rules on stops (window|fixed|deadline + tolerance + external location text)
 * - client_id nullable on stops (kind=external stops carry no client scope)
 * - Bezugspflege on client (primary + substitute caregiver refs)
 * - server-owned is_demo flag on client (Übungstour)
 * - comment payload on visit_task (done tasks may carry a comment)
 * - escalation marker on notice (SLA escalation to second Zentrale role)
 */
class Version1002Date20261013020000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('cck_client')) {
			$t = $schema->getTable('cck_client');
			if (!$t->hasColumn('primary_ref')) {
				$t->addColumn('primary_ref', 'string', ['notnull' => false, 'length' => 96]);
			}
			if (!$t->hasColumn('sub_ref')) {
				$t->addColumn('sub_ref', 'string', ['notnull' => false, 'length' => 96]);
			}
			if (!$t->hasColumn('is_demo')) {
				$t->addColumn('is_demo', 'integer', ['notnull' => true, 'default' => 0]);
			}
		}

		// Written out per table (not a foreach) so schema-drift audit sees the
		// literal table name next to every addColumn.
		if ($schema->hasTable('cck_ttstop')) {
			$t = $schema->getTable('cck_ttstop');
			if ($t->hasColumn('client_id')) {
				// external-kind stops carry no client scope → nullable
				$t->changeColumn('client_id', ['notnull' => false]);
			}
			if (!$t->hasColumn('time_kind')) {
				$t->addColumn('time_kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'window']);
			}
			if (!$t->hasColumn('tolerance_min')) {
				$t->addColumn('tolerance_min', 'integer', ['notnull' => false]);
			}
			if (!$t->hasColumn('loc_text')) {
				$t->addColumn('loc_text', 'string', ['notnull' => false, 'length' => 190]);
			}
		}
		if ($schema->hasTable('cck_tstop')) {
			$t = $schema->getTable('cck_tstop');
			if ($t->hasColumn('client_id')) {
				$t->changeColumn('client_id', ['notnull' => false]);
			}
			if (!$t->hasColumn('time_kind')) {
				$t->addColumn('time_kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'window']);
			}
			if (!$t->hasColumn('tolerance_min')) {
				$t->addColumn('tolerance_min', 'integer', ['notnull' => false]);
			}
			if (!$t->hasColumn('loc_text')) {
				$t->addColumn('loc_text', 'string', ['notnull' => false, 'length' => 190]);
			}
		}

		if ($schema->hasTable('cck_vtask')) {
			$t = $schema->getTable('cck_vtask');
			if (!$t->hasColumn('comment_enc')) {
				$t->addColumn('comment_enc', 'text', ['notnull' => false]);
			}
		}

		if ($schema->hasTable('cck_notice')) {
			$t = $schema->getTable('cck_notice');
			if (!$t->hasColumn('escalated')) {
				$t->addColumn('escalated', 'integer', ['notnull' => true, 'default' => 0]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		// v1000 conflated stop kind (client|external) with the time rule
		// (flexible|fixed|deadline). Split: values that were time rules move to
		// time_kind, everything else becomes a client-kind stop.
		$db = \OC::$server->get(IDBConnection::class);
		foreach (['cck_ttstop', 'cck_tstop'] as $table) {
			$qb = $db->getQueryBuilder();
			$qb->update($table)
				->set('time_kind', $qb->createParameter('tk'))
				->set('kind', $qb->createParameter('nk'))
				->where($qb->expr()->in('kind', $qb->createParameter('old')));
			foreach ([['fixed', 'fixed'], ['deadline', 'deadline'], ['flexible', 'window'], ['window', 'window']] as [$old, $tk]) {
				$qb->setParameter('tk', $tk)->setParameter('nk', 'client')->setParameter('old', [$old], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY);
				$qb->executeStatement();
			}
		}
	}
}
