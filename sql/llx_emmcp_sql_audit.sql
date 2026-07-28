-- emMCP read-only SQL access: audit trail.
--
-- Query *results* are never stored. The query text itself can carry personal
-- data in a WHERE clause, so it is truncated, and EMMCP_SQL_AUDIT_HASH_ONLY
-- reduces the record to its hash when that is not acceptable.
CREATE TABLE llx_emmcp_sql_audit(
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
	source varchar(16) DEFAULT 'mcp' NOT NULL,
	operation varchar(16) DEFAULT 'query' NOT NULL
) ENGINE=innodb;
