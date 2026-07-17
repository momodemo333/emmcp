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
 * \file    lib/emmcp_bootstrap.php
 * \ingroup emmcp
 * \brief   Registers the PSR-4 autoloader for the embedded dolibarr-mcp-oauth library.
 */

/**
 * Locate the embedded dolibarr-mcp-oauth library and register its PSR-4
 * autoloader for the DolibarrMcpOAuth\ namespace. Idempotent.
 *
 * @return string|null Absolute path to the library dir, or null if not found.
 */
function emmcp_mcp_oauth_autoload()
{
	static $registered = null;
	if ($registered !== null) {
		return $registered ?: null;
	}
	$candidates = array(
		dol_buildpath('/emmcp/vendor/dolibarr-mcp-oauth', 0),
		dol_buildpath('/dalfred/vendor/dolibarr-mcp-oauth', 0),
	);
	$libDir = '';
	foreach ($candidates as $candidate) {
		if ($candidate && is_dir($candidate.'/src')) {
			$libDir = $candidate;
			break;
		}
	}
	if ($libDir === '') {
		$registered = false;
		return null;
	}
	spl_autoload_register(function ($class) use ($libDir) {
		$prefix = 'DolibarrMcpOAuth\\';
		if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
			return;
		}
		$rel = str_replace('\\', '/', substr($class, strlen($prefix)));
		$file = $libDir.'/src/'.$rel.'.php';
		if (is_file($file)) {
			require $file;
		}
	});
	$registered = $libDir;
	return $libDir;
}
