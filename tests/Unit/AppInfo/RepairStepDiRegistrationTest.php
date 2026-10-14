<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Static gate (no DB): every repair-step FQCN in info.xml must appear in a
 * registerService() call in Application.php, and the factory must pass at
 * least as many $c->get() args as the constructor requires.
 */
class RepairStepDiRegistrationTest extends TestCase
{
	private const APP_DIR = __DIR__ . '/../../..';

	public function testRepairStepsRegistered(): void
	{
		$info = file_get_contents(self::APP_DIR . '/appinfo/info.xml');
		$app = file_get_contents(self::APP_DIR . '/lib/AppInfo/Application.php');
		preg_match_all('#<step>([^<]+)</step>#', $info, $m);
		$this->assertNotEmpty($m[1], 'info.xml has no repair steps');
		foreach ($m[1] as $fqcn) {
			$short = substr($fqcn, strrpos($fqcn, '\\') + 1);
			$this->assertStringContainsString("registerService({$short}::class", $app, "$fqcn not registered");
		}
	}

	public function testFactoryArityMatches(): void
	{
		$app = file_get_contents(self::APP_DIR . '/lib/AppInfo/Application.php');
		preg_match_all('/registerService\(([A-Za-z]+)::class, function \(\$c\): [A-Za-z]+ \{(.*?)\}\);/s', $app, $m, PREG_SET_ORDER);
		foreach ($m as [$_, $cls, $body]) {
			$fqcn = class_exists('OCA\\CareCheck\\Repair\\' . $cls)
				? 'OCA\\CareCheck\\Repair\\' . $cls
				: 'OCA\\CareCheck\\Service\\' . $cls;
			$rc = new \ReflectionClass($fqcn);
			$ctor = $rc->getConstructor();
			$required = $ctor === null ? 0 : $ctor->getNumberOfRequiredParameters();
			$passed = substr_count($body, '$c->get(');
			$this->assertGreaterThanOrEqual($required, $passed, "$cls factory passes too few args");
		}
	}
}
