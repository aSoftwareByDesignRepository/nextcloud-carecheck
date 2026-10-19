<?php
declare(strict_types=1);

/**
 * Mutation gauntlet for the optional-backlog services (NH-01/04/07/11/12).
 * Same contract as run-security-mutations.php: apply to real source, run the
 * filtered unit suite, mutant MUST be killed. Survivor = test gap.
 */

$appRoot = dirname(__DIR__, 2);
$workspaceRoot = dirname($appRoot, 3);
$appId = 'carecheck';

function run_unit_tests(string $appRoot, string $workspaceRoot, string $appId, string $filter): int {
	$phpunit = $workspaceRoot . '/lib/composer/bin/phpunit';
	if (!is_file($phpunit)) {
		$phpunit = 'vendor/bin/phpunit';
	}
	$cmd = sprintf(
		'cd %s && php -d opcache.enable_cli=0 %s --bootstrap tests/bootstrap.php --filter %s tests/Unit 2>&1',
		escapeshellarg($appRoot),
		escapeshellarg($phpunit),
		escapeshellarg($filter)
	);
	exec($cmd, $out, $code);
	echo implode("\n", array_slice($out, -4)) . "\n";
	return $code;
}

$targets = [
	[
		'file' => $appRoot . '/lib/Service/PhotoService.php',
		'filter' => 'OptionalFeaturesTest',
		'mutations' => [
			// NH-04 consent gate removed → unconsented uploads accepted
			'consent_gate_removed' => [
				'from' => "if (empty(\$payload['photo_consent'])) {",
				'to' => 'if (false) {',
			],
			// size cap removed → unbounded blob into appdata
			'size_cap_removed' => [
				'from' => "if (\$bytes === '' || strlen(\$bytes) > self::MAX_BYTES) {",
				'to' => "if (\$bytes === '' || strlen(\$bytes) > PHP_INT_MAX) {",
			],
			// sniffed-vs-declared mismatch accepted → polyglot upload
			'mime_mismatch_accepted' => [
				'from' => 'if ($mime === null || $mime !== $declaredMime) {',
				'to' => 'if ($mime === null) {',
			],
			// foreign-visit reference accepted → cross-client link
			'visit_client_unchecked' => [
				'from' => "if (\$v === null || (int)\$v['client_id'] !== \$clientId) {",
				'to' => 'if ($v === null) {',
			],
			// integrity check removed → corrupted/tampered blob served
			'hash_check_removed' => [
				'from' => 'if (!hash_equals((string)$p->getSha256(), hash(\'sha256\', $bytes))) {',
				'to' => 'if (false) {',
			],
		],
	],
	[
		'file' => $appRoot . '/lib/Service/ExportService.php',
		'filter' => 'OptionalFeaturesTest',
		'mutations' => [
			// period month not validated → "2026-99" silently overflows
			'period_month_unchecked' => [
				'from' => '&& checkdate((int)$m[2], 1, (int)$m[1]);',
				'to' => '&& true;',
			],
			// format gate removed → arbitrary fmt passes to $mime/$ext
			'fmt_gate_removed' => [
				'from' => "if (\$fmt !== 'json' && \$fmt !== 'xml') {",
				'to' => 'if (false) {',
			],
		],
	],
	[
		'file' => $appRoot . '/lib/Service/GroupSessionService.php',
		'filter' => 'OptionalFeaturesTest',
		'mutations' => [
			// idempotent replay skipped → offline retry double-creates session
			'idem_replay_skipped' => [
				'from' => 'if ($existing !== null) {',
				'to' => 'if (false) {',
			],
			// per-participant scope check removed → foreign clients get journal rows
			'scope_check_removed' => [
				'from' => "foreach (\$clientIds as \$cid) {\n\t\t\t\$this->clients->getForActor(\$actorRef, \$cid);\n\t\t}",
				'to' => '',
			],
			// close no longer idempotent → second close re-journals
			'close_not_idempotent' => [
				'from' => "if ((string)\$s->getStatus() !== 'open') {\n\t\t\treturn; // idempotent close\n\t\t}",
				'to' => "if ((string)\$s->getStatus() !== 'open') {\n\t\t\tthrow new \\RuntimeException('double close');\n\t\t}",
			],
		],
	],
	[
		'file' => $appRoot . '/lib/Service/DutyRosterAdapter.php',
		'filter' => 'NhFeaturesTest',
		'mutations' => [
			// owner opt-in gate removed → roster data crosses without consent
			'optin_gate_removed' => [
				'from' => "if (\$this->config->getAppValue('dutycheck', self::DC_ONDUTY_FLAG, '0') !== '1') {",
				'to' => 'if (false) {',
			],
			// installed gate removed → reader probed on absent sibling
			'installed_gate_removed' => [
				'from' => 'if (!$this->installed()) {',
				'to' => 'if (false) {',
			],
			// uid: prefix dropped → rows can't be matched to actor refs
			'actorref_prefix_dropped' => [
				'from' => "'actorRef' => \$uid !== '' ? 'uid:' . \$uid : null,",
				'to' => "'actorRef' => \$uid !== '' ? \$uid : null,",
			],
			// fail-closed flipped → a dead DutyCheck reports available
			'failclosed_flipped' => [
				'from' => "} catch (\\Throwable) {\n\t\t\treturn ['available' => false, 'rows' => []];",
				'to' => "} catch (\\Throwable) {\n\t\t\treturn ['available' => true, 'rows' => []];",
			],
		],
	],
];

$failedToKill = [];
foreach ($targets as $target) {
	$source = $target['file'];
	$orig = (string)file_get_contents($source);
	$backup = $source . '.mutation-bak';

	echo "== baseline {$target['filter']} ==\n";
	if (run_unit_tests($appRoot, $workspaceRoot, $appId, $target['filter']) !== 0) {
		fwrite(STDERR, "Baseline {$target['filter']} must pass before mutation run\n");
		exit(1);
	}

	foreach ($target['mutations'] as $name => $pair) {
		echo "\n== mutation: {$name} ==\n";
		$original = (string)file_get_contents($source);
		if (!str_contains($original, $pair['from'])) {
			fwrite(STDERR, "Mutation anchor not found for {$name}\n");
			$failedToKill[] = $name . ' (anchor missing)';
			continue;
		}
		file_put_contents($backup, $original);
		file_put_contents($source, str_replace($pair['from'], $pair['to'], $original));
		$code = run_unit_tests($appRoot, $workspaceRoot, $appId, $target['filter']);
		file_put_contents($source, (string)file_get_contents($backup));
		unlink($backup);
		if ($code === 0) {
			$failedToKill[] = $name;
			echo "MUTATION SURVIVED: {$name}\n";
		} else {
			echo "killed {$name}\n";
		}
	}
	if ((string)file_get_contents($source) !== $orig) {
		file_put_contents($source, $orig);
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, "Mutations not killed: " . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll optional-service mutations killed.\n";
exit(0);
