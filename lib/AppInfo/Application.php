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

		// ── Config & data transfer (test → production) ──────────────────
		$context->registerService(\OCA\CareCheck\ConfigTransfer\TransferManifest::class, fn () => new \OCA\CareCheck\ConfigTransfer\TransferManifest());
		$context->registerService(\OCA\CareCheck\ConfigTransfer\TransferHooks::class, function ($c): \OCA\CareCheck\ConfigTransfer\TransferHooks {
			return new \OCA\CareCheck\ConfigTransfer\TransferHooks(
				$c->get(\OCA\CareCheck\Service\AclService::class),
				$c->get(UpgradeBackupService::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\CareCheck\ConfigTransfer\TransferPendingStore::class, fn ($c) => new \OCA\CareCheck\ConfigTransfer\TransferPendingStore($c->get(IRootFolder::class), $c->get(IConfig::class), 'carecheck'));
		$context->registerService(\OCA\CareCheck\ConfigTransfer\TransferReceiptStore::class, fn ($c) => new \OCA\CareCheck\ConfigTransfer\TransferReceiptStore($c->get(IConfig::class), 'carecheck'));
		$context->registerService(\OCA\CareCheck\ConfigTransfer\TransferService::class, function ($c): \OCA\CareCheck\ConfigTransfer\TransferService {
			return new \OCA\CareCheck\ConfigTransfer\TransferService(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCA\CareCheck\ConfigTransfer\TransferManifest::class),
				$c->get(\OCA\CareCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\CareCheck\ConfigTransfer\TransferPendingStore::class),
				$c->get(\OCA\CareCheck\ConfigTransfer\TransferReceiptStore::class),
				'carecheck',
				$c->get(\OCP\IAppConfig::class),
			);
		});
		$context->registerService(\OCA\CareCheck\BackgroundJob\TransferApplyJob::class, function ($c): \OCA\CareCheck\BackgroundJob\TransferApplyJob {
			return new \OCA\CareCheck\BackgroundJob\TransferApplyJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCA\CareCheck\ConfigTransfer\TransferService::class),
				$c->get(ILockingProvider::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\CareCheck\Controller\TransferApiController::class, function ($c): \OCA\CareCheck\Controller\TransferApiController {
			return new \OCA\CareCheck\Controller\TransferApiController(
				'carecheck',
				$c->get(\OCP\IRequest::class),
				$c->get(\OCA\CareCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\CareCheck\ConfigTransfer\TransferService::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\OCP\Security\Bruteforce\IThrottler::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IUserSession::class),
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
