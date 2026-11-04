<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Db\Client;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\Tour;
use OCA\CareCheck\Db\TourMapper;
use OCA\CareCheck\Db\TourStop;
use OCA\CareCheck\Db\TourStopMapper;
use OCA\CareCheck\Db\TourTemplate;
use OCA\CareCheck\Db\TourTemplateMapper;
use OCA\CareCheck\Db\TtStop;
use OCA\CareCheck\Db\TtStopMapper;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\ClientService;
use OCA\CareCheck\Service\CryptoService;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\TourService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Archive contract: "no new stops are planned" — materialize() skips
 * template stops whose client is archived/missing, mints no empty tour
 * shell, and archive() prunes the template rows themselves.
 */
class TourGenerationTest extends TestCase
{
	private function aclMock(): AclService
	{
		return $this->createMock(AclService::class);
	}

	private function emptyDb(): IDBConnection
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$result->method('fetch')->willReturn(false);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1=1');
		$expr->method('in')->willReturn('1=1');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select','from','where','andWhere','orderBy','createNamedParameter'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function publishedTemplate(): TourTemplate
	{
		$t = new TourTemplate();
		$t->setId(7);
		$t->setStatus('published');
		$t->setWeekdaySet('1,2,3,4,5,6,7');
		$t->setLeadRef('member:1');
		return $t;
	}

	private function ttStop(int $templateId, ?int $clientId): TtStop
	{
		$s = new TtStop();
		$s->setTemplateId($templateId);
		$s->setClientId($clientId);
		$s->setKind($clientId === null ? 'external' : 'client');
		$s->setOrderHint(0);
		$s->setWinStart(480);
		$s->setWinEnd(600);
		return $s;
	}

	private function tourService(array $tplStops, ?Client $client, TourStopMapper $stops, TourMapper $tours): TourService
	{
		$templates = $this->createMock(TourTemplateMapper::class);
		$templates->method('findAll')->willReturn([$this->publishedTemplate()]);
		$ttStops = $this->createMock(TtStopMapper::class);
		$ttStops->method('findAll')->willReturn($tplStops);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn('Europe/Berlin');
		$clients = $this->createMock(ClientMapper::class);
		$clients->method('find')->willReturn($client);
		return new TourService(
			$this->emptyDb(),
			$tours,
			$stops,
			$templates,
			$ttStops,
			$this->aclMock(),
			$settings,
			null,
			null,
			null,
			$clients,
		);
	}

	public function testMaterializeSkipsArchivedClientStops(): void
	{
		$archived = new Client();
		$archived->setId(9);
		$archived->setActive(0);
		$stops = $this->createMock(TourStopMapper::class);
		$stops->expects($this->once())->method('insert')
			->with($this->callback(static fn (TourStop $s) => $s->getClientId() === null));
		$tours = $this->createMock(TourMapper::class);
		$tours->method('insert')->willReturnCallback(static function (Tour $t) {
			$t->setId(50);
			return $t;
		});
		$svc = $this->tourService([$this->ttStop(7, 9), $this->ttStop(7, null)], $archived, $stops, $tours);
		$this->assertSame(1, $svc->generate(0));
	}

	public function testMaterializeMintsNoEmptyTourShell(): void
	{
		$archived = new Client();
		$archived->setId(9);
		$archived->setActive(0);
		$stops = $this->createMock(TourStopMapper::class);
		$stops->expects($this->never())->method('insert');
		$tours = $this->createMock(TourMapper::class);
		$tours->expects($this->never())->method('insert');
		$svc = $this->tourService([$this->ttStop(7, 9)], $archived, $stops, $tours);
		$this->assertSame(0, $svc->generate(0));
	}

	public function testMaterializeKeepsActiveClientStops(): void
	{
		$active = new Client();
		$active->setId(9);
		$active->setActive(1);
		$stops = $this->createMock(TourStopMapper::class);
		$stops->expects($this->once())->method('insert')
			->with($this->callback(static fn (TourStop $s) => $s->getClientId() === 9));
		$tours = $this->createMock(TourMapper::class);
		$tours->method('insert')->willReturnCallback(static function (Tour $t) {
			$t->setId(50);
			return $t;
		});
		$svc = $this->tourService([$this->ttStop(7, 9)], $active, $stops, $tours);
		$this->assertSame(1, $svc->generate(0));
	}

	public function testArchivePrunesTemplateStops(): void
	{
		$client = new Client();
		$client->setId(9);
		$client->setActive(1);
		$client->setRevision(3);
		$clients = $this->createMock(ClientMapper::class);
		$clients->method('find')->with(9)->willReturn($client);
		$clients->expects($this->once())->method('update')->with($client);
		$ttStops = $this->createMock(TtStopMapper::class);
		$ttStops->expects($this->once())->method('deleteForClient')->with(9);
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');
		$svc = new ClientService(
			$db,
			$clients,
			$this->createMock(CryptoService::class),
			$this->aclMock(),
			$ttStops,
		);
		$svc->archive('uid:staff', 9);
		$this->assertSame(0, (int)$client->getActive());
	}
}
