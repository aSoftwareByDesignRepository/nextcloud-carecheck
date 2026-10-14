<?php

declare(strict_types=1);

namespace OCA\CareCheck\Controller;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Exception\ApiException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

trait ApiControllerTrait
{
	private function ok(mixed $data, int $status = Http::STATUS_OK): JSONResponse
	{
		return new JSONResponse(['success' => true, 'data' => $data], $status);
	}

	private function error(ApiException $e): JSONResponse
	{
		if ($e->getErrorCode() === Constants::ERR_NOT_FOUND) {
			return new JSONResponse(Constants::notFoundPayload(), Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([
			'success' => false,
			'error' => $e->getErrorCode(),
			'message' => $e->getMessage(),
			'fields' => $e->getFields(),
		], $e->getHttpStatus());
	}
}
