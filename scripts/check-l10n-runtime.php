<?php

declare(strict_types=1);

/**
 * Fails when PHP/JS source references translation strings that are missing from l10n/en.json.
 *
 * Scans lib/, templates/, and app-owned js/ (carecheck-main.js, qr.js — shared packs
 * under js/common/ carry their own fallback strings and are excluded).
 *
 * Exit codes:
 *   0  all scanned runtime strings present in en.json
 *   1  missing strings (printed to STDERR)
 *
 * Usage:
 *   php scripts/check-l10n-runtime.php
 */

$appRoot = dirname(__DIR__);
$enPath = $appRoot . '/l10n/en.json';
$en = json_decode((string)file_get_contents($enPath), true, 512, JSON_THROW_ON_ERROR);
$catalog = $en['translations'] ?? [];

$patterns = [
	// PHP: $l->t('…'), $this->l->t('…'), $this->l10n->t('…') (1st arg only)
	'/\$this->l10n->t\(\s*\'((?:\\\\\'|[^\'])*)\'/',
	'/\$this->l10n->t\(\s*"((?:\\\\"|[^"])*)"/',
	'/\$this->l->t\(\s*\'((?:\\\\\'|[^\'])*)\'/',
	'/\$this->l->t\(\s*"((?:\\\\"|[^"])*)"/',
	'/\$l->t\(\s*\'((?:\\\\\'|[^\'])*)\'/',
	'/\$l->t\(\s*"((?:\\\\"|[^"])*)"/',
	// JS: tr('…') wrapper → OC.L10N.translate('carecheck', …)
	'/(?<![a-zA-Z])tr\(\s*\'((?:\\\\\'|[^\'])*)\'/',
	'/(?<![a-zA-Z])tr\(\s*"((?:\\\\"|[^"])*)"/',
];

$scanDirs = [
	$appRoot . '/lib',
	$appRoot . '/templates',
	$appRoot . '/js/carecheck-main.js',
	$appRoot . '/js/qr.js',
];

$found = [];
$scanFiles = function (string $path) use ($patterns, &$found): void {
	$content = (string)file_get_contents($path);
	foreach ($patterns as $pattern) {
		if (preg_match_all($pattern, $content, $matches)) {
			foreach ($matches[1] as $raw) {
				$msgid = stripcslashes($raw);
				$found[$msgid] = true;
			}
		}
	}
};

foreach ($scanDirs as $dir) {
	if (is_file($dir)) {
		$scanFiles($dir);
		continue;
	}
	if (!is_dir($dir)) {
		continue;
	}
	$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
	foreach ($iter as $file) {
		if (!$file->isFile()) {
			continue;
		}
		$path = $file->getPathname();
		if (!preg_match('/\.(php|js)$/', $path)) {
			continue;
		}
		$scanFiles($path);
	}
}

$missing = [];
foreach (array_keys($found) as $msgid) {
	if (!array_key_exists($msgid, $catalog)) {
		$missing[] = $msgid;
	}
}
sort($missing);

if ($missing !== []) {
	fwrite(STDERR, 'Runtime strings missing from l10n/en.json (' . count($missing) . "):\n");
	foreach ($missing as $msgid) {
		fwrite(STDERR, "  - {$msgid}\n");
	}
	fwrite(STDERR, "\nl10n runtime check FAILED.\n");
	exit(1);
}

echo 'l10n runtime OK (' . count($found) . " unique strings, all in en.json).\n";
exit(0);
