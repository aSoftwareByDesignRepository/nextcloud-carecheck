<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Db\BreakGlassMapper;
use OCA\CareCheck\Db\Client;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\Notice;
use OCA\CareCheck\Db\NoticeMapper;
use OCA\CareCheck\Db\PlanVersionMapper;
use OCA\CareCheck\Db\TourStopMapper;
use OCA\CareCheck\Db\Visit;
use OCA\CareCheck\Db\VisitMapper;
use OCA\CareCheck\Db\VisitTaskMapper;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\CryptoService;
use OCA\CareCheck\Service\JournalService;
use OCA\CareCheck\Service\PresenceService;
use OCA\CareCheck\Service\SettingsService;
use OCA\CareCheck\Service\VisitService;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Checkin response contract — the companion's CheckinResult
 * (mobile/carecheck/src/api/types.ts) declares {visitId, id, briefing?}.
 * Every checkin return path (fresh insert, idempotent replay, unique-
 * collision dup) must carry visitId AND the briefing safety card. Fresh
 * checkins are the only reachable path in production — fresh mounts mint
 * fresh idem keys — so dropping briefing there leaves the safety surface
 * unreachable while the journal still records briefing_shown (a false
 * compliance trail), and a missing visitId unlinks photo/voicenote media.
 */
class CheckinContractTest extends TestCase
{
	private const CLIENT = 7;
	private const ACTOR = 'member:1';

	/** Scripted fetchAll batches — each findByIdem() call pops the next. */
	private function dbScripting(array $batches): IDBConnection
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('expr');
		$result = $this->createMock(IResult::class);
		$calls = 0;
		$result->method('fetchAll')->willReturnCallback(
			function () use (&$calls, $batches) {
				$batch = $batches[min($calls, \count($batches) - 1)];
				$calls++;
				return $batch;
			}
		);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'where', 'andWhere'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturn('param');
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function service(IDBConnection $db, VisitMapper $visits, ?JournalService $journal = null): VisitService
	{
		$client = new Client();
		$client->setId(self::CLIENT);
		$client->setPayloadEnc('enc-blob');
		$client->setIsDemo(0);
		$client->setRequiresQual(null);
		$clients = $this->createMock(ClientMapper::class);
		$clients->method('find')->willReturnCallback(
			fn (int $id) => $id === self::CLIENT ? $client : null
		);

		$mk = function (int $id, int $clientId, string $kind, string $state): Notice {
			$n = new Notice();
			$n->setId($id);
			$n->setClientId($clientId);
			$n->setKind($kind);
			$n->setState($state);
			return $n;
		};
		$notices = $this->createMock(NoticeMapper::class);
		$notices->method('findAll')->willReturn([
			$mk(1, self::CLIENT, 'incident', 'open'),
			$mk(2, self::CLIENT, 'incident', 'acked'), // closed — not counted
			$mk(3, 9, 'incident', 'open'),             // foreign client — not counted
			$mk(4, self::CLIENT, 'note', 'open'),      // not an incident — not counted
		]);

		$crypto = $this->createMock(CryptoService::class);
		$crypto->method('decrypt')->willReturn((string)json_encode([
			'risks' => ['dog bites', 'stairs'],
			'accessNote' => 'key under the mat',
			'contacts' => [
				['name' => 'Dr. Ada', 'phone' => '112', 'emergency' => true],
				['name' => 'Son', 'phone' => '0160', 'emergency' => false], // non-emergency — filtered out
			],
		]));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getBool')->willReturn(false);

		return new VisitService(
			$db,
			$visits,
			$this->createMock(VisitTaskMapper::class),
			$clients,
			$this->createMock(PlanVersionMapper::class),
			$this->createMock(TourStopMapper::class),
			$notices,
			$this->createMock(BreakGlassMapper::class),
			$this->createMock(AclService::class),
			$crypto,
			$journal ?? $this->createMock(JournalService::class),
			$settings,
			$this->createMock(PresenceService::class),
		);
	}

	private function visitRow(int $id, string $idem = 'idem-1'): array
	{
		return [
			'id' => $id, 'actor_ref' => self::ACTOR, 'idem_key' => $idem,
			'client_id' => self::CLIENT, 'tstop_id' => null,
			'checkin_ts' => '2026-11-16 10:00:00', 'checkout_ts' => null,
			'duration_sec' => null, 'plan_v_id' => null, 'sig_state' => 'none',
			'sig_enc' => null, 'signer_name' => null, 'joint_visit_ref' => null,
			'geo_lat' => null, 'geo_lon' => null, 'status' => 'in_progress',
			'reject_reason' => null, 'qual_mismatch' => 0, 'is_demo' => 0,
			'presence_ok' => 0, 'note_enc' => null,
			'created_at' => '2026-11-16 10:00:00', 'updated_at' => '2026-11-16 10:00:00',
		];
	}

	private function assertContractShape(array $res, int $visitId): void
	{
		$this->assertSame($visitId, $res['id']);
		$this->assertArrayHasKey('visitId', $res); // CheckinResult.visitId — media links hang on it
		$this->assertSame($visitId, $res['visitId']);
		$this->assertArrayHasKey('briefing', $res); // CheckinResult.briefing — the safety card
		foreach (['risks', 'accessNote', 'emergencyContacts', 'openIncidents', 'qualMismatch'] as $k) {
			$this->assertArrayHasKey($k, $res['briefing'], "briefing.{$k} missing");
		}
		$this->assertSame(['dog bites', 'stairs'], $res['briefing']['risks']);
		$this->assertSame('key under the mat', $res['briefing']['accessNote']);
		$this->assertSame([['name' => 'Dr. Ada', 'phone' => '112']], $res['briefing']['emergencyContacts']);
		$this->assertSame(1, $res['briefing']['openIncidents']);
	}

	public function testFreshCheckinCarriesVisitIdAndBriefing(): void
	{
		$visits = $this->createMock(VisitMapper::class);
		$visits->expects($this->once())->method('insert')->willReturnCallback(function (Visit $v) {
			$v->setId(42);
			return $v;
		});
		$journal = $this->createMock(JournalService::class);
		$journal->expects($this->exactly(2))->method('append'); // checkin + briefing_shown
		$svc = $this->service($this->dbScripting([[]]), $visits, $journal);
		$res = $svc->checkin(self::ACTOR, ['idem_key' => 'fresh-1', 'client_id' => self::CLIENT]);
		$this->assertContractShape($res, 42);
	}

	public function testIdempotentReplayCarriesSameShape(): void
	{
		$visits = $this->createMock(VisitMapper::class);
		$visits->expects($this->never())->method('insert');
		$journal = $this->createMock(JournalService::class);
		$journal->expects($this->never())->method('append');
		$svc = $this->service($this->dbScripting([[$this->visitRow(77)]]), $visits, $journal);
		$res = $svc->checkin(self::ACTOR, ['idem_key' => 'idem-1', 'client_id' => self::CLIENT]);
		$this->assertContractShape($res, 77);
	}

	public function testUniqueCollisionDupCarriesSameShape(): void
	{
		$visits = $this->createMock(VisitMapper::class);
		$visits->method('insert')->willThrowException(new class ('dup') extends DbException {
			public function getReason(): ?int
			{
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		});
		$db = $this->dbScripting([[], [$this->visitRow(88, 'race-1')]]);
		$db->expects($this->once())->method('rollBack');
		$svc = $this->service($db, $visits);
		$res = $svc->checkin(self::ACTOR, ['idem_key' => 'race-1', 'client_id' => self::CLIENT]);
		$this->assertContractShape($res, 88);
	}
}
