<?php

declare(strict_types=1);

namespace OCA\CareCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * NH-14 (QR/NFC presence proof): flag on the visit recording that a
 * physical presence token was verified at check-in. Additive, nullable-
 * defaulted — safe on every supported DB.
 */
class Version1006Date20261015000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('cck_visit') && !$schema->getTable('cck_visit')->hasColumn('presence_ok')) {
			$schema->getTable('cck_visit')->addColumn('presence_ok', 'integer', [
				'notnull' => true,
				'default' => 0,
			]);
		}
		return $schema;
	}
}
