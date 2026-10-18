<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Service\PrivacyProposalService;
use OCA\CareCheck\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F15 consumer: state + dedupe + fail-closed probing. The success path runs
 * against the live privacycheck facade in-container (unit env has no
 * server container to resolve SuiteProposalService from).
 */
class PrivacyProposalServiceTest extends TestCase
{
	private function make(bool $appEnabled, int $storedId = 0): PrivacyProposalService
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($appEnabled);
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueInt')
			->with(Constants::APP_ID, 'privacy_vvt_activity_id', 0)
			->willReturn($storedId);
		$settings = $this->createMock(SettingsService::class);
		return new PrivacyProposalService($apps, $cfg, $settings, $this->createMock(LoggerInterface::class));
	}

	public function testUnavailableWhenPrivacycheckDisabled(): void
	{
		$svc = $this->make(false);
		$this->assertFalse($svc->available());
		$this->assertSame(['available' => false, 'activityId' => null], $svc->state());
	}

	public function testProposeFailsClosedWhenSiblingMissing(): void
	{
		$svc = $this->make(false);
		$res = $svc->propose('admin1');
		$this->assertFalse($res['ok']);
		$this->assertSame('sibling_unavailable', $res['code']);
		$this->assertArrayNotHasKey('activityId', $res);
	}

	public function testProposeIsIdempotentOnceActivityStored(): void
	{
		// stored id → replay returns it without touching the sibling
		// (privacycheck disabled proves no facade call happens).
		$svc = $this->make(false, 42);
		$res = $svc->propose('admin1');
		$this->assertTrue($res['ok']);
		$this->assertTrue($res['already']);
		$this->assertSame(42, $res['activityId']);
		$this->assertSame(42, $svc->state()['activityId']);
	}

	public function testStateReportsStoredDraftEvenWhenSiblingAbsent(): void
	{
		// privacycheck uninstalled later → state still shows the filed draft
		// id; the UI renders "filed" rather than re-offering the CTA.
		$svc = $this->make(false, 7);
		$this->assertSame(['available' => false, 'activityId' => 7], $svc->state());
	}
}
