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
 * \file    oauth.php
 * \ingroup emmcp
 * \brief   OAuth 2.1 authorization server front controller for the MCP endpoint.
 *
 * Routes (via PATH_INFO, e.g. /custom/emmcp/oauth.php/token):
 *   GET  /.well-known/openid-configuration        AS metadata (RFC 8414 / OIDC flavor)
 *   GET  /.well-known/oauth-authorization-server  AS metadata (RFC 8414)
 *   GET  /.well-known/oauth-protected-resource    Protected Resource Metadata (RFC 9728)
 *   POST /register                                Dynamic Client Registration (RFC 7591)
 *   GET  /authorize                               Authorization endpoint (Dolibarr login + consent)
 *   POST /authorize                               Consent form submission
 *   POST /token                                   Token endpoint (code exchange + refresh, PKCE S256)
 *
 * Discovery note: the issuer is this file's URL (it has a path component),
 * so per the MCP authorization spec clients fall back to the path-appended
 * form {issuer}/.well-known/openid-configuration — which lands here through
 * PATH_INFO without requiring anything at the domain root.
 */

// Resolve the route before main.inc.php: everything except /authorize runs
// machine-to-machine (no session, no CSRF token, no login redirect).
$emmcp_route = '';
if (!empty($_SERVER['PATH_INFO'])) {
	$emmcp_route = $_SERVER['PATH_INFO'];
} elseif (!empty($_GET['route'])) {
	$emmcp_route = '/'.ltrim((string) $_GET['route'], '/');
}
$emmcp_route = rtrim($emmcp_route, '/');

if ($emmcp_route !== '/authorize') {
	if (!defined('NOLOGIN')) {
		define('NOLOGIN', '1');
	}
	if (!defined('NOCSRFCHECK')) {
		define('NOCSRFCHECK', '1');
	}
	if (!defined('NOTOKENRENEWAL')) {
		define('NOTOKENRENEWAL', '1');
	}
	if (!defined('NOSESSION')) {
		define('NOSESSION', '1');
	}
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
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
	die('{"error":"server_error","error_description":"Main include failed"}');
}

dol_include_once('/emmcp/lib/emmcp.lib.php');
dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
if (emmcp_mcp_oauth_autoload() === null) {
	http_response_code(500);
	die('{"error":"server_error","error_description":"dolibarr-mcp-oauth library not found"}');
}

if (!isModEnabled('emmcp')) {
	emmcp_oauth_json(array('error' => 'server_error', 'error_description' => 'Module emMCP not enabled'), 503);
}

$issuer = emmcpPublicUrl('/emmcp/oauth.php');
$mcpUrl = emmcpPublicUrl('/emmcp/mcp.php');
$config = new \DolibarrMcpOAuth\ExposureConfig('emmcp', 'emcp_', 'EMMCP');
$oauthServer = new \DolibarrMcpOAuth\OAuthServer($db, $config);
$oauthRouter = new \DolibarrMcpOAuth\OAuthRouter($oauthServer, $config, $issuer, $mcpUrl);

/**
 * Send a JSON response and exit.
 *
 * @param array $payload  Data
 * @param int   $httpCode HTTP status
 * @return never
 */
function emmcp_oauth_json($payload, $httpCode = 200)
{
	top_httphead('application/json');
	http_response_code($httpCode);
	header('Cache-Control: no-store');
	header('Pragma: no-cache');
	header('Access-Control-Allow-Origin: *');
	print json_encode($payload, JSON_UNESCAPED_SLASHES);
	exit;
}

// CORS preflight (browser-based MCP clients hit token/register cross-origin)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type, Authorization, mcp-protocol-version');
	http_response_code(204);
	exit;
}

