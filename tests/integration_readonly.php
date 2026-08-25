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
 * \file    tests/integration_readonly.php
 * \ingroup emmcp
 * \brief   Adversarial integration check of the read-only SQL gateway.
 *
 * The unit suite proves the validator refuses writes. This proves the *engine*
 * refuses them too, which is the guarantee that still holds if the validator is
 * ever outsmarted.
 *
 * Run: make php s=/var/www/html/custom/emmcp/tests/integration_readonly.php
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
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res && file_exists("/var/www/html/main.inc.php")) {
	$res = @include "/var/www/html/main.inc.php";
}
if (!$res) {
	die("Main include failed\n");
}

$failures = 0;

/**
 * @param string $label Check description
 * @param bool   $ok    Outcome
 * @param string $detail Extra context
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

// Loaded up front: the gateway now inspects the account's grants through the
// package's GrantInspector and refuses to run without it. mcp.php loads the
// autoloader before building the capability, so this mirrors production.
$autoload = dol_buildpath('/emmcp/vendor/dolibarr-mcp-server/vendor/autoload.php', 0);
if (!file_exists($autoload)) {
	$autoload = dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0);
}
if (file_exists($autoload)) {
	require_once $autoload;
}
check('MCP package autoloader available', class_exists('\\DolibarrMcp\\Sql\\SqlReadOnlyValidator'));

// --- State capture and cleanup registration, before ANY mutation ------------
//
// Everything below this point may change the instance, so the undo path is
// installed first. Registering it later left a window in which an exception
// would strand the schema version, an account or a constant.

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/emmcp/class/emmcpmigrations.class.php');

// Whether each constant existed at all, not just its value: restoring a
// missing constant as an empty string leaves a row that was not there before.

// The schema version is mutated by the downgrade check further down.
$initialSchemaVersion = '';
$initialSchemaVersionSet = false;
$resql = $db->query(
    "SELECT value FROM ".MAIN_DB_PREFIX."const"
    ." WHERE name = '".$db->escape(EmmcpMigrations::VERSION_CONSTANT)."'"
    ." AND entity = ".((int) $conf->entity)
);
if ($resql) {
    $obj = $db->fetch_object($resql);
    if ($obj) {
        $initialSchemaVersion = (string) $obj->value;
        $initialSchemaVersionSet = true;
    }
}

// Accounts this run actually created. Only these are dropped: a fixed name
// plus CREATE IF NOT EXISTS would silently adopt — and then delete — a
// pre-existing account belonging to someone else.
$createdProbeTables = array();

/**
 * Undo everything this run created or changed, whatever happens.
 *
 * Registered as a shutdown function so an exception, a fatal or an early exit
 * cannot leave database accounts, probe tables, an altered schema version or
 * altered constants behind.
 *
 * @return void
 */
function emmcpReadonlyCleanup()
{
    global $db, $conf, $createdProbeTables;
    global $initialSchemaVersion, $initialSchemaVersionSet;

    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach ($createdProbeTables as $table) {
        $db->query("DROP TABLE IF EXISTS ".$table);
    }


    if ($initialSchemaVersionSet) {
        $db->query(
            "UPDATE ".MAIN_DB_PREFIX."const SET value = '".$db->escape($initialSchemaVersion)."'"
            ." WHERE name = '".$db->escape(EmmcpMigrations::VERSION_CONSTANT)."'"
            ." AND entity = ".((int) $conf->entity)
        );
    } else {
        dolibarr_del_const($db, EmmcpMigrations::VERSION_CONSTANT, $conf->entity);
    }
}

register_shutdown_function('emmcpReadonlyCleanup');

print "== Migrations ==\n";
dol_include_once('/emmcp/class/emmcpmigrations.class.php');
$migrations = new EmmcpMigrations($db);
$ran = $migrations->run();
check('migrations run without error', $ran, implode(' | ', $migrations->getErrors()));

// Compared with version_compare, not equality: a database left at a *newer*
// version (a downgrade, a botched rollback) must be neither rewritten
// backwards nor re-migrated on every single request.
check('schema is not behind the code', !$migrations->isUpgradeNeeded(), $migrations->getStoredVersion());

