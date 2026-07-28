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
 * \file    tests/integration_mcp_flow.php
 * \ingroup emmcp
 * \brief   End-to-end check of the authorisation chain and tool exposure.
 *
 * Proves the property the design rests on: with the feature off the SQL tools
 * are not merely refused, they are absent from tools/list.
 *
 * Run: make php s=/var/www/html/custom/emmcp/tests/integration_mcp_flow.php
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

$autoload = dol_buildpath('/emmcp/vendor/dolibarr-mcp-server/vendor/autoload.php', 0);
if (!file_exists($autoload)) {
	$autoload = dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0);
}
if (!file_exists($autoload)) {
	die("MCP package autoloader not found\n");
}
require_once $autoload;

dol_include_once('/emmcp/class/emmcpmigrations.class.php');
dol_include_once('/emmcp/class/emmcpsqlpermissions.class.php');
dol_include_once('/emmcp/class/emmcpsqlgateway.class.php');
dol_include_once('/emmcp/class/emmcpsqlaudit.class.php');
dol_include_once('/emmcp/class/emmcpsqlcapability.class.php');

EmmcpMigrations::runIfNeeded($db);

$testUser = new User($db);
$testUser->fetch(1);
$testUser->getrights();

$permissions = new EmmcpSqlPermissions($db, $conf);

// Remember the starting state so the instance is left as it was found.
$initialFlag = getDolGlobalInt('EMMCP_SQL_ENABLED');
// Presence as well as value: the flag is off by default and may simply not
// exist, in which case restoring it as "0" leaves a row the instance did not
// have — and a constant row reads as configured to anyone auditing later.
$initialFlagSet = false;
$resql = $db->query(
	"SELECT rowid FROM ".MAIN_DB_PREFIX."const"
	." WHERE name = 'EMMCP_SQL_ENABLED' AND entity = ".((int) $conf->entity)
);
$initialFlagSet = ($resql && $db->num_rows($resql) > 0);
$initialOptIn = $permissions->hasUserOptIn(1);
$initialDbUser = getDolGlobalString('EMMCP_SQL_DB_USER');
$initialDbPass = getDolGlobalString('EMMCP_SQL_DB_PASSWORD');
// Presence, not just value: restoring a missing constant as an empty string
// would leave a row the instance did not have.
$initialDbUserSet = ($initialDbUser !== '');
$initialDbPassSet = ($initialDbPass !== '');
$initialOptInRowExisted = false;
$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."emmcp_sql_permissions WHERE fk_user = 1 AND entity = ".((int) $conf->entity));
$initialOptInRowExisted = ($resql && $db->num_rows($resql) > 0);

// Every audit row this run writes carries a source unique to the run, so the
// cleanup deletes exactly its own records. Deleting by "rowid greater than a
// marker" would take a concurrent session's rows with it.
$auditSource = substr('t_'.bin2hex(random_bytes(5)), 0, 16);

$grantedForTest = false;
$rightId = 0;

/**
 * Put the instance back exactly as it was found.
 *
 * Registered as a shutdown function rather than only called at the end: a
 * failed assertion, an exception or a fatal would otherwise leave the feature
 * enabled, a right granted and a database account lying around. Idempotent, so
 * calling it explicitly and having it run again at shutdown is harmless.
 *
 * @return void
 */
