<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Family rule: person pickers never offer deactivated Nextcloud accounts.
 */
final class DisabledAccountGuardContractTest extends TestCase
{
	public function testUidPickerSkipsDeactivatedAccounts(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		$this->assertMatchesRegularExpression(
			'/searchDisplayName\(\$q, 25\)[\s\S]{0,800}if \(!\$u->isEnabled\(\)\)/',
			$src,
			'ApiController uid picker must skip deactivated accounts'
		);
	}

	public function testValidRefRejectsDeactivatedUid(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AclService.php');
		$this->assertMatchesRegularExpression(
			'/str_starts_with\(\$ref, \'uid:\'\)[\s\S]{0,400}isEnabled\(\)/',
			$src,
			'AclService::validRef uid leg must reject deactivated accounts — a direct POST must not assign work to a dead login'
		);
	}
}