$storedBefore = $migrations->getStoredVersion();
$db->query("UPDATE ".MAIN_DB_PREFIX."const SET value = '9.9.9' WHERE name = '".EmmcpMigrations::VERSION_CONSTANT."'");
$ahead = new EmmcpMigrations($db);
check('a newer stored version is not treated as an upgrade', !$ahead->isUpgradeNeeded(), $ahead->getStoredVersion());
$ahead->run();
check('a newer stored version is never rolled back', $ahead->getStoredVersion() === '9.9.9', $ahead->getStoredVersion());
$db->query(
	"UPDATE ".MAIN_DB_PREFIX."const SET value = '".$db->escape($storedBefore)."'"
	." WHERE name = '".EmmcpMigrations::VERSION_CONSTANT."'"
);
check('schema version restored', (new EmmcpMigrations($db))->getStoredVersion() === $storedBefore);

foreach (array('emmcp_sql_permissions', 'emmcp_sql_audit') as $table) {
	$resql = $db->query("SHOW TABLES LIKE '".MAIN_DB_PREFIX.$table."'");
	check('table '.MAIN_DB_PREFIX.$table.' exists', $resql && $db->num_rows($resql) === 1);
}

print "\n== Gateway: engine-level read-only ==\n";
dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
check('dolibarr-mcp-sql library found', emmcp_mcp_sql_autoload() !== null);
$gateway = new \DolibarrMcpSql\SqlGateway(5, 10, 262144, '[EMMCP]');

// A legitimate read must work.
try {
	$out = $gateway->runSelect('SELECT rowid, nom FROM '.MAIN_DB_PREFIX.'societe LIMIT 3');
	check('SELECT returns rows', is_array($out['rows']), 'row_count='.$out['row_count']);
	check('columns are reported', in_array('nom', $out['columns'], true), implode(',', $out['columns']));
} catch (Throwable $e) {
	check('SELECT returns rows', false, $e->getMessage());
}

// The decisive one: a write reaching the gateway must be refused by the engine,
// not merely by the validator upstream.
try {
	$gateway->runSelect("UPDATE ".MAIN_DB_PREFIX."societe SET nom = 'SHOULD-NEVER-HAPPEN' WHERE rowid = 0");
	check('UPDATE refused by the engine', false, 'the write was accepted');
} catch (Throwable $e) {
	check('UPDATE refused by the engine', true, $e->getMessage());
}

// DDL is NOT stopped by a read-only transaction: CREATE/DROP/ALTER force an
// implicit commit and run anyway. Since the dedicated SELECT-only account is
// gone, the gateway's own keyword guard and the validator upstream are the
// whole guarantee — which is exactly why this check must stay green.
try {
	$gateway->runSelect("CREATE TABLE ".MAIN_DB_PREFIX."emmcp_should_never_exist (a int)");
	check('CREATE TABLE refused', false, 'DDL was accepted');
} catch (Throwable $e) {
	check('CREATE TABLE refused', true, $e->getMessage());
}

foreach (array(
	"DROP TABLE ".MAIN_DB_PREFIX."societe",
	"ALTER TABLE ".MAIN_DB_PREFIX."societe ADD COLUMN emmcp_x INT",
	"INSERT INTO ".MAIN_DB_PREFIX."societe (nom) VALUES ('x')",
	"DELETE FROM ".MAIN_DB_PREFIX."societe WHERE rowid = 0",
	"TRUNCATE TABLE ".MAIN_DB_PREFIX."societe",
	"GRANT SELECT ON *.* TO 'x'@'%'",
	"SET GLOBAL general_log = 'ON'",
) as $dangerous) {
	try {
		$gateway->runSelect($dangerous);
		check('refused: '.substr($dangerous, 0, 40), false, 'accepted');
	} catch (Throwable $e) {
		check('refused: '.substr($dangerous, 0, 40), true);
	}
}

