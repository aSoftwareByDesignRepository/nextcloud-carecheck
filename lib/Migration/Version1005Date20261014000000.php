<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.1 surface: CCK2 license state, DB rate-limit buckets, worker-mode pair
 * binding + station entitlement groundwork, NH-14 presence proof,
 * NH-19/15 client documents, NH-05 Angehörigen share links.
 */
class Version1005Date20261014000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// CCK2 signed license state (single-row: upsert deletes + inserts).
		if (!$schema->hasTable('cck_license')) {
			$t = $schema->createTable('cck_license');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('customer_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('valid_until', 'date', ['notnull' => true]);
			$t->addColumn('mobile_seats', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('stations', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('bundle', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('key_applied_at', 'datetime', ['notnull' => true]);
			$t->addColumn('payload_b64', 'text', ['notnull' => true]);
			$t->addColumn('signature_b64', 'text', ['notnull' => true]);
			$t->addColumn('license_fingerprint', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('bound_instance_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id'], 'cck_lic_pk');
		}

		// Fixed-window rate-limit buckets (pairing, per-device caps) —
		// hashed keys only, no raw IP/actor persisted.
		if (!$schema->hasTable('cck_rate')) {
			$t = $schema->createTable('cck_rate');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('bucket', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('cnt', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('win_start', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_rate_pk');
			$t->addUniqueIndex(['bucket'], 'cck_rate_b_uq');
		}

		if ($schema->hasTable('cck_pair')) {
			$t = $schema->getTable('cck_pair');
			// Worker-mode seat claim: a device token can bind a uid too
			// (member_id stays null) — one seat unit for both auth families.
			if (!$t->hasColumn('uid')) {
				$t->addColumn('uid', 'string', ['notnull' => false, 'length' => 190]);
			}
			// NH-09 groundwork: 'member' (default) | 'station' entitlement.
			if (!$t->hasColumn('kind')) {
				$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'member']);
			}
			if (!$t->hasColumn('last_seen')) {
				$t->addColumn('last_seen', 'datetime', ['notnull' => false]);
			}
		}

		// NH-14: per-client rotating presence secret — the QR card at the
		// client's home proves physical presence at check-in time.
		if ($schema->hasTable('cck_client')) {
			$t = $schema->getTable('cck_client');
			if (!$t->hasColumn('presence_hash')) {
				$t->addColumn('presence_hash', 'string', ['notnull' => false, 'length' => 64]);
			}
		}

		// NH-19/15: provider documents (NC Files) linked to a client with
		// optional expiry — compliance evidence like Betreuungsverfügung.
		if (!$schema->hasTable('cck_clientdoc')) {
			$t = $schema->createTable('cck_clientdoc');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('file_id', 'bigint', ['notnull' => true]); // NC filecache id
			$t->addColumn('expires_on', 'date', ['notnull' => false]);
			$t->addColumn('reminded_on', 'date', ['notnull' => false]); // last expiry notice day
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_cd_pk');
			$t->addIndex(['client_id'], 'cck_cd_cl_idx');
			$t->addIndex(['expires_on'], 'cck_cd_exp_idx');
		}

		// NH-05: Angehörigen read-only share — hashed bearer token, revocable,
		// expiring. The public view renders a minimal projection only.
		if (!$schema->hasTable('cck_share')) {
			$t = $schema->createTable('cck_share');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('token_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('label', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revoked', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('last_access_at', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'cck_sh_pk');
			$t->addUniqueIndex(['token_hash'], 'cck_sh_tok_uq');
			$t->addIndex(['client_id'], 'cck_sh_cl_idx');
		}

		return $schema;
	}
}
