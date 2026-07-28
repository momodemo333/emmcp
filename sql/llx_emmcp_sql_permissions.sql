-- emMCP read-only SQL access: per-user opt-in.
-- A missing row means "not allowed": absence of an entry is a refusal, never
-- a default grant. The global flag EMMCP_SQL_ENABLED and the Dolibarr right
-- emmcp->sqlquery->read must both hold as well.
CREATE TABLE llx_emmcp_sql_permissions(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	sql_enabled tinyint DEFAULT 0 NOT NULL,
	date_creation datetime NOT NULL,
	date_modification datetime NULL,
	fk_user_creat integer NULL,
	fk_user_modif integer NULL
) ENGINE=innodb;