$resql = $db->query("SHOW TABLES LIKE '".MAIN_DB_PREFIX."emmcp_should_never_exist'");
check('no table was created', $resql && $db->num_rows($resql) === 0);

$resql = $db->query("SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."societe WHERE nom = 'SHOULD-NEVER-HAPPEN'");
$obj = $resql ? $db->fetch_object($resql) : null;
check('no row was modified', $obj && (int) $obj->n === 0);

print "\n== Gateway: session hardening ==\n";
// The validator reads a statement the way a default MySQL session would. If
// the session actually runs with ANSI_QUOTES, "api_key" stops being a string
// and becomes a live column reference — validation would describe a different
// statement from the one executed.
try {
	$mode = $gateway->probeSessionSqlMode();
	check('sql_mode has no ANSI_QUOTES', strpos($mode, 'ANSI_QUOTES') === false, $mode);
	check('sql_mode has no NO_BACKSLASH_ESCAPES', strpos($mode, 'NO_BACKSLASH_ESCAPES') === false, $mode);
} catch (Throwable $e) {
	check('sql_mode probe', false, $e->getMessage());
}

// Row and byte caps bound the OUTPUT, not the execution: they are applied
// while fetching, after the server has done the work. The statement timeout
// is the only bound on CPU time, so its absence must abort the connection.
try {
	$timeout = $gateway->probeStatementTimeout();
	check('statement timeout is set on the session', $timeout > 0 && $timeout <= 30, (string) $timeout);
} catch (Throwable $e) {
	check('statement timeout probe', false, $e->getMessage());
}

print "\n== Gateway: row cap ==\n";
try {
	$capped = new \DolibarrMcpSql\SqlGateway(5, 2, 262144, '[EMMCP]');
	$out = $capped->runSelect('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe LIMIT 100');
	check('rows are capped at the configured maximum', $out['row_count'] <= 2, 'got '.$out['row_count']);
} catch (Throwable $e) {
	check('rows are capped at the configured maximum', false, $e->getMessage());
}

print "\n== Schema introspection ==\n";
try {
	$schema = $gateway->describeSchema(MAIN_DB_PREFIX.'facture', static function ($t) {
		return strpos($t, MAIN_DB_PREFIX) === 0;
	});
	check('facture table described', isset($schema['tables'][MAIN_DB_PREFIX.'facture']));
} catch (Throwable $e) {
	check('facture table described', false, $e->getMessage());
}

print "\n== Validator wired to the real prefix ==\n";
$autoload = dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0);
if (file_exists($autoload)) {
	require_once $autoload;
	$policy = new \DolibarrMcp\Sql\SqlPolicy(MAIN_DB_PREFIX);
	$validator = new \DolibarrMcp\Sql\SqlReadOnlyValidator($policy);

	try {
		$validator->validate('SELECT api_key FROM '.MAIN_DB_PREFIX.'user');
		check('credential column refused', false, 'accepted');
	} catch (\DolibarrMcp\Sql\SqlValidationException $e) {
		check('credential column refused', $e->code() === 'SQL_FORBIDDEN_COLUMN', $e->code());
	}

	try {
		$sql = $validator->validate('SELECT rowid, nom FROM '.MAIN_DB_PREFIX.'societe');
		check('legitimate query accepted and limited', strpos($sql, 'LIMIT') !== false, $sql);
		$out = $gateway->runSelect($sql);
		check('validated query executes', is_array($out['rows']));
	} catch (Throwable $e) {
		check('legitimate query accepted and limited', false, $e->getMessage());
	}
} else {
	print "  SKIP validator checks (MCP package autoloader not found)\n";
}

print "\n== Cleanup ==\n";
emmcpReadonlyCleanup();

// The gateway must not have been able to create anything, so the probe name
// used by the DDL attempts above must still be absent after cleanup.
$resql = $db->query("SHOW TABLES LIKE '".MAIN_DB_PREFIX."emmcp_should_never_exist'");
check('no probe table survives', $resql && $db->num_rows($resql) === 0);


print "\n".($failures === 0 ? "ALL CHECKS PASSED\n" : $failures." CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
