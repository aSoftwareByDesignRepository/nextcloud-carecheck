<?php

declare(strict_types=1);

/**
 * Pre-migration repair step: snapshot CareCheck data before schema migrations run.
 *
 * Registered under {@see info.xml} `<repair-steps><pre-migration>` so every
 * `occ app:update carecheck` and app reinstall over an existing version creates
 * a recoverable backup before migrations mutate the database.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\CareCheck\Repair;

use OCA\CareCheck\Exception\UpgradeBackupException;
use OCA\CareCheck\Service\UpgradeBackupService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class BackupBeforeUpdate implements IRepairStep
{
	public function __construct(
		private readonly UpgradeBackupService $backupService,
	) {
	}

	public function getName(): string
	{
		return 'Back up CareCheck data before update migrations';
	}

	public function run(IOutput $output): void
	{
		if (!$this->backupService->hasDataToBackup()) {
			$output->info('CareCheck: no existing tables to back up (fresh install); skipping pre-update snapshot.');
			return;
		}

		try {
			$result = $this->backupService->createSnapshot('pre-migration');
		} catch (UpgradeBackupException $e) {
			$output->warning('CareCheck: pre-update backup failed: ' . $e->getMessage());
			throw $e;
		}

		$tableCount = count($result['manifest']['tables'] ?? []);
		$output->info(sprintf(
			'CareCheck: pre-update backup created (%s, %d table(s)). '
			. 'Restore with `occ carecheck:upgrade-backup restore --latest --force` if needed.',
			$result['id'],
			$tableCount,
		));
	}
}
