<?php
/* Copyright (C) 2026 E-dem — GPL-3.0-or-later */

/**
 * \file    tests/integration_runtime_guard.php
 * \ingroup emmcp
 * \brief   The runtime guard names a foreign copy of a shared library instead of dying.
 *
 * Runs against the unprefixed runtime of the development checkout, the case
 * the guard exists for. Two ways a foreign copy wins, both seen in the wild:
 *  1. it is already declared when mcp.php starts (eager require by a module);
 *  2. a foreign autoloader registered ahead of ours resolves it on demand.
 *
 * Run: make php s=/var/www/html/custom/emmcp/tests/integration_runtime_guard.php
 */

define('NOLOGIN', '1');
define('NOCSRFCHECK', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');
define('NOSESSION', '1');

$res = @include "/var/www/html/main.inc.php";
if (!$res) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Main include failed\n");
}

dol_include_once('/emmcp/lib/emmcp.lib.php');
dol_include_once('/emmcp/lib/emmcp_runtime.php');

$failures = 0;
function check($label, $ok, $detail = '')
{
	global $failures;
	print ($ok ? '  OK   ' : '  FAIL ').$label.($ok || $detail === '' ? '' : ' -- '.$detail)."\n";
	if (!$ok) {
		$failures++;
	}
}

$autoload = emmcpFindMcpAutoloader();
check('a runtime is found', (bool) $autoload);
$dir = emmcp_runtime_package_dir($autoload);
$scoped = emmcp_runtime_prefix($dir) !== '';
print '  (runtime '.emmcp_runtime_short_path($dir).', '.($scoped ? 'scoped' : 'unscoped').")\n";

check('nothing foreign before anything is loaded', emmcp_runtime_conflicts($dir, false) === array());

if ($scoped) {
	print "  scoped runtime: foreign Psr\\ classes cannot collide; scenario skipped\n";
} else {
	// Scenario 2 first (nothing declared yet): a foreign autoloader that
	// answers for Psr\Log\ ahead of ours.
	$fixtureDir = __DIR__.'/fixtures/psr-log-1';
	$foreign = function ($class) use ($fixtureDir) {
		if ($class === 'Psr\Log\LoggerInterface') {
			require $fixtureDir.'/LoggerInterface.php';
		}
	};
	require_once $autoload;              // ours, prepended
	spl_autoload_register($foreign, true, true); // theirs, now ahead of ours

	$conflicts = emmcp_runtime_conflicts($dir, true);
	check('a foreign autoloader winning is reported', count($conflicts) === 1 && $conflicts[0]['type'] === 'Psr\Log\LoggerInterface', json_encode($conflicts));
	check('...before the class that would have died is loaded', !class_exists('Psr\Log\NullLogger', false));
	check('...naming the foreign file', isset($conflicts[0]) && strpos($conflicts[0]['file'], 'fixtures/psr-log-1/LoggerInterface.php') !== false);
	check('the message says what to do', isset($conflicts[0]) && strpos(emmcp_runtime_conflict_message($conflicts[0]), 'Diagnostic') !== false);

	// Scenario 1: it is now declared; the read-only check sees it too.
	$conflicts = emmcp_runtime_conflicts($dir, false);
	check('an already declared foreign copy is reported without loading anything', count($conflicts) >= 1 && $conflicts[0]['type'] === 'Psr\Log\LoggerInterface');
}

emmcp_runtime_record_error('test error', __FILE__, 1);
$last = emmcp_runtime_last_error();
check('a runtime error is recorded for the Diagnostic tab', is_array($last) && $last['message'] === 'test error');
emmcp_runtime_clear_error();
check('...and can be cleared', emmcp_runtime_last_error() === null);

print $failures === 0 ? "PASSED\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
