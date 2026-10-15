<?php

declare(strict_types=1);

namespace OCA\CareCheck\Config;

/**
 * Embedded vendor Ed25519 public key (32 bytes) for CCK2 verification.
 *
 * Env override allowed only under PHPUnit or CCK_ALLOW_VENDOR_KEY_OVERRIDE=1.
 */
final class VendorPublicKey
{
	public const DEFAULT_PUBLIC_KEY_B64 = 'naLgi4THUgwJCRoUehq20QU4uJsLVHzuKV04NhkITn8';

	/** Seed: sha256("carecheck-cck2-test-signing-v1"). */
	public const TEST_PUBLIC_KEY_B64 = '8N3C7yprjOahOs1hXwrFWytgnUajeND8iNZ0CTG6-rU';

	public static function publicKeyB64(): string
	{
		if (self::envOverrideAllowed()) {
			$fromEnv = getenv('CCK_VENDOR_PUBLIC_KEY_B64');
			if (is_string($fromEnv) && trim($fromEnv) !== '') {
				return trim($fromEnv);
			}
		}
		return self::DEFAULT_PUBLIC_KEY_B64;
	}

	public static function envOverrideAllowed(): bool
	{
		if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('PHPUNIT_RUNNING') || defined('PHPUNIT_RUN')) {
			return true;
		}
		return getenv('CCK_ALLOW_VENDOR_KEY_OVERRIDE') === '1';
	}

	/** @return non-empty-string */
	public static function bytes(): string
	{
		$decoded = self::base64urlDecode(self::publicKeyB64());
		if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			throw new \RuntimeException('Invalid vendor public key configuration.');
		}
		return $decoded;
	}

	public static function base64urlDecode(string $data): string|false
	{
		$padded = strtr($data, '-_', '+/');
		$padLen = (4 - strlen($padded) % 4) % 4;
		return base64_decode($padded . str_repeat('=', $padLen), true);
	}

	public static function base64urlEncode(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
