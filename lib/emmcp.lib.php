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
 * \file    lib/emmcp.lib.php
 * \ingroup emmcp
 * \brief   Library files with common functions for emMCP
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function emmcpAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('emmcp@emmcp');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/emmcp/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/emmcp/admin/sql_access.php', 1);
	$head[$h][1] = $langs->trans('EmmcpSqlAccess');
	$head[$h][2] = 'sqlaccess';
	$h++;

	// MCP activity: the call log, the rate limit and the alert live together
	// because an administrator reading the log is exactly the person who then
	// wants to change the limit.
	$head[$h][0] = dol_buildpath('/emmcp/admin/mcp_activity.php', 1);
	$head[$h][1] = $langs->trans('EmmcpMcpActivityTab');
	$head[$h][2] = 'mcpactivity';
	$h++;

	// Accesses granted through OAuth, and the button that ends one.
	$head[$h][0] = dol_buildpath('/emmcp/admin/mcp_access.php', 1);
	$head[$h][1] = $langs->trans('EmmcpAccessTab');
	$head[$h][2] = 'mcpaccess';
	$h++;

	// What blocks a connection, in one place: checks, the connection log
	// with the reason of each failure, and a report to send to support.
	$head[$h][0] = dol_buildpath('/emmcp/admin/diagnostic.php', 1);
	$head[$h][1] = $langs->trans('EmmcpDiagTab');
	$head[$h][2] = 'diagnostic';
	$h++;

	$head[$h][0] = dol_buildpath('/emmcp/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'emmcp@emmcp');

	return $head;
}

/**
 * Render a read-only code block with a native "copy to clipboard" button.
 *
 * Uses htmlspecialchars (not dol_escape_htmltag) so real newlines in the
 * snippet are preserved inside the <pre>, and Dolibarr's
 * showValueWithClipboardCPButton() for the copy button (value carried with
 * newlines intact via its $keepn handling).
 *
 * @param string $code  The snippet to display and copy (may be multi-line)
 * @param string $label Optional heading shown above the block
 * @return string HTML
 */
function emmcpCodeBlock($code, $label = '')
{
	dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
	emmcp_mcp_oauth_autoload();

	return \DolibarrMcpOAuth\Support\UrlHelper::codeBlock($code, $label);
}

/**
 * Build a public absolute URL for a module file, forcing https when the
 * current request reached us over TLS.
 *
 * dol_buildpath(..., 2) derives the scheme from DOL_MAIN_URL_ROOT, which is
 * often http when Dolibarr sits behind a TLS-terminating reverse proxy
 * (Traefik, nginx). OAuth 2.1 / the MCP authorization spec require https for
 * every endpoint and redirect URI (localhost excepted), so we upgrade the
 * scheme based on the real request when it was encrypted.
 *
 * @param string $relpath Module-relative path, e.g. '/emmcp/oauth.php'
 * @return string Absolute URL
 */
function emmcpPublicUrl($relpath)
{
	dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
	emmcp_mcp_oauth_autoload();

	return \DolibarrMcpOAuth\Support\UrlHelper::publicUrl($relpath);
}

/**
 * Resolve the autoloader of the embedded Dolibarr MCP server package.
 *
 * @return string|null Absolute path to autoload.php, or null if not found
 */
function emmcpFindMcpAutoloader()
{
	dol_include_once('/emmcp/lib/emmcp_bootstrap.php');
	emmcp_mcp_oauth_autoload();

	return \DolibarrMcpOAuth\Support\PackageLocator::findAutoloader(array(
		dol_buildpath('/emmcp/vendor/dolibarr-mcp-server/vendor/autoload.php', 0),
		dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0),
	));
}

/**
 * Ask the MCP endpoint whether an Authorization header reaches PHP.
 *
 * The server calls its own public URL with a dummy Bearer header and the
 * probe header mcp.php answers to. Local addresses are allowed on purpose.
 *
 * @return array{status: string, detail: string} status: seen | lost | unreachable
 */
