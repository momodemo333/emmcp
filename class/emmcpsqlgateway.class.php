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
 * \file    class/emmcpsqlgateway.class.php
 * \ingroup emmcp
 * \brief   Executes a validated read-only query behind engine-level guarantees.
 *
 * The text validator can in principle be outsmarted; the storage engine cannot.
 * Three measures, in order of importance:
 *
 *  - A dedicated connection. Running on Dolibarr's shared $db would put the
 *    whole request inside a read-only transaction — starting with the audit
 *    write that must record the query.
 *  - START TRANSACTION READ ONLY, so MySQL and MariaDB refuse any write at the
 *    engine level whatever the textual trickery.
 *  - mysqli_query, which accepts exactly one statement per call (only
 *    multi_query accepts more), removing stacked statements at the transport
 *    layer rather than by inspection.
 *
 * A statement timeout is set per session so a runaway report cannot pin a
 * connection, and results are capped by both row count and byte size.
 */
class EmmcpSqlGateway
{
	/** @var mysqli|null */
	private $link = null;

	/** @var string */
	private $database = '';

	/** @var int */
	private $timeoutSeconds;

	/** @var int */
	private $maxRows;

	/** @var int */
	private $maxBytes;

	/**
	 * @param int $timeoutSeconds Statement timeout
	 * @param int $maxRows        Hard row cap applied while fetching
	 * @param int $maxBytes       Hard response size cap
	 */
	public function __construct($timeoutSeconds = 5, $maxRows = 200, $maxBytes = 262144)
	{
		$this->timeoutSeconds = max(1, (int) $timeoutSeconds);
		$this->maxRows = max(1, (int) $maxRows);
		$this->maxBytes = max(1024, (int) $maxBytes);
	}

	/**
	 * Run an already-validated read-only query.
	 *
	 * @param  string $validatedSql Query that passed SqlReadOnlyValidator
	 * @return array{columns: string[], rows: array<int, array<string, mixed>>, row_count: int, truncated: bool, duration_ms: int, bytes: int}
	 * @throws Exception on connection or execution failure
	 */
	public function runSelect($validatedSql)
	{
		$this->assertLooksLikeARead($validatedSql);

		$link = $this->connect();
		$started = microtime(true);

		try {
			// Engine-level read-only guarantee: any write attempt that slipped
			// past the validator fails here.
			if (!$link->query('START TRANSACTION READ ONLY')) {
				throw new Exception('Could not open a read-only transaction.');
			}

			// Unbuffered: MYSQLI_USE_RESULT streams rows instead of pulling the
			// whole result set into PHP memory first. With the buffered default
			// a query matching a million rows is fully materialised before the
			// row cap is ever consulted, so the cap protects the response while
			// the process has already paid for everything.
			$result = $link->real_query($validatedSql)
				? $link->use_result()
				: false;
			if ($result === false) {
				throw new Exception('Query execution failed: '.$link->error);
			}

			$columns = array();
			$rows = array();
			$bytes = 0;
			$truncated = false;

			if ($result instanceof mysqli_result) {
				foreach ($result->fetch_fields() as $field) {
					$columns[] = $field->name;
				}

				while ($row = $result->fetch_assoc()) {
					if (count($rows) >= $this->maxRows) {
						$truncated = true;
						break;
					}
					$encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
					$bytes += is_string($encoded) ? strlen($encoded) : 0;
					if ($bytes > $this->maxBytes) {
						$truncated = true;
						break;
					}
					$rows[] = $row;
				}

				// An unbuffered result must be drained before the connection
				// accepts another statement, or ROLLBACK fails with "commands
				// out of sync". free() discards the remainder server-side.
				$result->free();
			}

			$link->query('ROLLBACK');

			return array(
				'columns' => $columns,
				'rows' => $rows,
				'row_count' => count($rows),
				'truncated' => $truncated,
				'duration_ms' => (int) round((microtime(true) - $started) * 1000),
				'bytes' => $bytes,
			);
		} catch (Throwable $e) {
			// Never leave the dedicated connection inside a transaction.
			@$link->query('ROLLBACK');
			throw $e;
		} finally {
			$this->disconnect();
		}
	}

