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
 * \file    lib/dolimcp.lib.php
 * \ingroup dolimcp
 * \brief   Library files with common functions for DoliMCP
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function dolimcpAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('dolimcp@dolimcp');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/dolimcp/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/dolimcp/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolimcp@dolimcp');

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
function dolimcpCodeBlock($code, $label = '')
{
	$html = '';
	if ($label !== '') {
		$html .= '<div class="paddingtop"><strong>'.dol_escape_htmltag($label).'</strong> ';
		// texttoshow='none' → render only the copy button (value hidden)
		$html .= showValueWithClipboardCPButton($code, 0, 'none');
		$html .= '</div>';
	}
	$html .= '<pre class="dolimcp-code">'.htmlspecialchars($code, ENT_QUOTES, 'UTF-8').'</pre>';

	return $html;
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
 * @param string $relpath Module-relative path, e.g. '/dolimcp/oauth.php'
 * @return string Absolute URL
 */
function dolimcpPublicUrl($relpath)
{
	$url = dol_buildpath($relpath, 2);

	$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
		|| ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

	if ($isHttps && str_starts_with($url, 'http://')) {
		$url = 'https://'.substr($url, 7);
	}

	return $url;
}

/**
 * Resolve the autoloader of the embedded Dolibarr MCP server package.
 *
 * @return string|null Absolute path to autoload.php, or null if not found
 */
function dolimcpFindMcpAutoloader()
{
	$candidates = array(
		dol_buildpath('/dolimcp/vendor/dolibarr-mcp-server/vendor/autoload.php', 0),
		dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0),
	);
	foreach ($candidates as $candidate) {
		if ($candidate && file_exists($candidate)) {
			return $candidate;
		}
	}
	return null;
}
