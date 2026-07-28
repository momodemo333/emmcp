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
 * \file    mcp.php
 * \ingroup emmcp
 * \brief   MCP Streamable HTTP endpoint (per-request, PHP-FPM friendly).
 *
 * One HTTP request = one JSON-RPC MCP message, handled by the official
 * MCP PHP SDK (no daemon, no event loop). Sessions are persisted on disk
 * between requests (Mcp-Session-Id header).
 *
 * Authentication: the caller provides a Dolibarr user API key via
 *   - "Authorization: Bearer <key>"  (standard for MCP clients), or
 *   - "DOLAPIKEY: <key>" header      (Dolibarr REST API convention), or
 *   - "?DOLAPIKEY=<key>" query param (fallback for clients without headers).
 * The key is validated against llx_user, then forwarded to the MCP tools
 * which act through the Dolibarr REST API with that user's permissions.
 */

// Same execution context as the native REST API (htdocs/api/index.php)
if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOREQUIRETRAN')) {
	define('NOREQUIRETRAN', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	die('{"error":"Main include failed"}');
}

/**
 * Emit a JSON-RPC style error and exit.
 *
 * On 401, a WWW-Authenticate header advertises the Protected Resource
 * Metadata URL so OAuth-capable MCP clients (claude.ai connectors…) can
 * run the discovery + authorization flow (RFC 9728 §5.1).
 *
 * @param int    $httpCode HTTP status code
 * @param int    $rpcCode  JSON-RPC error code
 * @param string $message  Error message
 * @return never
 */
function emmcp_error($httpCode, $rpcCode, $message)
{
	if ($httpCode == 401) {
		dol_include_once('/emmcp/lib/emmcp.lib.php');
		dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
		emmcp_mcp_oauth_autoload();
		$prm = emmcpPublicUrl('/emmcp/oauth.php').'/.well-known/oauth-protected-resource';
		$endpoint = new \DolibarrMcpOAuth\HttpEndpoint($GLOBALS['db'], new \DolibarrMcpOAuth\ExposureConfig('emmcp', 'emcp_', 'EMMCP'));
		header('WWW-Authenticate: '.$endpoint->wwwAuthenticateHeader($prm));
	}
	http_response_code($httpCode);
	header('Content-Type: application/json');
	print json_encode(array(
		'jsonrpc' => '2.0',
		'id' => null,
		'error' => array('code' => $rpcCode, 'message' => $message),
	));
	exit;
}

/**
 * Build the read-only SQL capability, or null when the caller may not use it.
 *
 * Returning null is not a soft failure: the MCP runtime then excludes the SQL
 * tools from attribute discovery entirely, so they never appear in tools/list.
 * Deny-by-default is structural rather than a runtime check to remember.
 *
 * Must be called only after the dolibarr-mcp-server autoloader is required —
 * EmmcpSqlCapability implements one of that package's interfaces.
 *
 * @param  DoliDB $db     Database handler
 * @param  Conf   $conf   Dolibarr configuration
 * @param  string $apiKey Caller's validated API key
 * @return EmmcpSqlCapability|null
 */
function emmcpBuildSqlCapability($db, $conf, $apiKey)
{
	dol_include_once('/emmcp/class/emmcpsqlpermissions.class.php');

	$permissions = new EmmcpSqlPermissions($db, $conf);

	// Cheapest gate first: skip loading a User object on the overwhelmingly
	// common path where the feature is simply off.
	if (!$permissions->isGloballyEnabled()) {
		return null;
	}

	$userId = emmcpResolveUserIdFromApiKey($db, $apiKey);
	if ($userId <= 0) {
		return null;
	}

	$mcpUser = new User($db);
	if ($mcpUser->fetch($userId) <= 0) {
		return null;
	}
	$mcpUser->getrights();

	$denial = $permissions->denialCode($mcpUser);
	if ($denial !== null) {
		dol_syslog('[EMMCP] SQL access refused for user '.((int) $userId).': '.$denial, LOG_INFO);

		return null;
	}

	dol_include_once('/emmcp/class/emmcpsqlgateway.class.php');
	dol_include_once('/emmcp/class/emmcpsqlaudit.class.php');
	dol_include_once('/emmcp/class/emmcpsqlcapability.class.php');

	return new EmmcpSqlCapability($db, $conf, $mcpUser, 'mcp');
}

