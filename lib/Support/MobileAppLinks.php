<?php

declare(strict_types=1);

/**
 * Canonical store / product links for the in-app "Get the App" page.
 *
 * Security notes (auditor-facing):
 * - Destinations are compile-time constants only — never request/user input.
 * - Consumers must open external https URLs with rel="noopener noreferrer" target="_blank".
 * - This surface is informational; it never gates AGPL web features.
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

namespace OCA\CareCheck\Support;

final class MobileAppLinks
{
	public const PLAY_STORE_PACKAGE_ID = 'de.softwarebydesign.carecheck';
	public const PLAY_STORE_URL = 'https://play.google.com/store/apps/details?id=de.softwarebydesign.carecheck';
	public const PRODUCT_PAGE_PATH = '/en/apps/carecheck.html';
	public const PRODUCT_PAGE_PATH_DE = '/de/apps/carecheck.html';
	public const PRIVACY_PAGE_PATH = '/en/privacy-carecheck-mobile.html';
	public const PRIVACY_PAGE_PATH_DE = '/de/datenschutz-carecheck-mobile.html';

	/** Companion exists but is not publicly listed on Google Play yet — the
	 *  Get-the-App page must say so honestly instead of showing a dead badge. */
	public const PLAY_LISTED = false;

	public function playStoreUrl(): string
	{
		return self::PLAY_STORE_URL;
	}

	public function playStorePackageId(): string
	{
		return self::PLAY_STORE_PACKAGE_ID;
	}

	public function playListed(): bool
	{
		return self::PLAY_LISTED;
	}

	public function productPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode)
			? self::PRODUCT_PAGE_PATH_DE
			: self::PRODUCT_PAGE_PATH;
		return SupportUsLinks::SITE_ORIGIN . $path;
	}

	public function privacyPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode)
			? self::PRIVACY_PAGE_PATH_DE
			: self::PRIVACY_PAGE_PATH;
		return SupportUsLinks::SITE_ORIGIN . $path;
	}

	public function isGermanLocale(string $languageCode): bool
	{
		$lang = strtolower(str_replace('_', '-', trim($languageCode)));
		return $lang === 'de' || str_starts_with($lang, 'de-');
	}
}
