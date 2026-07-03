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
 * \ingroup dolimcp
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
 * @param int    $httpCode HTTP status code
 * @param int    $rpcCode  JSON-RPC error code
 * @param string $message  Error message
 * @return never
 */
function dolimcp_error($httpCode, $rpcCode, $message)
{
	http_response_code($httpCode);
	header('Content-Type: application/json');
	print json_encode(array(
		'jsonrpc' => '2.0',
		'id' => null,
		'error' => array('code' => $rpcCode, 'message' => $message),
	));
	exit;
}

if (!isModEnabled('dolimcp')) {
	dolimcp_error(403, -32000, 'Module DoliMCP not enabled');
}

// --- Authentication -------------------------------------------------------

// The Authorization header may be stripped by Apache/FPM: check the usual
// fallbacks (REDIRECT_ prefix, getallheaders) before giving up.
$authHeader = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
	$authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
	$authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif (function_exists('getallheaders')) {
	foreach (getallheaders() as $name => $value) {
		if (strcasecmp($name, 'Authorization') === 0) {
			$authHeader = $value;
			break;
		}
	}
}

$apiKey = '';
if ($authHeader && preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
	$apiKey = $m[1];
}
if (empty($apiKey) && !empty($_SERVER['HTTP_DOLAPIKEY'])) {
	$apiKey = $_SERVER['HTTP_DOLAPIKEY'];
}
if (empty($apiKey)) {
	$apiKey = GETPOST('DOLAPIKEY', 'alphanohtml');
}
$apiKey = dol_string_nounprintableascii($apiKey);

if (empty($apiKey) || preg_match('/^dolcrypt:/i', $apiKey)) {
	dolimcp_error(401, -32001, 'Missing or invalid API key. Provide it with "Authorization: Bearer <key>" or a "DOLAPIKEY" header.');
}

// Validate against llx_user, same lookup as the native REST API
// (api_key may be stored plain or encrypted with dolEncrypt)
$sql = "SELECT u.rowid, u.login, u.statut as status";
$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
$sql .= " WHERE u.api_key = '".$db->escape($apiKey)."'";
$sql .= " OR u.api_key = '".$db->escape(dolEncrypt($apiKey, '', '', 'dolibarr'))."'";

$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) != 1) {
	dol_syslog('[DOLIMCP] Authentication KO: no unique user for provided api key', LOG_NOTICE);
	sleep(1); // Anti brute force, same delay as the native REST API
	dolimcp_error(401, -32001, 'Error user not valid (not found with api key or bad status)');
}
$obj = $db->fetch_object($resql);
if (empty($obj->status)) {
	dol_syslog('[DOLIMCP] Authentication KO: user '.$obj->login.' is disabled', LOG_NOTICE);
	sleep(1);
	dolimcp_error(401, -32001, 'Error user not valid (not found with api key or bad status)');
}

dol_syslog('[DOLIMCP] MCP request authenticated for user '.$obj->login, LOG_DEBUG);

// --- Load the Dolibarr MCP server package ----------------------------------

// POC: reuse the MCP server embedded in the Dalfred module. A standalone
// release of DoliMCP will bundle its own copy under /dolimcp/vendor/.
$autoloadCandidates = array(
	dol_buildpath('/dolimcp/vendor/dolibarr-mcp-server/vendor/autoload.php', 0),
	dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0),
);
$autoload = null;
foreach ($autoloadCandidates as $candidate) {
	if ($candidate && file_exists($candidate)) {
		$autoload = $candidate;
		break;
	}
}
if (!$autoload) {
	dolimcp_error(500, -32002, 'Dolibarr MCP server package not found (install the Dalfred module or bundle the package)');
}
require_once $autoload;

// --- Handle the MCP request ------------------------------------------------

// The MCP tools act through the local Dolibarr REST API with the caller's key.
// The config is request-scoped (no putenv: FPM workers are reused across
// requests and users, process-global state must not carry credentials).
$config = new DolibarrMcp\Config\ConnectionConfig(DOL_MAIN_URL_ROOT, $apiKey);

// Persist MCP sessions between PHP-FPM requests
$sessionDir = DOL_DATA_ROOT.'/dolimcp/sessions';

try {
	$response = DolibarrMcp\Bootstrap::handleHttpRequest(null, $sessionDir, $config);
	DolibarrMcp\Bootstrap::emit($response);
} catch (Throwable $e) {
	dol_syslog('[DOLIMCP] ERROR '.$e->getMessage(), LOG_ERR);
	dolimcp_error(500, -32603, 'Internal MCP server error: '.$e->getMessage());
}

$db->close();
