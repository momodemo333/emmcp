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
 * \file    class/emmcpsqlaudit.class.php
 * \ingroup emmcp
 * \brief   Audit trail for read-only SQL access.
 *
 * Results are never recorded. The query text can itself carry personal data in
 * a WHERE clause, so it is truncated, and EMMCP_SQL_AUDIT_HASH_ONLY reduces the
 * record to a hash when even that is unacceptable. The hash is always stored,
 * so repeated queries stay correlatable either way.
 *
 * Writes go through Dolibarr's shared connection, deliberately outside the
 * gateway's read-only transaction.
 */
class EmmcpSqlAudit
{
	const SQL_TEXT_MAX_LENGTH = 2000;

	/** @var DoliDB */
	private $db;

	/** @var Conf */
	private $conf;

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
	 * Record one attempt, successful or not.
	 *
	 * @param  int         $userId     Caller
	 * @param  string      $sql        Query as submitted
	 * @param  int         $durationMs Wall-clock duration
	 * @param  int         $rowCount   Rows returned
	 * @param  int         $bytes      Response size
	 * @param  bool        $success    Outcome
	 * @param  string|null $errorCode  Stable error code on failure
	 * @param  string      $source     'mcp' or 'dalfred'
	 * @return bool                    False when the record could not be written
	 */
	public function record($userId, $sql, $durationMs, $rowCount, $bytes, $success, $errorCode = null, $source = 'mcp', $operation = 'query')
	{
		$entity = isset($this->conf->entity) ? (int) $this->conf->entity : 1;
		$hash = hash('sha256', $sql);

		$storeText = getDolGlobalInt('EMMCP_SQL_AUDIT_HASH_ONLY') !== 1;
		$text = $storeText ? dol_trunc($sql, self::SQL_TEXT_MAX_LENGTH, 'right', 'UTF-8', 1) : null;

		$insert = "INSERT INTO ".MAIN_DB_PREFIX."emmcp_sql_audit";
		$insert .= " (entity, fk_user, date_creation, sql_hash, sql_text, duration_ms, row_count, bytes, success, error_code, source, operation)";
		$insert .= " VALUES (";
		$insert .= ((int) $entity);
		$insert .= ", ".((int) $userId);
		$insert .= ", '".$this->db->escape($this->db->idate(dol_now()))."'";
		$insert .= ", '".$this->db->escape($hash)."'";
		$insert .= ", ".($text === null ? 'NULL' : "'".$this->db->escape($text)."'");
		$insert .= ", ".((int) $durationMs);
		$insert .= ", ".((int) $rowCount);
		$insert .= ", ".((int) $bytes);
		$insert .= ", ".($success ? 1 : 0);
		$insert .= ", ".($errorCode === null ? 'NULL' : "'".$this->db->escape($errorCode)."'");
		$insert .= ", '".$this->db->escape($source)."'";
		$insert .= ", '".$this->db->escape($operation)."'";
		$insert .= ")";

		$resql = $this->db->query($insert);
		if (!$resql) {
			dol_syslog('[EMMCP] Could not write the SQL audit record: '.$this->db->lasterror(), LOG_ERR);

			return false;
		}

		return true;
	}
}
