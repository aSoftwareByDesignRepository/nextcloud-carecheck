<?php

declare(strict_types=1);

namespace OCA\CareCheck\Exception;

use OCA\CareCheck\Constants;

class ApiException extends \Exception
{
	/** @param array<string,string> $fields */
	public function __construct(
		private string $errorCode,
		string $message,
		private array $fields = [],
		private int $httpStatus = 400,
	) {
		parent::__construct($message);
	}

	public function getErrorCode(): string { return $this->errorCode; }
	public function getFields(): array { return $this->fields; }
	public function getHttpStatus(): int { return $this->httpStatus; }

	public static function notFound(): self
	{
		return new self(Constants::ERR_NOT_FOUND, 'Not found.', [], 404);
	}

	/** @param array<string,string> $fields */
	public static function validation(string $message, array $fields = []): self
	{
		return new self(Constants::ERR_VALIDATION, $message, $fields, 400);
	}

	public static function conflict(string $message): self
	{
		return new self(Constants::ERR_CONFLICT, $message, [], 409);
	}
}
