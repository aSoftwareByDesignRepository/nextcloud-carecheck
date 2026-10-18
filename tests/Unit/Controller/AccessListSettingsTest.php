<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Controller;

use OCA\CareCheck\Controller\ApiController;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * DS-2 access lists: the refpick sends typed refs (uid:x / group:g) but the
 * lists store plain ids — saveSettings must normalize or every UI save of a
 * newly picked principal fails validation. getSettings must ship parallel
 * *_labels so chips never render raw ids.
 */
class AccessListSettingsTest extends TestCase
{
	/** @var array<string, list<string>> captured setList calls */
	private array $savedLists = [];
	/** @var list<string> every uid handed to userExists() */
	private array $userLookups = [];
	/** @var list<string> every gid handed to groupExists() */
	private array $groupLookups = [];
	/** @var list<array{0:string,1:string,2:?int,3:?string}> audit() calls */
	private array $auditCalls = [];

	/**
	 * @param array<string, mixed> $params getParam map
	 * @param array<string, string> $uidNames uid => displayName ('' = blank name)
	 * @param array<string, string> $gidNames gid => displayName
	 * @param array<string, list<string>> $storedLists persisted list state
	 */
	private function controller(array $params, array $uidNames, array $gidNames, array $storedLists = []): ApiController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $k, $default = null) => $params[$k] ?? $default
		);

		$acl = $this->createMock(AclService::class);
		$acl->method('assertAppAccess')->willReturn('admin');
		$acl->method('actorRef')->willReturn('uid:admin');
		$acl->method('isAppAdmin')->willReturn(true);
		$this->auditCalls = [];
		$acl->method('audit')->willReturnCallback(
			function (string $ref, string $action, ?int $cid = null, ?string $detail = null) {
				$this->auditCalls[] = [$ref, $action, $cid, $detail];
			}
		);

		$this->userLookups = [];
		$this->groupLookups = [];
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(
			function (string $u) use ($uidNames) {
				$this->userLookups[] = $u;
				return array_key_exists($u, $uidNames);
			}
		);
		$users->method('get')->willReturnCallback(function (string $u) use ($uidNames) {
			if (!array_key_exists($u, $uidNames)) {
				return null;
			}
			$i = $this->createMock(IUser::class);
			$i->method('getDisplayName')->willReturn($uidNames[$u]);
			return $i;
		});

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(
			function (string $g) use ($gidNames) {
				$this->groupLookups[] = $g;
				return array_key_exists($g, $gidNames);
			}
		);
		$groups->method('get')->willReturnCallback(function (string $g) use ($gidNames) {
			if (!array_key_exists($g, $gidNames)) {
				return null;
			}
			$i = $this->createMock(IGroup::class);
			$i->method('getDisplayName')->willReturn($gidNames[$g]);
			return $i;
		});

		$settings = $this->createMock(SettingsService::class);
		$settings->method('get')->willReturn('');
		$settings->method('getInt')->willReturn(0);
		$settings->method('getList')->willReturnCallback(
			fn (string $k) => $storedLists[$k] ?? []
		);
		$this->savedLists = [];
		$settings->method('setList')->willReturnCallback(function (string $k, array $v) {
			$this->savedLists[$k] = $v;
		});

		$ctrl = (new \ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
		foreach (['acl' => $acl, 'users' => $users, 'groups' => $groups, 'settings' => $settings] as $name => $dep) {
			(new \ReflectionProperty(ApiController::class, $name))->setValue($ctrl, $dep);
		}
		(new \ReflectionProperty(Controller::class, 'request'))->setValue($ctrl, $request);
		return $ctrl;
	}

	public function testTypedRefsNormalizeToPlainIdsOnSave(): void
	{
		$ctrl = $this->controller(
			[
				'allowed_users' => '["uid:alex","bea"]',
				'allowed_groups' => '["group:nurses"]',
				'app_admins' => '["uid:root"]',
			],
			['alex' => 'Alex', 'bea' => 'Bea', 'root' => 'Root'],
			['nurses' => 'Nurses'],
		);
		$r = $ctrl->saveSettings();
		$this->assertTrue($r->getData()['success'] ?? false, json_encode($r->getData()));
		$this->assertSame(['alex', 'bea'], $this->savedLists['allowed_users']);
		$this->assertSame(['nurses'], $this->savedLists['allowed_groups']);
		$this->assertSame(['root'], $this->savedLists['app_admins']);
	}

	public function testGhostAndForeignKindRefsStayRejected(): void
	{
		$ctrl = $this->controller(
			['allowed_users' => '["uid:ghost"]'],
			['alex' => 'Alex'],
			[],
		);
		$r = $ctrl->saveSettings();
		$d = $r->getData();
		$this->assertFalse($d['success']);
		$this->assertSame('invalid', $d['fields']['allowed_users']);

		// member: refs are a different kind — they must never leak into a uid list
		$ctrl = $this->controller(
			['allowed_users' => '["member:3"]'],
			['alex' => 'Alex'],
			[],
		);
		$d = $ctrl->saveSettings()->getData();
		$this->assertSame('invalid', $d['fields']['allowed_users']);
		$this->assertNotContains('member:3', $this->userLookups);
	}

	public function testEmptyAndPrefixOnlyItemsRejectedBeforeLookup(): void
	{
		// "uid:" strips to an empty id — must be rejected before the existence
		// check, so userExists('') is never consulted.
		$ctrl = $this->controller(
			['allowed_users' => '["uid:","alex"]'],
			['alex' => 'Alex'],
			[],
		);
		$d = $ctrl->saveSettings()->getData();
		$this->assertSame('invalid', $d['fields']['allowed_users']);
		$this->assertNotContains('', $this->userLookups);
		$this->assertArrayNotHasKey('allowed_users', $this->savedLists);
	}

	public function testPolicySaveEmitsAuditRecord(): void
	{
		$ctrl = $this->controller(
			['allowed_users' => '["uid:alex"]', 'seat_limit' => '12'],
			['alex' => 'Alex'],
			[],
		);
		$r = $ctrl->saveSettings();
		$this->assertTrue($r->getData()['success'] ?? false);
		$this->assertCount(1, $this->auditCalls);
		[$ref, $action, , $detail] = $this->auditCalls[0];
		$this->assertSame('uid:admin', $ref);
		$this->assertSame('settings_save', $action);
		// keys only — never uids/gids of the mutated lists
		$this->assertSame('seat_limit,allowed_users', $detail);
	}

	public function testFailedValidationAndNoopSaveEmitNoAudit(): void
	{
		$ctrl = $this->controller(['allowed_users' => '["uid:ghost"]'], ['alex' => 'Alex'], []);
		$this->assertFalse($ctrl->saveSettings()->getData()['success']);
		$this->assertSame([], $this->auditCalls);

		$ctrl = $this->controller([], ['alex' => 'Alex'], []);
		$this->assertTrue($ctrl->saveSettings()->getData()['success']);
		$this->assertSame([], $this->auditCalls);
	}

	public function testGetSettingsShipsParallelLabelLists(): void
	{
		$ctrl = $this->controller(
			[],
			['alex' => 'Alex Mäule', 'noname' => ''],
			['nurses' => 'Pflege Nord'],
			[
				'allowed_users' => ['alex', 'noname', 'gone'],
				'allowed_groups' => ['nurses'],
				'app_admins' => [],
			],
		);
		$d = $ctrl->getSettings()->getData();
		$this->assertTrue($d['success']);
		$s = $d['data']['settings'];
		$this->assertSame(['alex', 'noname', 'gone'], $s['allowed_users']);
		// names where resolvable, raw id only as last resort
		$this->assertSame(['Alex Mäule', 'noname', 'gone'], $s['allowed_users_labels']);
		$this->assertSame(['Pflege Nord'], $s['allowed_groups_labels']);
		$this->assertSame([], $s['app_admins_labels']);
	}
}
