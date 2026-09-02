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

/**
 * Register the dolibarr-mcp-sql autoloader and return the library directory.
 *
 * Same shape as emmcp_mcp_oauth_autoload(): the library ships inside whichever
 * MCP-enabled module is installed, so look in ours first and fall back to
 * Dalfred's copy. Returns null when no copy is present, which the caller must
 * treat as "read-only SQL is unavailable", never as "allowed".
 *
 * @return string|null Directory holding the library, or null when absent
 */
function emmcp_mcp_sql_autoload()
{
	static $registered = null;
	if ($registered !== null) {
		return $registered ?: null;
	}
	$candidates = array(
		dol_buildpath('/emmcp/vendor/dolibarr-mcp-sql', 0),
		dol_buildpath('/dalfred/vendor/dolibarr-mcp-sql', 0),
		dol_buildpath('/dalfred/dolibarr-mcp-sql', 0),
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
		$prefix = 'DolibarrMcpSql\\';
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

/**
 * The per-module parameterization handed to every dolibarr-mcp-sql object.
 *
 * @return \DolibarrMcpSql\SqlConfig
 */
function emmcp_sql_config()
{
	return new \DolibarrMcpSql\SqlConfig('emmcp', 'emmcp_', 'EMMCP');
}

/**
 * Describe this installation for the MCP environment tool.
 *
 * Same shape as Dalfred's: the MCP package has no access to Dolibarr constants
 * or database, so the host builds this and passes it to Bootstrap.
 *
 * @param  bool $sqlEnabled Whether read-only SQL was granted for this session
 * @return \DolibarrMcp\Config\EnvironmentInfo
 */
function emmcp_mcp_environment($sqlEnabled = false)
{
	global $conf, $db;

	$version = '';
	dol_include_once('/emmcp/core/modules/modEmmcp.class.php');
	if (class_exists('modEmmcp')) {
		$module = new modEmmcp($db);
		$version = (string) $module->version;
	}

	return new \DolibarrMcp\Config\EnvironmentInfo(
		defined('DOL_VERSION') ? DOL_VERSION : null,
		'emmcp',
		$version !== '' ? $version : null,
		emmcp_mcp_enabled_modules($db),
		isset($conf->entity) ? (int) $conf->entity : null,
		isModEnabled('multicompany'),
		array('readonly_sql' => (bool) $sqlEnabled)
	);
}

/**
 * The Dolibarr modules currently enabled, by slug.
 *
 * Read from llx_const rather than $conf->modules: the latter is not populated
 * on every entry point, and an empty list would read as "no modules installed"
 * rather than "we did not look".
 *
 * Enabling a module writes MAIN_MODULE_<NAME> = 1, but several sub-keys share
 * that prefix and that value — MAIN_MODULE_EVENTORGANIZATION_MODELS,
 * ..._TRIGGERS and friends. They are recognised structurally rather than by a
 * list of known suffixes: a sub-key always has its parent module in the same
 * result set, so anything whose prefix is itself in the list is dropped. That
 * stays correct for a module whose own name contains an underscore.
 *
 * @param  DoliDB $db Database handler
 * @return array<int, string>
 */
function emmcp_mcp_enabled_modules($db)
{
	$sql = "SELECT name FROM ".MAIN_DB_PREFIX."const";
	$sql .= " WHERE name LIKE 'MAIN_MODULE_%' AND value = '1'";
	$sql .= " ORDER BY name ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return array();
	}

	$names = array();
	while ($obj = $db->fetch_object($resql)) {
		$names[] = $obj->name;
	}
	$known = array_flip($names);

	$modules = array();
	foreach ($names as $name) {
		$isSubKey = false;
		$position = strlen('MAIN_MODULE_');
		while (($position = strpos($name, '_', $position)) !== false) {
			if (isset($known[substr($name, 0, $position)])) {
				$isSubKey = true;
				break;
			}
			$position++;
		}
		if (!$isSubKey) {
			$modules[] = strtolower(substr($name, strlen('MAIN_MODULE_')));
		}
	}

	return $modules;
}
