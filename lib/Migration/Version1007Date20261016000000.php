<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repair: every index declared via `addIndex(..., ['unique' => true])` had
 * the flag land in $flags instead of producing a UNIQUE index — all of them
 * came out non-unique:
 *   cck_rate.bucket            — dup buckets weaken the rate limiter
 *   cck_share.token_hash       — bearer-token identity invariant
 *   cck_pair.token_hash        — device-token identity invariant
 *   cck_visit.actor_ref+idem_key   — idempotency invariant (offline replay)
 *   cck_journal.client_id+seq      — append-only chain sequence
 *   cck_tour.template_id+date      — tour-generation dedup
 *   cck_notice.actor_ref+idem_key  — notice idempotency
 *
 * `preSchemaChange` clears the ephemeral rate buckets (windows ≤ 10 min)
 * and dedupes the four data tables keeping the EARLIEST row per key
 * (consistent with findByIdem winner semantics). The dedupe uses only
 * portable SQL (derived-table NOT IN) — no dialect-specific DELETE JOIN.
 */
class Version1007Date20261016000000 extends SimpleMigrationStep
{
	public function __construct(private IDBConnection $db)
	{
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		// Rate buckets are ephemeral counters (windows ≤ 10 min) — clearing
		// the table before the unique index is the portable, race-free dedupe.
		$this->db->executeStatement('DELETE FROM `*PREFIX*cck_rate`');

		// Keep the earliest row per natural key — the winner under the
		// read-then-write semantics the code already uses. A duplicate here
		// means the broken index already let a race through; keeping MIN(id)
		// is consistent with `findByIdem` returning the first row.
		// The doubly-nested derived table is required for MySQL (cannot
		// delete from a table selected in the same statement's WHERE).
		foreach ([
			['cck_visit', 'actor_ref, idem_key'],
			['cck_journal', 'client_id, seq'],
			['cck_tour', 'template_id, date'],
			['cck_notice', 'actor_ref, idem_key'],
		] as [$table, $cols]) {
			$removed = $this->db->executeStatement(
				"DELETE FROM `*PREFIX*$table` WHERE `id` NOT IN (
					SELECT keep_id FROM (
						SELECT MIN(`id`) AS keep_id FROM `*PREFIX*$table` GROUP BY $cols
					) AS keepers
				)"
			);
			if ($removed > 0) {
				$output->warning("carecheck: removed $removed duplicate row(s) from $table while enforcing uniqueness");
			}
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('cck_rate')) {
			$t = $schema->getTable('cck_rate');
			// hasIndex() can't tell unique from non-unique — drop whatever
			// carries the name, then add unique; skip cleanly on re-run when
			// the unique index is already present alongside no stale one.
			if ($t->hasIndex('cck_rate_b_uq')) {
				$t->dropIndex('cck_rate_b_uq');
			}
			if (!$t->hasIndex('cck_rate_b_uq')) {
				$t->addUniqueIndex(['bucket'], 'cck_rate_b_uq');
			}
		}
		if ($schema->hasTable('cck_share')) {
			$t = $schema->getTable('cck_share');
			if ($t->hasIndex('cck_sh_tok_uq')) {
				$t->dropIndex('cck_sh_tok_uq');
			}
			if (!$t->hasIndex('cck_sh_tok_uq')) {
				$t->addUniqueIndex(['token_hash'], 'cck_sh_tok_uq');
			}
		}
		if ($schema->hasTable('cck_pair')) {
			$t = $schema->getTable('cck_pair');
			if ($t->hasIndex('cck_p_tok_idx')) {
				$t->dropIndex('cck_p_tok_idx');
			}
			if ($t->hasIndex('cck_p_tok_uq')) {
				$t->dropIndex('cck_p_tok_uq');
			}
			if (!$t->hasIndex('cck_p_tok_uq')) {
				$t->addUniqueIndex(['token_hash'], 'cck_p_tok_uq');
			}
		}
		if ($schema->hasTable('cck_visit')) {
			$t = $schema->getTable('cck_visit');
			if ($t->hasIndex('cck_v_idem_uq')) {
				$t->dropIndex('cck_v_idem_uq');
			}
			if (!$t->hasIndex('cck_v_idem_uq')) {
				$t->addUniqueIndex(['actor_ref', 'idem_key'], 'cck_v_idem_uq');
			}
		}
		if ($schema->hasTable('cck_journal')) {
			$t = $schema->getTable('cck_journal');
			if ($t->hasIndex('cck_j_cl_seq_uq')) {
				$t->dropIndex('cck_j_cl_seq_uq');
			}
			if (!$t->hasIndex('cck_j_cl_seq_uq')) {
				$t->addUniqueIndex(['client_id', 'seq'], 'cck_j_cl_seq_uq');
			}
		}
		if ($schema->hasTable('cck_tour')) {
			$t = $schema->getTable('cck_tour');
			if ($t->hasIndex('cck_tr_tpl_date_uq')) {
				$t->dropIndex('cck_tr_tpl_date_uq');
			}
			if (!$t->hasIndex('cck_tr_tpl_date_uq')) {
				$t->addUniqueIndex(['template_id', 'date'], 'cck_tr_tpl_date_uq');
			}
		}
		if ($schema->hasTable('cck_notice')) {
			$t = $schema->getTable('cck_notice');
			if ($t->hasIndex('cck_n_idem_uq')) {
				$t->dropIndex('cck_n_idem_uq');
			}
			if (!$t->hasIndex('cck_n_idem_uq')) {
				$t->addUniqueIndex(['actor_ref', 'idem_key'], 'cck_n_idem_uq');
			}
		}
		return $schema;
	}
}
