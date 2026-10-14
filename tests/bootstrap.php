<?php

declare(strict_types=1);

/**
 * Host unit runs: OCP stubs only (no Nextcloud DB).
 * Docker integration: load lib/base.php when under /var/www/html or CARECHECK_INTEGRATION=1.
 */

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}
if (!defined('PHPUNIT_RUNNING')) {
	define('PHPUNIT_RUNNING', true);
}

$inDockerTree = str_starts_with(__DIR__, '/var/www/html/');
$loadNc = $inDockerTree || getenv('CARECHECK_INTEGRATION') === '1';

$base = null;
if ($loadNc) {
	$candidates = [];
	$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '';
	if ($nextcloudRoot !== '') {
		$candidates[] = rtrim($nextcloudRoot, '/\\') . '/lib/base.php';
	}
	$candidates[] = __DIR__ . '/../../../lib/base.php';
	$candidates[] = '/var/www/html/lib/base.php';
	foreach ($candidates as $candidate) {
		if (is_file($candidate)) {
			$base = $candidate;
			break;
		}
	}
}

if ($base !== null) {
	require_once $base;
	$integrationBootstrap = dirname(__DIR__, 3) . '/scripts/phpunit-integration-bootstrap.php';
	if (is_file($integrationBootstrap)) {
		require_once $integrationBootstrap;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

if (!class_exists(\Test\TestCase::class)) {
	eval('namespace Test; class TestCase extends \\PHPUnit\\Framework\\TestCase {}');
}

if ($base === null && !interface_exists(\OC\Hooks\Emitter::class, false)) {
	eval('namespace OC\\Hooks; interface Emitter {}');
}

$ocpStubs = dirname(__DIR__, 3) . '/scripts/phpunit-ocp-doctrine-stubs.php';
if ($base === null && is_file($ocpStubs)) {
	require_once $ocpStubs;
}