switch ($emmcp_route) {
	// --- Discovery ---------------------------------------------------------

	case '/.well-known/openid-configuration':
	case '/.well-known/oauth-authorization-server':
		emmcp_oauth_json($oauthRouter->metadataAuthorizationServer());
		// no break (emmcp_oauth_json exits)

	case '/.well-known/oauth-protected-resource':
		emmcp_oauth_json($oauthRouter->metadataProtectedResource());
		// no break

	// --- Dynamic Client Registration (RFC 7591) -----------------------------

	case '/register':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			emmcp_oauth_json(array('error' => 'invalid_request', 'error_description' => 'POST required'), 405);
		}
		$body = json_decode((string) file_get_contents('php://input'), true);
		if (!is_array($body)) {
			emmcp_oauth_json(array('error' => 'invalid_client_metadata', 'error_description' => 'Invalid JSON body'), 400);
		}
		$r = $oauthRouter->handleRegister($body);
		emmcp_oauth_json($r['payload'], $r['httpCode']);
		// no break

	// --- Token endpoint ------------------------------------------------------

	case '/token':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			emmcp_oauth_json(array('error' => 'invalid_request', 'error_description' => 'POST required'), 405);
		}
		$getPost = fn(string $name, $default) => GETPOST($name, 'alphanohtml') ?: $default;
		$r = $oauthRouter->handleToken($getPost, $_SERVER);
		emmcp_oauth_json($r['payload'], $r['httpCode']);
		// no break

	// --- Authorization endpoint (login + consent) ----------------------------

	case '/authorize':
		// From here on, $user is a logged-in Dolibarr user (main.inc.php
		// displayed the login form first if needed).

		$params = array(
			'client_id' => (string) GETPOST('client_id', 'alphanohtml'),
			'redirect_uri' => (string) GETPOST('redirect_uri', 'alphanohtml'),
			'state' => (string) GETPOST('state', 'alphanohtml'),
			'scope' => (string) GETPOST('scope', 'alphanohtml'),
			'resource' => (string) GETPOST('resource', 'alphanohtml'),
			'response_type' => (string) GETPOST('response_type', 'alphanohtml'),
			'code_challenge' => (string) GETPOST('code_challenge', 'alphanohtml'),
			'code_challenge_method' => (string) GETPOST('code_challenge_method', 'alphanohtml'),
		);

		$decision = $oauthRouter->validateAuthorizeRequest($params);

		// Never redirect to an unvalidated URI (open redirect protection)
		if (!$decision->valid && $decision->redirectUri === null) {
			llxHeader('', 'emMCP OAuth');
			print '<div class="error">Invalid OAuth request: unknown client_id or unregistered redirect_uri.</div>';
			llxFooter();
			exit;
		}

		if (!$decision->valid) {
			$redirectParams = array('error' => $decision->error);
			if ($decision->errorDescription !== null) {
				$redirectParams['error_description'] = $decision->errorDescription;
			}
			$redirectParams['state'] = $decision->state;
			header('Location: '.\DolibarrMcpOAuth\Support\UrlHelper::buildRedirect($decision->redirectUri, $redirectParams), true, 302);
			exit;
		}

		$client = $decision->client;
		$redirectUri = $decision->redirectUri;
		$state = $decision->state;
		$scope = $decision->scope;
		$resource = $decision->resource;
		$codeChallenge = $decision->codeChallenge;

		$action = GETPOST('action', 'aZ09');

		if ($action === 'consent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			// CSRF is enforced by main.inc.php (token checked on POST)
			if (GETPOST('decision', 'aZ09') === 'accept') {
				$code = $oauthServer->createAuthorizationCode($client, (int) $user->id, $redirectUri, $codeChallenge, $scope, $resource);
				if ($code === null) {
					header('Location: '.\DolibarrMcpOAuth\Support\UrlHelper::buildRedirect($redirectUri, array('error' => 'server_error', 'state' => $state)), true, 302);
					exit;
				}
				dol_syslog('[EMMCP] OAuth consent granted by user '.$user->login.' to client '.$client->client_id, LOG_INFO);
				header('Location: '.\DolibarrMcpOAuth\Support\UrlHelper::buildRedirect($redirectUri, array('code' => $code, 'state' => $state)), true, 302);
				exit;
			}
			dol_syslog('[EMMCP] OAuth consent denied by user '.$user->login.' to client '.$client->client_id, LOG_INFO);
			header('Location: '.\DolibarrMcpOAuth\Support\UrlHelper::buildRedirect($redirectUri, array('error' => 'access_denied', 'state' => $state)), true, 302);
			exit;
		}

		// Consent screen
		$langs->load('emmcp@emmcp');
		llxHeader('', $langs->trans('EmmcpOAuthConsentTitle'));

		$clientLabel = !empty($client->client_name) ? $client->client_name : $client->client_id;

		print '<div class="center" style="max-width:600px;margin:40px auto;">';
		print load_fiche_titre($langs->trans('EmmcpOAuthConsentTitle'), '', 'lock');
		print '<div class="info" style="text-align:left;">';
		print $langs->trans('EmmcpOAuthConsentIntro', '<strong>'.dol_escape_htmltag($clientLabel).'</strong>', '<strong>'.dol_escape_htmltag($user->login).'</strong>');
		print '</div>';
		print '<div style="text-align:left;margin:16px 0;">';
		print '<ul>';
		print '<li>'.$langs->trans('EmmcpOAuthConsentScope1').'</li>';
		print '<li>'.$langs->trans('EmmcpOAuthConsentScope2').'</li>';
		print '</ul>';
		print '</div>';

		print '<form method="POST" action="'.dol_escape_htmltag($issuer.'/authorize').'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="consent">';
		foreach ($params as $k => $v) {
			print '<input type="hidden" name="'.$k.'" value="'.dol_escape_htmltag($v).'">';
		}
		print '<button type="submit" name="decision" value="accept" class="button buttongen marginrightonly">'.$langs->trans('EmmcpOAuthAccept').'</button>';
		print '<button type="submit" name="decision" value="deny" class="button buttongen button-cancel">'.$langs->trans('EmmcpOAuthDeny').'</button>';
		print '</form>';
		print '</div>';

		llxFooter();
		exit;
		// no break

	default:
		emmcp_oauth_json(array('error' => 'invalid_request', 'error_description' => 'Unknown route: '.$emmcp_route), 404);
}
