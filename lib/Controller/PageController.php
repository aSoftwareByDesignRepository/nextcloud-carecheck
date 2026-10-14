<?php

declare(strict_types=1);

namespace OCA\CareCheck\Controller;

use OCA\CareCheck\AppInfo\Application;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Service\AclService;
use OCA\CareCheck\Service\ShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller
{
	public function __construct(IRequest $request, private AclService $acl, private ShareService $shares)
	{
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		try {
			$this->acl->assertAppAccess();
		} catch (ApiException) {
			// Restricted mode: the app behaves as absent for unlisted users.
			$r = new TemplateResponse('core', '403', [], 'guest');
			$r->setStatus(Http::STATUS_FORBIDDEN);
			return $r;
		}
		Util::addTranslations(Application::APP_ID);
		\OCP\Util::addScript(Application::APP_ID, 'common/app-feedback');
		\OCP\Util::addScript(Application::APP_ID, 'common/mobile-nav');
		\OCP\Util::addScript(Application::APP_ID, 'common/field-errors');
		\OCP\Util::addStyle(Application::APP_ID, 'common/field-errors');
		Util::addScript(Application::APP_ID, 'qr');
		Util::addScript(Application::APP_ID, 'carecheck-main');
		Util::addStyle(Application::APP_ID, 'common/tokens');
		Util::addStyle(Application::APP_ID, 'app');
		$uid = $this->acl->currentUid();
		return new TemplateResponse(Application::APP_ID, 'main', [
			'isAppAdmin' => $this->acl->isAppAdmin($uid),
			'isZentrale' => $this->acl->isZentrale($uid),
			'isBuchhaltung' => $this->acl->isBuchhaltung($uid),
		]);
	}

	/**
	 * NH-05 — public Angehörigen share view. Minimal projection (name
	 * fragment + next/last visit) — a bearer-token page, rate-limited by
	 * Nextcloud's built-in PublicPage throttling for missing auth.
	 * Unknown/revoked/expired tokens render the same 'not found' page.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function shareView(string $token): TemplateResponse
	{
		$params = ['ok' => false];
		try {
			if (preg_match('/^[0-9a-f]{48}$/', $token) === 1) {
				$params = ['ok' => true] + $this->shares->view($token);
			}
		} catch (\Throwable $e) {
			\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning('share view failed: ' . $e->getMessage(), ['app' => 'carecheck']);
		}
		Util::addStyle(Application::APP_ID, 'common/tokens');
		Util::addStyle(Application::APP_ID, 'app');
		$r = new TemplateResponse(Application::APP_ID, 'share', $params, 'guest');
		if (!$params['ok']) {
			$r->setStatus(Http::STATUS_NOT_FOUND);
		}
		return $r;
	}
}
