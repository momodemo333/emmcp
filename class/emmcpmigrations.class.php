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
 * \file    class/emmcpmigrations.class.php
 * \ingroup emmcp
 * \brief   Versioned, idempotent database migrations for emMCP.
 *
 * Dolibarr does not replay init() when a customer merely uploads new module
 * files, so schema changes shipped in a release would never reach an existing
 * install. The usual E-dem answer is a printCommonFooter hook, but that hook
 * only fires on generic pages once the module has registered the 'all' hook
 * context — itself written by init(), which is exactly what did not run.
 *
 * emMCP has no business UI: its only entry points are mcp.php and its admin
 * pages. Migrations are therefore triggered from those entry points directly,
 * which sidesteps the bootstrapping paradox entirely — the schema is current
 * on the first MCP call after the files land, with no hook and no manual
 * disable/enable cycle.
 */
class EmmcpMigrations
{
	/**
	 * Must be bumped together with $this->version in the module descriptor.
	 * Forgetting one of the two silently stops migrations from running.
	 */
	const MODULE_VERSION = '1.3.3';

	const VERSION_CONSTANT = 'EMMCP_DB_VERSION';

	/** @var DoliDB */
	private $db;

	/** @var string[] */
	private $errors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Run pending migrations if the stored schema version is behind the code.
	 *
	 * Called on every entry point, so it must be cheap when there is nothing to
	 * do: a single constant read, then the cached outcome for the rest of the
	 * request.
	 *
	 * What is cached is the *result*, not the fact that an attempt was made.
	 * Flagging "done" before knowing the outcome meant a second call in the
	 * same request reported success after a failed migration — and the callers
	 * act on this: mcp.php would have served requests against the broken schema
	 * it had just refused, and the admin page would have re-enabled the actions
	 * it had just disabled.
	 *
	 * @param  DoliDB $db Database handler
	 * @return bool       True when the schema is up to date
	 */
	public static function runIfNeeded($db)
	{
		if (self::$cachedResult !== null) {
			return self::$cachedResult;
		}

		$migrations = new self($db);
		if (!$migrations->isUpgradeNeeded()) {
			self::$cachedResult = true;

			return self::$cachedResult;
		}

		self::$cachedResult = $migrations->run();

		return self::$cachedResult;
	}

	/**
	 * Clear the per-request cache of runIfNeeded().
	 *
	 * Only for tests, which need to exercise the caching itself; nothing in the
	 * module calls it. A request otherwise resolves the schema state once.
	 *
	 * @return void
	 */
	public static function resetRuntimeCacheForTesting()
	{
		self::$cachedResult = null;
	}

	/**
	 * Outcome of runIfNeeded() for this request: null until decided, then the
	 * real result — including false, which must stay false.
	 *
	 * @var bool|null
	 */
	private static $cachedResult = null;

	/**
	 * Replay every migration. They are idempotent, so this is safe to call at
	 * any time — used by init() and by the admin maintenance action.
	 *
	 * @return bool True on success
	 */
	public function run()
	{
		$ok = true;

		foreach ($this->statements() as $sql) {
			$resql = $this->db->query($sql);
			if (!$resql && !$this->isIgnorableError($this->db->lasterror())) {
				$this->errors[] = $this->db->lasterror();
				dol_syslog('[EMMCP] Migration failed: '.$this->db->lasterror(), LOG_ERR);
				$ok = false;
			}
		}

		$ok = $this->registerPermissions() && $ok;

		if ($ok) {
			$this->setStoredVersion(self::MODULE_VERSION);
		}

		return $ok;
	}

	/**
	 * Whether the stored schema version is behind the code.
	 *
	 * Compared with version_compare rather than for equality, so a database
	 * left at a *newer* version — a downgrade, or a botched rollback — is not
	 * silently rewritten backwards. Equality would also re-run migrations
	 * forever in that case, since the two values never match.
	 *
	 * @return bool
	 */
	public function isUpgradeNeeded()
	{
		$stored = $this->getStoredVersion();
		if ($stored === '') {
			return true;
		}

		return version_compare($stored, self::MODULE_VERSION, '<');
	}

