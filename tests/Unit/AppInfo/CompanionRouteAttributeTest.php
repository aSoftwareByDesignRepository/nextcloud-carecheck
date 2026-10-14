<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\AppInfo;

use OCA\CareCheck\Controller\CompanionApiController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every companionApi route method must declare #[PublicPage] +
 * #[NoCSRFRequired]: member devices authenticate via the X-CCK-Device-Token
 * header inside actor(), not an NC session — a missing PublicPage 401s the
 * request before dispatch, and a missing NoCSRFRequired applies cookie-CSRF
 * checks a token client cannot satisfy.
 *
 * Regression: licenseState() shipped without attributes (its docblock+attrs
 * had drifted onto addPhoto), silently breaking member-mode license readback.
 */
final class CompanionRouteAttributeTest extends TestCase
{
	public function testEveryRouteMethodDeclaresPublicPageAndNoCsrf(): void
	{
		$ref = new ReflectionClass(CompanionApiController::class);
		$missing = [];
		foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
			if ($m->isStatic() || $m->isConstructor() || $m->getDeclaringClass()->getName() !== CompanionApiController::class) {
				continue;
			}
			// Route actions return JSONResponse; skip helpers.
			$rt = $m->getReturnType();
			if ($rt === null || !str_contains((string)$rt, 'JSONResponse')) {
				continue;
			}
			$attrs = array_map(static fn ($a) => $a->getName(), $m->getAttributes());
			foreach ([PublicPage::class, NoCSRFRequired::class] as $need) {
				if (!in_array($need, $attrs, true)) {
					$missing[] = $m->getName() . ' missing ' . substr($need, strrpos($need, '\\') + 1);
				}
			}
		}
		self::assertSame([], $missing, 'companionApi route methods missing page attributes: ' . implode('; ', $missing));
	}
}
