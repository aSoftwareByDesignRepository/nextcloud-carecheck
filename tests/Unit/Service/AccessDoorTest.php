<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Db\AuditMapper;
use OCA\CareCheck\Db\BreakGlassMapper;
use OCA\CareCheck\Db\MemberMapper;
use OCA\CareCheck\Db\PairMapper;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * DS-2 access door — the deny/allow matrix is security-critical:
 * 'restricted' must not accidentally admit anyone, 'open' must not
 * accidentally lock out legitimate users.
 */
class AccessDoorTest extends TestCase
{
	private function acl(array $lists, string $mode, ?string $uid, array $groupOf): AclService
	{
		$session = $this->createMock(IUserSession::class);
		$user = $uid === null ? null : $this->createMock(IUser::class);
		if ($user !== null) {
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $u) => in_array('admin', $groupOf[$u] ?? [], true));
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $u, string $g) => in_array($g, $groupOf[$u] ?? [], true)
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturnCallback(fn (string $k) => $k === 'access_mode' ? $mode : '');
		$settings->method('getList')->willReturnCallback(fn (string $k) => $lists[$k] ?? []);
		$settings->method('getInt')->willReturn(0);

		return new AclService(
			$this->createMock(IDBConnection::class),
			$session,
			$groups,
			$this->createMock(IUserManager::class),
			$this->createMock(PairMapper::class),
			$this->createMock(MemberMapper::class),
			$this->createMock(BreakGlassMapper::class),
			$this->createMock(AuditMapper::class),
			$settings,
			$this->createMock(\OCA\CareCheck\Db\TeamLeadMapper::class),
		);
	}

	public function testOpenModeAdmitsAnyAuthenticatedUser(): void
	{
		$acl = $this->acl([], 'open', 'someone', []);
		$this->assertTrue($acl->hasAppAccess());
		$this->assertSame('someone', $acl->assertAppAccess());
	}

	public function testRestrictedDeniesUnlistedUser(): void
	{
		$acl = $this->acl([], 'restricted', 'outsider', []);
		$this->assertFalse($acl->hasAppAccess());
	}

	public function testRestrictedAdmitsListedUser(): void
	{
		$acl = $this->acl(['allowed_users' => ['allowed1']], 'restricted', 'allowed1', []);
		$this->assertTrue($acl->hasAppAccess());
	}

	public function testRestrictedAdmitsListedGroupMember(): void
	{
		$acl = $this->acl(['allowed_groups' => ['pflege-team']], 'restricted', 'nurse1', ['nurse1' => ['pflege-team']]);
		$this->assertTrue($acl->hasAppAccess());
	}

	public function testRestrictedAdmitsRoleGroupMembers(): void
	{
		$acl = $this->acl([], 'restricted', 'zen', ['zen' => [Constants::GROUP_ZENTRALE]]);
		$this->assertTrue($acl->hasAppAccess());
		$acl2 = $this->acl([], 'restricted', 'buch', ['buch' => [Constants::GROUP_BUCHHALTUNG]]);
		$this->assertTrue($acl2->hasAppAccess());
	}

	public function testRestrictedAdmitsNamedAppAdmin(): void
	{
		$acl = $this->acl(['app_admins' => ['delegated1']], 'restricted', 'delegated1', []);
		$this->assertTrue($acl->hasAppAccess());
	}

	public function testRestrictedAdminsAlwaysIn(): void
	{
		$acl = $this->acl([], 'restricted', 'root', ['root' => ['admin']]);
		$this->assertTrue($acl->hasAppAccess());
	}

	public function testAssertAppAccessIsUniform404(): void
	{
		$acl = $this->acl([], 'restricted', 'outsider', []);
		try {
			$acl->assertAppAccess();
			$this->fail('should throw');
		} catch (ApiException $e) {
			$this->assertSame(404, $e->getHttpStatus());
			$this->assertSame(Constants::ERR_NOT_FOUND, $e->getErrorCode());
		}
	}

	public function testAnonymousDeniedEvenInOpenMode(): void
	{
		$acl = $this->acl([], 'open', null, []);
		$this->assertFalse($acl->hasAppAccess());
	}
}
