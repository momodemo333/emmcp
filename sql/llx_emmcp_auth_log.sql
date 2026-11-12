-- emMCP connection log: every OAuth step (registration, consent, token) and
-- every refused MCP request, with the precise reason. Read from the
-- Diagnostic tab; kept 30 days.
CREATE TABLE llx_emmcp_auth_log(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	datec datetime NOT NULL,
	event varchar(32) NOT NULL,
	success tinyint DEFAULT 0 NOT NULL,
	reason varchar(255) NULL,
	client_id varchar(80) NULL,
	fk_user integer NULL,
	ip varchar(45) NULL,
	user_agent varchar(255) NULL
) ENGINE=innodb;
