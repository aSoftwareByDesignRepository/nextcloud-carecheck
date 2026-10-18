<?php

declare(strict_types=1);

namespace OCA\CareCheck\Middleware;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Controller\ApiController;
use OCA\CareCheck\Controller\CompanionApiController;
use OCA\CareCheck\Exception\ApiException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;

/**
 * Catch-all JSON envelope for CareCheck API routes. Without this, an
 * unexpected storage/runtime failure escapes to core and renders an HTML
 * 500 on /api/* endpoints — API clients must always get the canonical
 * {"success":false,...} envelope (api_500_html_envelope defect class).
 *
 * Scope: only the two JSON API controllers. PageController keeps core's
 * HTML error handling.
 */
class ApiExceptionMiddleware extends Middleware
{
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param Controller $controller
	 */
	public function afterException($controller, $methodName, \Exception $exception): JSONResponse
	{
		if (!($controller instanceof ApiController) && !($controller instanceof CompanionApiController)) {
			throw $exception;
		}
		if ($exception instanceof ApiException) {
			// A stray throw outside a handler's try-block — same canonical
			// wire shape, and NOT_FOUND stays the uniform oracle-free payload.
			if ($exception->getErrorCode() === Constants::ERR_NOT_FOUND) {
				return new JSONResponse(Constants::notFoundPayload(), Http::STATUS_NOT_FOUND);
			}
			return new JSONResponse(
				Constants::errorPayload($exception->getErrorCode(), $exception->getMessage(), $exception->getFields()),
				$exception->getHttpStatus());
		}
		$this->logger->error('CareCheck API request failed', [
			'app' => 'carecheck',
			'exception' => $exception,
		]);
		return new JSONResponse(
			Constants::errorPayload('INTERNAL_ERROR', 'Internal error.'),
			Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
