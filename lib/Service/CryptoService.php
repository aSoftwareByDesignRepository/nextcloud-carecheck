<?php

declare(strict_types=1);

namespace OCA\CareCheck\Service;

use OCA\CareCheck\Constants;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;

/**
 * AES-256-GCM field encryption. The data-encryption key (32 random bytes)
 * lives in the NC appdata directory — outside the database — so a DB dump
 * alone yields no plaintext (spec Q1 model a).
 */
class CryptoService
{
	private const DEK_FILE = 'dek.bin';
	private const HMAC_FILE = 'hmac.key';
	private ?string $dek = null;
	private ?string $hmacKey = null;

	public function __construct(private IAppData $appData)
	{
	}

	public function encrypt(string $plaintext): string
	{
		$key = $this->dek();
		$iv = random_bytes(12);
		$tag = '';
		$cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($cipher === false) {
			throw new \RuntimeException('encryption failed');
		}
		return 'v1:' . base64_encode($iv . $tag . $cipher);
	}

	public function decrypt(string $blob): string
	{
		if (!str_starts_with($blob, 'v1:')) {
			throw new \RuntimeException('unsupported cipher format');
		}
		$raw = base64_decode(substr($blob, 3), true);
		if ($raw === false || strlen($raw) < 29) {
			throw new \RuntimeException('corrupt cipher blob');
		}
		$iv = substr($raw, 0, 12);
		$tag = substr($raw, 12, 16);
		$cipher = substr($raw, 28);
		$plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->dek(), OPENSSL_RAW_DATA, $iv, $tag);
		if ($plain === false) {
			throw new \RuntimeException('decryption failed — key mismatch or tampering');
		}
		return $plain;
	}

	/** Blind index for exact-match client search. */
	public function nameHmac(string $normalizedName): string
	{
		return hash_hmac('sha256', $normalizedName, $this->hmacKey());
	}

	public function normalizeName(string $name): string
	{
		$name = mb_strtolower(trim($name));
		return preg_replace('/\s+/u', ' ', $name) ?? $name;
	}

	private function dek(): string
	{
		return $this->dek ??= $this->loadOrCreate(self::DEK_FILE);
	}

	private function hmacKey(): string
	{
		return $this->hmacKey ??= $this->loadOrCreate(self::HMAC_FILE);
	}

	private function loadOrCreate(string $name): string
	{
		try {
			$content = $this->appData->getFolder('keys')->getFile($name)->getContent();
			$raw = base64_decode($content, true);
			if ($raw !== false && strlen($raw) === 32) {
				return $raw;
			}
		} catch (NotFoundException) {
			// fall through to create
		}
		$key = random_bytes(32);
		try {
			$folder = $this->appData->getFolder('keys');
		} catch (NotFoundException) {
			$folder = $this->appData->newFolder('keys');
		}
		try {
			$file = $folder->getFile($name);
			$file->putContent(base64_encode($key));
		} catch (NotFoundException) {
			$folder->newFile($name)->putContent(base64_encode($key));
		}
		return $key;
	}
}
