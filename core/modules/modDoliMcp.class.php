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
 * \file    core/modules/modDoliMcp.class.php
 * \ingroup dolimcp
 * \brief   Descriptor of DoliMCP module — exposes Dolibarr as a remote MCP
 *          server (Streamable HTTP transport) usable by Claude, agents and
 *          any MCP-compatible client.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module DoliMCP
 */
class modDoliMcp extends DolibarrModules
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

		$this->numero = 491409;
		$this->rights_class = 'dolimcp';
		$this->family = 'interface';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'DoliMcpDescription';
		$this->descriptiondetail = 'DoliMcpDescriptionDetail';
		$this->editor_name = 'E-dem';
		$this->editor_url = 'https://www.e-dem.com';
		$this->version = '0.2.0';
		// Native Dolibarr "update available" check (compares this URL's answer to $this->version)
		$this->url_last_version = 'https://www.e-dem.com/dolibarr/dolimcp/last_version.php';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'technic';

		$this->module_parts = array();

		// Config pages
		$this->config_page_url = array('setup.php@dolimcp');

		// Dependencies: the MCP tools work through the Dolibarr REST API
		$this->hidden = false;
		$this->depends = array('modApi');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('dolimcp@dolimcp');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(16, 0);

		// Constants
		$this->const = array();

		// Permissions: none for the POC — authentication and authorization
		// are delegated to the user's API key and the REST API layer.
		$this->rights = array();

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
		// Create OAuth tables (llx_dolimcp_oauth_client, llx_dolimcp_oauth_token)
		$result = $this->_load_tables('/dolimcp/sql/');
		if ($result < 0) {
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
