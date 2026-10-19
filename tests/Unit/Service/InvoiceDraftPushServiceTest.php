<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Db\Client;
use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Service\BillingDraftService;
use OCA\CareCheck\Service\CrmLinkService;
use OCA\CareCheck\Service\InvoiceDraftPushService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F13 push adapter — unit env has no InvoiceCheck container, so the live
 * facade call runs in-container; here we prove the coded fail-soft surface
 * (never throws, never fabricates identity) around it.
 */
final class InvoiceDraftPushServiceTest extends TestCase
{
	private function svc(
		bool $ivEnabled,
		?Client $client = null,
		?int $pcCustomerId = null,
		array $drafts = [],
	): InvoiceDraftPushService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($ivEnabled);
		$clients = $this->createMock(ClientMapper::class);
		$clients->method('find')->willReturn($client);
		$crm = $this->createMock(CrmLinkService::class);
		$crm->method('pcCustomerIdFor')->willReturn($pcCustomerId);
		$draftsSvc = $this->createMock(BillingDraftService::class);
		$draftsSvc->method('drafts')->willReturn($drafts);
		return new InvoiceDraftPushService($apps, $clients, $crm, $draftsSvc,
			$this->createMock(LoggerInterface::class));
	}

	private function client(?int $companyId = 5): Client
	{
		$c = new Client();
		$c->setId(3);
		$c->setActive(1);
		$c->setCrmCompanyId($companyId);
		return $c;
	}

	public function testRejectsNonUidActor(): void
	{
		// member:/station: refs cannot own an InvoiceCheck draft — the facade
		// needs a real uid to authorize against.
		$r = $this->svc(true, $this->client())->push('member:7', 3, '2026-10', 'haeusliche_betreuung');
		$this->assertSame(['ok' => false, 'code' => 'bad_request'], $r);
	}

	public function testRejectsBadKindAndPeriod(): void
	{
		$svc = $this->svc(true, $this->client());
		$this->assertSame('bad_request', $svc->push('uid:u1', 3, '2026-10', 'bogus_kind')['code']);
		$this->assertSame('bad_request', $svc->push('uid:u1', 3, '10/2026', 'haeusliche_betreuung')['code']);
	}

	public function testRejectsUnknownOrInactiveClient(): void
	{
		// 'not_found' — the controller renders the canonical 404 so a staff
		// caller cannot enumerate client ids by error class.
		$this->assertSame('not_found', $this->svc(true, null)->push('uid:u1', 9, '2026-10', 'haeusliche_betreuung')['code']);
		$inactive = $this->client();
		$inactive->setActive(0);
		$this->assertSame('not_found', $this->svc(true, $inactive)->push('uid:u1', 3, '2026-10', 'haeusliche_betreuung')['code']);
	}

	public function testSiblingUnavailableWhenIvDisabled(): void
	{
		$r = $this->svc(false, $this->client(), 77)->push('uid:u1', 3, '2026-10', 'haeusliche_betreuung');
		$this->assertSame('sibling_unavailable', $r['code']);
	}

	public function testNotLinkedWhenNoCrmCompany(): void
	{
		// Link checks precede the availability gate — deterministic in both
		// host-unit and in-container runs.
		$r = $this->svc(false, $this->client(null))->push('uid:u1', 3, '2026-10', 'haeusliche_betreuung');
		$this->assertSame('not_linked', $r['code']);
	}

	public function testNoPcCustomerWhenCompanyLacksMapping(): void
	{
		$r = $this->svc(true, $this->client(5), null)->push('uid:u1', 3, '2026-10', 'haeusliche_betreuung');
		$this->assertSame('no_pc_customer', $r['code']);
	}

	public function testNoVisitsWhenPeriodHasNoSignedVisits(): void
	{
		// Valid link chain, IV availability is env-dependent — when the
		// facade resolves (container run) the drafts gate must fire; on a
		// bare host it can only ever be sibling_unavailable.
		$r = $this->svc(true, $this->client(5), 77, [])->push('uid:u1', 3, '2026-10', 'haeusliche_betreuung');
		$this->assertContains($r['code'], ['no_visits', 'sibling_unavailable']);
	}
}
