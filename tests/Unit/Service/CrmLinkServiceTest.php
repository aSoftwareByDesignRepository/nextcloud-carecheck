<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Db\Client;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\CrmLinkService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * F14 consumer: soft link care client → CRM company. The unit env has no
 * CustomerCheck container to resolve the facade from, so the live
 * `searchForPicker`/`findByCompanyId` paths run in-container; here we prove
 * the fail-closed surface, the uniform-404 write path, and unlink freedom.
 */
class CrmLinkServiceTest extends TestCase
{
	private function svc(bool $installed, ?Client $client = null): array
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($installed);
		$clients = $this->createMock(ClientMapper::class);
		$clients->method('find')->willReturn($client);
		$clients->method('update')->willReturnArgument(0);
		$acl = $this->createMock(AclService::class);
		return [new CrmLinkService($apps, $clients, $acl), $acl, $clients];
	}

	public function testCompaniesFailClosedWhenCucAbsent(): void
	{
		[$svc] = $this->svc(false);
		$this->assertSame(['available' => false, 'companies' => []], $svc->companies('uid:admin1', 'acme'));
	}

	public function testCompaniesNeverEnumerateWithoutQuery(): void
	{
		// No facade is resolvable in the unit env, but the empty-query early
		// return must hold even where the facade IS available: the CRM book
		// is search-only, never bulk-listed through a care surface.
		[$svc] = $this->svc(true);
		$out = $svc->companies('uid:admin1', '');
		$this->assertSame([], $out['companies']);
	}

	public function testLinkInfoKeepsStaleIdVisible(): void
	{
		// Stored id + unresolvable facade → id survives with null name so the
		// UI renders "no longer available" instead of dropping the relation.
		[$svc] = $this->svc(false);
		$info = $svc->linkInfo('uid:admin1', 42);
		$this->assertSame(42, $info['id']);
		$this->assertNull($info['name']);
		$this->assertFalse($info['available']);
	}

	public function testLinkInfoNullIdIsClean(): void
	{
		[$svc] = $this->svc(false);
		$info = $svc->linkInfo('uid:admin1', null);
		$this->assertNull($info['id']);
		$this->assertNull($info['name']);
	}

	public function testSetLinkUnknownClientIs404(): void
	{
		[$svc] = $this->svc(true, null);
		$this->expectException(ApiException::class);
		$svc->setLink('uid:admin1', 99, 5);
	}

	public function testSetLinkUnresolvableCompanyIs404(): void
	{
		$c = new Client();
		$c->setId(1);
		$c->setActive(1);
		[$svc] = $this->svc(true, $c); // no facade → company cannot resolve
		$this->expectException(ApiException::class);
		$svc->setLink('uid:admin1', 1, 5);
	}

	public function testClearLinkNeedsNoFacade(): void
	{
		$c = new Client();
		$c->setId(1);
		$c->setActive(1);
		$c->setCrmCompanyId(5);
		// Unlink must work while CustomerCheck is down — a dead sibling must
		// not trap a stale link.
		[$svc, $acl] = $this->svc(false, $c);
		$acl->expects($this->once())->method('audit')->with('uid:admin1', 'crm_link', 1, 'company:none');
		$svc->setLink('uid:admin1', 1, null);
		$this->assertNull($c->getCrmCompanyId());
	}

	public function testArchivedClientCannotBeLinked(): void
	{
		$c = new Client();
		$c->setId(1);
		$c->setActive(0);
		[$svc] = $this->svc(true, $c);
		$this->expectException(ApiException::class);
		$svc->setLink('uid:admin1', 1, 5);
	}
}