	/**
	 * List readable tables and their columns.
	 *
	 * This is the only place information_schema is queried, and it is done with
	 * bound parameters rather than by interpolating caller input. Denylisted
	 * tables are filtered out by the caller through the shared policy.
	 *
	 * @param  string|null  $tableFilter    Optional table name or prefix
	 * @param  callable     $isTableAllowed Predicate applied to each table name
	 * @param  int          $maxTables      Cap on the number of described tables
	 * @return array{tables: array<string, array<int, array{name: string, type: string, nullable: bool, key: string}>>, truncated: bool}
	 * @throws Exception
	 */
	public function describeSchema($tableFilter, $isTableAllowed, $maxTables = 200)
	{
		$link = $this->connect();

		try {
			$sql = 'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY'
				.' FROM information_schema.COLUMNS'
				.' WHERE TABLE_SCHEMA = ?';
			$params = array($this->database);
			$types = 's';

			if (is_string($tableFilter) && $tableFilter !== '') {
				$sql .= ' AND TABLE_NAME LIKE ?';
				$params[] = $tableFilter.'%';
				$types .= 's';
			}
			$sql .= ' ORDER BY TABLE_NAME ASC, ORDINAL_POSITION ASC';

			$stmt = $link->prepare($sql);
			if ($stmt === false) {
				throw new Exception('Could not prepare the schema query.');
			}
			$stmt->bind_param($types, ...$params);
			if (!$stmt->execute()) {
				throw new Exception('Could not read the schema.');
			}

			$result = $stmt->get_result();
			$tables = array();
			$truncated = false;

			while ($row = $result->fetch_assoc()) {
				$table = (string) $row['TABLE_NAME'];
				if (!call_user_func($isTableAllowed, $table)) {
					continue;
				}
				if (!isset($tables[$table]) && count($tables) >= $maxTables) {
					$truncated = true;
					continue;
				}
				$tables[$table][] = array(
					'name' => (string) $row['COLUMN_NAME'],
					'type' => (string) $row['COLUMN_TYPE'],
					'nullable' => ($row['IS_NULLABLE'] === 'YES'),
					'key' => (string) $row['COLUMN_KEY'],
				);
			}
			$stmt->close();

			return array('tables' => $tables, 'truncated' => $truncated);
		} finally {
			$this->disconnect();
		}
	}

	/**
	 * Last-resort guard before anything reaches the server.
	 *
	 * READ ONLY transactions stop DML but NOT DDL: CREATE, DROP and ALTER cause
	 * an implicit commit and run regardless — verified against MariaDB, where a
	 * CREATE TABLE issued inside a read-only transaction succeeds. The engine
	 * therefore does not, on its own, make this connection read-only.
	 *
	 * Real DDL protection needs a MySQL account holding only SELECT, which
	 * EMMCP_SQL_DB_USER allows an administrator to configure and which the
	 * documentation recommends for production. Since that cannot be required of
	 * a Dolistore customer, this cheap keyword check stands between the caller
	 * and the server as well.
	 *
	 * It is not the real validation — SqlReadOnlyValidator in the MCP package
	 * is, with a lexer and a parse tree. This only ensures nothing but a read
	 * ever reaches the driver, including on a future call path that forgets to
	 * validate first.
	 *
	 * @param  string $sql Query about to run
	 * @return void
	 * @throws Exception
	 */
	private function assertLooksLikeARead($sql)
	{
		if (preg_match('/^\s*\(*\s*(SELECT|WITH)\b/i', (string) $sql) !== 1) {
			throw new Exception('Only SELECT and WITH statements can be executed by this gateway.');
		}
	}