/**
 * Resolve the Dolibarr user behind an API key.
 *
 * The shared OAuth library validates the key but its AuthResult only carries
 * the key itself, so the lookup is repeated here rather than changing that
 * library's contract and re-releasing it into two modules.
 *
 * @param  DoliDB $db     Database handler
 * @param  string $apiKey Already-validated API key
 * @return int            User rowid, or 0
 */
function emmcpResolveUserIdFromApiKey($db, $apiKey)
{
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."user";
	$sql .= " WHERE api_key = '".$db->escape($apiKey)."'";
	$sql .= " OR api_key = '".$db->escape(dolEncrypt($apiKey, '', '', 'dolibarr'))."'";

	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) !== 1) {
		return 0;
	}
	$obj = $db->fetch_object($resql);

	return $obj ? (int) $obj->rowid : 0;
}

if (!isModEnabled('emmcp')) {
	emmcp_error(403, -32000, 'Module emMCP not enabled');
}

// Dolibarr does not replay init() when a customer only uploads new files, and
// this module has no business UI to hang a hook on. Its entry points carry the
// migration instead, so the schema is current on the first call after upgrade.
dol_include_once('/emmcp/class/emmcpmigrations.class.php');
if (!EmmcpMigrations::runIfNeeded($db)) {
	// Continuing would run the module against a schema it does not expect:
	// missing columns surface as opaque tool errors, and an audit table that
	// failed to migrate means calls would go unrecorded. Refuse instead, and
	// say nothing specific to the client — the detail is in the server log.
	dol_syslog('[EMMCP] Database migration failed; refusing MCP requests', LOG_ERR);
	emmcp_error(503, -32003, 'Service temporarily unavailable: the module database schema is not up to date.');
}

// --- Authentication -------------------------------------------------------
dol_include_once('/emmcp/lib/emmcp.lib.php');
dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
if (emmcp_mcp_oauth_autoload() === null) {
	emmcp_error(500, -32002, 'dolibarr-mcp-oauth library not found');
}

$mcpEndpoint = new \DolibarrMcpOAuth\HttpEndpoint($db, new \DolibarrMcpOAuth\ExposureConfig('emmcp', 'emcp_', 'EMMCP'));
$getPost = fn(string $name, $default) => GETPOST($name, 'alphanohtml') ?: $default;
$auth = $mcpEndpoint->resolveApiKey($_SERVER, $getPost);
if ($auth->apiKey === null) {
	emmcp_error($auth->httpCode, -32001, $auth->error);
}
$apiKey = $auth->apiKey;

// --- Load the Dolibarr MCP server package ----------------------------------

// POC: reuse the MCP server embedded in the Dalfred module. A standalone
// release of emMCP will bundle its own copy under /emmcp/vendor/.
$autoload = \DolibarrMcpOAuth\Support\PackageLocator::findAutoloader(array(
	dol_buildpath('/emmcp/vendor/dolibarr-mcp-server/vendor/autoload.php', 0),
	dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0),
));
if (!$autoload) {
	emmcp_error(500, -32002, 'Dolibarr MCP server package not found (install the Dalfred module or bundle the package)');
}
require_once $autoload;

// --- Handle the MCP request ------------------------------------------------

// The MCP tools act through the local Dolibarr REST API with the caller's key.
// The config is request-scoped (no putenv: FPM workers are reused across
// requests and users, process-global state must not carry credentials).
$config = new DolibarrMcp\Config\ConnectionConfig(DOL_MAIN_URL_ROOT, $apiKey);

// Persist MCP sessions between PHP-FPM requests
$sessionDir = DOL_DATA_ROOT.'/emmcp/sessions';

// --- Optional read-only SQL capability -------------------------------------
//
// Every other tool acts through the REST API and inherits the caller's
// Dolibarr permissions for free. Raw SQL inherits nothing, so it is authorised
// explicitly here — and only here. When the capability stays null the SQL tools
// are not even discovered, so they never reach tools/list.
$sqlCapability = emmcpBuildSqlCapability($db, $conf, $apiKey);

try {
	$response = DolibarrMcp\Bootstrap::handleHttpRequest(null, $sessionDir, $config, $sqlCapability);
	DolibarrMcp\Bootstrap::emit($response);
} catch (Throwable $e) {
	// The detail stays on the server: exception messages here routinely carry
	// file paths, database host names and account names, and this response goes
	// to a remote MCP client.
	dol_syslog(
		'[EMMCP] ERROR '.get_class($e).': '.$e->getMessage()
			.' at '.$e->getFile().':'.$e->getLine(),
		LOG_ERR
	);
	emmcp_error(500, -32603, 'Internal MCP server error. See the Dolibarr log for details.');
}

$db->close();
