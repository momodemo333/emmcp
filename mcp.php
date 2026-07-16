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

if (!isModEnabled('emmcp')) {
	emmcp_error(403, -32000, 'Module emMCP not enabled');
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

try {
	$response = DolibarrMcp\Bootstrap::handleHttpRequest(null, $sessionDir, $config);
	DolibarrMcp\Bootstrap::emit($response);
} catch (Throwable $e) {
	dol_syslog('[EMMCP] ERROR '.$e->getMessage(), LOG_ERR);
	emmcp_error(500, -32603, 'Internal MCP server error: '.$e->getMessage());
}

$db->close();