	/**
	 * Register rights declared in the descriptor but missing from the database.
	 *
	 * Rights are written by init() through insert_permissions(), and init() is
	 * not replayed on a files-only upgrade. Without this, the sqlquery right
	 * introduced in 1.2.0 would never exist on an existing install: the admin
	 * page would offer to grant a right nobody can hold, and every SQL call
	 * would be refused with no visible cause.
	 *
	 * insert_permissions() is called with $reinitadminperms = 0 on purpose.
	 * Passing 1 — as DolibarrModules::_init() does — re-grants every module
	 * right to every admin, silently undoing revocations a customer made.
	 *
	 * @return bool
	 */
	private function registerPermissions()
	{
		$sql = "SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."rights_def";
		$sql .= " WHERE module = 'emmcp' AND perms = 'sqlquery'";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj && (int) $obj->n > 0) {
				return true;
			}
		}

		dol_include_once('/emmcp/core/modules/modEmmcp.class.php');
		if (!class_exists('modEmmcp')) {
			$this->errors[] = 'modEmmcp descriptor not found';

			return false;
		}

		// insert_permissions() returns an ERROR COUNT, not a success flag:
		// 0 means it worked. Treating it like the usual Dolibarr ">0 is OK"
		// convention marks a successful run as failed and blocks the schema
		// version from being stored.
		$module = new modEmmcp($this->db);
		$errorCount = $module->insert_permissions(0);
		if ($errorCount > 0) {
			$this->errors[] = 'could not register module rights';
			dol_syslog('[EMMCP] Could not register module rights', LOG_ERR);

			return false;
		}

		dol_syslog('[EMMCP] Registered missing module rights (sqlquery)', LOG_INFO);

		return true;
	}

	/**
	 * Every migration statement, in order. All must be idempotent.
	 *
	 * @return string[]
	 */
	private function statements()
	{
		$p = MAIN_DB_PREFIX;

		return array(
			// 1.2.0 — read-only SQL access
			"CREATE TABLE IF NOT EXISTS ".$p."emmcp_sql_permissions(
				rowid integer AUTO_INCREMENT PRIMARY KEY,
				entity integer DEFAULT 1 NOT NULL,
				fk_user integer NOT NULL,
				sql_enabled tinyint DEFAULT 0 NOT NULL,
				date_creation datetime NOT NULL,
				date_modification datetime NULL,
				fk_user_creat integer NULL,
				fk_user_modif integer NULL
			) ENGINE=innodb",

			"CREATE TABLE IF NOT EXISTS ".$p."emmcp_sql_audit(
				rowid integer AUTO_INCREMENT PRIMARY KEY,
				entity integer DEFAULT 1 NOT NULL,
				fk_user integer NOT NULL,
				date_creation datetime NOT NULL,
				sql_hash varchar(64) NOT NULL,
				sql_text text NULL,
				duration_ms integer DEFAULT 0 NOT NULL,
				row_count integer DEFAULT 0 NOT NULL,
				bytes integer DEFAULT 0 NOT NULL,
				success tinyint DEFAULT 0 NOT NULL,
				error_code varchar(64) NULL,
				source varchar(16) DEFAULT 'mcp' NOT NULL
			) ENGINE=innodb",

			// Distinguishes a refused attempt and a schema read from a query.
			"ALTER TABLE ".$p."emmcp_sql_audit ADD COLUMN operation varchar(16) DEFAULT 'query' NOT NULL",

			"ALTER TABLE ".$p."emmcp_sql_permissions ADD UNIQUE INDEX uk_emmcp_sql_perm_user (entity, fk_user)",
			"ALTER TABLE ".$p."emmcp_sql_audit ADD INDEX idx_emmcp_sql_audit_user (fk_user)",
			"ALTER TABLE ".$p."emmcp_sql_audit ADD INDEX idx_emmcp_sql_audit_date (date_creation)",
			"ALTER TABLE ".$p."emmcp_sql_audit ADD INDEX idx_emmcp_sql_audit_entity (entity)",

			// The .sql files only run on a fresh install, so the foreign key
			// declared there must be created here too for existing databases.
			"ALTER TABLE ".$p."emmcp_sql_permissions ADD CONSTRAINT fk_emmcp_sql_perm_user"
				." FOREIGN KEY (fk_user) REFERENCES ".$p."user (rowid) ON DELETE CASCADE",
		);
	}

	/**
	 * Errors that mean "already applied" rather than "broken".
	 *
	 * MariaDB has no ADD INDEX IF NOT EXISTS, so replaying an index creation
	 * legitimately reports a duplicate.
	 *
	 * @param  string $error Database error message
	 * @return bool
	 */
	private function isIgnorableError($error)
	{
		$harmless = array(
			'duplicate key name',
			'duplicate column name',
			'already exists',
			'check that column/key exists',
			// Replaying the foreign key on a table that already carries it.
			'duplicate foreign key constraint name',
			'errno: 121',
		);

		$error = strtolower((string) $error);
		foreach ($harmless as $needle) {
			if (strpos($error, $needle) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return string Stored schema version, empty when never migrated
	 */
	public function getStoredVersion()
	{
		global $conf;

		$entity = isset($conf->entity) ? (int) $conf->entity : 1;
		$sql = "SELECT value FROM ".MAIN_DB_PREFIX."const";
		$sql .= " WHERE name = '".$this->db->escape(self::VERSION_CONSTANT)."'";
		$sql .= " AND entity = ".((int) $entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return '';
		}
		$obj = $this->db->fetch_object($resql);

		return $obj ? (string) $obj->value : '';
	}

	/**
	 * @param  string $version Version to store
	 * @return void
	 */
	private function setStoredVersion($version)
	{
		global $conf;

		// Never move the recorded version backwards.
		$stored = $this->getStoredVersion();
		if ($stored !== '' && version_compare($stored, $version, '>')) {
			dol_syslog(
				'[EMMCP] Stored schema version '.$stored.' is newer than the code ('.$version.'); leaving it alone',
				LOG_WARNING
			);

			return;
		}

		// mcp.php runs with NOREQUIREHTML and friends; admin.lib.php is not
		// guaranteed to be loaded that early.
		if (!function_exists('dolibarr_set_const')) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		}

		$entity = isset($conf->entity) ? (int) $conf->entity : 1;
		dolibarr_set_const(
			$this->db,
			self::VERSION_CONSTANT,
			$version,
			'chaine',
			0,
			'emMCP database schema version (managed automatically)',
			$entity
		);
	}

	/**
	 * @return string[] Errors collected during the last run
	 */
	public function getErrors()
	{
		return $this->errors;
	}
}
