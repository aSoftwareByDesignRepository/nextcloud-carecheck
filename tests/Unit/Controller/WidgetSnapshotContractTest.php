<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Widget-snapshot endpoint contract — the route must register inside the
 * 'routes' key (a stray top-level array never dispatches) and must carry the
 * companion dual-auth attributes, not a session-only guard that locks out
 * paired member devices.
 */
final class WidgetSnapshotContractTest extends TestCase
{
	public function testRouteDeclaredInsideRoutesKey(): void
	{
		$routes = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$found = false;
		foreach ($routes['routes'] as $r) {
			if (($r['name'] ?? '') === 'companionApi#widgetSnapshot') {
				$found = true;
				$this->assertSame('GET', $r['verb']);
				$this->assertSame('/api/v1/widget-snapshot', $r['url']);
			}
		}
		$this->assertTrue($found, 'companionApi#widgetSnapshot must be declared inside the routes key');
		foreach (array_keys($routes) as $k) {
			$this->assertIsString($k, 'route table must not carry stray numeric-key entries');
		}
	}

	public function testControllerUsesCompanionDualAuthAndActorScopedCache(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/CompanionApiController.php');
		$this->assertMatchesRegularExpression(
			'/#\[PublicPage\]\s*#\[NoCSRFRequired\]\s*public function widgetSnapshot/',
			$src,
			'widgetSnapshot must be PublicPage+NoCSRFRequired (device-token or session dual auth)'
		);
		$this->assertStringContainsString("'snap:' . \$actor", $src,
			'cache key must be the actor ref — member devices have no session uid');
		$this->assertStringContainsString("(\$status['success'] ?? false) !== true", $src,
			'error envelopes must pass through uncached');
		$this->assertStringNotContainsString('IUserSession', $src,
			'session-uid auth locks out paired member devices — actor() is the auth boundary');
	}

	public function testRedactionLockedFlowsThroughSingleCentralReader(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/CompanionApiController.php');
		// The openTours feed is what the companion actually pushes through the
		// widget bridge — the flag must ride it, not only the snapshot route.
		$this->assertMatchesRegularExpression(
			"/'tours'\s*=>\s*\\\$this->tours->actorOpenStops\([^)]*\)[\s\S]*?'redactionLocked'\s*=>\s*\\\$this->redactionLocked\(\)/",
			$src,
			'openTours must emit redactionLocked via the central reader — the client derives widgetRedaction.locked from it'
		);
		// Exactly one reader of the platform flag — a second local copy would
		// shadow the deployment value (mirrored-state contract).
		$this->assertSame(1, substr_count($src, "getValueBool('carecheck', 'widget_redaction_locked'"),
			'widget_redaction_locked must be read in exactly one place (redactionLocked() helper)');
		$this->assertSame(2, substr_count($src, '$this->redactionLocked()'),
			'openTours + widgetSnapshot must both read through redactionLocked()');
	}
}
