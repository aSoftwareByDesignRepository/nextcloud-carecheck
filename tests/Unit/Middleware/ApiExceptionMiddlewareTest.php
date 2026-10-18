<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Middleware;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Controller\ApiController;
use OCA\CareCheck\Controller\PageController;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Middleware\ApiExceptionMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * api_500_html_envelope contract: every non-2xx on the JSON API surfaces is
 * the canonical {"success":false,...} envelope — never core's HTML 500 —
 * and NOT_FOUND stays the uniform oracle-free payload even for stray throws.
 */
class ApiExceptionMiddlewareTest extends TestCase
{
	private function middleware(LoggerInterface $logger): ApiExceptionMiddleware
	{
		return new ApiExceptionMiddleware($logger);
	}

	private function apiController(): ApiController
	{
		return (new \ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
	}

	public function testUnhandledThrowableBecomesJson500(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$r = $this->middleware($logger)->afterException(
			$this->apiController(), 'getSettings', new \RuntimeException('db gone'));
		$d = $r->getData();
		$this->assertSame(500, $r->getStatus());
		$this->assertFalse($d['success']);
		$this->assertSame('INTERNAL_ERROR', $d['error']);
		// exception text must never leak to the wire
		$this->assertStringNotContainsString('db gone', (string)$d['message']);
		$this->assertSame([], $d['fields']);
	}

	public function testStrayNotFoundKeepsUniformPayload(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$r = $this->middleware($logger)->afterException(
			$this->apiController(), 'getClient', ApiException::notFound());
		$this->assertSame(404, $r->getStatus());
		$this->assertSame(Constants::notFoundPayload(), $r->getData());
	}

	public function testStrayValidationKeepsFieldsAndStatus(): void
	{
		$r = $this->middleware($this->createMock(LoggerInterface::class))->afterException(
			$this->apiController(), 'saveSettings',
			ApiException::validation('Validation failed.', ['allowed_users' => 'invalid']));
		$d = $r->getData();
		$this->assertSame(400, $r->getStatus());
		$this->assertSame('VALIDATION_ERROR', $d['error']);
		$this->assertSame(['allowed_users' => 'invalid'], $d['fields']);
	}

	public function testNonApiControllerRethrows(): void
	{
		$page = (new \ReflectionClass(PageController::class))->newInstanceWithoutConstructor();
		$this->expectException(\RuntimeException::class);
		$this->middleware($this->createMock(LoggerInterface::class))
			->afterException($page, 'index', new \RuntimeException('nope'));
	}
}
