<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Integration;

use OCA\CareCheck\Service\DriveSegmentService;
use OCP\IConfig;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * F11 / NH-08 contract: segments derive ONLY from consecutive completed
 * visits on one tour — gaps break the chain, rejected visits produce
 * nothing, and the payload never carries client identity.
 */
final class DriveSegmentsIntegrationTest extends TestCase
{
	private string $prefix;
	private int $tourId;
	private int $clientId;
	private array $creds;

	protected function setUp(): void
	{
		parent::setUp();
		if (!\class_exists(\OC::class) || !isset(\OC::$server)) {
			self::markTestSkipped('Nextcloud not bootstrapped');
		}
		$db = \OC::$server->get(IDBConnection::class);
		$config = \OC::$server->get(IConfig::class);
		$this->prefix = (string)$config->getSystemValue('dbtableprefix', 'oc_');
		$this->creds = [
			'host' => (string)$config->getSystemValue('dbhost', 'localhost'),
			'name' => (string)$config->getSystemValue('dbname'),
			'user' => (string)$config->getSystemValue('dbuser'),
			'pass' => (string)$config->getSystemValue('dbpassword'),
			'type' => $db->getDatabaseProvider(),
		];
	}

	private function pdo(): \PDO
	{
		$c = $this->creds;
		if ($c['type'] === IDBConnection::PLATFORM_POSTGRES) {
			[$host, $port] = array_pad(explode(':', $c['host'], 2), 2, '5432');
			$dsn = "pgsql:host={$host};port={$port};dbname={$c['name']}";
		} else {
			[$host, $port] = array_pad(explode(':', $c['host'], 2), 2, '3306');
			$dsn = "mysql:host={$host};port={$port};dbname={$c['name']};charset=utf8mb4";
		}
		return new \PDO($dsn, $c['user'], $c['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
	}

	private function seedTourWithVisits(array $visits): void
	{
		$pdo = $this->pdo();
		$p = $this->prefix;
		$pdo->prepare("INSERT INTO `{$p}cck_client` (name_hmac, payload_enc, active, revision, created_at, updated_at, is_demo) VALUES (?, 'x', 1, 1, NOW(), NOW(), 0)")
			->execute([hash('sha256', (string)mt_rand())]);
		$this->clientId = (int)$pdo->lastInsertId();
		$pdo->prepare("INSERT INTO `{$p}cck_tour` (template_id, date, executor_ref, status, revision, created_at) VALUES (NULL, '2026-10-13', 'member:1', 'open', 1, NOW())")
			->execute();
		$this->tourId = (int)$pdo->lastInsertId();
		foreach ($visits as $i => $v) {
			$pdo->prepare("INSERT INTO `{$p}cck_tstop` (tour_id, client_id, order_hint, win_start, win_end, kind, executor_ref, status) VALUES (?, ?, ?, 0, 1439, 'client', 'member:1', 'pending')")
				->execute([$this->tourId, $this->clientId, $i]);
			$stopId = (int)$pdo->lastInsertId();
			$pdo->prepare("INSERT INTO `{$p}cck_visit` (actor_ref, idem_key, client_id, tstop_id, checkin_ts, checkout_ts, status, created_at, updated_at) VALUES ('member:1', ?, ?, ?, ?, ?, ?, NOW(), NOW())")
				->execute(["seg-{$this->tourId}-{$i}", $this->clientId, $stopId, $v['in'], $v['out'], $v['status']]);
		}
	}

	public function testConsecutiveCompletedLegsProduceSegments(): void
	{
		$this->seedTourWithVisits([
			['in' => '2026-10-13 08:00:00', 'out' => '2026-10-13 08:45:00', 'status' => 'done'],
			['in' => '2026-10-13 09:10:00', 'out' => '2026-10-13 09:50:00', 'status' => 'done'],
			['in' => '2026-10-13 10:20:00', 'out' => '2026-10-13 11:00:00', 'status' => 'done'],
		]);
		$svc = \OC::$server->get(DriveSegmentService::class);
		$segs = $svc->segments('2026-10-13', '2026-10-13');
		$this->assertCount(2, $segs);
		$this->assertSame('Dienstfahrt Tour', $segs[0]['generic_purpose']);
		// leg 1: checkout stop1 → checkin stop2
		$this->assertStringContainsString('08:45', $segs[0]['departure_ts']);
		$this->assertStringContainsString('09:10', $segs[0]['arrival_ts']);
		// allow-list: no client-identifying field may cross the flange
		foreach ($segs as $seg) {
			$this->assertSame(
				['segment_id', 'date', 'executor', 'departure_ts', 'arrival_ts', 'generic_purpose'],
				array_keys($seg)
			);
			$this->assertSame('member:1', $seg['executor']);
		}
	}

	public function testRejectedVisitBreaksTheChain(): void
	{
		$this->seedTourWithVisits([
			['in' => '2026-10-13 08:00:00', 'out' => '2026-10-13 08:45:00', 'status' => 'done'],
			['in' => '2026-10-13 09:10:00', 'out' => '2026-10-13 09:50:00', 'status' => 'rejected_review'],
			['in' => '2026-10-13 10:20:00', 'out' => '2026-10-13 11:00:00', 'status' => 'done'],
		]);
		$svc = \OC::$server->get(DriveSegmentService::class);
		// rejected middle stop → no consecutive completed pair → zero segments
		$this->assertSame([], $svc->segments('2026-10-13', '2026-10-13'));
	}

	public function testInvalidRangeRejected(): void
	{
		$svc = \OC::$server->get(DriveSegmentService::class);
		try {
			$svc->segments('2026-10-13', '2026-10-01');
			$this->fail('reversed range must be rejected');
		} catch (\Throwable $e) {
			$this->assertSame(400, $e->getHttpStatus());
		}
	}

	protected function tearDown(): void
	{
		if (isset($this->tourId)) {
			$pdo = $this->pdo();
			$p = $this->prefix;
			$pdo->exec("DELETE FROM `{$p}cck_visit` WHERE tstop_id IN (SELECT id FROM `{$p}cck_tstop` WHERE tour_id = {$this->tourId})");
			$pdo->exec("DELETE FROM `{$p}cck_tstop` WHERE tour_id = {$this->tourId}");
			$pdo->exec("DELETE FROM `{$p}cck_tour` WHERE id = {$this->tourId}");
			$pdo->exec("DELETE FROM `{$p}cck_client` WHERE id = {$this->clientId}");
		}
		parent::tearDown();
	}
}
