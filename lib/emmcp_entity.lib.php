<?php
/* Copyright (C) 2026 Morgan Demoulin <support@e-dem.com>
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
 * \file    lib/emmcp_entity.lib.php
 * \ingroup emmcp
 * \brief   Multicompany: which entity an MCP access works in, and who may use it.
 *
 * mcp.php and oauth.php run without a Dolibarr session, so Dolibarr starts
 * them in entity 1. Under Multicompany a user shared between entities
 * (transverse mode) must be able to connect an AI client to another entity:
 *
 *  - OAuth: the entity is chosen on the consent screen and stored on the
 *    authorization code; the token exchange copies it onto the access and
 *    refresh tokens (llx_emmcp_oauth_token.entity). mcp.php then works in the
 *    entity of the token it receives.
 *  - API key (Bearer): mcp.php?entity=N, or the user's main entity.
 *
 * In both cases the user's access to the entity is checked here, with the
 * rule of Multicompany itself (dao_multicompany::verifyRight), before any
 * call: Dolibarr's REST API does not check the access to an entity sent in
 * DOLAPIENTITY as such (it only applies the user's rights there), so the
 * refusal would come late, as a bare 403 on the first tool call.
 *
 * Only core and Multicompany tables are read, never Multicompany's classes,
 * whose API changes between its versions.
 *
 * Without Multicompany none of this applies: everything stays in entity 1,
 * exactly as in 1.10.x.
 */

/**
 * Is Multicompany enabled on this install?
 *
 * Read from the database rather than isModEnabled() alone: the request may
 * still be in an entity whose configuration has not been loaded yet.
 *
 * @param DoliDB $db Database handler
 * @return bool
 */
function emmcpMulticompanyActive($db)
{
	static $cache = null;
	if ($cache !== null) {
		return $cache;
	}
	if (isModEnabled('multicompany')) {
		return $cache = true;
	}
	$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."const";
	$sql .= " WHERE name = 'MAIN_MODULE_MULTICOMPANY' AND value = '1' AND entity IN (0, 1)";
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;

	return $cache = ($obj && (int) $obj->nb > 0);
}

/**
 * Is Multicompany in transverse mode (users shared between entities)?
 *
 * @param DoliDB $db Database handler
 * @return bool
 */
function emmcpMulticompanyTransverse($db)
{
	$sql = "SELECT value FROM ".MAIN_DB_PREFIX."const";
	$sql .= " WHERE name = 'MULTICOMPANY_TRANSVERSE_MODE' AND entity IN (0, 1)";
	$sql .= " ORDER BY entity DESC";
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;

	return $obj && !empty($obj->value);
}

/**
 * Is the emMCP module enabled in this entity?
 *
 * @param DoliDB $db     Database handler
 * @param int    $entity Entity id
 * @return bool
 */
function emmcpModuleEnabledInEntity($db, $entity)
{
	$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."const";
	$sql .= " WHERE name = 'MAIN_MODULE_EMMCP' AND value = '1'";
	$sql .= " AND entity IN (0, ".((int) $entity).")";
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;

	return $obj && (int) $obj->nb > 0;
}

/**
 * Is the emMCP module enabled in at least one entity?
 *
 * @param DoliDB $db Database handler
 * @return bool
 */
function emmcpModuleEnabledSomewhere($db)
{
	$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."const";
	$sql .= " WHERE name = 'MAIN_MODULE_EMMCP' AND value = '1'";
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;

	return $obj && (int) $obj->nb > 0;
}

/**
 * Entities of the install, with their label and status.
 *
 * @param DoliDB $db Database handler
 * @return array<int, array{id:int, label:string, active:bool}> Keyed by entity id
 */
function emmcpEntities($db)
{
	$entities = array();
	$sql = "SELECT rowid, label, active FROM ".MAIN_DB_PREFIX."entity ORDER BY rowid";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$entities[(int) $obj->rowid] = array('id' => (int) $obj->rowid, 'label' => dol_html_entity_decode((string) $obj->label, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'active' => !empty($obj->active));
		}
	}
	if (empty($entities)) {
		$entities[1] = array('id' => 1, 'label' => 'Entity 1', 'active' => true);
	}

	return $entities;
}

/**
 * Label of an entity, "#N" when unknown.
 *
 * @param DoliDB $db     Database handler
 * @param int    $entity Entity id
 * @return string
 */
function emmcpEntityLabel($db, $entity)
{
	$entities = emmcpEntities($db);

	return isset($entities[(int) $entity]) && $entities[(int) $entity]['label'] !== '' ? $entities[(int) $entity]['label'] : '#'.((int) $entity);
}

/**
 * Main entity of a user: the one Dolibarr's REST API uses when no entity is
 * asked. A superadmin (entity 0) works in entity 1.
 *
 * @param DoliDB $db     Database handler
 * @param int    $userId User id
 * @return int Entity id, 0 when the user does not exist
 */
function emmcpUserMainEntity($db, $userId)
{
	$sql = "SELECT entity FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".((int) $userId);
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;
	if (!$obj) {
		return 0;
	}

	return (int) $obj->entity > 0 ? (int) $obj->entity : 1;
}

