<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1013Date20261019000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// F14: soft link to a CustomerCheck company (payer/Träger). Plain
		// column — never inside the encrypted payload, so the suite can
		// resolve the link. Non-unique: one CRM company (e.g. a Kommune)
		// legitimately pays for many care clients.
		if ($schema->hasTable('cck_client')) {
			$table = $schema->getTable('cck_client');
			if (!$table->hasColumn('crm_company_id')) {
				$table->addColumn('crm_company_id', 'bigint', ['notnull' => false]);
			}
			if (!$table->hasIndex('cck_client_crm_idx')) {
				$table->addIndex(['crm_company_id'], 'cck_client_crm_idx');
			}
		}

		return $schema;
	}
}
