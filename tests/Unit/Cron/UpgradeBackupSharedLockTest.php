<?php

declare(strict_types=1);

/**
 * Contract proof for the upgrade-backup coordination contract:
 * UpgradeBackupService::runExclusive() holds LOCK_KEY exclusively — every
 * background job that scans/writes app tables must take the SHARED lock,
 * skip on LockedException without doing work, and always release.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\CareCheck\Tests\Unit\Cron;

use OCA\CareCheck\Cron\AnchorJob;
use OCA\CareCheck\Cron\AzcAbsenceSyncJob;
use OCA\CareCheck\Cron\DocExpiryJob;
use OCA\CareCheck\Cron\EscalationJob;
use OCA\CareCheck\Cron\OverdueDetectionJob;
use OCA\CareCheck\Cron\RetentionPurgeJob;
use OCA\CareCheck\Cron\TourGenerateJob;
use OCA\CareCheck\Cron\VoiceTranscribeJob;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\TourService;
use OCA\CareCheck\Service\UpgradeBackupService;
use OCA\CareCheck\Service\VoiceNoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class UpgradeBackupSharedLockTest extends TestCase
{
	/** Every app background job — the whole family is wired into the contract. */
	private const JOB_CLASSES = [
		AnchorJob::class,
		AzcAbsenceSyncJob::class,
		DocExpiryJob::class,
		EscalationJob::class,
		OverdueDetectionJob::class,
		RetentionPurgeJob::class,
		TourGenerateJob::class,
		VoiceTranscribeJob::class,
	];

	/**
	 * Static contract: every job class (a) declares an ILockingProvider
	 * constructor dependency and (b) its source acquires the shared lock on
	 * UpgradeBackupService::LOCK_KEY and releases it in a finally.
	 */
	public function testEveryJobIsWiredIntoSharedLockContract(): void
	{
		foreach (self::JOB_CLASSES as $class) {
			$ref = new \ReflectionClass($class);
			$ctor = $ref->getConstructor();
			$this->assertNotNull($ctor, $class);
			$hasLockDep = false;
			foreach ($ctor->getParameters() as $p) {
				$t = $p->getType();
				if ($t instanceof \ReflectionNamedType && $t->getName() === ILockingProvider::class) {
					$hasLockDep = true;
				}
			}
			$this->assertTrue($hasLockDep, "$class constructor lacks an ILockingProvider dependency");

			$file = $ref->getFileName();
			$this->assertNotFalse($file, $class);
			$src = (string)file_get_contents($file);
			$this->assertStringContainsString('UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('ILockingProvider::LOCK_SHARED', $src, $class);
			$this->assertStringContainsString('releaseLock(UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('} finally {', $src, $class);
		}

		// Sanity: the contract really does cover every job dir — no silently
		// missed class when a ninth job lands (jobs live under lib/Cron here;
		// lib/BackgroundJob is globbed too so a future move stays covered).
		$onDisk = [];
		foreach (['Cron', 'BackgroundJob'] as $ns) {
			$dir = dirname(__DIR__, 3) . '/lib/' . $ns;
			foreach (glob($dir . '/*Job.php') ?: [] as $f) {
				$onDisk[] = 'OCA\\CareCheck\\' . $ns . '\\' . basename($f, '.php');
			}
		}
		sort($onDisk);
		$declared = self::JOB_CLASSES;
		sort($declared);
		$this->assertSame($onDisk, $declared, 'JOB_CLASSES must enumerate every *Job.php in lib/Cron + lib/BackgroundJob');
	}

	private function invokeRun(object $job, $argument = null): void
	{
		$m = new \ReflectionMethod($job, 'run');
		$m->setAccessible(true);
		$m->invoke($job, $argument);
	}

	private function lockedProvider(): ILockingProvider
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED)
			->willThrowException(new LockedException('restore in progress'));
		return $locking;
	}

	public function testLockedOutJobSkipsWithoutTouchingAppTables(): void
	{
		$tours = $this->createMock(TourService::class);
		$tours->expects($this->never())->method('generate');
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('touchCron');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('skipping'), $this->arrayHasKey('app'));

		$job = new TourGenerateJob(
			$this->createMock(ITimeFactory::class),
			$tours,
			$settings,
			$this->lockedProvider(),
			$logger,
		);
		$this->invokeRun($job);
	}

	public function testLockedOutRawQueryJobSkipsWithoutTouchingAppTables(): void
	{
		// Second shape: jobs that build queries on IDBConnection directly —
		// the lock gate must sit before the first app-table touch.
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('touchCron');

		$job = new RetentionPurgeJob(
			$this->createMock(ITimeFactory::class),
			$db,
			$settings,
			$this->lockedProvider(),
			$this->createMock(LoggerInterface::class),
		);
		$this->invokeRun($job);
	}

	public function testSharedLockIsReleasedWhenWorkThrows(): void
	{
		// acquire+release must bracket the work even when it blows up —
		// a leaked shared lock would starve the exclusive restore.
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getInt')->willReturn(3);
		$tours = $this->createMock(TourService::class);
		$tours->method('generate')
			->willThrowException(new \RuntimeException('db gone'));

		$job = new TourGenerateJob(
			$this->createMock(ITimeFactory::class),
			$tours,
			$settings,
			$locking,
			$this->createMock(LoggerInterface::class),
		);
		try {
			$this->invokeRun($job);
			$this->fail('work exception must propagate after the lock is released');
		} catch (\RuntimeException $e) {
			$this->assertSame('db gone', $e->getMessage());
		}
	}

	public function testHappyPathAcquiresAndReleasesSharedLock(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->once())->method('touchCron')->with('generate');
		$settings->method('getInt')->with('horizon_days')->willReturn(3);
		$tours = $this->createMock(TourService::class);
		$tours->expects($this->once())->method('generate')->with(3)->willReturn(2);

		$job = new TourGenerateJob(
			$this->createMock(ITimeFactory::class),
			$tours,
			$settings,
			$locking,
			$this->createMock(LoggerInterface::class),
		);
		$this->invokeRun($job);
	}
}
