<?php

declare(strict_types=1);

/**
 * Verifies that all locale JSON files contain exactly the same set of msgid keys
 * (and the same key order as en.json).
 *
 * Locales are globbed from l10n/*.json so newly added catalogs are never
 * silently skipped (zh_TW was missed by a hardcoded list).
 *
 * Usage: php scripts/check-l10n-parity.php
 */

$base = __DIR__ . '/../l10n';
$localeFiles = array_map(
	static fn (string $p): string => basename($p, '.json'),
	glob($base . '/*.json') ?: []
);
usort($localeFiles, static fn (string $a, string $b): int => $a === 'en' ? -1 : ($b === 'en' ? 1 : strcmp($a, $b)));
$catalogs = [];

foreach ($localeFiles as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
	$catalogs[$lang] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$enKeys = array_keys($catalogs['en']['translations'] ?? []);
$enKeysSorted = $enKeys;
sort($enKeysSorted);
$ok = true;

foreach (array_diff($localeFiles, ['en']) as $lang) {
	$langKeys = array_keys($catalogs[$lang]['translations'] ?? []);
	$missing = array_values(array_diff($enKeysSorted, $langKeys));
	$extra = array_values(array_diff($langKeys, $enKeysSorted));
	sort($missing);
	sort($extra);
	if ($missing !== []) {
		$ok = false;
		fwrite(STDERR, "Keys missing in {$lang}.json (" . count($missing) . "):\n");
		foreach ($missing as $key) {
			fwrite(STDERR, "  - {$key}\n");
		}
	}
	if ($extra !== []) {
		$ok = false;
		fwrite(STDERR, "Extra keys in {$lang}.json (" . count($extra) . "):\n");
		foreach ($extra as $key) {
			fwrite(STDERR, "  - {$key}\n");
		}
	}
	if ($langKeys !== $enKeys) {
		$ok = false;
		fwrite(STDERR, "Key order mismatch in {$lang}.json (same keys but different order than en.json).\n");
	}
}

if (!$ok) {
	fwrite(STDERR, "\nl10n parity check FAILED.\n");
	exit(1);
}

echo 'l10n parity OK (' . count($enKeys) . ' keys, ' . implode('/', $localeFiles) . ").\n";
exit(0);