function emmcpFlowCleanup()
{
	global $db, $conf, $roUser, $initialFlag, $initialOptIn, $initialDbUser, $initialDbPass;
	global $auditSource, $grantedForTest, $rightId, $createdDbUser;
	global $initialDbUserSet, $initialDbPassSet, $initialOptInRowExisted, $initialFlagSet;

	static $done = false;
	if ($done) {
		return;
	}
	$done = true;

	// Only an account this run actually created.
	if ($createdDbUser !== '') {
		$db->query("DROP USER IF EXISTS '".$db->escape($createdDbUser)."'@'%'");
		$db->query("FLUSH PRIVILEGES");
	}

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

	if ($initialFlagSet) {
		dolibarr_set_const($db, 'EMMCP_SQL_ENABLED', (string) $initialFlag, 'chaine', 0, '', $conf->entity);
	} else {
		dolibarr_del_const($db, 'EMMCP_SQL_ENABLED', $conf->entity);
	}

	dol_include_once('/emmcp/class/emmcpsqlpermissions.class.php');
	if ($initialOptInRowExisted) {
		(new EmmcpSqlPermissions($db, $conf))->setUserOptIn(1, $initialOptIn, 1);
	} else {
		// There was no row before; leave none behind.
		$db->query(
			"DELETE FROM ".MAIN_DB_PREFIX."emmcp_sql_permissions"
			." WHERE fk_user = 1 AND entity = ".((int) $conf->entity)
		);
	}

	if ($grantedForTest && !empty($rightId)) {
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."user_rights WHERE fk_user = 1 AND fk_id = ".((int) $rightId));
	}

	// Exactly this run's rows, identified by its unique source marker: a
	// "rowid greater than N" delete would also remove a concurrent session's
	// records.
	$db->query(
		"DELETE FROM ".MAIN_DB_PREFIX."emmcp_sql_audit"
		." WHERE source = '".$db->escape($auditSource)."'"
	);
}

/**
 * Snapshot of everything the run is expected to have restored.
 *
 * @return array<string, mixed>
 */
function emmcpFlowInspectState()
{
	global $db, $roUser;

	$one = function ($sql) use ($db) {
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;

		return $obj ? (int) $obj->n : -1;
	};

	return array(
		'flag' => getDolGlobalInt('EMMCP_SQL_ENABLED'),
		'optins' => $one("SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."emmcp_sql_permissions WHERE sql_enabled = 1"),
		'rights' => $one(
			"SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."user_rights ur"
			." JOIN ".MAIN_DB_PREFIX."rights_def r ON r.id = ur.fk_id WHERE r.module = 'emmcp'"
		),
		'dbusers' => $one("SELECT COUNT(*) AS n FROM mysql.user WHERE user = '".$db->escape($roUser)."'"),
		'audit_after_marker' => $one(
			"SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."emmcp_sql_audit"
			." WHERE source = '".$db->escape($GLOBALS['auditSource'])."'"
		),
		'dbuser_const' => getDolGlobalString('EMMCP_SQL_DB_USER'),
		'flag_row_exists' => (bool) $one(
			"SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."const"
			." WHERE name = 'EMMCP_SQL_ENABLED' AND entity = ".((int) $GLOBALS['conf']->entity)
		),
	);
}

// Execution now requires a dedicated SELECT-only account, so the flow test
// provisions one and removes it at the end.
global $dolibarr_main_db_name, $dolibarr_main_db_user, $dolibarr_main_db_pass;
$roUser = substr('emmcp_flow_'.bin2hex(random_bytes(4)), 0, 32);
$roPass = 'ro_'.bin2hex(random_bytes(8));
$createdDbUser = '';

register_shutdown_function('emmcpFlowCleanup');
// No IF NOT EXISTS: a name collision must fail the run rather than take over
// an account this test did not create.
$db->query("CREATE USER '".$db->escape($roUser)."'@'%' IDENTIFIED BY '".$db->escape($roPass)."'");
$createdDbUser = $roUser;
$db->query("GRANT SELECT ON `".$dolibarr_main_db_name."`.* TO '".$db->escape($roUser)."'@'%'");
$db->query("FLUSH PRIVILEGES");
dolibarr_set_const($db, 'EMMCP_SQL_DB_USER', $roUser, 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, 'EMMCP_SQL_DB_PASSWORD', $roPass, 'chaine', 0, '', $conf->entity);
$conf->global->EMMCP_SQL_DB_USER = $roUser;
$conf->global->EMMCP_SQL_DB_PASSWORD = $roPass;

/**
 * List the tool names the MCP server would advertise for a given capability.
 *
 * @param  mixed $capability Capability or null
 * @return string[]
 */
function listToolNames($capability)
{
	$sessionDir = sys_get_temp_dir().'/emmcp-flow-'.uniqid();
	$config = new \DolibarrMcp\Config\ConnectionConfig(DOL_MAIN_URL_ROOT, 'unused-for-discovery');

	$init = new \GuzzleHttp\Psr7\ServerRequest('POST', 'https://example.org/mcp.php', array(
		'Content-Type' => 'application/json',
		'Accept' => 'application/json, text/event-stream',
	), json_encode(array(
		'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
		'params' => array(
			'protocolVersion' => '2025-06-18',
			'capabilities' => new stdClass(),
			'clientInfo' => array('name' => 'emmcp-selftest', 'version' => '1.0'),
		),
	)));

	$response = \DolibarrMcp\Bootstrap::handleHttpRequest($init, $sessionDir, $config, $capability);
	$sessionId = $response->getHeaderLine('Mcp-Session-Id');

	$list = new \GuzzleHttp\Psr7\ServerRequest('POST', 'https://example.org/mcp.php', array(
		'Content-Type' => 'application/json',
		'Accept' => 'application/json, text/event-stream',
		'Mcp-Session-Id' => $sessionId,
	), json_encode(array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new stdClass())));

	$response = \DolibarrMcp\Bootstrap::handleHttpRequest($list, $sessionDir, $config, $capability);
	$body = json_decode((string) $response->getBody(), true);

	$names = array();
	foreach (($body['result']['tools'] ?? array()) as $tool) {
		$names[] = $tool['name'];
	}

	return $names;
}

