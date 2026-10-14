<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Integration;

use OCP\IConfig;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * MF-01: concurrent journal appends on the same client must serialize —
 * no forked prev_hash, no duplicate seq, no spurious failure.
 *
 * Forks real OS processes, each with its own PDO connection, running the
 * exact locking protocol JournalService::append() implements
 * (client-row FOR UPDATE → head FOR UPDATE → insert → commit).
 */
final class JournalConcurrencyIntegrationTest extends TestCase
{
	private const WRITERS = 6;

	private string $prefix;
	private int $clientId;
	private array $creds;

	protected function setUp(): void
	{
		parent::setUp();
		if (!\function_exists('pcntl_fork')) {
			self::markTestSkipped('pcntl required for true-concurrency test');
		}
		/** @var IDBConnection $db */
		$db = \OC::$server->get(IDBConnection::class);
		$platform = $db->getDatabaseProvider();
		if ($platform !== IDBConnection::PLATFORM_MYSQL && $platform !== IDBConnection::PLATFORM_POSTGRES) {
			self::markTestSkipped('concurrency invariant is a row-lock contract — MySQL/MariaDB/PostgreSQL only');
		}
		/** @var IConfig $config */
		$config = \OC::$server->get(IConfig::class);
		$this->prefix = (string)$config->getSystemValue('dbtableprefix', 'oc_');
		$this->creds = [
			'host' => (string)$config->getSystemValue('dbhost', 'localhost'),
			'name' => (string)$config->getSystemValue('dbname'),
			'user' => (string)$config->getSystemValue('dbuser'),
			'pass' => (string)$config->getSystemValue('dbpassword'),
			'type' => $platform,
		];
		$this->clientId = $this->seedClient();
	}

	public function testConcurrentAppendsSerializeWithoutForking(): void
	{
		// Children must not inherit a live socket fd: their exit() would run
		// destructors that COM_QUIT the shared \OC::$server connection and
		// kill it for every test that follows. Close it in the parent first —
		// the adapter lazily reconnects on the next query.
		\OC::$server->get(IDBConnection::class)->close();

		$pids = [];
		for ($i = 0; $i < self::WRITERS; $i++) {
			$pid = pcntl_fork();
			self::assertNotSame(-1, $pid, 'pcntl_fork failed');
			if ($pid === 0) {
				// Child: fresh connection — the parent's socket must not be shared.
				exit($this->childAppend($i) ? 0 : 1);
			}
			$pids[] = $pid;
		}

		$failures = 0;
		foreach ($pids as $pid) {
			pcntl_waitpid($pid, $status);
			if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
				$failures++;
			}
		}
		\OC::$server->get(IDBConnection::class)->connect();
		self::assertSame(0, $failures, 'a concurrent append must never spuriously fail');

		$pdo = $this->connect();
		$stmt = $pdo->query(
			sprintf('SELECT seq FROM `%scck_journal` WHERE client_id = %d ORDER BY seq', $this->prefix, $this->clientId)
		);
		$seqs = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
		self::assertCount(self::WRITERS, $seqs, 'every writer must land exactly one entry');
		self::assertSame(range(1, self::WRITERS), $seqs, 'seqs must be contiguous from genesis — no fork, no gap, no dupe');

		// prev_hash chain: every entry must reference its predecessor's payload_hash
		$rows = $pdo->query(
			sprintf('SELECT seq, prev_hash, payload_hash FROM `%scck_journal` WHERE client_id = %d ORDER BY seq', $this->prefix, $this->clientId)
		)->fetchAll(\PDO::FETCH_ASSOC);
		for ($i = 1; $i < count($rows); $i++) {
			self::assertSame($rows[$i - 1]['payload_hash'], $rows[$i]['prev_hash'], "seq {$rows[$i]['seq']} forks the chain");
		}
	}

	/** Mirrors JournalService::append()'s locking protocol at the SQL layer. */
	private function childAppend(int $n): bool
	{
		try {
			$pdo = $this->connect();
			$pdo->beginTransaction();
			$pdo->prepare(sprintf('SELECT id FROM `%scck_client` WHERE id = ? FOR UPDATE', $this->prefix))
				->execute([$this->clientId]);
			$head = $pdo->prepare(sprintf(
				'SELECT seq, payload_hash FROM `%scck_journal` WHERE client_id = ? ORDER BY seq DESC LIMIT 1 FOR UPDATE',
				$this->prefix
			));
			$head->execute([$this->clientId]);
			$row = $head->fetch(\PDO::FETCH_ASSOC);
			$seq = $row === false ? 1 : ((int)$row['seq'] + 1);
			$prev = $row === false ? hash('sha256', 'GENESIS:' . $this->clientId) : $row['payload_hash'];
			$ph = hash('sha256', $prev . '|child' . $n);
			$pdo->prepare(sprintf(
				'INSERT INTO `%scck_journal` (client_id, seq, prev_hash, payload_hash, type, actor_ref, payload_enc, ts)
				 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
				$this->prefix
			))->execute([$this->clientId, $seq, $prev, $ph, 'conc_test', 'member:test', 'x']);
			$pdo->commit();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function connect(): \PDO
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

	private function seedClient(): int
	{
		$pdo = $this->connect();
		$pdo->prepare(sprintf(
			'INSERT INTO `%scck_client` (name_hmac, payload_enc, requires_qual, active, revision, created_at, updated_at)
			 VALUES (?, ?, NULL, 1, 1, NOW(), NOW())',
			$this->prefix
		))->execute([hash('sha256', 'conc-client' . random_bytes(8)), 'x']);
		return (int)$pdo->lastInsertId();
	}

	protected function tearDown(): void
	{
		if (isset($this->clientId)) {
			try {
				$pdo = $this->connect();
				$pdo->exec(sprintf('DELETE FROM `%scck_journal` WHERE client_id = %d', $this->prefix, $this->clientId));
				$pdo->exec(sprintf('DELETE FROM `%scck_client` WHERE id = %d', $this->prefix, $this->clientId));
			} catch (\Throwable) {
				// cleanup best-effort
			}
		}
		parent::tearDown();
	}
}
