<?php
declare(strict_types=1);

/**
 * Surgical mutation gauntlet for CareCheck's security-critical pure modules.
 * Each mutant is applied to real source, the filtered unit suite is run, and
 * the mutant must be KILLED (non-zero exit). A surviving mutant = test gap.
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
		'cd %s && php %s --bootstrap tests/bootstrap.php --filter %s tests/Unit 2>&1',
		escapeshellarg($appRoot),
		escapeshellarg($phpunit),
		escapeshellarg($filter)
	);
	exec($cmd, $out, $code);
	echo implode("\n", array_slice($out, -4)) . "\n";
	return $code;
}

function targets(string $appRoot): array {
	return [
		[
			'file' => $appRoot . '/lib/Service/BundleValidator.php',
			'filter' => 'BundleValidatorTest',
			'mutations' => [
				'idem_key_optional' => [
					'from' => "if (\$k === '' || strlen(\$k) > 96) {",
					'to' => "if (strlen(\$k) > 96) {",
				],
				'strict_id_accepts_decimal' => [
					'from' => "if (!preg_match('/^\\d+\$/', (string)\$raw)) {",
					'to' => "if (!preg_match('/^\\d+(\\.\\d+)?\$/', (string)\$raw)) {",
				],
				'refused_reason_not_required' => [
					'from' => "in_array(\$st, ['refused', 'not_done'], true) && trim((string)(\$t['reason_code'] ?? '')) === '') {",
					'to' => "in_array(\$st, ['not_done'], true) && trim((string)(\$t['reason_code'] ?? '')) === '') {",
				],
				'unknown_reason_code_passes' => [
					'from' => "&& !in_array((string)\$t['reason_code'], Constants::REASON_CODES, true)) {",
					'to' => "&& in_array((string)\$t['reason_code'], Constants::REASON_CODES, true)) {",
				],
				'signature_optional' => [
					'from' => "if (\$b64 === '') {",
					'to' => "if (false) {",
				],
				'signer_name_optional' => [
					'from' => "trim((string)(\$in['signer_name'] ?? '')) === '') {",
					'to' => "trim((string)(\$in['signer_name'] ?? '')) !== '') {",
				],
				'signature_any_bytes' => [
					'from' => 'return str_starts_with($raw, "\x89PNG\r\n\x1a\n") || str_starts_with($raw, "\xff\xd8\xff");',
					'to' => 'return true;',
				],
				'signature_size_cap_removed' => [
					'from' => 'strlen($raw) > Constants::SIG_MAX_BYTES',
					'to' => 'strlen($raw) > PHP_INT_MAX',
				],
				'geo_toggle_ignored' => [
					'from' => "if (!\$allowed || !isset(\$in['geo']['lat'], \$in['geo']['lon'])) {",
					'to' => "if (!isset(\$in['geo']['lat'], \$in['geo']['lon'])) {",
				],
				'geo_range_unbounded' => [
					'from' => 'if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {',
					'to' => 'if ($lat < -900 || $lat > 900 || $lon < -1800 || $lon > 1800) {',
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/AclService.php',
			'filter' => 'RefAndScopeTest',
			'mutations' => [
				'member_existence_unchecked' => [
					'from' => "\$m = \$this->members->find((int)\$id);\n\t\t\treturn \$m !== null && (int)\$m->getActive() === 1;",
					'to' => 'return true;',
				],
				'member_active_ignored' => [
					'from' => "return \$m !== null && (int)\$m->getActive() === 1;",
					'to' => 'return \$m !== null;',
				],
				'uid_existence_unchecked' => [
					'from' => "return \$uid !== '' && \$this->users->userExists(\$uid);",
					'to' => "return \$uid !== '';",
				],
				'member_id_loose' => [
					'from' => "if (preg_match('/^\\d+\$/', \$id) !== 1) {",
					'to' => "if (preg_match('/^\\d+\$/', \$id) === 1) {",
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/AclService.php',
			'filter' => 'AccessDoorTest',
			'mutations' => [
				'restricted_behaves_open' => [
					'from' => "if (\$this->settings->get('access_mode') !== 'restricted') {",
					'to' => 'if (true) {',
				],
				'allowed_users_list_ignored' => [
					'from' => "if (in_array(\$uid, \$this->settings->getList('allowed_users'), true)) {",
					'to' => 'if (false) {',
				],
				'app_admin_bypass_removed' => [
					'from' => 'if ($this->isAppAdmin($uid)) {',
					'to' => 'if (false) {',
				],
				'denied_user_admitted' => [
					'from' => "\t\tforeach ([Constants::GROUP_ZENTRALE, Constants::GROUP_BUCHHALTUNG, Constants::GROUP_APP_ADMIN] as \$g) {\n\t\t\tif (\$this->groups->isInGroup(\$uid, \$g)) {\n\t\t\t\treturn true;\n\t\t\t}\n\t\t}\n\t\treturn false;",
					'to' => "\t\tforeach ([Constants::GROUP_ZENTRALE, Constants::GROUP_BUCHHALTUNG, Constants::GROUP_APP_ADMIN] as \$g) {\n\t\t\tif (\$this->groups->isInGroup(\$uid, \$g)) {\n\t\t\t\treturn true;\n\t\t\t}\n\t\t}\n\t\treturn true;",
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/VisitService.php',
			'filter' => 'testResolveNoticeErrorIsAuthorOnlyAndOpenOnly',
			'mutations' => [
				'foreign_notice_retractable' => [
					'from' => "if (\$n === null || \$n->getActorRef() !== \$actorRef || \$n->getState() !== 'open') {",
					'to' => "if (\$n === null || \$n->getState() !== 'open') {",
				],
				'closed_notice_retractable' => [
					'from' => "if (\$n === null || \$n->getActorRef() !== \$actorRef || \$n->getState() !== 'open') {",
					'to' => "if (\$n === null || \$n->getActorRef() !== \$actorRef) {",
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/ExportService.php',
			'filter' => 'RefAndScopeTest',
			'mutations' => [
				'csv_injection_no_prefix' => [
					'from' => 'return "\'" . $v;',
					'to' => 'return $v;',
				],
				'csv_pattern_narrowed' => [
					'from' => "preg_match('/^[=+\\-@\\x09\\x0D\\x0B]/', \$v)",
					'to' => "preg_match('/^[=]/', \$v)",
				],
			],
		],
		[
			'file' => $appRoot . '/lib/Service/CryptoService.php',
			'filter' => 'CryptoServiceTest',
			'mutations' => [
				'format_gate_removed' => [
					'from' => "if (!str_starts_with(\$blob, 'v1:')) {",
					'to' => "if (false) {",
				],
				'corrupt_blob_gate_removed' => [
					'from' => 'if ($raw === false || strlen($raw) < 29) {',
					'to' => 'if ($raw === false) {',
				],
				'gcm_tag_wrong_offset' => [
					'from' => '$tag = substr($raw, 12, 16);',
					'to' => '$tag = substr($raw, 0, 16);',
				],
				'decrypt_failure_swallowed' => [
					'from' => "if (\$plain === false) {\n\t\t\tthrow new \\RuntimeException('decryption failed — key mismatch or tampering');\n\t\t}",
					'to' => "if (\$plain === false) {\n\t\t\treturn '';\n\t\t}",
				],
				'hmac_algo_weakened' => [
					'from' => "return hash_hmac('sha256', \$normalizedName, \$this->hmacKey());",
					'to' => "return hash_hmac('md5', \$normalizedName, \$this->hmacKey());",
				],
				'iv_static' => [
					'from' => '$iv = random_bytes(12);',
					'to' => '$iv = str_repeat("\\x00", 12);',
				],
			],
		],
	];
}

$failedToKill = [];
foreach (targets($appRoot) as $target) {
	$source = $target['file'];
	if (!is_file($source)) {
		fwrite(STDERR, "Missing {$source}\n");
		exit(1);
	}
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

echo "\nAll security mutations killed.\n";
exit(0);