print "== Feature disabled (default state) ==\n";
dolibarr_set_const($db, 'EMMCP_SQL_ENABLED', '0', 'chaine', 0, '', $conf->entity);
$conf->global->EMMCP_SQL_ENABLED = '0';

check('global flag reports disabled', !$permissions->isGloballyEnabled());
check('denial code is SQL_DISABLED', $permissions->denialCode($testUser) === 'SQL_DISABLED', (string) $permissions->denialCode($testUser));

$names = listToolNames(null);
check('other tools are exposed', in_array('dolibarr_list', $names, true), count($names).' tools');
check('dolibarr_sql_query is ABSENT', !in_array('dolibarr_sql_query', $names, true));
check('dolibarr_sql_schema is ABSENT', !in_array('dolibarr_sql_schema', $names, true));

print "\n== Flag on, user not opted in ==\n";
dolibarr_set_const($db, 'EMMCP_SQL_ENABLED', '1', 'chaine', 0, '', $conf->entity);
$conf->global->EMMCP_SQL_ENABLED = '1';
$permissions->setUserOptIn(1, false, 1);
$permissions = new EmmcpSqlPermissions($db, $conf);

$code = $permissions->denialCode($testUser);
check('still refused without opt-in', $code === 'SQL_PERMISSION_DENIED', (string) $code);

print "\n== Flag on, user opted in, right granted ==\n";
$permissions->setUserOptIn(1, true, 1);
$permissions = new EmmcpSqlPermissions($db, $conf);
check('user opt-in persisted', $permissions->hasUserOptIn(1));

// Being an administrator is NOT enough: Dolibarr only force-grants a handful of
// user-module rights in loadRights(), so this right has to be assigned like any
// other. That is the deny-by-default behaviour we want, and it is asserted here
// rather than assumed.
$resql = $db->query("SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = 'emmcp' AND perms = 'sqlquery'");
$rightRow = $resql ? $db->fetch_object($resql) : null;
check('right is registered by the migration', $rightRow !== null);

