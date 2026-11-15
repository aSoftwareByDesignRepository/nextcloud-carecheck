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

// Dynamic tr() references — msgids reached through lookup tables instead of
// literal call args. Defect class 2026-11-15: a catalog regen dropped the
// still-referenced msgid 'done' from every locale because it only lived as
// the label cell of STOP_STATUS (`var s = STOP_STATUS[x]; tr(s[1])`) — no
// literal tr('done') exists for the literal-arg pass to see.
$unescJs = function (string $raw): string {
	return stripcslashes($raw);
};
$mapCells = function (string $src): array {
	// SCREAMING_NAME object tables -> list of ['scalar', [lit]] or ['array', [lits]]
	$maps = [];
	if (!preg_match_all('/(?:const|let|var)\s+([A-Z][A-Z0-9_]*)\s*=\s*\{/', $src, $heads, PREG_OFFSET_CAPTURE)) {
		return $maps;
	}
	foreach ($heads[1] as $h) {
		$name = $h[0];
		$pos = $h[1];
		$bracePos = strpos($src, '{', $pos);
		$depth = 0;
		$i = $bracePos;
		$len = strlen($src);
		for (; $i < $len; $i++) {
			if ($src[$i] === '{') { $depth++; }
			elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { break; } }
		}
		$body = substr($src, $bracePos + 1, $i - $bracePos - 1);
		// split top-level key:value cells on depth-0 commas
		$cells = [];
		$d = 0;
		$start = 0;
		$blen = strlen($body);
		for ($j = 0; $j < $blen; $j++) {
			$c = $body[$j];
			if ($c === '{' || $c === '[' || $c === '(') { $d++; }
			elseif ($c === '}' || $c === ']' || $c === ')') { $d--; }
			elseif ($c === "'" || $c === '"') {
				$j++;
				while ($j < $blen && $body[$j] !== $c) { $j += ($body[$j] === '\\' ? 2 : 1); }
			}
			elseif ($c === ',' && $d === 0) {
				$cells[] = substr($body, $start, $j - $start);
				$start = $j + 1;
			}
		}
		$tail = substr($body, $start);
		if (trim($tail) !== '') { $cells[] = $tail; }
		foreach ($cells as $cell) {
			if (strpos($cell, ':') === false) { continue; }
			$vpart = trim(substr($cell, strpos($cell, ':') + 1));
			preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/', $vpart, $lm);
			$lits = [];
			foreach ($lm[1] as $k => $v) { $lits[] = $v !== '' ? $v : $lm[2][$k]; }
			if ($lits === []) { continue; }
			$kind = str_starts_with($vpart, '[') ? 'array' : 'scalar';
			$maps[$name][] = [$kind, $lits];
		}
	}
	return $maps;
};
$scanDynamicTr = function (string $src) use ($mapCells, $unescJs, &$found): void {
	$maps = $mapCells($src);
	$scalarVals = function (string $name) use ($maps): array {
		$out = [];
		foreach ($maps[$name] ?? [] as [$kind, $lits]) {
			if ($kind === 'scalar') { $out[] = $lits[0]; }
		}
		return $out;
	};
	// tr(NAME[…]) direct deref
	if (preg_match_all('/(?<![\w$.])tr\(\s*([A-Z][A-Z0-9_]*)\s*\[/', $src, $m)) {
		foreach ($m[1] as $name) { foreach ($scalarVals($name) as $v) { $found[$unescJs($v)] = true; } }
	}
	// tr(alias[N]) where `var|let|const alias = …NAME[…]` binds the alias
	$bindings = [];
	if (preg_match_all('/(?:const|let|var)\s+([a-z_$][\w$]*)\s*=\s*([^;\n]+)/', $src, $bm)) {
		foreach ($bm[1] as $i => $ident) {
			if (preg_match_all('/([A-Z][A-Z0-9_]*)\s*\[/', $bm[2][$i], $nm)) {
				$bindings[$ident] = $nm[1];
			}
		}
	}
	if (preg_match_all('/(?<![\w$.])tr\(\s*([a-z_$][\w$]*)\s*\[\s*(\d+)\s*\]/', $src, $m)) {
		foreach ($m[1] as $i => $ident) {
			$idx = (int)$m[2][$i];
			foreach ($bindings[$ident] ?? [] as $name) {
				foreach ($maps[$name] ?? [] as [$kind, $lits]) {
					if ($kind === 'array' && isset($lits[$idx]) && $lits[$idx] !== '') {
						$found[$unescJs($lits[$idx])] = true;
					}
				}
			}
		}
	}
	// helper(NAME, …) where helper's body calls tr(param[…])
	if (preg_match_all('/function\s+(\w+)\s*\(\s*([\w$]+)/', $src, $fm, PREG_OFFSET_CAPTURE)) {
		foreach ($fm[1] as $i => $fmName) {
			$pname = $fm[2][$i][0];
			$body = substr($src, $fm[0][$i][1], 800);
			if (!preg_match('/(?<![\w$.])tr\(\s*' . preg_quote($pname, '/') . '\s*\[/', $body)) { continue; }
			if (preg_match_all('/(?<![\w$.])' . preg_quote($fmName[0], '/') . '\s*\(\s*([A-Z][A-Z0-9_]*)\s*[,)]/', $src, $cm)) {
				foreach ($cm[1] as $name) { foreach ($scalarVals($name) as $v) { $found[$unescJs($v)] = true; } }
			}
		}
	}
	// tr(cond ? 'a' : 'b') — literals inside the arg-1 expression, minus
	// === 'token' comparands; 'x' + ident concat prefixes are key fragments.
	if (preg_match_all('/(?<![\w$.])tr\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
		foreach ($m[0] as [$full, $off]) {
			$i = $off + strlen($full);
			$depth = 1;
			$start = $i;
			$len = strlen($src);
			for (; $i < $len && $depth > 0; $i++) {
				$c = $src[$i];
				if ($c === '(' || $c === '[' || $c === '{') { $depth++; }
				elseif ($c === ')' || $c === ']' || $c === '}') { $depth--; }
			}
			$arg = substr($src, $start, $i - $start - 1);
			// only arg-1 (top-level comma split)
			$d = 0;
			$arg1 = $arg;
			for ($j = 0; $j < strlen($arg); $j++) {
				$cc = $arg[$j];
				if ($cc === '(' || $cc === '[' || $cc === '{') { $d++; }
				elseif ($cc === ')' || $cc === ']' || $cc === '}') { $d--; }
				elseif (($cc === "'" || $cc === '"')) {
					$j++;
					while ($j < strlen($arg) && $arg[$j] !== $cc) { $j += ($arg[$j] === '\\' ? 2 : 1); }
				}
				elseif ($cc === ',' && $d === 0) { $arg1 = substr($arg, 0, $j); break; }
			}
			$arg1 = preg_replace("/[!=]==\\s*'(?:[^'\\\\]|\\\\.)*'/", '', $arg1);
			$arg1 = preg_replace("/'(?:[^'\\\\]|\\\\.)*'\\s*[!=]==/", '', $arg1);
			if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $arg1, $lm, PREG_OFFSET_CAPTURE)) {
				foreach ($lm[1] as $k => $lit) {
					$after = substr($arg1, $lm[0][$k][1] + strlen($lm[0][$k][0]));
					if (preg_match('/^\s*\+/', $after)) { continue; }
					$found[$unescJs($lit[0])] = true;
				}
			}
		}
	}
};

$scanDynamic = function (string $path) use ($scanDynamicTr): void {
	if (preg_match('/\.js$/', $path)) {
		$scanDynamicTr((string)file_get_contents($path));
	}
};

foreach ($scanDirs as $dir) {
	if (is_file($dir)) {
		$scanFiles($dir);
		$scanDynamic($dir);
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
		$scanDynamic($path);
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
