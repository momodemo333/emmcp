<?php
/* Copyright (C) 2026 E-dem
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/obsolete_files_test.php
 * \ingroup emmcp
 * \brief   EmmcpMigrations::removeObsoleteFiles() deletes exactly the listed
 *          files, inside the module only, and never fails the upgrade.
 *
 * Plain PHP, no Dolibarr needed. Run as a non-root user (the "cannot delete"
 * case relies on directory permissions, which root ignores):
 *   php tests/obsolete_files_test.php
 */

if (!function_exists('dol_syslog')) {
	$GLOBALS['logged'] = array();
	/**
	 * @param string $msg   Message
	 * @param int    $level Level
	 * @return void
	 */
	function dol_syslog($msg, $level = 0)
	{
		$GLOBALS['logged'][] = $msg;
	}
}
if (!defined('LOG_WARNING')) {
	define('LOG_WARNING', 4);
}
if (!defined('LOG_INFO')) {
	define('LOG_INFO', 6);
}

require __DIR__.'/../class/emmcpmigrations.class.php';

$failures = 0;
/**
 * @param string $label Label
 * @param bool   $ok    Outcome
 * @return void
 */
function check($label, $ok)
{
	global $failures;
	print ($ok ? '  OK   ' : '  FAIL ').$label."\n";
	$failures += $ok ? 0 : 1;
}

$base = sys_get_temp_dir().'/emmcp-obsolete-'.bin2hex(random_bytes(4));
$module = $base.'/emmcp';
$outside = $base.'/outside';
mkdir($outside, 0777, true);

$list = EmmcpMigrations::OBSOLETE_FILES;
foreach ($list as $rel) {
	@mkdir(dirname($module.'/'.$rel), 0777, true);
	file_put_contents($module.'/'.$rel, 'old');
}
// A file the module still ships, next to the obsolete ones: must survive.
file_put_contents($module.'/class/emmcpmigrations.class.php', 'current');
// Outside the module: must survive.
file_put_contents($outside.'/victim.php', 'keep');
// An obsolete path that is a symlink to a file outside: the link is skipped,
// and above all its target is untouched.
unlink($module.'/'.$list[0]);
symlink($outside.'/victim.php', $module.'/'.$list[0]);
// An obsolete path that is a directory: never removed.
$dirCase = $list[1];
unlink($module.'/'.$dirCase);
mkdir($module.'/'.$dirCase);
// An obsolete file the web server may not delete (read-only directory).
$roRel = 'vendor/dolibarr-mcp-server/vendor/webmozart/assert/.php-cs-fixer.php';
chmod(dirname($module.'/'.$roRel), 0555);

$m = new EmmcpMigrations(null);
$removed = $m->removeObsoleteFiles($module);

check('read-only case did not throw, and was logged', (bool) array_filter($GLOBALS['logged'], function ($l) {
	return strpos($l, 'Could not remove obsolete file') !== false;
}));
check('read-only file left in place', is_file($module.'/'.$roRel));
check('symlink target outside the module untouched', file_get_contents($outside.'/victim.php') === 'keep');
check('symlink itself not followed for deletion', is_link($module.'/'.$list[0]));
check('directory with an obsolete name kept', is_dir($module.'/'.$dirCase));
check('current file kept', file_get_contents($module.'/class/emmcpmigrations.class.php') === 'current');

$expected = array_values(array_diff($list, array($list[0], $dirCase, $roRel)));
check('every other listed file removed ('.count($expected).')', $removed === $expected);
$left = array_filter($expected, function ($rel) use ($module) {
	return file_exists($module.'/'.$rel);
});
check('none of them left on disk', !$left);
check('second run removes nothing (idempotent)', $m->removeObsoleteFiles($module) === array());
check('missing module directory is a no-op', $m->removeObsoleteFiles($base.'/nope') === array());

// Clean up.
chmod(dirname($module.'/'.$roRel), 0755);
exec('rm -rf '.escapeshellarg($base));

print "\n".($failures === 0 ? "ALL CHECKS PASSED\n" : $failures." CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