	/**
	 * Open the dedicated connection.
	 *
	 * @return mysqli
	 * @throws Exception
	 */
	private function connect()
	{
		global $dolibarr_main_db_host, $dolibarr_main_db_port, $dolibarr_main_db_name;
		global $dolibarr_main_db_user, $dolibarr_main_db_pass, $dolibarr_main_db_type;

		if (!empty($dolibarr_main_db_type) && strpos($dolibarr_main_db_type, 'mysql') === false) {
			throw new Exception('Read-only SQL access requires MySQL or MariaDB.');
		}
		if (!class_exists('mysqli')) {
			throw new Exception('The mysqli extension is required for read-only SQL access.');
		}

		// A dedicated account is required, with no fallback to Dolibarr's own.
		//
		// Falling back meant the "read-only" feature ran with full DML and DDL
		// privileges, leaving nothing but application-level checks between a
		// caller and a write — and a read-only transaction does not stop DDL.
		// Requiring the account is the one measure that makes the server, not
		// this code, the thing enforcing read-only.
		$user = getDolGlobalString('EMMCP_SQL_DB_USER');
		$pass = getDolGlobalString('EMMCP_SQL_DB_PASSWORD');
		if ($user === '') {
			throw new Exception(
				'No dedicated read-only database account is configured. '
					.'Set one in the emMCP "SQL access" admin tab.'
			);
		}
		if ($user === $dolibarr_main_db_user) {
			throw new Exception(
				'The configured SQL account is the Dolibarr application account. '
					.'Use a separate account granted SELECT only.'
			);
		}

		$port = !empty($dolibarr_main_db_port) ? (int) $dolibarr_main_db_port : 3306;
		$this->database = (string) $dolibarr_main_db_name;

		$link = @new mysqli($dolibarr_main_db_host, $user, $pass, $this->database, $port);
		if ($link->connect_errno) {
			// The message can carry the host and user: log it, do not surface it.
			dol_syslog('[EMMCP] SQL gateway connection failed: '.$link->connect_error, LOG_ERR);
			throw new Exception('Could not open the reporting database connection.');
		}
		$link->set_charset('utf8mb4');
		$this->link = $link;

		// Both throw rather than degrade: a session without a timeout, or one
		// whose SQL mode invalidates the validator's reading of the statement,
		// must not run a query at all.
		try {
			$this->assertNotApplicationAccount($link, $dolibarr_main_db_user);
			$this->applySafeSqlMode($link);
			$this->applyStatementTimeout($link);
		} catch (Throwable $e) {
			$this->disconnect();
			throw $e;
		}

		return $link;
	}

	/**
	 * Install the statement timeout, or refuse to run at all.
	 *
	 * MariaDB and MySQL spell it differently and in different units. Failure
	 * used to be swallowed on the grounds that the row and byte caps still
	 * bounded the damage — which was simply wrong: those caps bound the
	 * *output*, and they are applied while fetching, i.e. after the server has
	 * done the work. A cartesian join or a sort over a large table can burn
	 * minutes of CPU before returning its first row. The timeout is the only
	 * bound on execution time, so a connection without one is refused.
	 *
	 * @param  mysqli $link Connection
	 * @return void
	 * @throws Exception
	 */
	private function applyStatementTimeout($link)
	{
		$isMariaDb = stripos((string) $link->server_info, 'mariadb') !== false;

		if ($isMariaDb) {
			$ok = $link->query('SET SESSION max_statement_time = '.((int) $this->timeoutSeconds));
		} else {
			$ok = $link->query('SET SESSION MAX_EXECUTION_TIME = '.((int) $this->timeoutSeconds * 1000));
		}

		if (!$ok) {
			dol_syslog('[EMMCP] Could not set the SQL statement timeout: '.$link->error, LOG_ERR);
			throw new Exception('Could not apply the query timeout; refusing to run without it.');
		}
	}

