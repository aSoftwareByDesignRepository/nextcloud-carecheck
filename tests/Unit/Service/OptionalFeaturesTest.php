<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Db\Client;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Db\ExportJobMapper;
use OCA\CareCheck\Db\GroupParticipantMapper;
use OCA\CareCheck\Db\GroupSession;
use OCA\CareCheck\Db\GroupSessionMapper;
use OCA\CareCheck\Db\MemberMapper;
use OCA\CareCheck\Db\PhotoMapper;
use OCA\CareCheck\Db\Team;
use OCA\CareCheck\Db\TeamLead;
use OCA\CareCheck\Db\TeamLeadMapper;
use OCA\CareCheck\Db\TeamMapper;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\ClientService;
use OCA\CareCheck\Service\CryptoService;
use OCA\CareCheck\Service\ExportService;
use OCA\CareCheck\Service\GroupSessionService;
use OCA\CareCheck\Service\JournalService;
use OCA\CareCheck\Service\PhotoService;
use OCA\CareCheck\Service\TeamService;
use OCP\App\IAppManager;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;

use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * NH-01/04/07/11/12 — the optional backlog: consent/mime/size gates,
 * idempotent group sessions, team-lead group enforcement, billing
 * interchange format + period validation.
 */
class OptionalFeaturesTest extends TestCase
{
	private function db(IResult $result): IDBConnection
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1=1');
		$expr->method('lte')->willReturn('1=1');
		$expr->method('gte')->willReturn('1=1');
		$expr->method('in')->willReturn('1=1');
		$func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class));
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		foreach (['select','from','where','andWhere','innerJoin','orderBy','addOrderBy','setMaxResults','groupBy'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function emptyResult(): IResult
	{
		$r = $this->createMock(IResult::class);
		$r->method('iterateAssociative')->willReturnCallback(static fn () => (function () { yield from []; })());
		$r->method('fetch')->willReturn(false);
		$r->method('fetchAll')->willReturn([]);
		$r->method('fetchOne')->willReturn(false);
		return $r;
	}

	private function mockJournal(): JournalService
	{
		$j = $this->createMock(JournalService::class);
		$j->method('append')->willReturn(new \OCA\CareCheck\Db\JournalEntry());
		return $j;
	}

	private function expectField(ApiException $e, string $field, string $value): void
	{
		$this->assertSame('VALIDATION_ERROR', $e->getErrorCode());
		$this->assertSame($value, $e->getFields()[$field] ?? null);
	}

	/* ---------- NH-07 ExportService::structuredBilling ---------- */

	private function exportSvc(IDBConnection $db, ?Client $client = null, string $name = 'Ada'): ExportService
	{
		$clients = $this->createMock(ClientMapper::class);
		$clients->method('find')->willReturn($client);
		$exports = $this->createMock(ExportJobMapper::class);
		$cs = $this->createMock(ClientService::class);
		$cs->method('hydrated')->willReturn(['payload' => ['name' => $name]]);
		$acl = $this->createMock(AclService::class);
		$journal = $this->createMock(JournalService::class);
		$journal->method('append')->willReturn(new \OCA\CareCheck\Db\JournalEntry());
		return new ExportService($db, $clients, $exports, $cs, $acl, $journal);
	}

	public function testStructuredBillingRejectsBadPeriodAndFormat(): void
	{
		$svc = $this->exportSvc($this->db($this->emptyResult()), new Client());
		try {
			$svc->structuredBilling('uid:x', 1, '2026-99', 'json');
			$this->fail('month 99 must be rejected — DateTime would overflow silently');
		} catch (ApiException $e) {
			$this->expectField($e, 'period', 'invalid');
		}
		try {
			$svc->structuredBilling('uid:x', 1, '2026-02', 'yaml');
			$this->fail();
		} catch (ApiException $e) {
			$this->expectField($e, 'format', 'invalid');
		}
	}

	public function testStructuredBillingJsonShapeAndXmlEscaping(): void
	{
		$visitResult = $this->createMock(IResult::class);
		$visitResult->method('iterateAssociative')->willReturnCallback(static fn () => (function () {
			yield ['id' => 9, 'checkin_ts' => '2026-10-05 08:00:00', 'checkout_ts' => '2026-10-05 09:30:00',
				'duration_sec' => 5400, 'sig_state' => 'client'];
		})());
		$empty = $this->emptyResult();
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select','from','where','andWhere','orderBy','addOrderBy','setMaxResults','groupBy'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeQuery')->willReturnOnConsecutiveCalls($visitResult, $empty, $visitResult, $empty);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$svc = $this->exportSvc($db, new Client(), 'Müller <Sohn> & Co');
		$json = $svc->structuredBilling('uid:x', 7, '2026-10', 'json');
		$this->assertSame('application/json; charset=utf-8', $json['mime']);
		$this->assertSame('leistungsnachweis-7-2026-10.json', $json['filename']);
		$doc = json_decode($json['content'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame('cck-leistungsnachweis', $doc['format']);
		$this->assertSame(7, $doc['client']['id']);
		$this->assertSame('Müller <Sohn> & Co', $doc['client']['name']);
		// only allow-listed fields — nothing else may leak into billing
		$this->assertSame(
			['date','checkin','checkout','duration_min','task_codes','task_done','task_total','signature_state'],
			array_keys($doc['visits'][0])
		);
		$this->assertSame('90', $doc['visits'][0]['duration_min']);
		$this->assertSame('CLIENT', $doc['visits'][0]['signature_state']);

		$xml = $svc->structuredBilling('uid:x', 7, '2026-10', 'xml');
		$this->assertStringContainsString('name="Müller &lt;Sohn&gt; &amp; Co"', $xml['content']);
		$this->assertStringContainsString('<period>2026-10</period>', $xml['content']);
		$dom = new \DOMDocument();
		$this->assertTrue($dom->loadXML($xml['content']), 'billing XML must be well-formed');
	}

	/* ---------- NH-04 PhotoService gates ---------- */

	/** Real 1×1 PNG — sniff() uses getimagesize, magic bytes alone fail. */
	private static function png(): string
	{
		$im = imagecreatetruecolor(1, 1);
		ob_start();
		imagepng($im);
		$png = (string)ob_get_clean();
		return $png;
	}

	private function photoSvc(array $payload, ?\OCP\Files\IAppData $appData = null): array
	{
		$photos = $this->createMock(PhotoMapper::class);
		$clients = $this->createMock(ClientService::class);
		$clients->method('getForActor')->willReturn(new Client());
		$clients->method('hydrated')->willReturn(['payload' => $payload]);
		$acl = $this->createMock(AclService::class);
		$crypto = $this->createMock(CryptoService::class);
		$crypto->method('encrypt')->willReturnArgument(0);
		$crypto->method('decrypt')->willReturnArgument(0);
		$journal = $this->createMock(JournalService::class);
		$journal->method('append')->willReturn(new \OCA\CareCheck\Db\JournalEntry());
		$appData = $appData ?? $this->createMock(\OCP\Files\IAppData::class);
		$svc = new PhotoService(
			$this->db($this->emptyResult()), $photos, $clients, $acl, $crypto, $journal, $appData
		);
		return [$svc, $photos, $journal, $acl, $appData];
	}

	public function testPhotoStoreRefusesWithoutConsent(): void
	{
		$appData = $this->createMock(\OCP\Files\IAppData::class);
		$appData->expects($this->never())->method('getFolder');
		[$svc] = $this->photoSvc(['photo_consent' => false], $appData);
		try {
			$svc->store('uid:x', 5, null, 'image/png', self::png());
			$this->fail('photo without consent must be refused');
		} catch (ApiException $e) {
			$this->expectField($e, 'photo', 'consent_required');
		}
	}

	public function testPhotoStoreRefusesOversizeAndMimeMismatch(): void
	{
		[$svc] = $this->photoSvc(['photo_consent' => true]);
		try {
			$svc->store('uid:x', 5, null, 'image/png', str_repeat('a', 5 * 1024 * 1024 + 1));
			$this->fail();
		} catch (ApiException $e) {
			$this->expectField($e, 'photo', 'size');
		}
		try {
			// PNG bytes declared as jpeg — client-declared mime is untrusted
			$svc->store('uid:x', 5, null, 'image/jpeg', self::png());
			$this->fail();
		} catch (ApiException $e) {
			$this->expectField($e, 'photo', 'type');
		}
		try {
			$svc->store('uid:x', 5, null, 'image/png', '<script>alert(1)</script>');
			$this->fail();
		} catch (ApiException $e) {
			$this->expectField($e, 'photo', 'type');
		}
	}

	public function testPhotoStoreHappyPathSealsBlobAndAudits(): void
	{
		$file = $this->createMock(ISimpleFile::class);
		$file->expects($this->once())->method('putContent');
		$inner = $this->createMock(ISimpleFolder::class);
		$inner->expects($this->once())->method('newFile')->willReturn($file);
		$root = $this->createMock(ISimpleFolder::class);
		$root->method('getFolder')->willThrowException(new \OCP\Files\NotFoundException());
		$root->method('newFolder')->willReturn($inner);
		$appData = $this->createMock(\OCP\Files\IAppData::class);
		$appData->method('getFolder')->willReturn($root);
		[$svc, $photos, $journal, $acl] = $this->photoSvc(['photo_consent' => true], $appData);
		$journal->expects($this->once())->method('append')->with(5, 'photo_add', 'uid:x');
		$acl->expects($this->once())->method('audit')->with('uid:x', 'photo_add', 5);
		$out = $svc->store('uid:x', 5, null, 'image/png', self::png());
		$this->assertSame('image/png', $out['mime']);
		$this->assertSame(strlen(self::png()), $out['size']);
	}


	public function testPhotoStoreRejectsVisitOfForeignClient(): void
	{
		// Visit exists but belongs to a different client — uniform 404,
		// never silently link a photo across clients (existence-oracle rule).
		$visitResult = $this->createMock(IResult::class);
		$visitResult->method('fetch')->willReturn(['id' => 9, 'client_id' => 999]);

		$photos = $this->createMock(PhotoMapper::class);
		$clients = $this->createMock(ClientService::class);
		$clients->method('getForActor')->willReturn(new Client());
		$clients->method('hydrated')->willReturn(['payload' => ['photo_consent' => true]]);
		$acl = $this->createMock(AclService::class);
		$crypto = $this->createMock(CryptoService::class);
		$journal = $this->createMock(JournalService::class);
		$appData = $this->createMock(\OCP\Files\IAppData::class);
		$appData->expects($this->never())->method('getFolder');
		$svc = new PhotoService(
			$this->db($visitResult), $photos, $clients, $acl, $crypto, $journal, $appData
		);
		try {
			$svc->store('uid:x', 5, 9, 'image/png', self::png());
			$this->fail('photo on foreign visit must 404');
		} catch (ApiException $e) {
			$this->assertSame('NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testPhotoFileForRejectsCorruptedBlob(): void
	{
		// Stored sha256 no longer matches decrypted bytes → integrity failure,
		// never serve tampered content.
		$photo = new \OCA\CareCheck\Db\Photo();
		$photo->setId(3);
		$photo->setClientId(5);
		$photo->setBlobKey('k1');
		$photo->setMime('image/png');
		$photo->setSha256(hash('sha256', 'original-bytes'));

		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('tampered-bytes');
		$inner = $this->createMock(ISimpleFolder::class);
		$inner->method('getFile')->willReturn($file);
		$root = $this->createMock(ISimpleFolder::class);
		$root->method('getFolder')->willReturn($inner);
		$appData = $this->createMock(\OCP\Files\IAppData::class);
		$appData->method('getFolder')->willReturn($root);

		$photos = $this->createMock(PhotoMapper::class);
		$photos->method('find')->willReturn($photo);
		$clients = $this->createMock(ClientService::class);
		$clients->method('getForActor')->willReturn(new Client());
		$acl = $this->createMock(AclService::class);
		$acl->expects($this->never())->method('audit'); // no read-audit on failure
		$crypto = $this->createMock(CryptoService::class);
		$crypto->method('decrypt')->willReturnArgument(0);
		$journal = $this->createMock(JournalService::class);
		$svc = new PhotoService(
			$this->db($this->emptyResult()), $photos, $clients, $acl, $crypto, $journal, $appData
		);
		$this->expectException(\RuntimeException::class);
		$svc->fileFor('uid:x', 5, 3);
	}

	/* ---------- NH-12 GroupSessionService ---------- */

	private function groupSvc(?GroupSession $found, array $parts = []): array
	{
		$sessions = $this->createMock(GroupSessionMapper::class);
		$sessions->method('find')->willReturn($found);
		$pmap = $this->createMock(GroupParticipantMapper::class);
		$pmap->method('findBySession')->willReturn($parts);
		$clients = $this->createMock(ClientService::class);
		$acl = $this->createMock(AclService::class);
		$journal = $this->createMock(JournalService::class);
		$journal->method('append')->willReturn(new \OCA\CareCheck\Db\JournalEntry());
		$svc = new GroupSessionService(
			$this->db($this->emptyResult()), $sessions, $pmap, $clients, $acl, $journal
		);
		return [$svc, $sessions, $pmap, $clients, $journal];
	}

	public function testGroupOpenValidatesInputs(): void
	{
		[$svc] = $this->groupSvc(null);
		foreach ([['', [1], 'k', 'title'], ['T', [], 'k', 'client_ids'], ['T', [1], '', 'idem_key']] as [$t, $ids, $k, $field]) {
			try {
				$svc->open('uid:x', $t, $ids, $k);
				$this->fail();
			} catch (ApiException $e) {
				$this->assertSame('VALIDATION_ERROR', $e->getErrorCode());
				$this->assertArrayHasKey($field, $e->getFields());
			}
		}
		try {
			$svc->open('uid:x', 'T', range(1, 51), 'k');
			$this->fail();
		} catch (ApiException $e) {
			$this->expectField($e, 'client_ids', 'too_many');
		}
	}

	public function testGroupOpenIdempotentReplaySkipsInsertAndScope(): void
	{
		$existing = new GroupSession();
		$existing->setId(42);
		$existing->setStatus('open');
		$sessions = $this->createMock(GroupSessionMapper::class);
		$sessions->method('findByIdem')->willReturn($existing);
		$sessions->expects($this->never())->method('insert');
		$clients = $this->createMock(ClientService::class);
		$clients->expects($this->never())->method('getForActor');
		$svc = new GroupSessionService(
			$this->db($this->emptyResult()), $sessions,
			$this->createMock(GroupParticipantMapper::class), $clients,
			$this->createMock(AclService::class), $this->mockJournal()
		);
		$out = $svc->open('uid:x', 'Gym', [1, 2, 3], 'dup-key');
		$this->assertSame(42, $out['id']);
		$this->assertTrue($out['idempotent']);
	}

	public function testGroupOpenScopeCheckedBeforeInsert(): void
	{
		$sessions = $this->createMock(GroupSessionMapper::class);
		$sessions->method('findByIdem')->willReturn(null);
		$sessions->expects($this->never())->method('insert');
		$clients = $this->createMock(ClientService::class);
		$clients->method('getForActor')->willReturnCallback(function ($actor, $cid) {
			if ($cid === 99) {
				throw ApiException::notFound();
			}
			return new Client();
		});
		$svc = new GroupSessionService(
			$this->db($this->emptyResult()), $sessions,
			$this->createMock(GroupParticipantMapper::class), $clients,
			$this->createMock(AclService::class), $this->mockJournal()
		);
		try {
			$svc->open('uid:x', 'Gym', [1, 99], 'k');
			$this->fail('out-of-scope participant must abort the whole open');
		} catch (ApiException $e) {
			$this->assertSame('NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testGroupSetAttendedRefusesClosedAndUnknown(): void
	{
		$closed = new GroupSession();
		$closed->setStatus('closed');
		$closed->setActorRef('uid:x');
		[$svc] = $this->groupSvc($closed);
		try {
			$svc->setAttended('uid:x', 1, 5, false);
			$this->fail();
		} catch (ApiException $e) {
			$this->assertSame('CONFLICT', $e->getErrorCode());
		}

		$open = new GroupSession();
		$open->setStatus('open');
		$open->setActorRef('uid:x');
		[$svc2] = $this->groupSvc($open, []); // no participants
		try {
			$svc2->setAttended('uid:x', 1, 5, false);
			$this->fail();
		} catch (ApiException $e) {
			$this->assertSame('NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testGroupCloseIsIdempotentAndJournalsPerClient(): void
	{
		$closed = new GroupSession();
		$closed->setStatus('closed');
		$closed->setActorRef('uid:x');
		[$svc, , , , $journal] = $this->groupSvc($closed);
		$journal->expects($this->never())->method('append');
		$svc->close('uid:x', 1); // already closed → no-op, no journal

		$open = new GroupSession();
		$open->setStatus('open');
		$open->setActorRef('uid:x');
		$open->setTitle('Gym');
		$p1 = new \OCA\CareCheck\Db\GroupParticipant();
		$p1->setClientId(10);
		$p1->setAttended(1);
		$p2 = new \OCA\CareCheck\Db\GroupParticipant();
		$p2->setClientId(11);
		$p2->setAttended(0);
		[$svc2, , , , $journal2] = $this->groupSvc($open, [$p1, $p2]);
		$seen = [];
		$journal2->expects($this->exactly(2))->method('append')
			->willReturnCallback(function ($cid, $action, $actor, $meta) use (&$seen) {
				$seen[$cid] = $meta['attended'];
				$this->assertSame('group_session_close', $action);
			});
		$svc2->close('uid:x', 1);
		$this->assertSame([10 => true, 11 => false], $seen);
	}

	/* ---------- NH-11 TeamService ---------- */

	public function testAssignLeadRequiresExistingUserInTeamleadGroup(): void
	{
		$teams = $this->createMock(TeamMapper::class);
		$teams->method('find')->willReturn(new Team());
		$leads = $this->createMock(TeamLeadMapper::class);
		$leads->method('findByTeam')->willReturn([]);
		$users = $this->createMock(IUserManager::class);
		$groups = $this->createMock(IGroupManager::class);
		$acl = $this->createMock(AclService::class);
		$svc = new TeamService(
			$this->db($this->emptyResult()), $teams, $leads,
			$this->createMock(ClientMapper::class), $this->createMock(MemberMapper::class),
			$acl, $users, $groups
		);

		$users->method('userExists')->willReturnOnConsecutiveCalls(false, true);
		try {
			$svc->assignLead('uid:admin', 1, 'ghost');
			$this->fail();
		} catch (ApiException $e) {
			$this->assertSame('NOT_FOUND', $e->getErrorCode());
		}

		$groups->method('isInGroup')->willReturn(false);
		$leads->expects($this->never())->method('insert');
		try {
			$svc->assignLead('uid:admin', 1, 'plainuser');
			$this->fail('lead row for a non-teamlead uid must be refused');
		} catch (ApiException $e) {
			$this->expectField($e, 'uid', 'not_teamlead');
		}
	}

	public function testAssignLeadIsIdempotent(): void
	{
		$teams = $this->createMock(TeamMapper::class);
		$teams->method('find')->willReturn(new Team());
		$existing = new TeamLead();
		$existing->setUid('lead1');
		$leads = $this->createMock(TeamLeadMapper::class);
		$leads->method('findByTeam')->willReturn([$existing]);
		$leads->expects($this->never())->method('insert');
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(true);
		$svc = new TeamService(
			$this->db($this->emptyResult()), $teams, $leads,
			$this->createMock(ClientMapper::class), $this->createMock(MemberMapper::class),
			$this->createMock(AclService::class), $users, $groups
		);
		$svc->assignLead('uid:admin', 1, 'lead1'); // no exception, no insert
		$this->addToAssertionCount(1);
	}

	public function testListTeamsShipsLeadLabelsBesideRawUids(): void
	{
		// Chips render `leadLabels`; `leads` keeps raw uids for the rm route.
		$team = new Team();
		$lead = new TeamLead();
		$lead->setUid('lead1');
		$teams = $this->createMock(TeamMapper::class);
		$teams->method('findAll')->willReturn([$team]);
		$leads = $this->createMock(TeamLeadMapper::class);
		$leads->method('findByTeam')->willReturn([$lead]);
		$acl = $this->createMock(AclService::class);
		$acl->method('refLabel')->willReturnCallback(
			static fn (?string $r) => $r === 'uid:lead1' ? 'Lead One' : null
		);
		$svc = new TeamService(
			$this->db($this->emptyResult()), $teams, $leads,
			$this->createMock(ClientMapper::class), $this->createMock(MemberMapper::class),
			$acl, $this->createMock(IUserManager::class), $this->createMock(IGroupManager::class)
		);
		$out = $svc->list('uid:admin');
		$this->assertSame(['lead1'], $out[0]['leads']);
		$this->assertSame(['Lead One'], $out[0]['leadLabels']);
	}
}
