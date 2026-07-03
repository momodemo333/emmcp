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

	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolimcp@dolimcp');

	return $head;
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