	/**
	 * Confirm the session is not, in fact, the application account.
	 *
	 * The constant may name a different account than the one the server ends up
	 * using — a MySQL proxy, an authentication plugin mapping, or simply an
	 * administrator who set the constant to an alias. CURRENT_USER() reports
	 * what the server actually authenticated, which is the only answer that
	 * matters here.
	 *
	 * @param  mysqli $link       Connection
	 * @param  string $appDbUser  Dolibarr's own database user
	 * @return void
	 * @throws Exception
	 */
	private function assertNotApplicationAccount($link, $appDbUser)
	{
		$resql = $link->query('SELECT CURRENT_USER() AS u');
		$row = $resql ? $resql->fetch_assoc() : null;
		if ($resql) {
			$resql->free();
		}

		$current = (string) ($row['u'] ?? '');
		if ($current === '') {
			throw new Exception('Could not identify the database account in use.');
		}

		// CURRENT_USER() is "name@host"; compare the account name only.
		$accountName = strpos($current, '@') !== false ? substr($current, 0, strpos($current, '@')) : $current;
		$accountName = trim($accountName, "'\"");

		if ($accountName === (string) $appDbUser) {
			dol_syslog('[EMMCP] SQL gateway resolved to the application account; refusing', LOG_ERR);
			throw new Exception(
				'The reporting connection resolved to the Dolibarr application account. '
					.'Configure a separate account granted SELECT only.'
			);
		}
	}

	/**
	 * Pin a SQL mode the lexer's assumptions actually hold under.
	 *
	 * The validator reads a statement the way a default MySQL session would.
	 * A session inheriting ANSI_QUOTES reads "api_key" as an identifier where
	 * the lexer saw a string literal, so a masked-out literal becomes a live
	 * column reference. NO_BACKSLASH_ESCAPES moves the string boundaries the
	 * lexer computed. Either one makes validation describe a different
	 * statement from the one that runs, so the mode is set explicitly and a
	 * failure to set it aborts.
	 *
	 * @param  mysqli $link Connection
	 * @return void
	 * @throws Exception
	 */
	private function applySafeSqlMode($link)
	{
		if (!$link->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'")) {
			dol_syslog('[EMMCP] Could not normalise sql_mode: '.$link->error, LOG_ERR);
			throw new Exception('Could not normalise the SQL mode; refusing to run.');
		}

		// Trust but verify: a proxy or a server-side hook could have altered it.
		$resql = $link->query('SELECT @@session.sql_mode AS mode');
		$row = $resql ? $resql->fetch_assoc() : null;
		if ($resql) {
			$resql->free();
		}
		$mode = strtoupper((string) ($row['mode'] ?? ''));

		if (strpos($mode, 'ANSI_QUOTES') !== false || strpos($mode, 'NO_BACKSLASH_ESCAPES') !== false) {
			dol_syslog('[EMMCP] Unsafe sql_mode still active: '.$mode, LOG_ERR);
			throw new Exception('The database session uses a SQL mode incompatible with query validation.');
		}
	}

	/**
	 * Effective session SQL mode, for the integration checks.
	 *
	 * @return string
	 * @throws Exception
	 */
	public function probeSessionSqlMode()
	{
		$link = $this->connect();
		try {
			$resql = $link->query('SELECT @@session.sql_mode AS mode');
			$row = $resql ? $resql->fetch_assoc() : null;
			if ($resql) {
				$resql->free();
			}

			return strtoupper((string) ($row['mode'] ?? ''));
		} finally {
			$this->disconnect();
		}
	}

	/**
	 * Effective statement timeout in seconds, for the integration checks.
	 *
	 * @return float
	 * @throws Exception
	 */
	public function probeStatementTimeout()
	{
		$link = $this->connect();
		try {
			$isMariaDb = stripos((string) $link->server_info, 'mariadb') !== false;
			$sql = $isMariaDb
				? 'SELECT @@session.max_statement_time AS t'
				: 'SELECT @@session.max_execution_time / 1000 AS t';
			$resql = $link->query($sql);
			$row = $resql ? $resql->fetch_assoc() : null;
			if ($resql) {
				$resql->free();
			}

			return (float) ($row['t'] ?? 0);
		} finally {
			$this->disconnect();
		}
	}

	/**
	 * @return void
	 */
	private function disconnect()
	{
		if ($this->link instanceof mysqli) {
			@$this->link->close();
			$this->link = null;
		}
	}
}
