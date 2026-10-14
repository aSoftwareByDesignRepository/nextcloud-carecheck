<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Db\Member;
use OCA\CareCheck\Db\MemberMapper;
use OCA\CareCheck\Db\Pair;
use OCA\CareCheck\Db\PairMapper;
use OCA\CareCheck\Exception\ApiException;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\Lock\ILockingProvider;

/**
 * Pairing. Two auth families mint the same wire credential
 * (`X-CCK-Device-Token`):
 *  - member mode: Zentrale issues a single-use 8-char code (unambiguous
 *    alphabet, ≤15 min TTL); the device redeems it for a random 32-byte
 *    token bound to a member identity.
 *  - worker mode: an NC-authenticated session (Login Flow v2 app password)
 *    registers a device label → same random token bound to `uid:{uid}`.
 * Only token hashes are stored. Both paths claim one CCK2 mobile seat;
 * redemption is rate-limited per network subject.
 */
class PairService
{
	public function __construct(
		private IDBConnection $db,
		private PairMapper $pairs,
		private MemberMapper $members,
		private SettingsService $settings,
		private LicenseService $license,
		private RateLimitService $rateLimit,
		private IRequest $request,
		private IGroupManager $groups,
		private ILockingProvider $locking,
	) {
	}

	/** Issue a fresh pairing code for a member. Zentrale-side. */
	public function issueCode(int $memberId): array
	{
		$member = $this->members->find($memberId);
		if ($member === null || (int)$member->getActive() === 0) {
			throw ApiException::notFound();
		}
		$code = $this->randomCode();
		$p = new Pair();
		$p->setMemberId($memberId);
		$p->setKind('member');
		$p->setCodeHash(hash('sha256', $code));
		$p->setCodeExpires((new \DateTime())->modify('+' . max(1, $this->settings->getInt('pair_code_ttl_min')) . ' minutes'));
		$p->setCodeUsed(0);
		$p->setRevoked(0);
		$p->setCreatedAt(new \DateTime());
		$p = $this->pairs->insert($p);
		return ['code' => $code, 'expiresAt' => $p->getCodeExpires()->format(\DateTimeInterface::ATOM)];
	}

