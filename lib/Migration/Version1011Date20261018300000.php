<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-12 — group sessions (1 worker : N clients). The session is its own
 * artefact (the visit model stays strictly 1:1); closing it writes one
 * journaled `group_session` entry per participant client and produces
 * the Teilnehmerliste export. Presence is per-participant.
 */
class Version1011Date20261018300000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('cck_gsession')) {
			$t = $schema->createTable('cck_gsession');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('title', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 12, 'default' => 'open']);
			$t->addColumn('idem_key', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('started_at', 'datetime', ['notnull' => true]);
			$t->addColumn('closed_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'cck_gsess_pk');
			$t->addIndex(['actor_ref'], 'cck_gsess_ar_idx');
			$t->addUniqueIndex(['actor_ref', 'idem_key'], 'cck_gsess_idem_uq');
		}
		if (!$schema->hasTable('cck_gpart')) {
			$t = $schema->createTable('cck_gpart');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('session_id', 'bigint', ['notnull' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('attended', 'integer', ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'cck_gpart_pk');
			$t->addIndex(['session_id'], 'cck_gpart_s_idx');
			$t->addUniqueIndex(['session_id', 'client_id'], 'cck_gpart_uq');
		}
		return $schema;
	}
}
