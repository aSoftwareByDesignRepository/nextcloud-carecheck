<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Exception\ApiException;
use PHPUnit\Framework\TestCase;

class ApiPayloadTest extends TestCase
{
	public function testUniform404Shape(): void
	{
		$p = Constants::notFoundPayload();
		$this->assertSame(
			['success' => false, 'error' => 'NOT_FOUND', 'message' => 'Not found.', 'fields' => []],
			$p
		);
	}

	public function testNotFoundExceptionCarries404(): void
	{
		$e = ApiException::notFound();
		$this->assertSame(404, $e->getHttpStatus());
		$this->assertSame('NOT_FOUND', $e->getErrorCode());
	}

	public function testConflictCarries409(): void
	{
		$this->assertSame(409, ApiException::conflict('x')->getHttpStatus());
	}
}
