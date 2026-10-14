<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-04 (clinical photo documentation): metadata rows for encrypted
 * photo blobs stored in appdata. The blob itself never lives here —
 * `sha256` is the plaintext hash for integrity proof. Consent is a
 * payload flag on the client (`photo_consent`); the service refuses
 * to store without it.
 */
class Version1008Date20261018000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('cck_photo')) {
			$t = $schema->createTable('cck_photo');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('visit_id', 'bigint', ['notnull' => false]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('mime', 'string', ['notnull' => true, 'length' => 40]);
			$t->addColumn('byte_size', 'integer', ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('sha256', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('blob_key', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_photo_pk');
			$t->addIndex(['client_id'], 'cck_photo_cl_idx');
			$t->addIndex(['visit_id'], 'cck_photo_v_idx');
			$t->addUniqueIndex(['blob_key'], 'cck_photo_bk_uq');
		}
		return $schema;
	}
}
