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
 * Discovery URLs at the root of the domain for the MCP endpoint and its
 * authorization server: RFC 9728 (protected resource) and RFC 8414
 * (authorization server) "path insertion".
 *
 * A client that follows the WWW-Authenticate header of the 401 (claude.ai,
 * Claude Code) never needs them. A client that builds the well-known URLs
 * itself (ChatGPT, most probably) asks for exactly these, and a module can
 * only answer them through a rewrite rule of the web server.
 *
 * @param string $mcpUrl Public URL of mcp.php
 * @param string $issuer Public URL of oauth.php (the issuer)
 * @return array{prm: string, as: string}
 */
function emmcpRootDiscoveryUrls($mcpUrl, $issuer)
{
	$p = parse_url($mcpUrl);
	$origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');

	return array(
		'prm' => $origin.'/.well-known/oauth-protected-resource'.(string) parse_url($mcpUrl, PHP_URL_PATH),
		'as' => $origin.'/.well-known/oauth-authorization-server'.(string) parse_url($issuer, PHP_URL_PATH),
	);
}

/**
 * Web server rules that answer the root discovery URLs from the module,
 * through an internal rewrite (no redirection, the issuer stays the same).
 *
 * The targets are absolute URL paths, so the rules hold wherever Dolibarr
 * lives on the domain (/custom/, a sub-path, an alternative custom folder).
 * They only match the OAuth metadata names: /.well-known/acme-challenge/
 * (Let's Encrypt) and every other well-known URL are left alone.
 *
 * @param string $mcpUrl Public URL of mcp.php
 * @param string $issuer Public URL of oauth.php (the issuer)
 * @return array{apache: string, nginx: string}
 */
function emmcpRootDiscoveryRules($mcpUrl, $issuer)
{
	$mcpPath = (string) parse_url($mcpUrl, PHP_URL_PATH);
	$issuerPath = (string) parse_url($issuer, PHP_URL_PATH);
	$qMcp = preg_quote($mcpPath, '#');
	$qIssuer = preg_quote($issuerPath, '#');

	$apache = "# emMCP: OAuth discovery at the root of the domain (RFC 9728, RFC 8414)\n"
		."<IfModule mod_rewrite.c>\n"
		."\tRewriteEngine On\n"
		."\tRewriteRule ^\\.well-known/oauth-protected-resource(".$qMcp.")?/?$ ".$issuerPath."/.well-known/oauth-protected-resource [L]\n"
		."\tRewriteRule ^\\.well-known/(oauth-authorization-server|openid-configuration)(".$qIssuer.")?/?$ ".$issuerPath."/.well-known/$1 [L]\n"
		."</IfModule>\n";

	$nginx = "# emMCP: OAuth discovery at the root of the domain (RFC 9728, RFC 8414)\n"
		."location ~ ^/\\.well-known/oauth-protected-resource(".$qMcp.")?/?$ {\n"
		."\trewrite ^ ".$issuerPath."/.well-known/oauth-protected-resource last;\n"
		."}\n"
		."location ~ ^/\\.well-known/(oauth-authorization-server|openid-configuration)(".$qIssuer.")?/?$ {\n"
		."\trewrite ^/\\.well-known/([a-z-]+) ".$issuerPath."/.well-known/\$1 last;\n"
		."}\n";

	return array('apache' => $apache, 'nginx' => $nginx);
}

/**
 * Ask the root discovery URLs whether they answer with this module's
 * metadata: the protected resource must name this mcp.php, the
 * authorization server this issuer, exactly (clients compare strings).
 *
 * @param string $mcpUrl Public URL of mcp.php
 * @param string $issuer Public URL of oauth.php (the issuer)
 * @return array<string, array{url: string, ok: bool, detail: string}> keyed prm, as
 */
function emmcpProbeRootDiscovery($mcpUrl, $issuer)
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

	$urls = emmcpRootDiscoveryUrls($mcpUrl, $issuer);
	$expect = array('prm' => array('resource', $mcpUrl), 'as' => array('issuer', $issuer));
	$result = array();
	foreach ($urls as $key => $url) {
		list($field, $expected) = $expect[$key];
		$r = getURLContent($url, 'GET', '', 1, array('Accept: application/json'), array('http', 'https'), 2, -1);
		$json = (empty($r['curl_error_no']) && !empty($r['content'])) ? json_decode($r['content'], true) : null;
		$got = is_array($json) && isset($json[$field]) && is_string($json[$field]) ? $json[$field] : null;
		if ($got === $expected) {
			$detail = '';
		} elseif (!empty($r['curl_error_msg'])) {
			$detail = $r['curl_error_msg'];
		} else {
			$detail = 'HTTP '.(isset($r['http_code']) ? $r['http_code'] : '?').($got !== null ? ', '.$field.' = '.$got : '');
		}
		$result[$key] = array('url' => $url, 'ok' => $got === $expected, 'detail' => $detail);
	}

	return $result;
}
