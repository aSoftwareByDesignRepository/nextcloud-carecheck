<?php

declare(strict_types=1);

namespace OCA\CareCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\App\IAppManager;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\UpgradeBackupService;
use OCA\CareCheck\Support\NavGate;
use OCA\CareCheck\Repair\BackupBeforeUpdate;
use OCP\Files\IRootFolder;
use OCA\CareCheck\Repair\EnsureCareCheckSchema;
use OCA\CareCheck\Repair\UninstallDropTables;
use OCA\CareCheck\Service\CryptoService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IDBConnection;
use OCP\IConfig;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'carecheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerService(CryptoService::class, function ($c): CryptoService {
			return new CryptoService(
				$c->get(IAppDataFactory::class)->get(self::APP_ID),
			);
		});
		// Repair steps — explicit registration per repair-step-DI rule
		$context->registerService(EnsureCareCheckSchema::class, function ($c): EnsureCareCheckSchema {
			return new EnsureCareCheckSchema(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
			);
		});
		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});

		// NH-04 photo blobs are sealed in appdata — explicit factory like CryptoService.
		$context->registerService(\OCA\CareCheck\Service\PhotoService::class, function ($c): \OCA\CareCheck\Service\PhotoService {
			return new \OCA\CareCheck\Service\PhotoService(
				$c->get(IDBConnection::class),
				$c->get(\OCA\CareCheck\Db\PhotoMapper::class),
				$c->get(\OCA\CareCheck\Service\ClientService::class),
				$c->get(\OCA\CareCheck\Service\AclService::class),
				$c->get(CryptoService::class),
				$c->get(\OCA\CareCheck\Service\JournalService::class),
				$c->get(IAppDataFactory::class)->get(self::APP_ID),
			);
		});

		// API routes always answer the JSON envelope — an unhandled exception
		// must never escape to core's HTML 500 on /api/* surfaces.
		$context->registerMiddleware(\OCA\CareCheck\Middleware\ApiExceptionMiddleware::class);

		// Suite flange F11 — advertise check:drive-segments to MobilityCheck.
		$context->registerCapability(\OCA\CareCheck\Capabilities::class);
	}

	public function boot(IBootContext $context): void
	{
		$app = $context->getAppContainer();
		// Menu entry only for users with app access — in restricted mode the
		// door is a uniform 404, so the entry must not disclose existence.
		NavGate::register(
			$app,
			'carecheck.page.index',
			'app.svg',
			'CareCheck',
			10,
			static fn(string $uid): bool => $app->get(AclService::class)->hasAppAccess($uid),
		);
	}
}