function emmcpProbeAuthorizationHeader()
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

	$probeUrl = emmcpPublicUrl('/emmcp/mcp.php');
	$r = getURLContent($probeUrl, 'POST', '{}', 1, array('Content-Type: application/json', 'Authorization: Bearer emmcp-probe', 'X-Dolibarr-Probe: 1'), array('http', 'https'), 2, -1);
	$json = (empty($r['curl_error_no']) && !empty($r['content'])) ? json_decode($r['content'], true) : null;
	if (is_array($json) && isset($json['authorization_seen'])) {
		return array('status' => $json['authorization_seen'] ? 'seen' : 'lost', 'detail' => '');
	}

	return array(
		'status' => 'unreachable',
		'detail' => !empty($r['curl_error_msg']) ? $r['curl_error_msg'] : 'HTTP '.(isset($r['http_code']) ? $r['http_code'] : '?'),
	);
}

/**
 * User-Agent ChatGPT sends while it discovers the OAuth metadata. Seen in
 * the access log of a working connector on 2026-11-16: the first requests
 * (401 on mcp.php, protected resource, authorization server) carry it; the
 * later ones carry openai-connectors-oauth/1.0 and openai-mcp/1.0.0.
 */
function emmcpChatgptDiscoveryUserAgent()
{
	return 'Python/3.14 aiohttp/3.14.4';
}

/**
 * Read the answer of a discovery request into a verdict.
 *
 * Hosting firewalls (ModSecurity rule sets) commonly turn away User-Agents
 * that look like a script library with 403 or 406; ChatGPT then stops at
 * "Unable to detect OAuth settings" although the module answers everyone else.
 *
 * @param int    $httpCode         HTTP status, 0 when the request failed
 * @param string $content          Response body
 * @param string $curlError        Transport error, '' when none
 * @param string $expectedResource Public URL of mcp.php
 * @return array{status: string, detail: string} status: ok | blocked | unexpected | unreachable
 */
function emmcpClassifyDiscoveryAnswer($httpCode, $content, $curlError, $expectedResource)
{
	if ($curlError !== '') {
		return array('status' => 'unreachable', 'detail' => $curlError);
	}
	$httpCode = (int) $httpCode;
	if ($httpCode === 403 || $httpCode === 406) {
		return array('status' => 'blocked', 'detail' => 'HTTP '.$httpCode);
	}
	$json = $content !== '' ? json_decode($content, true) : null;
	$resource = is_array($json) && isset($json['resource']) && is_string($json['resource']) ? $json['resource'] : null;
	if ($httpCode === 200 && $resource === $expectedResource) {
		return array('status' => 'ok', 'detail' => '');
	}

	return array('status' => 'unexpected', 'detail' => 'HTTP '.$httpCode.($resource !== null ? ', resource = '.$resource : ''));
}

/**
 * Fetch this module's protected resource metadata from its public URL with
 * ChatGPT's discovery User-Agent, to catch a hosting firewall that blocks it.
 *
 * The request leaves from the server itself: a block is proof, a pass is a
 * strong hint only (a firewall in front of the host, or one that exempts
 * local addresses, may treat outside requests differently).
 *
 * @param string $issuer Public URL of oauth.php
 * @param string $mcpUrl Public URL of mcp.php
 * @return array{url: string, status: string, detail: string}
 */
function emmcpProbeDiscoveryUserAgent($issuer, $mcpUrl)
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

	$url = $issuer.'/.well-known/oauth-protected-resource';
	// A User-Agent header replaces the one getURLContent sets with CURLOPT_USERAGENT.
	$r = getURLContent($url, 'GET', '', 1, array('Accept: application/json', 'User-Agent: '.emmcpChatgptDiscoveryUserAgent()), array('http', 'https'), 2, -1);
	$verdict = emmcpClassifyDiscoveryAnswer(
		isset($r['http_code']) ? (int) $r['http_code'] : 0,
		isset($r['content']) ? (string) $r['content'] : '',
		!empty($r['curl_error_no']) ? (string) $r['curl_error_msg'] : '',
		$mcpUrl
	);

	return array('url' => $url) + $verdict;
}
