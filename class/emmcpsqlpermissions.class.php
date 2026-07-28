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
 * \file    class/emmcpsqlpermissions.class.php
 * \ingroup emmcp
 * \brief   Who may run read-only SQL through the MCP endpoint.
 *
 * Every other MCP tool reaches Dolibarr over the REST API and therefore
 * inherits the caller's permissions for free. Raw SQL inherits nothing, so
 * authorisation is rebuilt explicitly here, and every condition must hold:
 *
 *   1. the module is enabled;
 *   2. the administrator turned the feature on globally;
 *   3. the user holds the emmcp->sqlquery->read Dolibarr right;
 *   4. the user was opted in individually;
 *   5. multi-entity is not in play (unless explicitly allowed).
 *
 * The absence of a permission row is a refusal, never a default grant.
 */
class EmmcpSqlPermissions
{
	/** @var DoliDB */
	private $db;

	/** @var Conf */
	private $conf;

	/** @var array<int, bool> */
	private $optInCache = array();

	/**
	 * @param DoliDB $db   Database handler
	 * @param Conf   $conf Dolibarr configuration
	 */
	public function __construct($db, $conf)
	{
		$this->db = $db;
		$this->conf = $conf;
	}

	/**
	 * @return bool True when the administrator enabled the feature
	 */
	public function isGloballyEnabled()
	{
		return getDolGlobalInt('EMMCP_SQL_ENABLED') === 1;
	}

	/**
	 * @param  int  $userId User rowid
	 * @return bool True when this user was individually opted in
	 */
	public function hasUserOptIn($userId)
	{
		$userId = (int) $userId;
		if ($userId <= 0) {
			return false;
		}
		if (isset($this->optInCache[$userId])) {
			return $this->optInCache[$userId];
		}

		$entity = isset($this->conf->entity) ? (int) $this->conf->entity : 1;
		$sql = "SELECT sql_enabled FROM ".MAIN_DB_PREFIX."emmcp_sql_permissions";
		$sql .= " WHERE fk_user = ".((int) $userId);
		$sql .= " AND entity = ".((int) $entity);

		$enabled = false;
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$enabled = $obj ? ((int) $obj->sql_enabled === 1) : false;
		}

		$this->optInCache[$userId] = $enabled;

		return $enabled;
	}

	/**
	 * Stable code of the first unmet condition, or null when access is allowed.
	 *
	 * The administrator sees the precise reason in the audit trail and the
	 * admin page; the MCP client only ever gets the generic refusal.
	 *
	 * @param  User        $user Dolibarr user, rights loaded
	 * @return string|null
	 */
	public function denialCode($user)
	{
		if (!isModEnabled('emmcp')) {
			return 'SQL_MODULE_DISABLED';
		}
		if (!$this->isGloballyEnabled()) {
			return 'SQL_DISABLED';
		}
		if (empty($user) || empty($user->id)) {
			return 'SQL_PERMISSION_DENIED';
		}
		if (!$this->userHasRight($user)) {
			return 'SQL_PERMISSION_DENIED';
		}
		if (!$this->hasUserOptIn($user->id)) {
			return 'SQL_PERMISSION_DENIED';
		}
		if ($this->isBlockedByMultiEntity()) {
			return 'SQL_MULTIENTITY_BLOCKED';
		}

		return null;
	}

	/**
	 * @param  User $user Dolibarr user, rights loaded
	 * @return bool
	 */
	public function canUse($user)
	{
		return $this->denialCode($user) === null;
	}

	/**
	 * Raw SQL cannot be filtered per entity, so multicompany is refused
	 * wholesale rather than per current entity.
	 *
	 * Checking that conf->entity was not 1 was worthless here: mcp.php runs
	 * with NOSESSION, so the request stays on entity 1 while the query itself
	 * reads every entity's rows. The dangerous case was precisely the one the
	 * check let through.
	 *
	 * @return bool
	 */
	private function isBlockedByMultiEntity()
	{
		if (!isModEnabled('multicompany')) {
			return false;
		}

		return getDolGlobalInt('EMMCP_SQL_ALLOW_MULTIENTITY') !== 1;
	}

	/**
	 * hasRight() landed in Dolibarr 16 but older code paths still expose the
	 * rights tree only; support both so the module keeps its 16.x floor.
	 *
	 * @param  User $user Dolibarr user
	 * @return bool
	 */
	private function userHasRight($user)
	{
		if (method_exists($user, 'hasRight')) {
			return (bool) $user->hasRight('emmcp', 'sqlquery', 'read');
		}

		return !empty($user->rights->emmcp->sqlquery->read);
	}

	/**
	 * Persist the per-user opt-in.
	 *
	 * @param  int  $userId     Target user
	 * @param  bool $enabled    New value
	 * @param  int  $modifierId User performing the change
	 * @return bool
	 */
	public function setUserOptIn($userId, $enabled, $modifierId)
	{
		$userId = (int) $userId;
		$entity = isset($this->conf->entity) ? (int) $this->conf->entity : 1;
		$value = $enabled ? 1 : 0;
		$now = $this->db->idate(dol_now());

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."emmcp_sql_permissions";
		$sql .= " WHERE fk_user = ".((int) $userId)." AND entity = ".((int) $entity);
		$resql = $this->db->query($sql);
		$existing = $resql ? $this->db->fetch_object($resql) : null;

		if ($existing) {
			$update = "UPDATE ".MAIN_DB_PREFIX."emmcp_sql_permissions";
			$update .= " SET sql_enabled = ".((int) $value);
			$update .= ", date_modification = '".$this->db->escape($now)."'";
			$update .= ", fk_user_modif = ".((int) $modifierId);
			$update .= " WHERE rowid = ".((int) $existing->rowid);
			$ok = (bool) $this->db->query($update);
		} else {
			$insert = "INSERT INTO ".MAIN_DB_PREFIX."emmcp_sql_permissions";
			$insert .= " (entity, fk_user, sql_enabled, date_creation, fk_user_creat)";
			$insert .= " VALUES (".((int) $entity).", ".((int) $userId).", ".((int) $value);
			$insert .= ", '".$this->db->escape($now)."', ".((int) $modifierId).")";
			$ok = (bool) $this->db->query($insert);
		}

		unset($this->optInCache[$userId]);

		return $ok;
	}

	/**
	 * Users holding the Dolibarr right, with their opt-in state, for the admin
	 * page.
	 *
	 * @return array<int, array{id: int, login: string, lastname: string, firstname: string, admin: int, opted_in: bool}>
	 */
	public function listCandidateUsers()
	{
		$entity = isset($this->conf->entity) ? (int) $this->conf->entity : 1;

		$sql = "SELECT u.rowid, u.login, u.lastname, u.firstname, u.admin, p.sql_enabled";
		$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."emmcp_sql_permissions as p";
		$sql .= " ON p.fk_user = u.rowid AND p.entity = ".((int) $entity);
		$sql .= " WHERE u.statut = 1";
		$sql .= " ORDER BY u.login ASC";

		$users = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $users;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$users[] = array(
				'id' => (int) $obj->rowid,
				'login' => (string) $obj->login,
				'lastname' => (string) $obj->lastname,
				'firstname' => (string) $obj->firstname,
				'admin' => (int) $obj->admin,
				'opted_in' => ((int) $obj->sql_enabled === 1),
			);
		}

		return $users;
	}
}