$grantedForTest = false;
if ($rightRow) {
	$rightId = (int) $rightRow->id;
	$resql = $db->query("SELECT fk_id FROM ".MAIN_DB_PREFIX."user_rights WHERE fk_user = 1 AND fk_id = ".$rightId);
	$alreadyGranted = $resql && $db->num_rows($resql) > 0;
	check('admin does NOT hold the right by default', !$alreadyGranted);

	if (!$alreadyGranted) {
		$db->query("INSERT INTO ".MAIN_DB_PREFIX."user_rights (fk_user, fk_id, entity) VALUES (1, ".$rightId.", ".((int) $conf->entity).")");
		$grantedForTest = true;
	}

	$testUser = new User($db);
	$testUser->fetch(1);
	$testUser->getrights();
}

$code = $permissions->denialCode($testUser);
$hasRight = method_exists($testUser, 'hasRight') ? $testUser->hasRight('emmcp', 'sqlquery', 'read') : false;
print "  info user is admin=".((int) $testUser->admin).", hasRight(emmcp/sqlquery/read)=".((int) $hasRight)."\n";

if ($code === null) {
	check('access granted', true);

	$capability = new EmmcpSqlCapability($db, $conf, $testUser, $auditSource);
	$names = listToolNames($capability);
	check('dolibarr_sql_query is PRESENT', in_array('dolibarr_sql_query', $names, true));
	check('dolibarr_sql_schema is PRESENT', in_array('dolibarr_sql_schema', $names, true));
	check('other tools still exposed', in_array('dolibarr_list', $names, true));

	print "\n== Tool execution ==\n";
	$tools = new \DolibarrMcp\Tools\Gated\SqlTools($capability);

	$out = json_decode($tools->queryDatabase('SELECT rowid, nom FROM '.MAIN_DB_PREFIX.'societe'), true);
	check('legitimate query succeeds', !empty($out['success']), json_encode($out['code'] ?? null));

	$out = json_decode($tools->queryDatabase('UPDATE '.MAIN_DB_PREFIX.'societe SET nom = "x"'), true);
	check('UPDATE refused with stable code', ($out['code'] ?? '') === 'SQL_NOT_READ_ONLY', json_encode($out['code'] ?? null));

	$out = json_decode($tools->queryDatabase('SELECT api_key FROM '.MAIN_DB_PREFIX.'user'), true);
	check('credential column refused', ($out['code'] ?? '') === 'SQL_FORBIDDEN_COLUMN', json_encode($out['code'] ?? null));

	$out = json_decode($tools->queryDatabase('SELECT * FROM '.MAIN_DB_PREFIX.'user'), true);
	check('SELECT * refused', ($out['code'] ?? '') === 'SQL_STAR_NOT_ALLOWED', json_encode($out['code'] ?? null));

	$out = json_decode($tools->queryDatabase('SELECT token_hash FROM '.MAIN_DB_PREFIX.'emmcp_oauth_token'), true);
	check('OAuth token table refused', ($out['code'] ?? '') === 'SQL_FORBIDDEN_TABLE', json_encode($out['code'] ?? null));

	// The bypasses found in review, exercised end to end through the real tool
	// rather than only in the unit suite.
	$bypasses = array(
		'cross-database read'      => array('SELECT rowid FROM mysql.user', 'SQL_QUALIFIED_TABLE'),
		'own database qualified'   => array('SELECT rowid FROM '.$db->database_name.'.'.MAIN_DB_PREFIX.'societe', 'SQL_QUALIFIED_TABLE'),
		'star on ordinary table'   => array('SELECT * FROM '.MAIN_DB_PREFIX.'facture', 'SQL_STAR_NOT_ALLOWED'),
		'optimizer hint'           => array('SELECT /*+ MAX_EXECUTION_TIME(0) */ rowid FROM '.MAIN_DB_PREFIX.'societe', 'SQL_OPTIMIZER_HINT'),
		'mariadb comment'          => array('SELECT 1 /*M! , 2 */', 'SQL_EXECUTABLE_COMMENT'),
		'lock in share mode'       => array('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe LOCK IN SHARE MODE', 'SQL_NOT_READ_ONLY'),
		'for share'                => array('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe FOR SHARE', 'SQL_NOT_READ_ONLY'),
		'pattern-matched column'   => array('SELECT smtp_password FROM '.MAIN_DB_PREFIX.'societe', 'SQL_FORBIDDEN_COLUMN'),
	);
	foreach ($bypasses as $label => $case) {
		$out = json_decode($tools->queryDatabase($case[0]), true);
		check('bypass closed: '.$label, ($out['code'] ?? '') === $case[1], json_encode($out['code'] ?? null));
	}

	// COUNT(*) must survive the star ban, and still actually run.
	$out = json_decode($tools->queryDatabase('SELECT COUNT(*) AS n FROM '.MAIN_DB_PREFIX.'societe'), true);
	check('COUNT(*) still works', !empty($out['success']), json_encode($out['code'] ?? null));

	$out = json_decode($tools->describeDatabaseSchema(MAIN_DB_PREFIX.'facture'), true);
	check('schema tool returns tables', !empty($out['success']) && !empty($out['tables']));

	print "\n== Audit trail ==\n";
	$resql = $db->query("SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."emmcp_sql_audit WHERE source = '".$db->escape($auditSource)."'");
	$obj = $resql ? $db->fetch_object($resql) : null;
	check('successful queries were recorded', $obj && (int) $obj->n > 0, $obj ? $obj->n.' rows' : 'none');

	// Refusals never reach the database, so they are the entries an
	// administrator investigating an incident actually needs.
	$resql = $db->query(
		"SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."emmcp_sql_audit"
		." WHERE operation = 'query' AND success = 0 AND error_code = 'SQL_STAR_NOT_ALLOWED'"
	);
	$obj = $resql ? $db->fetch_object($resql) : null;
	check('refused queries were recorded', $obj && (int) $obj->n > 0, $obj ? $obj->n.' rows' : 'none');

	$resql = $db->query("SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."emmcp_sql_audit WHERE source = '".$db->escape($auditSource)."' AND operation = 'schema'");
	$obj = $resql ? $db->fetch_object($resql) : null;
	check('schema introspections were recorded', $obj && (int) $obj->n > 0, $obj ? $obj->n.' rows' : 'none');

	$resql = $db->query("SELECT sql_text, sql_hash FROM ".MAIN_DB_PREFIX."emmcp_sql_audit WHERE source = '".$db->escape($auditSource)."' ORDER BY rowid DESC LIMIT 1");
	$obj = $resql ? $db->fetch_object($resql) : null;
	check('audit stores a hash', $obj && strlen((string) $obj->sql_hash) === 64);
	check('audit stores no result data', $obj && stripos((string) $obj->sql_text, 'ACME') === false);
} else {
	print "  info access still refused (code=".$code."). Grant the Dolibarr right emmcp->sqlquery->read to user 1 to exercise execution.\n";
	check('denial code is the permission one', $code === 'SQL_PERMISSION_DENIED', $code);
}

print "\n== Restoring initial state ==\n";
emmcpFlowCleanup();

// Everything the run touched must be back as it was, so the script can be
// replayed indefinitely without accumulating state — including audit rows,
// which would otherwise make "the trail is empty" impossible to assert later.
$state = emmcpFlowInspectState();

check('SQL access is disabled again', (int) $state['flag'] === (int) $initialFlag, (string) $state['flag']);
check('flag constant restored to its initial presence', $state['flag_row_exists'] === $initialFlagSet, var_export($state['flag_row_exists'], true));
check('no opt-in left behind', $state['optins'] === 0, (string) $state['optins']);
check('temporary right revoked', $state['rights'] === 0, (string) $state['rights']);
check('test account removed', $state['dbusers'] === 0, (string) $state['dbusers']);
check('audit rows created by this run were purged', $state['audit_after_marker'] === 0, (string) $state['audit_after_marker']);
check('credential constants restored', $state['dbuser_const'] === $initialDbUser, $state['dbuser_const']);

print "\n".($failures === 0 ? "ALL CHECKS PASSED\n" : $failures." CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
