<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Db\ClientMapper;
use OCA\CareCheck\Exception\ApiException;

/**
 * NH-14 — QR/NFC presence proof at the client home (anti-fraud hardening
 * on top of the signature, never a replacement for it).
 *
 * Design (TourCheck checkpoint pattern, slimmed):
 *   - Staff mints a per-client token: a random secret; only its SHA-256
 *     lands in `cck_client.presence_hash`. The printed QR carries the
 *     cleartext token (`cck-pres:{clientId}:{secret}`) — a photo of the
 *     QR IS the credential, exactly like a printed NFC tag, so storing
 *     the cleartext server-side would add nothing but a leak surface.
 *   - Companion scans the QR at check-in and sends `presence_token`;
 *     the server hashes it and compares against the client row — match
 *     → `presence_ok=1` on the visit + journal evidence; mismatch →
 *     check-in still succeeds (presence proof is additive evidence, a
 *     broken QR must not block care) but the flag stays 0.
 *   - Rotation = mint a new token; the old QR dies instantly.
 */
class PresenceService
{
	public const TOKEN_PREFIX = 'cck-pres:';

	public function __construct(
		private ClientMapper $clients,
		private AclService $acl,
	) {
	}

	/**
	 * Mint + store a fresh presence token for the client (staff only —
	 * printing the QR is a Zentrale task). Returns the QR payload; the
	 * secret is never retrievable afterwards (hash only).
	 */
	public function mint(string $actorRef, int $clientId): array
	{
		$this->acl->assertStaff($actorRef);
		$this->acl->assertClientScope($actorRef, $clientId);
		$c = $this->clients->find($clientId);
		if ($c === null) {
			throw ApiException::notFound();
		}
		$secret = bin2hex(random_bytes(16));
		$c->setPresenceHash(hash('sha256', $secret));
		$this->clients->update($c);
		return [
			'qr' => self::TOKEN_PREFIX . $clientId . ':' . $secret,
			'clientId' => $clientId,
		];
	}

	/**
	 * Verify a scanned token against the client's stored hash.
	 * Returns true only on an exact hash match for THIS client — a token
	 * from another client (wrong home, copied QR) never validates.
	 */
	public function verify(int $clientId, string $token): bool
	{
		if (!str_starts_with($token, self::TOKEN_PREFIX)) {
			return false;
		}
		$parts = explode(':', substr($token, strlen(self::TOKEN_PREFIX)), 2);
		if (count($parts) !== 2 || (int)$parts[0] !== $clientId) {
			return false;
		}
		$c = $this->clients->find($clientId);
		$stored = $c?->getPresenceHash();
		if ($stored === null || $stored === '') {
			return false;
		}
		return hash_equals($stored, hash('sha256', $parts[1]));
	}
}
