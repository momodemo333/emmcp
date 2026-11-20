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
		// Connector URL of one entity (mcp.php?entity=N): its metadata names
		// that same URL as the protected resource.
		dol_include_once('/emmcp/lib/emmcp_entity.lib.php');
		if (emmcpRequestedEntity() > 0) {
			$prm .= '?entity='.emmcpRequestedEntity();
		}
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
 * DolibarrMcpSql\SqlCapability implements one of that package's interfaces.
 *
 * @param  DoliDB $db     Database handler
 * @param  Conf   $conf   Dolibarr configuration
 * @param  string $apiKey Caller's validated API key
 * @return \DolibarrMcpSql\SqlCapability|null
 */
function emmcpBuildSqlCapability($db, $conf, $apiKey)
{
	dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
	if (emmcp_mcp_sql_autoload() === null) {
		dol_syslog('[EMMCP] dolibarr-mcp-sql library not found; SQL tools stay hidden', LOG_WARNING);

		return null;
	}

	$config = emmcp_sql_config();

	// Raw SQL reads every entity's rows. The library refuses it under
	// Multicompany through isModEnabled('multicompany'), which reads the
	// configuration of the current entity: once mcp.php has switched to the
	// entity of the access, that flag may be missing there. Same rule, read
	// install-wide.
	dol_include_once('/emmcp/lib/emmcp_entity.lib.php');
	if (emmcpMulticompanyActive($db) && getDolGlobalInt($config->const('ALLOW_MULTIENTITY')) !== 1) {
		return null;
	}

	$permissions = new \DolibarrMcpSql\SqlPermissions($db, $conf, $config);

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

	return new \DolibarrMcpSql\SqlCapability($db, $conf, $mcpUser, $config, 'mcp');
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

dol_include_once('/emmcp/lib/emmcp_entity.lib.php');
$emmcpMulticompany = emmcpMulticompanyActive($db);

// Without Multicompany: entity 1, as always. With it, the request starts in
// entity 1 but works in the entity of its access (resolved after
// authentication), so only require the module somewhere for now.
if (!$emmcpMulticompany && !isModEnabled('emmcp')) {
	emmcp_error(403, -32000, 'Module emMCP not enabled');
}
if ($emmcpMulticompany && !emmcpModuleEnabledSomewhere($db)) {
	emmcp_error(403, -32000, 'Module emMCP not enabled in any entity');
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

// Self-test, before any authentication: the admin page sends a request
// carrying both "Authorization: Bearer <dummy>" and "X-Dolibarr-Probe: 1",
// and asks one thing — did the Authorization header reach PHP? It only tells
// the caller whether their own header survived, nothing else.
if (!empty($_SERVER['HTTP_X_DOLIBARR_PROBE'])) {
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	echo json_encode(array('probe' => 'authorization', 'authorization_seen' => \DolibarrMcpOAuth\HttpEndpoint::seesAuthorizationHeader($_SERVER)));
	exit;
}

dol_include_once('/emmcp/class/emmcpauthlog.class.php');
dol_include_once('/emmcp/lib/emmcp_runtime.php');

$mcpEndpoint = new \DolibarrMcpOAuth\HttpEndpoint($db, new \DolibarrMcpOAuth\ExposureConfig('emmcp', 'emcp_', 'EMMCP'));
$getPost = fn(string $name, $default) => GETPOST($name, 'alphanohtml') ?: $default;
$auth = $mcpEndpoint->resolveApiKey($_SERVER, $getPost);
if ($auth->apiKey === null) {
	// The connection log is what an administrator reads when a client says
	// "authorization failed". A request without any credential is the normal
	// first step of every OAuth connection, but right after a token was
	// issued it means the web server dropped the Authorization header.
	$presented = \DolibarrMcpOAuth\HttpEndpoint::seesAuthorizationHeader($_SERVER)
		|| !empty($_SERVER['HTTP_DOLAPIKEY']) || GETPOST('DOLAPIKEY', 'alphanohtml') !== '';
	EmmcpAuthLog::record(
		$db,
		'mcp_auth',
		false,
		$presented ? $auth->error : 'No credentials reached PHP (expected on the first request of a connection; right after a token was issued, the web server is stripping the Authorization header)'
	);
	emmcp_error($auth->httpCode, -32001, $auth->error);
}
$apiKey = $auth->apiKey;

// Multicompany: the entity this access works in. An OAuth token carries the
// entity chosen at consent; an API key uses ?entity=N or the user's main
// entity. The user's access to that entity is checked before anything runs.
$mcpEntity = null;
if ($emmcpMulticompany) {
	$entityUserId = emmcpResolveUserIdFromApiKey($db, $apiKey);
	$requestedEntity = emmcpRequestedEntity();
	$bearer = emmcpPresentedBearer($_SERVER);
	$tokenEntity = str_starts_with($bearer, 'emcp_a') ? emmcpTokenEntity($db, 'access', $bearer) : 0;
	if ($tokenEntity > 0) {
		if ($requestedEntity > 0 && $requestedEntity !== $tokenEntity) {
			EmmcpAuthLog::record($db, 'mcp_entity', false, 'This access was granted for entity '.$tokenEntity.', the URL asks for entity '.$requestedEntity, null, $entityUserId, $requestedEntity);
			emmcp_error(403, -32001, 'This access was granted for entity '.$tokenEntity.', not '.$requestedEntity.'. Use the connector URL of entity '.$tokenEntity.', or connect again choosing entity '.$requestedEntity.'.');
		}
		$mcpEntity = $tokenEntity;
	} else {
		$mcpEntity = $requestedEntity > 0 ? $requestedEntity : emmcpUserMainEntity($db, $entityUserId);
	}
	if (!emmcpUserCanUseEntity($db, $entityUserId, $mcpEntity)) {
		EmmcpAuthLog::record($db, 'mcp_entity', false, 'User has no access to entity '.$mcpEntity.' (Multicompany), or the entity is disabled', null, $entityUserId, $mcpEntity);
		emmcp_error(403, -32001, 'Access refused: this Dolibarr user has no access to entity '.$mcpEntity.' (Multicompany), or that entity is disabled.');
	}
	if (!emmcpModuleEnabledInEntity($db, $mcpEntity)) {
		EmmcpAuthLog::record($db, 'mcp_entity', false, 'Module emMCP not enabled in entity '.$mcpEntity, null, $entityUserId, $mcpEntity);
		emmcp_error(403, -32000, 'Module emMCP not enabled in entity '.$mcpEntity.'.');
	}
	emmcpSwitchEntity($db, $mcpEntity);
	dol_syslog('[EMMCP] MCP request works in entity '.$mcpEntity.' (user '.$entityUserId.')', LOG_DEBUG);
}

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

// Shared-library collisions: refuse with the culprit's name instead of dying
// on a fatal error. See lib/emmcp_runtime.php.
$runtimeDir = emmcp_runtime_package_dir($autoload);
$GLOBALS['emmcp_runtime_user'] = emmcpResolveUserIdFromApiKey($db, $apiKey);
$conflicts = emmcp_runtime_conflicts($runtimeDir, false);
emmcp_runtime_arm_fatal_handler();
if (empty($conflicts)) {
	require_once $autoload;
	$conflicts = emmcp_runtime_conflicts($runtimeDir, true);
}
if (!empty($conflicts)) {
	$conflictMessage = emmcp_runtime_conflict_message($conflicts[0]);
	dol_syslog('[EMMCP] '.$conflictMessage, LOG_ERR);
	emmcp_runtime_record_error($conflictMessage, $conflicts[0]['file']);
	EmmcpAuthLog::record($db, 'mcp_runtime', false, $conflictMessage, null, $GLOBALS['emmcp_runtime_user']);
	emmcp_runtime_disarm();
	emmcp_error(500, -32002, $conflictMessage);
}

// --- Handle the MCP request ------------------------------------------------

// The MCP tools act through the local Dolibarr REST API with the caller's key.
// The config is request-scoped (no putenv: FPM workers are reused across
// requests and users, process-global state must not carry credentials).
if ($mcpEntity !== null && !property_exists('DolibarrMcp\Config\ConnectionConfig', 'entity')) {
	// An engine older than 2.8.0 would silently ignore the entity and work in
	// the user's main entity: refuse rather than act in the wrong company.
	emmcp_runtime_disarm();
	emmcp_error(500, -32002, 'The embedded MCP server package is too old for Multicompany (2.8.0 or later required).');
}
$config = new DolibarrMcp\Config\ConnectionConfig(DOL_MAIN_URL_ROOT, $apiKey, 30, $mcpEntity);

// Persist MCP sessions between PHP-FPM requests
$sessionDir = DOL_DATA_ROOT.'/emmcp/sessions';

// --- Optional read-only SQL capability -------------------------------------
//
// Every other tool acts through the REST API and inherits the caller's
// Dolibarr permissions for free. Raw SQL inherits nothing, so it is authorised
// explicitly here — and only here. When the capability stays null the SQL tools
// are not even discovered, so they never reach tools/list.
$sqlCapability = emmcpBuildSqlCapability($db, $conf, $apiKey);

// Lets the agent ask what it is connected to instead of guessing — Dolibarr
// version, enabled modules, and whether SQL is available in this session.
$environment = emmcp_mcp_environment($sqlCapability !== null);

// --- Audit, rate limit and alerting ------------------------------------------
//
// The body is read here rather than left to the SDK because we need to know
// what is being asked BEFORE dispatching: a rate limit applied afterwards caps
// nothing. It is then handed to the transport explicitly, since php://input
// cannot be read twice.
$rawBody = file_get_contents('php://input');
$rawBody = $rawBody === false ? '' : $rawBody;

$audit = null;
$call = array('method' => 'unknown', 'tool' => null, 'arguments' => null, 'client' => null);
if (emmcp_mcp_audit_autoload() !== null) {
	$audit = new \DolibarrMcpAudit\McpAudit($db, emmcp_mcp_audit_config());
	$call = \DolibarrMcpAudit\McpAudit::describeRequest($rawBody);
}

// The user the agent acts as. Resolved once: the audit trail names a person,
// not a key, and the limit counts per person.
$mcpUserId = emmcpResolveUserIdFromApiKey($db, $apiKey);
$mcpLogin = '';
$mcpUser = null;
if ($mcpUserId > 0) {
	$tmpUser = new User($db);
	if ($tmpUser->fetch($mcpUserId) > 0) {
		$mcpLogin = (string) $tmpUser->login;
		// loadRights() only exists from Dolibarr 20; getrights() is the older name.
		if (method_exists($tmpUser, 'loadRights')) {
			$tmpUser->loadRights();
		} else {
			$tmpUser->getrights();
		}
		$mcpUser = $tmpUser;
	}
}

// Tools other modules add through the "emmcpRegisterTools" hook. They run in
// this process as the session's user, so they get the user with rights loaded.
$extensions = emmcp_mcp_extensions($db, $mcpUser, $apiKey, $mcpEntity);

// Only tool calls are capped. Refusing initialize or tools/list would break the
// client outright while stopping no data from leaving.
if ($audit !== null && $mcpUserId > 0 && $call['method'] === 'tools/call') {
	$decision = $audit->check($mcpUserId);
	if (!$decision->allowed) {
		$audit->record(
			$mcpUserId,
			$mcpLogin,
			$call['method'],
			$call['tool'],
			$call['arguments'],
			0,
			false,
			'rate limit reached',
			$call['client']
		);
		dol_syslog('[EMMCP] MCP rate limit reached for user '.$mcpLogin, LOG_WARNING);
		emmcp_error(429, -32000, $decision->message());
	}
}

$startedAt = microtime(true);

try {
	// The runtime's dependencies may be prefixed (released package) or not
	// (development checkout, Dalfred's copy): name them through the helper.
	$serverRequestClass = emmcp_runtime_class($runtimeDir, 'GuzzleHttp\Psr7\ServerRequest');
	$utilsClass = emmcp_runtime_class($runtimeDir, 'GuzzleHttp\Psr7\Utils');
	$request = $serverRequestClass::fromGlobals()
		->withBody($utilsClass::streamFor($rawBody));

	$response = DolibarrMcp\Bootstrap::handleHttpRequest($request, $sessionDir, $config, $sqlCapability, $environment, $extensions);

	if ($audit !== null && $mcpUserId > 0) {
		// A failed tool answers 200 with the error inside the payload, so the
		// body has to be read to tell a real success from a refusal. Rewound
		// afterwards: emit() streams the same body to the client.
		$payload = (string) $response->getBody();
		$response->getBody()->rewind();

		$failure = $response->getStatusCode() >= 400
			? 'HTTP '.$response->getStatusCode()
			: \DolibarrMcpAudit\McpAudit::failureReason($payload);

		$audit->record(
			$mcpUserId,
			$mcpLogin,
			$call['method'],
			$call['tool'],
			$call['arguments'],
			(int) round((microtime(true) - $startedAt) * 1000),
			$failure === null,
			$failure,
			$call['client']
		);
	}

	emmcp_runtime_disarm();
	DolibarrMcp\Bootstrap::emit($response);
} catch (Throwable $e) {
	emmcp_runtime_disarm();
	emmcp_runtime_record_error(get_class($e).': '.$e->getMessage(), $e->getFile(), $e->getLine());
	EmmcpAuthLog::record($db, 'mcp_runtime', false, get_class($e).': '.$e->getMessage(), null, $mcpUserId > 0 ? $mcpUserId : null);
	if ($audit !== null && $mcpUserId > 0) {
		$audit->record(
			$mcpUserId,
			$mcpLogin,
			$call['method'],
			$call['tool'],
			$call['arguments'],
			(int) round((microtime(true) - $startedAt) * 1000),
			false,
			substr($e->getMessage(), 0, 255),
			$call['client']
		);
	}
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
