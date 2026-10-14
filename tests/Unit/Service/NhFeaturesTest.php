<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Db\Client;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\Share;
use OCA\CareCheck\Db\ShareMapper;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\AzcAbsenceAdapter;
use OCA\CareCheck\Service\ClientImportService;
use OCA\CareCheck\Service\ClientService;
use OCA\CareCheck\Service\CryptoService;
use OCA\CareCheck\Service\PresenceService;
use OCA\CareCheck\Service\ShareService;
use OCA\CareCheck\Service\TourService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * B-feature hardening tests: NH-14 presence proof, NH-05 share oracle
 * uniformity, NH-20 CSV import, NH-13 AZC adapter fail-closed.
 */
class NhFeaturesTest extends TestCase
{
	private function aclMock(): AclService
	{
		return $this->createMock(AclService::class);
	}

	private function emptyDb(): IDBConnection
	{
		$result = $this->createMock(IResult::class);
		$result->method('iterateAssociative')->willReturnCallback(
			static fn () => (function () { yield from []; })()
		);
		$result->method('fetchAll')->willReturn([]);
		$result->method('fetch')->willReturn(false);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1=1');
		$expr->method('lte')->willReturn('1=1');
		$expr->method('gte')->willReturn('1=1');
		$expr->method('in')->willReturn('1=1');
		$expr->method('isNotNull')->willReturn('1=1');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('addOrderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	/* ---------- NH-14 PresenceService ---------- */

	private function presenceSvc(?Client $client): PresenceService
	{
		$clients = $this->createMock(ClientMapper::class);
		$clients->method('find')->willReturn($client);
		$clients->method('update')->willReturnArgument(0);
		return new PresenceService($clients, $this->aclMock());
	}

	private function clientWithHash(?string $hash): Client
	{
		$c = new Client();
		$c->setId(7);
		$c->setPresenceHash($hash);
		return $c;
	}

	public function testPresenceVerifyAcceptsMintedToken(): void
	{
		$secret = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
		$svc = $this->presenceSvc($this->clientWithHash(hash('sha256', $secret)));
		$this->assertTrue($svc->verify(7, "cck-pres:7:$secret"));
	}

	public function testPresenceVerifyRejectsWrongClientBinding(): void
	{
		$secret = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
		$svc = $this->presenceSvc($this->clientWithHash(hash('sha256', $secret)));
		// token claims client 7 but is presented for client 8 → must fail
		$this->assertFalse($svc->verify(8, "cck-pres:7:$secret"));
	}

	public function testPresenceVerifyRejectsWrongSecret(): void
	{
		$svc = $this->presenceSvc($this->clientWithHash(hash('sha256', 'real-secret')));
		$this->assertFalse($svc->verify(7, 'cck-pres:7:forged-secret'));
	}

	public function testPresenceVerifyRejectsMalformedTokens(): void
	{
		$svc = $this->presenceSvc($this->clientWithHash(hash('sha256', 'x')));
		$this->assertFalse($svc->verify(7, 'totally-not-a-token'));
		$this->assertFalse($svc->verify(7, 'cck-pres:7'));           // missing secret part
		$this->assertFalse($svc->verify(7, 'cck-pres::somesecret')); // empty client id
	}

	public function testPresenceVerifyRejectsWhenNoHashStored(): void
	{
		$svc = $this->presenceSvc($this->clientWithHash(null));
		$this->assertFalse($svc->verify(7, 'cck-pres:7:anything'));
	}

	public function testPresenceMintProducesHashOnlyAndCorrectFormat(): void
	{
		$stored = null;
		$clients = $this->createMock(ClientMapper::class);
		$clients->method('find')->willReturn($this->clientWithHash(null));
		$clients->method('update')->willReturnCallback(function (Client $c) use (&$stored) {
			$stored = $c->getPresenceHash();
			return $c;
		});
		$svc = new PresenceService($clients, $this->aclMock());
		$out = $svc->mint('uid:staff', 7);
		$this->assertMatchesRegularExpression('/^cck-pres:7:[0-9a-f]{32}$/', $out['qr']);
		// only the sha256 of the secret is stored, never the cleartext
		$secret = explode(':', $out['qr'])[2];
		$this->assertSame(hash('sha256', $secret), $stored);
		$this->assertStringNotContainsString($secret, (string)$stored);
	}

	/* ---------- NH-05 ShareService — uniform 404 oracle ---------- */

	private function shareSvc(?Share $found, ?Client $client = null): ShareService
	{
		$shares = $this->createMock(ShareMapper::class);
		$shares->method('findByToken')->willReturn($found);
		$clients = $this->createMock(ClientMapper::class);
		$clients->method('find')->willReturn($client);
		return new ShareService(
			$shares,
			$clients,
			$this->createMock(ClientService::class),
			$this->aclMock(),
			$this->createMock(TourService::class),
			$this->emptyDb(),
		);
	}

	private function share(int $revoked, ?\DateTime $expires): Share
	{
		$s = new Share();
		$s->setId(1);
		$s->setClientId(7);
		$s->setRevoked($revoked);
		$s->setExpiresAt($expires);
		$s->setCreatedAt(new \DateTime());
		return $s;
	}

	private function assertShareNotFound(?Share $found, ?Client $client = null): void
	{
		try {
			$this->shareSvc($found, $client)->view('tok');
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame('NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testShareViewUnknownTokenIsNotFound(): void
	{
		$this->assertShareNotFound(null);
	}

	public function testShareViewRevokedIsNotFound(): void
	{
		$this->assertShareNotFound($this->share(1, null));
	}

	public function testShareViewExpiredIsNotFound(): void
	{
		$this->assertShareNotFound($this->share(0, new \DateTime('-1 day')));
	}

	public function testShareViewInactiveClientIsNotFound(): void
	{
		$c = $this->clientWithHash(null);
		$c->setActive(0);
		$this->assertShareNotFound($this->share(0, null), $c);
	}

	public function testShareViewFutureExpiryIsAccepted(): void
	{
		$clients = $this->createMock(ClientMapper::class);
		$c = $this->clientWithHash(null);
		$c->setActive(1);
		$clients->method('find')->willReturn($c);
		$cs = $this->createMock(ClientService::class);
		$cs->method('hydrated')->willReturn(['payload' => ['name' => 'Erika Muster']]);
		$tours = $this->createMock(TourService::class);
		$tours->method('providerTz')->willReturn('Europe/Berlin');
		$svc = new ShareService(
			$this->createMockShareMapper($this->share(0, new \DateTime('+30 days'))),
			$clients, $cs, $this->aclMock(), $tours, $this->emptyDb(),
		);
		$out = $svc->view('tok');
		$this->assertSame('Erika', $out['client']); // first name only — minimal projection
		$this->assertNull($out['nextVisit']);
		$this->assertNull($out['lastVisit']);
	}

	private function createMockShareMapper(Share $s): ShareMapper
	{
		$m = $this->createMock(ShareMapper::class);
		$m->method('findByToken')->willReturn($s);
		$m->method('update')->willReturnArgument(0);
		return $m;
	}

	public function testShareRevokeForeignClientIsNotFound(): void
	{
		$shares = $this->createMock(ShareMapper::class);
		$shares->method('find')->willReturn($this->share(0, null)); // belongs to client 7
		$svc = new ShareService(
			$shares,
			$this->createMock(ClientMapper::class),
			$this->createMock(ClientService::class),
			$this->aclMock(),
			$this->createMock(TourService::class),
			$this->emptyDb(),
		);
		try {
			$svc->revoke('uid:staff', 99 /* foreign client */, 1);
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame('NOT_FOUND', $e->getErrorCode());
		}
	}

	/* ---------- NH-20 ClientImportService ---------- */

	private function importSvc(): ClientImportService
	{
		$crypto = $this->createMock(CryptoService::class);
		$crypto->method('normalizeName')->willReturnCallback(
			static fn (string $n) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $n)))
		);
		return new ClientImportService(
			$this->emptyDb(),
			$this->createMock(ClientService::class),
			$crypto,
			$this->aclMock(),
		);
	}

	public function testImportPreviewParsesSemicolonCsv(): void
	{
		$out = $this->importSvc()->preview('uid:staff', "name;street;zip;city\nErika Muster;Hauptstr. 1;12345;Berlin\n");
		$this->assertNull($out['error']);
		$this->assertCount(1, $out['rows']);
		$this->assertSame('Erika Muster', $out['rows'][0]['name']);
		$this->assertSame('ok', $out['rows'][0]['status']);
		$this->assertSame(2, $out['rows'][0]['row']);
	}

	public function testImportPreviewAutodetectsCommaDelimiter(): void
	{
		$out = $this->importSvc()->preview('uid:staff', "Name,Street\nKarl Test,Weg 2\n");
		$this->assertNull($out['error']);
		$this->assertSame('Karl Test', $out['rows'][0]['name']);
	}

	public function testImportPreviewRejectsMissingNameColumn(): void
	{
		$out = $this->importSvc()->preview('uid:staff', "street;city\nWeg 1;Berlin\n");
		$this->assertSame('missing_name_column', $out['error']);
	}

	public function testImportPreviewRejectsEmptyCsv(): void
	{
		$this->assertSame('empty', $this->importSvc()->preview('uid:staff', "  \n")['error']);
	}

	public function testImportPreviewFlagsOverlongFields(): void
	{
		$out = $this->importSvc()->preview('uid:staff', "name;zip\n" . str_repeat('x', 191) . ";12345\n");
		$this->assertSame('error', $out['rows'][0]['status']);
		$this->assertSame('field_too_long', $out['rows'][0]['error']);
	}

	public function testImportCommitSkipsDuplicatesAndCreatesRest(): void
	{
		$crypto = $this->createMock(CryptoService::class);
		$crypto->method('normalizeName')->willReturnCallback(
			static fn (string $n) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $n)))
		);
		$created = [];
		$clients = $this->createMock(ClientService::class);
		$clients->method('create')->willReturnCallback(
			function (string $a, array $p) use (&$created) { $created[] = $p['name']; return new \OCA\CareCheck\Db\Client(); }
		);
		$svc = new ClientImportService($this->emptyDb(), $clients, $crypto, $this->aclMock());
		// two identical rows in one file → second is a dup within the same import
		$out = $svc->commit('uid:staff', "name;street\nAnna A;Weg 1\nAnna A;Weg 1\nBert B;Weg 2\n");
		$this->assertSame(2, $out['created']);
		$this->assertSame(1, $out['skipped']);
		$this->assertSame([], $out['errors']);
		$this->assertSame(['Anna A', 'Bert B'], $created);
	}

	/* ---------- NH-13 AzcAbsenceAdapter — fail closed ---------- */

	public function testAzcImportFailsSoftWhenAppAbsent(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$svc = new AzcAbsenceAdapter($this->emptyDb(), $apps);
		$out = $svc->import('2026-10-01', '2026-10-31');
		$this->assertSame(['scanned' => 0, 'imported' => 0, 'skipped' => 0, 'available' => false], $out);
	}

	public function testAzcImportFailsSoftOnSchemaDrift(): void
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('table missing'));
		$svc = new AzcAbsenceAdapter($db, $apps);
		$out = $svc->import('2026-10-01', '2026-10-31');
		$this->assertFalse($out['available']);
		$this->assertSame(0, $out['imported']);
	}
}
