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
 * \file    class/emmcpauthlog.class.php
 * \ingroup emmcp
 * \brief   Connection log: OAuth steps and refused MCP requests, with the reason.
 *
 * When a sign-in from Claude, ChatGPT... fails, the AI client shows a generic
 * message and a reference that only its vendor can read. This log is the
 * server side of that story: which step failed and why, in words an
 * administrator can act on. It never stores a secret (no token, code or key).
 *
 * Writing to it must never break the request it describes: every failure here
 * is swallowed and logged to syslog.
 */
class EmmcpAuthLog
{
	/** Days of history kept. */
	const RETENTION_DAYS = 30;

	/** At most this many "no credentials" rows per IP and minute (that route is unauthenticated). */
	const ANONYMOUS_PER_MINUTE = 2;

	/**
	 * Record one event.
	 *
	 * @param DoliDB      $db       Database handler
	 * @param string      $event    Short event code (register, authorize, consent, token, mcp_auth, mcp_runtime...)
	 * @param bool        $success  Outcome
	 * @param string|null $reason   Precise reason (failure) or detail (success)
	 * @param string|null $clientId OAuth client_id, when known
	 * @param int|null    $userId   Dolibarr user, when known
	 * @param int|null    $entity   Entity concerned, when not the one of the request
	 * @return void
	 */
	public static function record($db, $event, $success, $reason = null, $clientId = null, $userId = null, $entity = null)
	{
		global $conf;

		try {
			if (!self::tableExists($db)) {
				return;
			}

			$ip = function_exists('getUserRemoteIP') ? (string) getUserRemoteIP() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
			$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
			// The entity concerned: the one of the request, unless the caller
			// names another (Multicompany refusal for an entity the request
			// never switched to, so that entity's administrator sees it).
			$entity = $entity !== null && (int) $entity > 0 ? (int) $entity : (isset($conf->entity) ? (int) $conf->entity : 1);

			if ($event === 'mcp_auth' && !$success && $userId === null && self::recentAnonymous($db, $ip) >= self::ANONYMOUS_PER_MINUTE) {
				return;
			}

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."emmcp_auth_log";
			$sql .= " (entity, datec, event, success, reason, client_id, fk_user, ip, user_agent)";
			$sql .= " VALUES (".$entity.", '".$db->idate(dol_now())."',";
			$sql .= " '".$db->escape(dol_trunc((string) $event, 32, 'right', 'UTF-8', 1))."',";
			$sql .= " ".($success ? 1 : 0).",";
			$sql .= " ".($reason !== null && $reason !== '' ? "'".$db->escape(dol_trunc((string) $reason, 250, 'right', 'UTF-8', 1))."'" : "NULL").",";
			$sql .= " ".($clientId !== null && $clientId !== '' ? "'".$db->escape(dol_trunc((string) $clientId, 80, 'right', 'UTF-8', 1))."'" : "NULL").",";
			$sql .= " ".($userId !== null && (int) $userId > 0 ? (int) $userId : "NULL").",";
			$sql .= " ".($ip !== '' ? "'".$db->escape(substr($ip, 0, 45))."'" : "NULL").",";
			$sql .= " ".($ua !== '' ? "'".$db->escape(dol_trunc($ua, 250, 'right', 'UTF-8', 1))."'" : "NULL").")";

			if (!$db->query($sql)) {
				dol_syslog('[EMMCP] auth log insert failed: '.$db->lasterror(), LOG_WARNING);
				return;
			}

			// Opportunistic purge, roughly once every 50 writes.
			if (mt_rand(1, 50) === 1) {
				self::purge($db);
			}
		} catch (\Throwable $e) {
			dol_syslog('[EMMCP] auth log: '.$e->getMessage(), LOG_WARNING);
		}
	}

	/**
	 * Latest events of the current entity, newest first.
	 *
	 * @param DoliDB $db    Database handler
	 * @param int    $limit Maximum rows
	 * @return array<int, object>
	 */
	public static function fetchRecent($db, $limit = 50)
	{
		global $conf;

		if (!self::tableExists($db)) {
			return array();
		}

		$sql = "SELECT l.rowid, l.datec, l.event, l.success, l.reason, l.client_id, l.fk_user, l.ip, l.user_agent,";
		$sql .= " c.client_name, u.login";
		$sql .= " FROM ".MAIN_DB_PREFIX."emmcp_auth_log as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."emmcp_oauth_client as c ON c.client_id = l.client_id";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.entity = ".((int) $conf->entity);
		$sql .= " ORDER BY l.datec DESC, l.rowid DESC";
		$sql .= $db->plimit((int) $limit);

		$rows = array();
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$rows[] = $obj;
			}
		}

		return $rows;
	}

	/**
	 * Delete rows older than the retention.
	 *
	 * @param DoliDB $db Database handler
	 * @return void
	 */
	public static function purge($db)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."emmcp_auth_log";
		$sql .= " WHERE datec < '".$db->idate(dol_now() - self::RETENTION_DAYS * 86400)."'";
		$db->query($sql);
	}

	/**
	 * Whether the table exists: on a files-only upgrade, the very first
	 * request may come before the migration that creates it.
	 *
	 * @param DoliDB $db Database handler
	 * @return bool
	 */
	private static function tableExists($db)
	{
		static $exists = null;
		if ($exists === null) {
			$resql = $db->query("SELECT 1 FROM ".MAIN_DB_PREFIX."emmcp_auth_log WHERE 1 = 0");
			$exists = (bool) $resql;
		}

		return $exists;
	}

	/**
	 * Anonymous refusals recorded for this IP over the last minute.
	 *
	 * @param DoliDB $db Database handler
	 * @param string $ip Remote IP
	 * @return int
	 */
	private static function recentAnonymous($db, $ip)
	{
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."emmcp_auth_log";
		$sql .= " WHERE event = 'mcp_auth' AND fk_user IS NULL";
		$sql .= " AND ip = '".$db->escape(substr($ip, 0, 45))."'";
		$sql .= " AND datec > '".$db->idate(dol_now() - 60)."'";
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return (int) $obj->nb;
		}

		return 0;
	}
}
