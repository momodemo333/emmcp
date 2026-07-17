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