/**
 * May this user work in this entity? Same rule as Multicompany
 * (dao_multicompany::verifyRight): a superadmin (user entity 0) everywhere;
 * an administrator of the entity; otherwise, in transverse mode, a member of
 * at least one user group of the entity, and outside it, a user of the
 * entity. The entity must exist and be active.
 *
 * Without Multicompany only entity 1 exists.
 *
 * @param DoliDB $db     Database handler
 * @param int    $userId User id
 * @param int    $entity Entity id
 * @return bool
 */
function emmcpUserCanUseEntity($db, $userId, $entity)
{
	$userId = (int) $userId;
	$entity = (int) $entity;
	if ($userId <= 0 || $entity <= 0) {
		return false;
	}
	if (!emmcpMulticompanyActive($db)) {
		return $entity === 1;
	}

	$entities = emmcpEntities($db);
	if (!isset($entities[$entity]) || !$entities[$entity]['active']) {
		return false;
	}

	$sql = "SELECT entity, admin, statut FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".$userId;
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;
	if (!$obj || empty($obj->statut)) {
		return false;
	}
	$userEntity = (int) $obj->entity;
	if ($userEntity === 0) {
		return true;
	}
	if ($userEntity === $entity && !empty($obj->admin)) {
		return true;
	}
	if (!emmcpMulticompanyTransverse($db)) {
		return $userEntity === $entity;
	}

	$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."usergroup_user";
	$sql .= " WHERE fk_user = ".$userId." AND entity = ".$entity;
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;

	return $obj && (int) $obj->nb > 0;
}

/**
 * Entities a user may connect an AI client to: the entities they may work
 * in where emMCP is enabled.
 *
 * @param DoliDB $db     Database handler
 * @param int    $userId User id
 * @return array<int, array{id:int, label:string, active:bool}> Keyed by entity id
 */
function emmcpUserMcpEntities($db, $userId)
{
	$result = array();
	foreach (emmcpEntities($db) as $id => $entity) {
		if (emmcpUserCanUseEntity($db, $userId, $id) && emmcpModuleEnabledInEntity($db, $id)) {
			$result[$id] = $entity;
		}
	}

	return $result;
}

/**
 * Entity requested in the URL (mcp.php?entity=N, or the "resource" of an
 * OAuth request pointing to such a URL). 0 when none.
 *
 * @param string $url URL, or empty to read the current query string
 * @return int
 */
function emmcpRequestedEntity($url = '')
{
	if ($url === '') {
		$value = isset($_GET['entity']) ? (string) $_GET['entity'] : '';
	} else {
		$query = (string) parse_url($url, PHP_URL_QUERY);
		$params = array();
		parse_str($query, $params);
		$value = isset($params['entity']) && is_string($params['entity']) ? $params['entity'] : '';
	}

	return preg_match('/^[1-9][0-9]{0,8}$/', $value) ? (int) $value : 0;
}

/**
 * Entity stored on an OAuth token (code, access or refresh) presented in clear.
 *
 * @param DoliDB $db    Database handler
 * @param string $type  'code', 'access' or 'refresh'
 * @param string $value Clear token value
 * @return int Entity id, 0 when the token is unknown
 */
function emmcpTokenEntity($db, $type, $value)
{
	if ($value === '') {
		return 0;
	}
	$sql = "SELECT entity FROM ".MAIN_DB_PREFIX."emmcp_oauth_token";
	$sql .= " WHERE token_type = '".$db->escape($type)."'";
	$sql .= " AND token_hash = '".$db->escape(hash('sha256', $value))."'";
	$resql = $db->query($sql);
	$obj = $resql ? $db->fetch_object($resql) : null;

	return $obj ? (int) $obj->entity : 0;
}

/**
 * Bearer value presented to mcp.php, read from the same sources as the
 * authentication (Authorization header under its CGI/FPM names).
 *
 * @param array $server $_SERVER
 * @return string Empty when none
 */
function emmcpPresentedBearer(array $server)
{
	$header = '';
	foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
		if (!empty($server[$key])) {
			$header = (string) $server[$key];
			break;
		}
	}
	if ($header === '' && function_exists('getallheaders')) {
		foreach ((array) getallheaders() as $name => $value) {
			if (strcasecmp((string) $name, 'Authorization') === 0) {
				$header = (string) $value;
				break;
			}
		}
	}

	return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : '';
}

/**
 * Make the rest of the request work in another entity: $conf->entity and its
 * configuration (enabled modules, constants), as Dolibarr's REST API does when
 * it switches to the entity of the API key's user.
 *
 * @param DoliDB $db     Database handler
 * @param int    $entity Entity id
 * @return void
 */
function emmcpSwitchEntity($db, $entity)
{
	global $conf;

	$entity = (int) $entity;
	if ($entity <= 0 || (int) $conf->entity === $entity) {
		return;
	}
	$conf->entity = $entity;
	$conf->setValues($db);
}
