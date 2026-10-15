<?php

/**
 * Minimal Nextcloud/OC stubs for PHPStan — classes that exist at runtime in
 * the server but are outside the published OCP surface (nextcloud/ocp).
 *
 * @copyright Copyright (c) 2026, Alexander Mäule
 * @license AGPL-3.0-or-later
 */

namespace OC\Security\CSP;

class ContentSecurityPolicyNonceManager
{
	public function getNonce(): string
	{
		return '';
	}
}
