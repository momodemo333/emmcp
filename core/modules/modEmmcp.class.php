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
 * \file    core/modules/modEmmcp.class.php
 * \ingroup emmcp
 * \brief   Descriptor of emMCP module — exposes Dolibarr as a remote MCP
 *          server (Streamable HTTP transport) usable by Claude, agents and
 *          any MCP-compatible client.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module emMCP
 */
class modEmmcp extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		$this->numero = 491412;
		$this->rights_class = 'emmcp';
		$this->family = 'interface';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'EmmcpDescription';
		$this->descriptiondetail = 'EmmcpDescriptionDetail';
		$this->editor_name = 'E-dem';
		$this->editor_url = 'https://www.e-dem.com';
		// Keep in sync with EmmcpMigrations::MODULE_VERSION, or migrations
		// silently stop running on existing installs.
		$this->version = '1.4.0';
		// Native Dolibarr "update available" check (compares this URL's answer to $this->version)
		$this->url_last_version = 'https://www.e-dem.com/dolibarr/emmcp/last_version.php';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'technic';

		$this->module_parts = array();

		// Config pages
		$this->config_page_url = array('setup.php@emmcp');

		// Dependencies: the MCP tools work through the Dolibarr REST API
		$this->hidden = false;
		$this->depends = array('modApi');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('emmcp@emmcp');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(16, 0);

		// Constants
		//
		// EMMCP_SQL_ENABLED is deliberately NOT declared here. Constants listed
		// in $this->const are deleted by _remove() and recreated by _init(), so
		// a disable/enable cycle — which every module update triggers — would
		// silently re-open read-only SQL access after a customer turned it off.
		// It is written by the admin page instead and survives on its own.
		$this->const = array();

		// Permissions.
		//
		// The MCP tools themselves need none: they act through the REST API
		// with the caller's API key and inherit that user's Dolibarr rights.
		// Raw SQL cannot inherit anything, so it gets an explicit right, off
		// for everyone until an administrator grants it.
		$r = 0;
		// numero . sprintf("%02d") rather than numero + 1: the latter produces an
		// id that is itself a valid module number, so it collides with whatever
		// module owns it. That is exactly what happened here — emMCP shared
		// 491409 with emSmartFill, and this right showed up inside emSmartFill's
		// block on the permissions screen.
		//
		// The number itself comes from the E-dem registry in
		// dolibarr/docs/INVENTAIRE-MODULES.md, which is the only reliable answer
		// to "which number is free": 491410 and 491411 look free from this
		// repository alone but belong to admdashboard and emtimetobill, which
		// live in other project directories.
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'EmmcpRightSqlQuery';
		$this->rights[$r][3] = 0; // not granted by default
		$this->rights[$r][4] = 'sqlquery';
		$this->rights[$r][5] = 'read';

		// Menus: none for the POC
		$this->menu = array();
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		// Create OAuth and read-only SQL tables
		$result = $this->_load_tables('/emmcp/sql/');
		if ($result < 0) {
			return -1;
		}

		// Replay migrations too: _load_tables only creates missing tables, it
		// does not alter existing ones. A failure here means the module would
		// run against a schema it does not expect, so enabling must fail
		// rather than leave the customer in that state.
		dol_include_once('/emmcp/class/emmcpmigrations.class.php');
		$migrations = new EmmcpMigrations($this->db);
		if (!$migrations->run()) {
			$this->error = 'Database migration failed: '.implode(', ', $migrations->getErrors());
			dol_syslog('[EMMCP] '.$this->error, LOG_ERR);

			return -1;
		}

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param  string $options Options when disabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();

		return $this->_remove($sql, $options);
	}
}
