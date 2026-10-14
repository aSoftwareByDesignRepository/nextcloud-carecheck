<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * CareCheck initial schema (cck_* ≤ 27 chars; explicit short PK/index names).
 * Booleans are integer 0/1 (Oracle-safe). All creates guarded (idempotent).
 */
class Version1000Date20261012230000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('cck_client')) {
			$t = $schema->createTable('cck_client');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name_hmac', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('payload_enc', 'text', ['notnull' => true]); // AES-256-GCM blob: name/street/contacts/risks
			$t->addColumn('requires_qual', 'string', ['notnull' => false, 'length' => 32]);
			$t->addColumn('retention_class', 'string', ['notnull' => true, 'length' => 16, 'default' => 'default']);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_cl_pk');
			$t->addIndex(['name_hmac'], 'cck_cl_hmac_idx');
			$t->addIndex(['active'], 'cck_cl_act_idx');
		}

		if (!$schema->hasTable('cck_plan_v')) {
			$t = $schema->createTable('cck_plan_v');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('snapshot_enc', 'text', ['notnull' => true]); // encrypted immutable plan snapshot
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'draft']);
			$t->addColumn('published_by', 'string', ['notnull' => false, 'length' => 96]);
			$t->addColumn('published_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_pv_pk');
			$t->addIndex(['client_id', 'status'], 'cck_pv_cl_st_idx');
		}

		if (!$schema->hasTable('cck_task_cat')) {
			$t = $schema->createTable('cck_task_cat');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', 'string', ['notnull' => true, 'length' => 48]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('icon', 'string', ['notnull' => false, 'length' => 48]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'fixed']);
			$t->addColumn('requires_qual', 'string', ['notnull' => false, 'length' => 32]);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['id'], 'cck_tc_pk');
			$t->addIndex(['code'], 'cck_tc_code_idx');
		}

		if (!$schema->hasTable('cck_tour_tpl')) {
			$t = $schema->createTable('cck_tour_tpl');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('weekday_set', 'string', ['notnull' => true, 'length' => 13]); // "1,3,5"
			$t->addColumn('lead_ref', 'string', ['notnull' => false, 'length' => 96]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'draft']);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_tt_pk');
		}

		if (!$schema->hasTable('cck_ttstop')) {
			$t = $schema->createTable('cck_ttstop');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('template_id', 'bigint', ['notnull' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('win_start', 'integer', ['notnull' => true, 'default' => 0]); // minutes since midnight, provider tz
			$t->addColumn('win_end', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('order_hint', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('executor_ref', 'string', ['notnull' => false, 'length' => 96]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'flexible']);
			$t->setPrimaryKey(['id'], 'cck_ts_pk');
			$t->addIndex(['template_id'], 'cck_ts_tpl_idx');
		}

		if (!$schema->hasTable('cck_tour')) {
			$t = $schema->createTable('cck_tour');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('template_id', 'bigint', ['notnull' => false]); // null = ad-hoc tour
			$t->addColumn('date', 'date', ['notnull' => true]);
			$t->addColumn('executor_ref', 'string', ['notnull' => false, 'length' => 96]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'open']);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_tr_pk');
			$t->addIndex(['date'], 'cck_tr_date_idx');
			$t->addUniqueIndex(['template_id', 'date'], 'cck_tr_tpl_date_uq');
		}

		if (!$schema->hasTable('cck_tstop')) {
			$t = $schema->createTable('cck_tstop');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('tour_id', 'bigint', ['notnull' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('order_hint', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('win_start', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('win_end', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'flexible']);
			$t->addColumn('executor_ref', 'string', ['notnull' => false, 'length' => 96]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 20, 'default' => 'pending']);
			$t->addColumn('skip_reason_code', 'string', ['notnull' => false, 'length' => 48]);
			$t->setPrimaryKey(['id'], 'cck_sp_pk');
			$t->addIndex(['tour_id'], 'cck_sp_tour_idx');
			$t->addIndex(['status'], 'cck_sp_st_idx');
			$t->addIndex(['executor_ref', 'status'], 'cck_sp_ex_st_idx');
		}

		if (!$schema->hasTable('cck_visit')) {
			$t = $schema->createTable('cck_visit');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('idem_key', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('tstop_id', 'bigint', ['notnull' => false]);
			$t->addColumn('checkin_ts', 'datetime', ['notnull' => false]);
			$t->addColumn('checkout_ts', 'datetime', ['notnull' => false]);
			$t->addColumn('duration_sec', 'integer', ['notnull' => false]);
			$t->addColumn('plan_v_id', 'bigint', ['notnull' => false]);
			$t->addColumn('sig_state', 'string', ['notnull' => true, 'length' => 20, 'default' => 'none']);
			$t->addColumn('sig_enc', 'text', ['notnull' => false]); // encrypted signature blob ≤256KiB
			$t->addColumn('signer_name', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('joint_visit_ref', 'bigint', ['notnull' => false]);
			$t->addColumn('geo_lat', 'float', ['notnull' => false]);
			$t->addColumn('geo_lon', 'float', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 20, 'default' => 'in_progress']);
			$t->addColumn('reject_reason', 'string', ['notnull' => false, 'length' => 96]);
			$t->addColumn('qual_mismatch', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('is_demo', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('note_enc', 'text', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_v_pk');
			$t->addUniqueIndex(['actor_ref', 'idem_key'], 'cck_v_idem_uq');
			$t->addIndex(['client_id'], 'cck_v_cl_idx');
			$t->addIndex(['status'], 'cck_v_st_idx');
		}

		if (!$schema->hasTable('cck_vtask')) {
			$t = $schema->createTable('cck_vtask');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('visit_id', 'bigint', ['notnull' => true]);
			$t->addColumn('task_code', 'string', ['notnull' => true, 'length' => 48]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 20]);
			$t->addColumn('reason_code', 'string', ['notnull' => false, 'length' => 48]);
			$t->addColumn('value_enc', 'text', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'cck_vt_pk');
			$t->addIndex(['visit_id'], 'cck_vt_v_idx');
		}

		if (!$schema->hasTable('cck_notice')) {
			$t = $schema->createTable('cck_notice');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => false]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('note_enc', 'text', ['notnull' => false]);
			$t->addColumn('priority', 'string', ['notnull' => true, 'length' => 16, 'default' => 'normal']);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 20, 'default' => 'open']);
			$t->addColumn('acked_by', 'string', ['notnull' => false, 'length' => 96]);
			$t->addColumn('acked_at', 'datetime', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_n_pk');
			$t->addIndex(['state', 'kind'], 'cck_n_st_k_idx');
		}

		if (!$schema->hasTable('cck_member')) {
			$t = $schema->createTable('cck_member');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('display_name', 'string', ['notnull' => true, 'length' => 190]);
			$t->addColumn('qualification', 'string', ['notnull' => true, 'length' => 32, 'default' => 'hilfskraft']);
			$t->addColumn('active', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_m_pk');
		}

		if (!$schema->hasTable('cck_pair')) {
			$t = $schema->createTable('cck_pair');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('member_id', 'bigint', ['notnull' => false]);
			$t->addColumn('code_hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('code_expires', 'datetime', ['notnull' => false]);
			$t->addColumn('code_used', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('token_hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('device_label', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('redeemed_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revoked', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_p_pk');
			$t->addIndex(['code_hash'], 'cck_p_code_idx');
			$t->addUniqueIndex(['token_hash'], 'cck_p_tok_uq');
			$t->addIndex(['member_id', 'revoked'], 'cck_p_m_r_idx');
		}

		if (!$schema->hasTable('cck_journal')) {
			$t = $schema->createTable('cck_journal');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('seq', 'bigint', ['notnull' => true]);
			$t->addColumn('prev_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('payload_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('type', 'string', ['notnull' => true, 'length' => 48]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('payload_enc', 'text', ['notnull' => false]);
			$t->addColumn('ts', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_j_pk');
			$t->addUniqueIndex(['client_id', 'seq'], 'cck_j_cl_seq_uq');
		}

		if (!$schema->hasTable('cck_anchor')) {
			$t = $schema->createTable('cck_anchor');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('date', 'date', ['notnull' => true]);
			$t->addColumn('heads_digest', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('anchor_token', 'text', ['notnull' => false]); // TSA token or internal attestation
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 20, 'default' => 'unanchored']);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_a_pk');
			$t->addIndex(['date'], 'cck_a_date_idx');
		}

		if (!$schema->hasTable('cck_audit')) {
			$t = $schema->createTable('cck_audit');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('client_id', 'bigint', ['notnull' => false]);
			$t->addColumn('action', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('detail', 'string', ['notnull' => false, 'length' => 190]);
			$t->addColumn('ts', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_au_pk');
			$t->addIndex(['client_id'], 'cck_au_cl_idx');
			$t->addIndex(['actor_ref', 'ts'], 'cck_au_ac_ts_idx');
		}

		if (!$schema->hasTable('cck_brkglass')) {
			$t = $schema->createTable('cck_brkglass');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('client_id', 'bigint', ['notnull' => true]);
			$t->addColumn('reason_code', 'string', ['notnull' => true, 'length' => 48]);
			$t->addColumn('granted_at', 'datetime', ['notnull' => true]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => true]);
			$t->addColumn('state', 'string', ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$t->addColumn('qual_mismatch', 'integer', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'cck_bg_pk');
			$t->addIndex(['actor_ref', 'state'], 'cck_bg_a_s_idx');
		}

		if (!$schema->hasTable('cck_absence')) {
			$t = $schema->createTable('cck_absence');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('from_date', 'date', ['notnull' => true]);
			$t->addColumn('until_date', 'date', ['notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_ab_pk');
			$t->addIndex(['actor_ref', 'from_date'], 'cck_ab_a_d_idx');
		}

		if (!$schema->hasTable('cck_export')) {
			$t = $schema->createTable('cck_export');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('scope_json', 'text', ['notnull' => false]);
			$t->addColumn('actor_ref', 'string', ['notnull' => true, 'length' => 96]);
			$t->addColumn('hash', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cck_ex_pk');
		}

		return $schema;
	}
}