	/**
	 * Redeem a code → device token. Single-use, expiry-checked, license-gated.
	 * @return array{deviceToken:string,member:array{id:int,displayName:string,qualification:string}}
	 */
	public function redeem(string $code, ?string $deviceLabel): array
	{
		// Redemption is the public door: bounded per remote subject so a code
		// can't be brute-forced (8-char unambiguous alphabet, single-use).
		$this->rateLimit->hit('pair_redeem', $this->remoteSubject(), 10, 600);
		$pair = $this->pairForCode($code);
		if ($pair === null || (int)$pair->getCodeUsed() === 1 || $pair->getCodeExpires() < new \DateTime()) {
			throw ApiException::validation('Invalid or expired code.', ['code' => 'invalid']);
		}
		$member = $this->members->find((int)$pair->getMemberId());
		if ($member === null || (int)$member->getActive() === 0) {
			throw ApiException::validation('Invalid or expired code.', ['code' => 'invalid']);
		}
		$token = bin2hex(random_bytes(32));
		// Seat check + single-use claim run under one lock, and the claim is a
		// conditional UPDATE — a concurrent redemption of the same code flips
		// `code_used` first and the loser gets the uniform invalid-code error.
		$claimed = \OCA\CareCheck\Support\LockGuard::run($this->locking, 'cck-seat-mobile', function () use ($pair, $token, $deviceLabel) {
			$this->assertSeatAvailable();
			$upd = $this->db->getQueryBuilder();
			$upd->update('cck_pair')
				->set('code_used', $upd->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
				->set('token_hash', $upd->createNamedParameter(hash('sha256', $token)))
				->set('device_label', $upd->createNamedParameter($deviceLabel !== null ? mb_substr($deviceLabel, 0, 190) : null))
				->set('redeemed_at', $upd->createNamedParameter(new \DateTime(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATETIME_MUTABLE))
				->where($upd->expr()->eq('id', $upd->createNamedParameter((int)$pair->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('code_used', $upd->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->gt('code_expires', $upd->createNamedParameter(new \DateTime(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATETIME_MUTABLE)));
			return $upd->executeStatement() === 1;
		});
		if (!$claimed) {
			throw ApiException::validation('Invalid or expired code.', ['code' => 'invalid']);
		}
		return [
			'deviceToken' => $token,
			'member' => [
				'id' => (int)$member->getId(),
				'displayName' => (string)$member->getDisplayName(),
				'qualification' => (string)$member->getQualification(),
			],
		];
	}

	/**
	 * Worker mode (Family A): an NC session/app-password registers a named
	 * device → device token bound to `uid:{uid}`. Same wire credential as
	 * member mode, same seat unit, same license gate.
	 */
	public function registerWorkerDevice(string $uid, ?string $deviceLabel): array
	{
		if ($uid === '') {
			throw new ApiException(Constants::ERR_AUTH, 'Unauthorized.', [], 401);
		}
		$this->rateLimit->hit('pair_register', 'uid:' . $uid, 10, 600);
		$token = bin2hex(random_bytes(32));
		$p = new Pair();
		$p->setUid($uid);
		$p->setKind('worker');
		$p->setTokenHash(hash('sha256', $token));
		$p->setDeviceLabel($deviceLabel !== null ? mb_substr($deviceLabel, 0, 190) : null);
		$p->setCodeUsed(1);
		$p->setRedeemedAt(new \DateTime());
		$p->setRevoked(0);
		$p->setCreatedAt(new \DateTime());
		// seat check + insert under the lock — two concurrent registrations
		// cannot both observe the same free seat
		$p = \OCA\CareCheck\Support\LockGuard::run($this->locking, 'cck-seat-mobile', function () use ($p) {
			$this->assertSeatAvailable();
			return $this->pairs->insert($p);
		});
		return [
			'deviceToken' => $token,
			'member' => null,
			'worker' => ['uid' => $uid],
		];
	}

	/**
	 * NH-09 — station/kiosk mode: a shared Sozialstation tablet gets a
	 * `kind='station'` token with SIGN-OFF scope only (board view + sign-off
	 * confirmation, never client payloads). Consumes the `stations`
	 * entitlement — a separate pool from mobileSeats. Zentrale pairs it
	 * via a staff session; the device itself never sees client detail.
	 */
	public function registerStationDevice(string $actorRef, ?string $deviceLabel): array
	{
		$this->aclCheckStaff($actorRef);
		$this->rateLimit->hit('pair_station', $actorRef, 10, 600);
		if (!$this->license->isStationPlanActive()) {
			throw new ApiException('NO_STATION_SEAT', 'No station entitlement — upgrade your license.', [], 402);
		}
		$token = bin2hex(random_bytes(32));
		$p = new Pair();
		$p->setKind('station');
		$p->setTokenHash(hash('sha256', $token));
		$p->setDeviceLabel($deviceLabel !== null ? mb_substr($deviceLabel, 0, 190) : null);
		$p->setCodeUsed(1);
		$p->setRedeemedAt(new \DateTime());
		$p->setRevoked(0);
		$p->setCreatedAt(new \DateTime());
		$p = \OCA\CareCheck\Support\LockGuard::run($this->locking, 'cck-seat-station', function () use ($p) {
			if ($this->stationSeatsUsed() >= $this->license->getStationLimit()) {
				throw new ApiException('NO_SEAT', 'Station limit reached — contact your vendor.', [], 402);
			}
			return $this->pairs->insert($p);
		});
		return ['deviceToken' => $token, 'station' => ['id' => (int)$p->getId()]];
	}

	public function stationSeatsUsed(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('cck_pair')
			->where($qb->expr()->isNotNull('token_hash'))
			->andWhere($qb->expr()->eq('revoked', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter('station')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function aclCheckStaff(string $actorRef): void
	{
		// mirrors AclService::isStaff — PairService can't inject AclService
		// (AclService needs PairMapper for actor resolution → circular dep)
		if (!str_starts_with($actorRef, 'uid:')) {
			throw ApiException::notFound();
		}
		$uid = substr($actorRef, 4);
		if (!$this->groups->isAdmin($uid)
			&& !$this->groups->isInGroup($uid, Constants::GROUP_ZENTRALE)
			&& !$this->groups->isInGroup($uid, Constants::GROUP_APP_ADMIN)) {
			throw ApiException::notFound();
		}
	}

	/** Mobile seats = non-revoked member + worker devices (stations have their own pool). */
	public function seatsUsed(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('cck_pair')
			->where($qb->expr()->isNotNull('token_hash'))
			->andWhere($qb->expr()->eq('revoked', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->in('kind', $qb->createNamedParameter(['member', 'worker'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function assertSeatAvailable(): void
	{
		$this->license->requireMobileLicense();
		if ($this->seatsUsed() >= $this->license->getMobileSeatLimit()) {
			throw new ApiException('NO_SEAT', 'Seat limit reached — contact your Zentrale.', [], 402);
		}
	}

	public function revokeDevice(int $pairId): void
	{
		$p = $this->pairs->find($pairId);
		if ($p === null) {
			throw ApiException::notFound();
		}
		$p->setRevoked(1);
		$this->pairs->update($p);
	}

	/**
	 * Self-unpair (DELETE /api/v1/pair): the device revokes its own token.
	 * Effective on the next request — the seat is freed immediately.
	 */
	public function revokeByToken(string $token): void
	{
		$p = $this->pairForTokenRow($token);
		if ($p === null) {
			throw ApiException::notFound(); // uniform — an invalid token learns nothing
		}
		$p->setRevoked(1);
		$this->pairs->update($p);
	}

	/** Deactivation cascade: all of a member's devices lose access + seats free. */
	public function revokeByMember(int $memberId): int
	{
		$n = 0;
		foreach ($this->pairs->findAll() as $p) {
			if ((int)$p->getMemberId() === $memberId && (int)$p->getRevoked() === 0) {
				$p->setRevoked(1);
				$this->pairs->update($p);
				$n++;
			}
		}
		return $n;
	}

	private function pairForTokenRow(string $token): ?Pair
	{
		return $this->pairs->findActiveByTokenHash(hash('sha256', $token));
	}

	private function remoteSubject(): string
	{
		$ip = $this->request->getRemoteAddress();
		return 'ip:' . substr(hash('sha256', $ip !== '' ? $ip : 'unknown'), 0, 24);
	}

	private function pairForCode(string $code): ?Pair
	{
		return $this->pairs->findByCodeHash(hash('sha256', strtoupper(trim($code))));
	}

	private function randomCode(): string
	{
		$alphabet = Constants::PAIR_CODE_ALPHABET;
		$code = '';
		for ($i = 0; $i < Constants::PAIR_CODE_LEN; $i++) {
			$code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		return $code;
	}
}
