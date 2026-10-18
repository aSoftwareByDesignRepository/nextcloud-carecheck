<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Service\CryptoService;
use OCP\Files\IAppData;

use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/** In-memory IAppData for the key store. */
final class FakeAppData implements IAppData
{
	/** @var array<string,FakeFile|true> */
	public array $files = [];

	public function getDirectoryListing(): array { return []; }
	public function getId(): int { return 1; }

	public function newFolder(string $name): ISimpleFolder
	{
		$this->files["dir:$name"] = true;
		return $this->getFolder($name);
	}

	public function getFolder(string $name): ISimpleFolder
	{
		if (!isset($this->files["dir:$name"])) {
			throw new NotFoundException('no folder');
		}
		$files = &$this->files;
		return new class($files) implements ISimpleFolder {
			public function __construct(private array &$files) {}
			public function getName(): string { return 'keys'; }
			public function getDirectoryListing(): array { return []; }
			public function fileExists(string $name): bool { return isset($this->files[$name]); }
			public function getFile(string $name): ISimpleFile
			{
				if (!isset($this->files[$name])) {
					throw new NotFoundException('no file');
				}
				return $this->files[$name];
			}
			public function newFile(string $name, $content = null): ISimpleFile
			{
				$this->files[$name] = new FakeFile();
				if ($content !== null) { $this->files[$name]->putContent($content); }
				return $this->files[$name];
			}
			public function delete(): void {}
			public function getFolder(string $name): ISimpleFolder { throw new \LogicException('unused'); }
			public function newFolder(string $name): ISimpleFolder { throw new \LogicException('unused'); }
			public function getOrCreateFolder(string $path, int $maxRetries = 5): ISimpleFolder { return $this; }
		};
	}
}

final class FakeFile implements ISimpleFile
{
	private string $content = '';
	public function getName(): string { return 'f'; }
	public function getSize(): int { return strlen($this->content); }
	public function getETag(): string { return 'e'; }
	public function getMtime(): int { return 0; }
	public function getContent(): string { return $this->content; }
	public function putContent($data): void { $this->content = (string)$data; }
	public function delete(): void { $this->content = ''; }
	public function getMimeType(): string { return 'text/plain'; }
	public function getExtension(): string { return 'bin'; }
	public function read() { $h = fopen('php://memory', 'rb+'); fwrite($h, $this->content); rewind($h); return $h; }
	public function write() { }
}

class CryptoServiceTest extends TestCase
{
	private function crypto(): CryptoService
	{
		$appData = new FakeAppData();
		$appData->newFolder('keys');
		return new CryptoService($appData);
	}

	public function testRoundtrip(): void
	{
		$c = $this->crypto();
		$enc = $c->encrypt('sensitive äöü data');
		$this->assertStringStartsWith('v1:', $enc);
		$this->assertSame('sensitive äöü data', $c->decrypt($enc));
	}

	public function testEncryptionUsesFreshIv(): void
	{
		$c = $this->crypto();
		$this->assertNotSame($c->encrypt('same payload'), $c->encrypt('same payload'));
	}

	public function testNonV1BlobRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('unsupported cipher format');
		$this->crypto()->decrypt('garbage-not-v1');
	}

	public function testTruncatedBlobIsCorruptNotDecryptFailure(): void
	{
		// < iv+tag minimum: must surface 'corrupt cipher blob', proving the
		// length gate fires before tag extraction (not a generic decrypt error).
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('corrupt cipher blob');
		$this->crypto()->decrypt('v1:' . base64_encode(str_repeat("\x01", 28)));
	}

	public function testTamperedCiphertextRejected(): void
	{
		$c = $this->crypto();
		$enc = $c->encrypt('payload');
		$raw = base64_decode(substr($enc, 3));
		$raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';
		$this->expectException(\RuntimeException::class);
		$c->decrypt('v1:' . base64_encode($raw));
	}

	public function testDifferentKeysCannotDecrypt(): void
	{
		$c1 = $this->crypto();
		$c2 = $this->crypto(); // fresh key material
		$this->expectException(\RuntimeException::class);
		$c2->decrypt($c1->encrypt('secret'));
	}

	public function testNameHmacDeterministicAndKeyed(): void
	{
		$c = $this->crypto();
		$h1 = $c->nameHmac('müller');
		$this->assertSame($h1, $c->nameHmac('müller'));
		$this->assertNotSame($h1, $c->nameHmac('schmidt'));
		$this->assertSame(64, strlen($h1));
	}
}
