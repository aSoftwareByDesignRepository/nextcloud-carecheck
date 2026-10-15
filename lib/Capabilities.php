<?php

declare(strict_types=1);

namespace OCA\CareCheck;

use OCP\Capabilities\IPublicCapability;
use OCP\IURLGenerator;

/**
 * Suite flange advertisement (F11): MobilityCheck capability-detects
 * `check.drive-segments` before pulling. Versioned so the contract can
 * evolve without silent consumer breakage.
 */
class Capabilities implements IPublicCapability
{
	public function __construct(private IURLGenerator $urlGenerator)
	{
	}

	public function getCapabilities(): array
	{
		// linkToRoute keeps webroot + /index.php correct for consuming apps.
		$urlGenerator = $this->urlGenerator;
		return [
			'check' => [
				'drive-segments' => [
					'version' => 1,
					'endpoint' => $urlGenerator->linkToRoute('carecheck.api.driveSegments'),
				],
				// NH-03 / F19: visit-time segments for AZC draft stamps.
				'work-log' => [
					'version' => 1,
					'endpoint' => $urlGenerator->linkToRoute('carecheck.api.workLog'),
				],
				// NH-21 / F13: billing draft candidates for InvoiceCheck.
				'billing-drafts' => [
					'version' => 1,
					'endpoint' => $urlGenerator->linkToRoute('carecheck.api.billingDrafts'),
				],
			],
		];
	}
}
