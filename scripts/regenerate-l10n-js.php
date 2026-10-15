<?php

declare(strict_types=1);

/**
 * Regenerates l10n/*.js from l10n/*.json so the JS catalog stays in sync.
 * Matches the one-tab OC.L10N.register format used across Check apps.
 *
 * Usage: php scripts/regenerate-l10n-js.php
 */

$base = __DIR__ . '/../l10n';
$localeFiles = ['en', 'de', 'da', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'sv', 'pt_BR', 'pt'];

foreach ($localeFiles as $lang) {
	$jsonPath = $base . '/' . $lang . '.json';
	$jsPath = $base . '/' . $lang . '.js';
	if (!is_file($jsonPath)) {
		fwrite(STDERR, "Missing: $jsonPath\n");
		exit(1);
	}
	$cat = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
	$translations = $cat['translations'] ?? [];
	$pluralForm = $cat['pluralForm'] ?? null;

	$lines = ["OC.L10N.register(\n", "\t\"carecheck\",\n", "\t{\n"];
	foreach ($translations as $key => $val) {
		$k = json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$v = json_encode(is_array($val) ? $val : (string)$val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$lines[] = "\t" . $k . ' : ' . $v . ",\n";
	}
	$last = array_pop($lines);
	$lines[] = rtrim($last, ",\n") . "\n";
	if ($pluralForm !== null && $pluralForm !== '') {
		$lines[] = "\t},\n";
		$lines[] = "\t" . json_encode($pluralForm) . "\n";
		$lines[] = ");\n";
	} else {
		$lines[] = "\t}\n";
		$lines[] = ");\n";
	}
	file_put_contents($jsPath, implode('', $lines));
	echo "regenerated {$lang}.js\n";
}
