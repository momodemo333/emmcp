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
 * \file    class/emmcpsqlcapability.class.php
 * \ingroup emmcp
 * \brief   The database capability emMCP grants to the MCP SQL tools.
 *
 * IMPORTANT: this file implements an interface from the dolibarr-mcp-server
 * package, so it must only be required *after* that package's autoloader.
 * Loading it earlier is a fatal error, not a recoverable one.
 *
 * The capability is built only once EmmcpSqlPermissions has cleared the caller.
 * Its mere existence is the authorisation: the MCP runtime does not re-check
 * anything, and an entry point that grants nothing leaves the tools
 * undiscoverable.
 */
class EmmcpSqlCapability implements \DolibarrMcp\Sql\SqlCapabilityInterface
{
	/** @var DoliDB */
	private $db;

	/** @var Conf */
	private $conf;

	/** @var User */
	private $user;

	/** @var string */
	private $source;

	/** @var \DolibarrMcp\Sql\SqlPolicy */
	private $policy;

	/** @var EmmcpSqlGateway */
	private $gateway;

	/** @var EmmcpSqlAudit */
	private $audit;

	/**
	 * @param DoliDB $db     Database handler
	 * @param Conf   $conf   Dolibarr configuration
	 * @param User   $user   Authorised caller
	 * @param string $source 'mcp' or 'dalfred'
	 */
	public function __construct($db, $conf, $user, $source = 'mcp')
	{
		$this->db = $db;
		$this->conf = $conf;
		$this->user = $user;
		$this->source = $source;

		$this->policy = new \DolibarrMcp\Sql\SqlPolicy(MAIN_DB_PREFIX, array(
			'maxRows' => getDolGlobalInt('EMMCP_SQL_MAX_ROWS') ?: 200,
			'timeoutSeconds' => getDolGlobalInt('EMMCP_SQL_TIMEOUT') ?: 5,
			'maxBytes' => getDolGlobalInt('EMMCP_SQL_MAX_BYTES') ?: 262144,
		));

		$this->gateway = new EmmcpSqlGateway(
			$this->policy->timeoutSeconds(),
			$this->policy->maxRows(),
			$this->policy->maxBytes()
		);

		$this->audit = new EmmcpSqlAudit($db, $conf);
	}

	/**
	 * @return \DolibarrMcp\Sql\SqlPolicy
	 */
	public function getPolicy(): \DolibarrMcp\Sql\SqlPolicy
	{
		return $this->policy;
	}

	/**
	 * @param  string|null $tableFilter Optional table name or prefix
	 * @return array{tables: array<string, array<int, array{name: string, type: string, nullable: bool, key: string}>>, truncated: bool}
	 */
	public function describeSchema(?string $tableFilter = null): array
	{
		$policy = $this->policy;

		return $this->gateway->describeSchema(
			$tableFilter,
			static function ($table) use ($policy) {
				return $policy->isTableAllowed($table);
			}
		);
	}

	/**
	 * Record an attempt refused before any database access.
	 *
	 * Metadata only, and never raises: the caller is already being refused, and
	 * a logging failure must not replace the reason with a different error.
	 *
	 * @param  string $sql       Statement as submitted
	 * @param  string $errorCode Stable reason
	 * @param  string $operation 'query' or 'schema'
	 * @return void
	 */
	public function auditRefusal(string $sql, string $errorCode, string $operation = 'query'): void
	{
		try {
			$this->audit->record(
				(int) $this->user->id,
				$sql,
				0,
				0,
				0,
				false,
				$errorCode,
				$this->source,
				$operation
			);
		} catch (Throwable $e) {
			dol_syslog('[EMMCP] Could not audit a refused SQL attempt: '.$e->getMessage(), LOG_ERR);
		}
	}

	/**
	 * Record a schema introspection: it reveals which modules an instance runs.
	 *
	 * The caller withholds the schema when this returns false, so the outcome
	 * is reported honestly rather than swallowed.
	 *
	 * @param  string|null $tableFilter Filter as submitted
	 * @param  int         $tableCount  Tables described
	 * @param  int         $durationMs  Duration
	 * @return bool                     True when the entry was written
	 */
	public function auditSchemaAccess(?string $tableFilter, int $tableCount, int $durationMs): bool
	{
		try {
			return (bool) $this->audit->record(
				(int) $this->user->id,
				'[schema] '.($tableFilter !== null && $tableFilter !== '' ? $tableFilter : '*'),
				$durationMs,
				$tableCount,
				0,
				true,
				null,
				$this->source,
				'schema'
			);
		} catch (Throwable $e) {
			dol_syslog('[EMMCP] Could not audit a schema introspection: '.$e->getMessage(), LOG_ERR);

			return false;
		}
	}

	/**
	 * @param  string $validatedSql Query that passed SqlReadOnlyValidator
	 * @return \DolibarrMcp\Sql\SqlExecutionResult
	 * @throws Exception
	 */
	public function runSelect(string $validatedSql): \DolibarrMcp\Sql\SqlExecutionResult
	{
		$started = microtime(true);

		try {
			$outcome = $this->gateway->runSelect($validatedSql);
		} catch (Throwable $e) {
			dol_syslog('[EMMCP] SQL query failed for user '.((int) $this->user->id).': '.$e->getMessage(), LOG_ERR);
			$this->audit->record(
				(int) $this->user->id,
				$validatedSql,
				(int) round((microtime(true) - $started) * 1000),
				0,
				0,
				false,
				'SQL_EXECUTION_ERROR',
				$this->source
			);

			throw $e;
		}

		// An untraceable query is worse than a refused one: if the trail cannot
		// be written, the result is not returned.
		$recorded = $this->audit->record(
			(int) $this->user->id,
			$validatedSql,
			$outcome['duration_ms'],
			$outcome['row_count'],
			$outcome['bytes'],
			true,
			null,
			$this->source
		);
		if (!$recorded) {
			throw new Exception('The audit trail could not be written; the query result is withheld.');
		}

		return new \DolibarrMcp\Sql\SqlExecutionResult(
			$outcome['columns'],
			$outcome['rows'],
			$outcome['row_count'],
			$outcome['truncated'],
			$outcome['duration_ms'],
			$outcome['bytes']
		);
	}
}
