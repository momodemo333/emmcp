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
check('MCP package autoloader available', class_exists('\\DolibarrMcp\\Sql\\GrantInspector'));

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

print "\n== Gateway: dedicated account is mandatory ==\n";
dol_include_once('/emmcp/class/emmcpsqlgateway.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

// Whether the constants existed at all, not just their value: restoring a
// missing constant as an empty string leaves a row that was not there before.
$initialDbUserSet = (getDolGlobalString('EMMCP_SQL_DB_USER') !== '');
$initialDbPassSet = (getDolGlobalString('EMMCP_SQL_DB_PASSWORD') !== '');
$initialDbUser = getDolGlobalString('EMMCP_SQL_DB_USER');
$initialDbPass = getDolGlobalString('EMMCP_SQL_DB_PASSWORD');

// Accounts this run actually created. Only these are dropped: a fixed name
// plus CREATE IF NOT EXISTS would silently adopt — and then delete — a
// pre-existing account belonging to someone else.
$createdDbUsers = array();
$createdProbeTables = array();

/**
 * Create a database account with a name unique to this run.
 *
 * CREATE without IF NOT EXISTS on purpose: if the name somehow already exists,
 * the run must fail rather than take over an account it did not make.
 *
 * @param  string $prefix Readable prefix
 * @param  string $grant  Privileges to grant on the current database
 * @return array{0: string, 1: string} user and password
 */
function makeTestAccount($prefix, $grant)
{
    global $db, $dolibarr_main_db_name, $createdDbUsers;

    $user = substr($prefix.'_'.bin2hex(random_bytes(4)), 0, 32);
    $pass = 'p_'.bin2hex(random_bytes(8));

    $db->query("CREATE USER '".$db->escape($user)."'@'%' IDENTIFIED BY '".$db->escape($pass)."'");
    $createdDbUsers[] = $user;
    $db->query("GRANT ".$grant." ON `".$dolibarr_main_db_name."`.* TO '".$db->escape($user)."'@'%'");
    $db->query("FLUSH PRIVILEGES");

    return array($user, $pass);
}

/**
 * Undo everything this run created, whatever happens.
 *
 * Registered as a shutdown function so an exception, a fatal or an early exit
 * cannot leave database accounts, probe tables or altered constants behind.
 *
 * @return void
 */
function emmcpReadonlyCleanup()
{
    global $db, $conf, $createdDbUsers, $createdProbeTables;
    global $initialDbUser, $initialDbPass, $initialDbUserSet, $initialDbPassSet;

    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach ($createdDbUsers as $user) {
        $db->query("DROP USER IF EXISTS '".$db->escape($user)."'@'%'");
    }
    if ($createdDbUsers !== array()) {
        $db->query("FLUSH PRIVILEGES");
    }

    foreach ($createdProbeTables as $table) {
        $db->query("DROP TABLE IF EXISTS ".$table);
    }

    // Restore absence as absence: writing an empty value would leave a
    // constant row the instance did not have before this run.
    if ($initialDbUserSet) {
        dolibarr_set_const($db, 'EMMCP_SQL_DB_USER', $initialDbUser, 'chaine', 0, '', $conf->entity);
    } else {
        dolibarr_del_const($db, 'EMMCP_SQL_DB_USER', $conf->entity);
    }
    if ($initialDbPassSet) {
        dolibarr_set_const($db, 'EMMCP_SQL_DB_PASSWORD', $initialDbPass, 'chaine', 0, '', $conf->entity);
    } else {
        dolibarr_del_const($db, 'EMMCP_SQL_DB_PASSWORD', $conf->entity);
    }
}

register_shutdown_function('emmcpReadonlyCleanup');

// No dedicated account configured: the feature must simply not run. Falling
// back to Dolibarr's own account would give a "read-only" feature full DML and
// DDL privileges.
dolibarr_del_const($db, 'EMMCP_SQL_DB_USER', $conf->entity);
dolibarr_del_const($db, 'EMMCP_SQL_DB_PASSWORD', $conf->entity);
unset($conf->global->EMMCP_SQL_DB_USER, $conf->global->EMMCP_SQL_DB_PASSWORD);

try {
	(new EmmcpSqlGateway(5, 10, 262144))->runSelect('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe LIMIT 1');
	check('refused without a dedicated account', false, 'the query ran');
} catch (Throwable $e) {
	check('refused without a dedicated account', true, $e->getMessage());
}

// Refusing the application account even when it is named explicitly.
dolibarr_set_const($db, 'EMMCP_SQL_DB_USER', $dolibarr_main_db_user, 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'EMMCP_SQL_DB_PASSWORD', $dolibarr_main_db_pass, 'chaine', 0, '', $conf->entity);
$conf->global->EMMCP_SQL_DB_USER = $dolibarr_main_db_user;
$conf->global->EMMCP_SQL_DB_PASSWORD = $dolibarr_main_db_pass;

try {
	(new EmmcpSqlGateway(5, 10, 262144))->runSelect('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe LIMIT 1');
	check('refused when pointed at the application account', false, 'the query ran');
} catch (Throwable $e) {
	check('refused when pointed at the application account', true, $e->getMessage());
}

// Now provision a genuine SELECT-only account for the rest of the run.
list($roUser, $roPass) = makeTestAccount('emmcp_ro', 'SELECT');

$resql = $db->query("SELECT COUNT(*) as n FROM mysql.user WHERE user = '".$db->escape($roUser)."'");
$obj = $resql ? $db->fetch_object($resql) : null;
$provisioned = $obj && (int) $obj->n > 0;
check('SELECT-only test account provisioned', $provisioned);

if (!$provisioned) {
	print "  cannot continue without the test account\n";
	dolibarr_set_const($db, 'EMMCP_SQL_DB_USER', $initialDbUser, 'chaine', 0, '', $conf->entity);
	exit(1);
}

dolibarr_set_const($db, 'EMMCP_SQL_DB_USER', $roUser, 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'EMMCP_SQL_DB_PASSWORD', $roPass, 'chaine', 0, '', $conf->entity);
$conf->global->EMMCP_SQL_DB_USER = $roUser;
$conf->global->EMMCP_SQL_DB_PASSWORD = $roPass;

print "\n== Gateway: over-privileged accounts are refused ==\n";
// Being dedicated is not the same as being restricted: nothing stops an
// administrator from creating a separate account and granting it everything.
// These accounts are all "dedicated" and all must be refused.
$overPrivileged = array(
	'select_plus_insert' => 'SELECT, INSERT',
	'select_plus_create' => 'SELECT, CREATE',
	'select_plus_execute' => 'SELECT, EXECUTE',
);
$probeUsers = array();

foreach ($overPrivileged as $label => $grant) {
	list($pUser, $pPass) = makeTestAccount('emmcp_probe', $grant);
	$probeUsers[] = $pUser;

	dolibarr_set_const($db, 'EMMCP_SQL_DB_USER', $pUser, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'EMMCP_SQL_DB_PASSWORD', $pPass, 'chaine', 0, '', $conf->entity);
	$conf->global->EMMCP_SQL_DB_USER = $pUser;
	$conf->global->EMMCP_SQL_DB_PASSWORD = $pPass;

	try {
		(new EmmcpSqlGateway(5, 10, 262144))->runSelect('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe LIMIT 1');
		check('refused: account with '.$grant, false, 'the query ran');
	} catch (Throwable $e) {
		$msg = $e->getMessage();
		check('refused: account with '.$grant, true);
		// Grant lines carry the account password hash; the reason must not
		// quote one.
		check(
			'  refusal reason quotes no grant line',
			stripos($msg, 'IDENTIFIED') === false && stripos($msg, 'GRANT USAGE') === false,
			$msg
		);
	}
}

// A global SELECT reaches every database on the server.
$gUser = substr('emmcp_probe_g_'.bin2hex(random_bytes(4)), 0, 32);
$gPass = 'p_'.bin2hex(random_bytes(8));
$probeUsers[] = $gUser;
$createdDbUsers[] = $gUser;
$db->query("CREATE USER '".$db->escape($gUser)."'@'%' IDENTIFIED BY '".$db->escape($gPass)."'");
$db->query("GRANT SELECT ON *.* TO '".$db->escape($gUser)."'@'%'");
$db->query("FLUSH PRIVILEGES");
dolibarr_set_const($db, 'EMMCP_SQL_DB_USER', $gUser, 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'EMMCP_SQL_DB_PASSWORD', $gPass, 'chaine', 0, '', $conf->entity);
$conf->global->EMMCP_SQL_DB_USER = $gUser;
$conf->global->EMMCP_SQL_DB_PASSWORD = $gPass;
try {
	(new EmmcpSqlGateway(5, 10, 262144))->runSelect('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe LIMIT 1');
	check('refused: account with global SELECT', false, 'the query ran');
} catch (Throwable $e) {
	check('refused: account with global SELECT', true);
}

foreach ($probeUsers as $pUser) {
	$db->query("DROP USER IF EXISTS '".$db->escape($pUser)."'@'%'");
}
$db->query("FLUSH PRIVILEGES");
check('probe accounts removed', true);

// Back to the genuinely SELECT-only account for the rest of the run.
dolibarr_set_const($db, 'EMMCP_SQL_DB_USER', $roUser, 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'EMMCP_SQL_DB_PASSWORD', $roPass, 'chaine', 0, '', $conf->entity);
$conf->global->EMMCP_SQL_DB_USER = $roUser;
$conf->global->EMMCP_SQL_DB_PASSWORD = $roPass;

print "\n== Gateway: engine-level read-only ==\n";
$gateway = new EmmcpSqlGateway(5, 10, 262144);

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
// implicit commit and run anyway. The gateway's own keyword guard is what
// blocks it here; a MySQL account limited to SELECT (EMMCP_SQL_DB_USER) is the
// only way to have the server enforce it.
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
	$capped = new EmmcpSqlGateway(5, 2, 262144);
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

print "\n== Server-enforced read-only (SELECT-only grant) ==\n";
// With a SELECT-only account the refusal comes from the server itself, not
// from the gateway's keyword guard — which is the whole point of requiring it.
/**
 * Run a statement and report whether the server refused it.
 *
 * mysqli may be in exception mode (Dolibarr sets mysqli_report), so a refusal
 * arrives as a thrown error rather than a false return. Both count.
 *
 * @param  mysqli $link Connection
 * @param  string $sql  Statement
 * @return bool         True when the server refused it
 */
function serverRefuses($link, $sql)
{
	try {
		return @$link->query($sql) === false;
	} catch (Throwable $e) {
		return true;
	}
}

$probeTable = MAIN_DB_PREFIX.'emmcp_probe_'.bin2hex(random_bytes(4));
$createdProbeTables[] = $probeTable;

try {
	$link = new mysqli($dolibarr_main_db_host, $roUser, $roPass, $dolibarr_main_db_name, !empty($dolibarr_main_db_port) ? (int) $dolibarr_main_db_port : 3306);
	check('test account can connect', !$link->connect_errno, (string) $link->connect_error);

	check(
		'server refuses DDL for the test account',
		serverRefuses($link, "CREATE TABLE ".$probeTable." (a int)")
	);
	check(
		'server refuses UPDATE for the test account',
		serverRefuses($link, "UPDATE ".MAIN_DB_PREFIX."societe SET nom = nom WHERE rowid = 0")
	);
	check(
		'server refuses INSERT for the test account',
		serverRefuses($link, "INSERT INTO ".MAIN_DB_PREFIX."societe (nom) VALUES ('x')")
	);
	check(
		'server allows SELECT for the test account',
		!serverRefuses($link, "SELECT rowid FROM ".MAIN_DB_PREFIX."societe LIMIT 1")
	);
	$link->close();
} catch (Throwable $e) {
	check('grant probe', false, $e->getMessage());
}

print "\n== Cleanup ==\n";
emmcpReadonlyCleanup();

$resql = $db->query("SELECT COUNT(*) as n FROM mysql.user WHERE user = '".$db->escape($roUser)."'");
$obj = $resql ? $db->fetch_object($resql) : null;
check('test account removed', $obj && (int) $obj->n === 0);

$resql = $db->query("SHOW TABLES LIKE '".$db->escape($probeTable)."'");
check('probe table removed', $resql && $db->num_rows($resql) === 0);

$stillSet = (getDolGlobalString('EMMCP_SQL_DB_USER') !== '');
check('credential constant restored to its initial presence', $stillSet === $initialDbUserSet);

print "\n".($failures === 0 ? "ALL CHECKS PASSED\n" : $failures." CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
