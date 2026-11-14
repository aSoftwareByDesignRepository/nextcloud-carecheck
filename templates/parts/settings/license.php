<?php
/**
 * Settings → License — thin adapter onto the shared license page
 * (canonical source in _shared/license-page).
 *
 * CareCheck specifics: companions claim seats automatically server-side, so
 * there is no admin seat-assignment UI — the page renders in its
 * license-key-only variant.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @license AGPL-3.0-or-later
 */

$l = $l ?? (isset($_['l']) ? $_['l'] : null);
if ($l === null) {
	try {
		$l = \OCP\Util::getL10N('carecheck');
	} catch (\Throwable) {
		$l = new class { public function t($s, $p = []) { return $s; } };
	}
}

$summary = is_array($_['licenseSummary'] ?? null) ? $_['licenseSummary'] : null;
$urlGenerator = $_['urlGenerator'] ?? null;
if ($urlGenerator === null) {
	try {
		$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
	} catch (\Throwable) {
		$urlGenerator = null;
	}
}

$_['license'] = $summary === null ? null : [
	'active' => !empty($summary['active']),
	'cryptographicallyValid' => !empty($summary['cryptographicallyValid']),
	'dateValid' => !empty($summary['dateValid']),
	'validUntil' => (string)($summary['validUntil'] ?? ''),
	'customerId' => (string)($summary['customerId'] ?? ''),
];
$_['productCode'] = 'CCK2';
$_['mobileSeatsLimit'] = (int)($summary['mobileSeats'] ?? 0);
$_['mobileSeatsUsed'] = 0;
$_['mobileSeats'] = [];
$_['showMobileSeats'] = false;
$_['showTerminal'] = false;
$_['companionText'] = $l->t('the CareCheck companion app');
$_['instanceBound'] = (bool)($summary['instanceValid'] ?? true);
$_['apiLicenseUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('carecheck.api.applyLicenseKey') : '';
$_['apiClearLicenseUrl'] = $urlGenerator ? $urlGenerator->linkToRoute('carecheck.api.clearLicenseKey') : '';
$_['licenseRenewMailto'] = 'mailto:info@software-by-design.de?subject=' . rawurlencode('CareCheck license');
$_['productsUrl'] = 'https://nextcloud.software-by-design.de/';
try {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? \OCP\Util::callRegister());
} catch (\Throwable) {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? '');
}

echo '<div id="cck-license">';
include dirname(__DIR__, 2) . '/common/license-page.php';
echo '</div>';
