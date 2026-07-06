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

dol_include_once('/emmcp/class/emmcpoauthserver.class.php');
dol_include_once('/emmcp/lib/emmcp.lib.php');

if (!isModEnabled('emmcp')) {
	emmcp_oauth_json(array('error' => 'server_error', 'error_description' => 'Module emMCP not enabled'), 503);
}

$issuer = emmcpPublicUrl('/emmcp/oauth.php');
$mcpUrl = emmcpPublicUrl('/emmcp/mcp.php');
$oauthServer = new EmmcpOAuthServer($db);

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
		emmcp_oauth_json(array(
			'issuer' => $issuer,
			'authorization_endpoint' => $issuer.'/authorize',
			'token_endpoint' => $issuer.'/token',
			'registration_endpoint' => $issuer.'/register',
			'response_types_supported' => array('code'),
			'grant_types_supported' => array('authorization_code', 'refresh_token'),
			'code_challenge_methods_supported' => array('S256'),
			'token_endpoint_auth_methods_supported' => array('none', 'client_secret_basic', 'client_secret_post'),
			'scopes_supported' => array('dolibarr'),
			'service_documentation' => 'https://github.com/momodemo333/dolibarr-mcp-server',
		));
		// no break (emmcp_oauth_json exits)

	case '/.well-known/oauth-protected-resource':
		emmcp_oauth_json(array(
			'resource' => $mcpUrl,
			'authorization_servers' => array($issuer),
			'scopes_supported' => array('dolibarr'),
			'bearer_methods_supported' => array('header'),
			'resource_name' => 'Dolibarr MCP Server',
		));
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
		$response = $oauthServer->registerClient($body);
		if ($response === null) {
			emmcp_oauth_json(array('error' => 'invalid_client_metadata', 'error_description' => $oauthServer->error), 400);
		}
		emmcp_oauth_json($response, 201);
		// no break

	// --- Token endpoint ------------------------------------------------------

	case '/token':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			emmcp_oauth_json(array('error' => 'invalid_request', 'error_description' => 'POST required'), 405);
		}

		$grantType = (string) GETPOST('grant_type', 'alphanohtml');
		$clientId = (string) GETPOST('client_id', 'alphanohtml');
		$clientSecret = (string) GETPOST('client_secret', 'alphanohtml');

		// client_secret_basic support
		if (empty($clientId) && !empty($_SERVER['PHP_AUTH_USER'])) {
			$clientId = urldecode($_SERVER['PHP_AUTH_USER']);
			$clientSecret = urldecode((string) ($_SERVER['PHP_AUTH_PW'] ?? ''));
		}

		$client = $oauthServer->getClient($clientId);
		if (!$client) {
			emmcp_oauth_json(array('error' => 'invalid_client'), 401);
		}
		if (!$oauthServer->authenticateClient($client, $clientSecret)) {
			emmcp_oauth_json(array('error' => 'invalid_client'), 401);
		}

		$oauthServer->purgeExpired();

		if ($grantType === 'authorization_code') {
			$tokens = $oauthServer->exchangeAuthorizationCode(
				$client,
				(string) GETPOST('code', 'alphanohtml'),
				(string) GETPOST('redirect_uri', 'alphanohtml'),
				(string) GETPOST('code_verifier', 'alphanohtml')
			);
		} elseif ($grantType === 'refresh_token') {
			$tokens = $oauthServer->refreshTokens($client, (string) GETPOST('refresh_token', 'alphanohtml'));
		} else {
			emmcp_oauth_json(array('error' => 'unsupported_grant_type'), 400);
		}

		if ($tokens === null) {
			emmcp_oauth_json(array('error' => $oauthServer->error ?: 'invalid_grant'), 400);
		}
		dol_syslog('[EMMCP] OAuth tokens issued to client '.$client->client_id.' (grant: '.$grantType.')', LOG_INFO);
		emmcp_oauth_json($tokens);
		// no break

	// --- Authorization endpoint (login + consent) ----------------------------

	case '/authorize':
		// From here on, $user is a logged-in Dolibarr user (main.inc.php
		// displayed the login form first if needed).

		$clientId = (string) GETPOST('client_id', 'alphanohtml');
		$redirectUri = (string) GETPOST('redirect_uri', 'alphanohtml');
		$state = (string) GETPOST('state', 'alphanohtml');
		$scope = (string) GETPOST('scope', 'alphanohtml');
		$resource = (string) GETPOST('resource', 'alphanohtml');
		$responseType = (string) GETPOST('response_type', 'alphanohtml');
		$codeChallenge = (string) GETPOST('code_challenge', 'alphanohtml');
		$codeChallengeMethod = (string) GETPOST('code_challenge_method', 'alphanohtml');

		$client = $oauthServer->getClient($clientId);

		// Never redirect to an unvalidated URI (open redirect protection)
		if (!$client || !$oauthServer->isRegisteredRedirectUri($client, $redirectUri)) {
			llxHeader('', 'emMCP OAuth');
			print '<div class="error">Invalid OAuth request: unknown client_id or unregistered redirect_uri.</div>';
			llxFooter();
			exit;
		}

		/**
		 * Redirect back to the client with query parameters.
		 *
		 * @param string $uri    Validated redirect URI
		 * @param array  $params Query parameters to append
		 * @return never
		 */
		function emmcp_oauth_redirect($uri, $params)
		{
			$uri .= (strpos($uri, '?') === false ? '?' : '&').http_build_query($params);
			header('Location: '.$uri, true, 302);
			exit;
		}

		if ($responseType !== 'code') {
			emmcp_oauth_redirect($redirectUri, array('error' => 'unsupported_response_type', 'state' => $state));
		}
		if (empty($codeChallenge) || strtoupper($codeChallengeMethod) !== 'S256') {
			emmcp_oauth_redirect($redirectUri, array('error' => 'invalid_request', 'error_description' => 'PKCE S256 required', 'state' => $state));
		}

		$action = GETPOST('action', 'aZ09');

		if ($action === 'consent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			// CSRF is enforced by main.inc.php (token checked on POST)
			if (GETPOST('decision', 'aZ09') === 'accept') {
				$code = $oauthServer->createAuthorizationCode($client, (int) $user->id, $redirectUri, $codeChallenge, $scope, $resource);
				if ($code === null) {
					emmcp_oauth_redirect($redirectUri, array('error' => 'server_error', 'state' => $state));
				}
				dol_syslog('[EMMCP] OAuth consent granted by user '.$user->login.' to client '.$client->client_id, LOG_INFO);
				emmcp_oauth_redirect($redirectUri, array('code' => $code, 'state' => $state));
			}
			dol_syslog('[EMMCP] OAuth consent denied by user '.$user->login.' to client '.$client->client_id, LOG_INFO);
			emmcp_oauth_redirect($redirectUri, array('error' => 'access_denied', 'state' => $state));
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
		foreach (array('client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => $state, 'scope' => $scope, 'resource' => $resource, 'response_type' => $responseType, 'code_challenge' => $codeChallenge, 'code_challenge_method' => $codeChallengeMethod) as $k => $v) {
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
