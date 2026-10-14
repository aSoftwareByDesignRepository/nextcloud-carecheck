<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Db\Member;
use OCA\CareCheck\Db\MemberMapper;
use OCA\CareCheck\Db\Notice;
use OCA\CareCheck\Db\NoticeMapper;
use OCA\CareCheck\Db\PairMapper;
use OCA\CareCheck\Db\BreakGlassMapper;
use OCA\CareCheck\Db\AuditMapper;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\ExportService;
use OCA\CareCheck\Service\VisitService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Ref validation + actor scoping — the ghost-member/oracle class of bugs.
 */
class RefAndScopeTest extends TestCase
{
	private function acl(array $memberById, array $uidExists): AclService
	{
		$members = $this->createMock(MemberMapper::class);
		$members->method('find')->willReturnCallback(
			fn (int $id) => $memberById[$id] ?? null
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(
			fn (string $uid) => $uidExists[$uid] ?? false
		);
		return new AclService(
			$this->createMock(IDBConnection::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$users,
			$this->createMock(PairMapper::class),
			$members,
			$this->createMock(BreakGlassMapper::class),
			$this->createMock(AuditMapper::class),
			$this->createMock(\OCA\CareCheck\Service\SettingsService::class),
			$this->createMock(\OCA\CareCheck\Db\TeamLeadMapper::class),
		);
	}

	private function member(int $id, int $active): Member
	{
		$m = new Member();
		$m->setId($id);
		$m->setActive($active);
		return $m;
	}

	public function testMemberRefRequiresExistenceAndActive(): void
	{
		$acl = $this->acl([5 => $this->member(5, 1), 6 => $this->member(6, 0)], []);
		$this->assertTrue($acl->validRef('member:5'));
		$this->assertFalse($acl->validRef('member:6'));   // deactivated member refuses
		$this->assertFalse($acl->validRef('member:999')); // ghost member refuses
	}

	public function testUidRefRequiresExistingAccount(): void
	{
		$acl = $this->acl([], ['alex' => true]);
		$this->assertTrue($acl->validRef('uid:alex'));
		$this->assertFalse($acl->validRef('uid:deleted-user'));
		$this->assertFalse($acl->validRef('uid:'));
	}

	public function testMalformedRefsRefuse(): void
	{
		$acl = $this->acl([1 => $this->member(1, 1)], ['x' => true]);
		foreach (['', 'plain', 'member:', 'member:abc', 'member:1x', 'member: 1', 'member:1.5', 'UID:alex', ' uid:alex', 'member:1 '] as $bad) {
			$this->assertFalse($acl->validRef($bad), "accepted {$bad}");
		}
	}

	/** CSV cells starting with a formula char get the ' prefix — every variant. */
	public function testCsvFormulaInjectionEscaping(): void
	{
		$svc = (new \ReflectionClass(ExportService::class))->newInstanceWithoutConstructor();
		$m = new \ReflectionMethod(ExportService::class, 'csvSafe');
		foreach (['=cmd', '+1+1', '-2', '@SUM(1)', "\t=1", "\r=1", "\x0B=1"] as $dangerous) {
			$out = $m->invoke($svc, $dangerous);
			$this->assertSame("'" . $dangerous, $out, "unescaped: " . bin2hex($dangerous));
		}
		foreach (['normal', 'A-1', '1-2', 'text=2', '', 'null'] as $safe) {
			$this->assertSame($safe, $m->invoke($svc, $safe), "over-escaped: " . bin2hex($safe));
		}
	}

	/** noticesFor returns ONLY the caller's notices — foreign notices never leak. */
	public function testNoticesForIsActorScoped(): void
	{
		$mk = function (int $id, string $actor, string $state, int $escalated): Notice {
			$n = new Notice();
			$n->setId($id);
			$n->setActorRef($actor);
			$n->setKind('note');
			$n->setState($state);
			$n->setEscalated($escalated);
			$n->setCreatedAt(new \DateTime('2026-10-13T10:00:00'));
			$n->setAckedAt(null);
			return $n;
		};
		$notices = $this->createMock(NoticeMapper::class);
		$notices->method('findAll')->willReturn([
			$mk(1, 'member:1', 'open', 0),
			$mk(2, 'member:2', 'open', 0),      // foreign — must not leak
			$mk(3, 'member:1', 'acked', 1),
			$mk(4, 'uid:admin', 'open', 0),     // foreign staff notice
		]);
		$svc = new VisitService(
			$this->createMock(IDBConnection::class),
			$this->createMock(\OCA\CareCheck\Db\VisitMapper::class),
			$this->createMock(\OCA\CareCheck\Db\VisitTaskMapper::class),
			$this->createMock(\OCA\CareCheck\Db\ClientMapper::class),
			$this->createMock(\OCA\CareCheck\Db\PlanVersionMapper::class),
			$this->createMock(\OCA\CareCheck\Db\TourStopMapper::class),
			$notices,
			$this->createMock(BreakGlassMapper::class),
			$this->createMock(AclService::class),
			$this->createMock(\OCA\CareCheck\Service\CryptoService::class),
			$this->createMock(\OCA\CareCheck\Service\JournalService::class),
			$this->createMock(\OCA\CareCheck\Service\SettingsService::class),
			$this->createMock(\OCA\CareCheck\Service\PresenceService::class),
		);
		$out = $svc->noticesFor('member:1');
		$this->assertSame([1, 3], array_column($out, 'id'));
		$this->assertSame('acked', $out[1]['state']);
		$this->assertTrue($out[1]['escalated']);
		$this->assertNull($out[1]['ackedAt']);
		// shape carries no client-identifying fields (push/billing privacy rule)
		$this->assertSame(['id', 'kind', 'state', 'escalated', 'createdAt', 'ackedAt', 'resolution'], array_keys($out[0]));
	}

	/**
	 * "Sent in error" retract: author-only, open-only, uniform 404 for
	 * foreign/closed/missing — probing another member's notice id must
	 * look exactly like a nonexistent id.
	 */
	public function testResolveNoticeErrorIsAuthorOnlyAndOpenOnly(): void
	{
		$mk = function (int $id, string $actor, string $state): Notice {
			$n = new Notice();
			$n->setId($id);
			$n->setActorRef($actor);
			$n->setKind('note');
			$n->setState($state);
			$n->setCreatedAt(new \DateTime());
			return $n;
		};
		$store = [11 => $mk(11, 'member:1', 'open'), 22 => $mk(22, 'member:2', 'open'), 33 => $mk(33, 'member:1', 'acked')];
		$notices = $this->createMock(NoticeMapper::class);
		$notices->method('find')->willReturnCallback(fn (int $id) => $store[$id] ?? null);
		$notices->expects($this->once())->method('update')->willReturnCallback(function (Notice $n) { return $n; });
		$svc = new VisitService(
			$this->createMock(IDBConnection::class),
			$this->createMock(\OCA\CareCheck\Db\VisitMapper::class),
			$this->createMock(\OCA\CareCheck\Db\VisitTaskMapper::class),
			$this->createMock(\OCA\CareCheck\Db\ClientMapper::class),
			$this->createMock(\OCA\CareCheck\Db\PlanVersionMapper::class),
			$this->createMock(\OCA\CareCheck\Db\TourStopMapper::class),
			$notices,
			$this->createMock(BreakGlassMapper::class),
			$this->createMock(AclService::class),
			$this->createMock(\OCA\CareCheck\Service\CryptoService::class),
			$this->createMock(\OCA\CareCheck\Service\JournalService::class),
			$this->createMock(\OCA\CareCheck\Service\SettingsService::class),
			$this->createMock(\OCA\CareCheck\Service\PresenceService::class),
		);
		// own open notice → resolved as error
		$svc->resolveNoticeError('member:1', 11);
		$this->assertSame('error', $store[11]->getResolution());
		$this->assertSame('acked', $store[11]->getState());
		// foreign notice → uniform 404 (not "not yours")
		foreach ([22, 33, 999] as $bad) {
			try {
				$svc->resolveNoticeError('member:1', $bad);
				$this->fail("id {$bad} should 404");
			} catch (\OCA\CareCheck\Exception\ApiException $e) {
				$this->assertSame(404, $e->getHttpStatus());
			}
		}
	}
}
