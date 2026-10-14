<?php

declare(strict_types=1);

namespace OCA\CareCheck;

use OCP\Capabilities\IPublicCapability;

/**
 * Suite flange advertisement (F11): MobilityCheck capability-detects
 * `check.drive-segments` before pulling. Versioned so the contract can
 * evolve without silent consumer breakage.
 */
class Capabilities implements IPublicCapability
{
	public function getCapabilities(): array
	{
		return [
			'check' => [
				'drive-segments' => [
					'version' => 1,
					'endpoint' => '/apps/carecheck/api/drive-segments',
				],
				// NH-03 / F19: visit-time segments for AZC draft stamps.
				'work-log' => [
					'version' => 1,
					'endpoint' => '/apps/carecheck/api/work-log',
				],
				// NH-21 / F13: billing draft candidates for InvoiceCheck.
				'billing-drafts' => [
					'version' => 1,
					'endpoint' => '/apps/carecheck/api/billing-drafts',
				],
			],
		];
	}
}
