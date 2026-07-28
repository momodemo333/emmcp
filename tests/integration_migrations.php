<?php
/* Copyright (C) 2026 E-dem
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    tests/integration_migrations.php
 * \ingroup emmcp
 * \brief   Caching behaviour of EmmcpMigrations::runIfNeeded().
 *
 * The callers act on the answer — mcp.php refuses requests and the admin page
 * disables its actions — so a failure reported once must stay reported. The
 * guard used to be set before the outcome was known, so a second call in the
 * same request answered "up to date" after a failed migration.
 *
 * Read-only against the real database: the failure path runs against a stub.
 *
 * Run: make php s=/var/www/html/custom/emmcp/tests/integration_migrations.php
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

$res = 0;
if (!$res && file_exists("/var/www/html/main.inc.php")) {
	$res = @include "/var/www/html/main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Main include failed\n");
}

$failures = 0;

/**
 * @param  string $label  Check description
 * @param  bool   $ok     Outcome
 * @param  string $detail Extra context
 * @return void
 */
function check($label, $ok, $detail = '')
{
	global $failures;
	if ($ok) {
		print "  OK   ".$label."\n";
	} else {
		$failures++;
		print "  FAIL ".$label.($detail !== '' ? ' -- '.$detail : '')."\n";
	}
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/emmcp/class/emmcpmigrations.class.php');

/**
 * Minimal DoliDB stand-in whose every write fails.
 *
 * Only the handful of methods EmmcpMigrations touches are implemented. SELECTs
 * return "no rows" so the stored version reads as empty and a migration is
 * considered necessary; everything else fails with a non-ignorable error.
 */
class EmmcpFailingDbStub
{
	/** @var int */
	public $queries = 0;

	/**
	 * @param  string $sql Statement
	 * @return false       Always: this stub exists to fail
	 */
	public function query($sql)
	{
		$this->queries++;

		return false;
	}

	/**
	 * @param  mixed $resql Result handle
	 * @return null
	 */
	public function fetch_object($resql)
	{
		return null;
	}

	/**
	 * @param  mixed $resql Result handle
	 * @return int
	 */
	public function num_rows($resql)
	{
		return 0;
	}

	/**
	 * @param  string $value Value to escape
	 * @return string
	 */
	public function escape($value)
	{
		return str_replace("'", "''", (string) $value);
	}

	/**
	 * @param  int $date Timestamp
	 * @return string
	 */
	public function idate($date)
	{
		return date('Y-m-d H:i:s', (int) $date);
	}

	/**
	 * @return string
	 */
	public function lasterror()
	{
		return 'stubbed failure: the storage engine refused the statement';
	}

	/**
	 * @return string
	 */
	public function error()
	{
		return $this->lasterror();
	}

	/**
	 * Used by DolibarrModules::insert_permissions() when reading llx_const.
	 *
	 * @param  string $field Column name
	 * @return string
	 */
	public function decrypt($field)
	{
		return (string) $field;
	}

	/**
	 * @param  string $field Column name
	 * @return string
	 */
	public function encrypt($field)
	{
		return (string) $field;
	}

	/**
	 * @param  mixed $resql Result handle
	 * @return void
	 */
	public function free($resql = null)
	{
	}
}

print "== runIfNeeded() caches the real outcome ==\n";

// Failure path: two calls, both must say false. The second one used to say
// true because the guard was set before the result was known.
EmmcpMigrations::resetRuntimeCacheForTesting();
$stub = new EmmcpFailingDbStub();

$first = EmmcpMigrations::runIfNeeded($stub);
$second = EmmcpMigrations::runIfNeeded($stub);

check('first call on a failing database returns false', $first === false, var_export($first, true));
check('second call still returns false', $second === false, var_export($second, true));
check('the failure was not retried from scratch', $stub->queries > 0, (string) $stub->queries);

$queriesAfterTwoCalls = $stub->queries;
EmmcpMigrations::runIfNeeded($stub);
check('further calls are answered from the cache', $stub->queries === $queriesAfterTwoCalls, (string) $stub->queries);

// Success path against the real database: both calls true.
EmmcpMigrations::resetRuntimeCacheForTesting();

$firstOk = EmmcpMigrations::runIfNeeded($db);
$secondOk = EmmcpMigrations::runIfNeeded($db);

check('first call on a healthy database returns true', $firstOk === true, var_export($firstOk, true));
check('second call still returns true', $secondOk === true, var_export($secondOk, true));

// Leave the cache resolved, as a normal request would.
EmmcpMigrations::resetRuntimeCacheForTesting();
EmmcpMigrations::runIfNeeded($db);

print "\n".($failures === 0 ? "ALL CHECKS PASSED\n" : $failures." CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
