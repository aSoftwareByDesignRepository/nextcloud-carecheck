<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Db\AuditMapper;
use OCA\CareCheck\Db\BreakGlassMapper;
use OCA\CareCheck\Db\Member;
use OCA\CareCheck\Db\MemberMapper;
use OCA\CareCheck\Db\PairMapper;
use OCA\CareCheck\Db\TeamLeadMapper;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * refLabel — UI chips must show display names, never the raw
 * member:/uid:/group: wire token. Deleted principals resolve to null so
 * the caller falls back to the raw ref instead of a blank chip.
 */
class RefLabelTest extends TestCase
{
	private function acl(array $membersById, array $usersByUid, array $groupsByGid): AclService
	{
		$members = $this->createMock(MemberMapper::class);
		$members->method('find')->willReturnCallback(fn (int $id) => $membersById[$id] ?? null);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use ($usersByUid) {
			if (!array_key_exists($uid, $usersByUid)) {
				return null;
			}
			$u = $this->createMock(IUser::class);
			$u->method('getDisplayName')->willReturn($usersByUid[$uid]);
			return $u;
		});

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(function (string $gid) use ($groupsByGid) {
			if (!array_key_exists($gid, $groupsByGid)) {
				return null;
			}
			$g = $this->createMock(IGroup::class);
			$g->method('getDisplayName')->willReturn($groupsByGid[$gid]);
			return $g;
		});

		return new AclService(
			$this->createMock(IDBConnection::class),
			$this->createMock(IUserSession::class),
			$groups,
			$users,
			$this->createMock(PairMapper::class),
			$members,
			$this->createMock(BreakGlassMapper::class),
			$this->createMock(AuditMapper::class),
			$this->createMock(SettingsService::class),
			$this->createMock(TeamLeadMapper::class),
		);
	}

	private function member(int $id, string $name): Member
	{
		$m = new Member();
		$m->setId($id);
		$m->setDisplayName($name);
		return $m;
	}

	public function testResolvesAllRefKinds(): void
	{
		$acl = $this->acl(
			[7 => $this->member(7, 'Maria Pflege')],
			['alex' => 'Alex Mäule'],
			['pflege-nord' => 'Pflege Nord'],
		);
		$this->assertSame('Maria Pflege', $acl->refLabel('member:7'));
		$this->assertSame('Alex Mäule', $acl->refLabel('uid:alex'));
		$this->assertSame('Pflege Nord', $acl->refLabel('group:pflege-nord'));
	}

	public function testUnknownAndMalformedRefsResolveNull(): void
	{
		$acl = $this->acl([], [], []);
		foreach ([null, '', 'plain', 'member:', 'member:abc', 'member:999', 'uid:ghost', 'group:gone', 'station:1'] as $bad) {
			$this->assertNull($acl->refLabel($bad), "leaked a label for {$bad}");
		}
	}

	public function testEmptyDisplayNameFallsBackToId(): void
	{
		$acl = $this->acl([], ['serviceacct' => ''], ['g1' => '']);
		$this->assertSame('serviceacct', $acl->refLabel('uid:serviceacct'));
		$this->assertSame('g1', $acl->refLabel('group:g1'));
	}
}
