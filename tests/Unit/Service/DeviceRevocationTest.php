<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Db\MemberMapper;
use OCA\CareCheck\Db\Pair;
use OCA\CareCheck\Db\PairMapper;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Service\LicenseService;
use OCA\CareCheck\Service\PairService;
use OCA\CareCheck\Service\RateLimitService;
use OCA\CareCheck\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * US-209 single-device revocation: the member list carries live devices
 * (id + label) so staff can revoke one lost phone; a repeat revoke is a
 * uniform 404 — no second success, no resurrection signal.
 */
class DeviceRevocationTest extends TestCase
{
	private function pairService(PairMapper $pairs): PairService
	{
		return new PairService(
			$this->createMock(IDBConnection::class),
			$pairs,
			$this->createMock(MemberMapper::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LicenseService::class),
			$this->createMock(RateLimitService::class),
			$this->createMock(IRequest::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ILockingProvider::class),
		);
	}

	private function deviceRow(int $id, int $memberId, ?string $label, int $revoked = 0, bool $redeemed = true): Pair
	{
		$p = new Pair();
		$p->setId($id);
		$p->setMemberId($memberId);
		$p->setKind('member');
		$p->setDeviceLabel($label);
		$p->setRevoked($revoked);
		$p->setTokenHash($redeemed ? hash('sha256', 'tok' . $id) : null);
		$p->setLastSeen(new \DateTime('2026-11-04 08:00:00'));
		return $p;
	}

	public function testDevicesByMemberListsOnlyLiveRedeemedRows(): void
	{
		$pairs = $this->createMock(PairMapper::class);
		$pairs->method('findAll')->willReturn([
			$this->deviceRow(11, 5, 'Pixel Anna'),
			$this->deviceRow(12, 5, null),
			$this->deviceRow(13, 5, 'old phone', revoked: 1),
			$this->deviceRow(14, 5, 'unredeemed code', redeemed: false),
			$this->deviceRow(15, 8, 'other member'),
		]);
		$svc = $this->pairService($pairs);
		$devices = $svc->devicesByMember();
		$this->assertSame([11, 12], array_map(static fn (array $d) => $d['id'], $devices[5]));
		$this->assertSame('Pixel Anna', $devices[5][0]['label']);
		$this->assertNull($devices[5][1]['label']);
		$this->assertSame([15], array_map(static fn (array $d) => $d['id'], $devices[8]));
	}

	public function testRevokeDeviceRepeatIsUniform404(): void
	{
		$p = $this->deviceRow(11, 5, 'Pixel Anna', revoked: 1);
		$pairs = $this->createMock(PairMapper::class);
		$pairs->method('find')->with(11)->willReturn($p);
		$pairs->expects($this->never())->method('update');
		$svc = $this->pairService($pairs);
		try {
			$svc->revokeDevice(11);
			$this->fail('repeat revoke must throw');
		} catch (ApiException $e) {
			$this->assertSame('NOT_FOUND', $e->getErrorCode());
			$this->assertSame(404, $e->getHttpStatus());
		}
	}

	public function testRevokeDeviceMarksLiveRow(): void
	{
		$p = $this->deviceRow(11, 5, 'Pixel Anna');
		$pairs = $this->createMock(PairMapper::class);
		$pairs->method('find')->with(11)->willReturn($p);
		$pairs->expects($this->once())->method('update')->with($p);
		$svc = $this->pairService($pairs);
		$svc->revokeDevice(11);
		$this->assertSame(1, (int)$p->getRevoked());
	}
}
