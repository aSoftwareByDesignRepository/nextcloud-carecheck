<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-01 (voice note → transcript): audio blobs are AES-256-GCM sealed in
 * appdata; this table holds metadata, TaskProcessing state, and the
 * sealed transcript. Status machine: pending → submitted → done|failed.
 * `unavailable` when the instance has no audio2text provider — honest,
 * never silently dropped.
 */
class Version1009Date20261018100000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('cck_vnote')) {
			$t = $schema->createTable('cck_vnote');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('visit_id', 'bigint', ['notnull' => false]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('mime', 'string', ['notnull' => true, 'length' => 60]);
			$t->addColumn('byte_size', 'integer', ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('sha256', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('blob_key', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$t->addColumn('task_id', 'bigint', ['notnull' => false]);
			$t->addColumn('transcript_enc', 'text', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_vnote_pk');
			$t->addIndex(['client_id'], 'cck_vnote_cl_idx');
			$t->addIndex(['visit_id'], 'cck_vnote_v_idx');
			$t->addIndex(['status'], 'cck_vnote_st_idx');
			$t->addUniqueIndex(['blob_key'], 'cck_vnote_bk_uq');
		}
		return $schema;
	}
}
