-- emMCP: one row per MCP call from an external client.
--
-- Serves two purposes at once: support needs to see what an agent actually did
-- when a customer reports a problem, and an administrator needs to see whether
-- someone is pulling more data than their work requires. The rate limiter
-- counts these same rows, so the number it enforces and the trail an admin
-- reads can never disagree.
--
-- Tool RESULTS are deliberately absent: they hold the business data itself, and
-- a log that copies what it is meant to police becomes a second copy to guard.
CREATE TABLE llx_emmcp_mcp_log(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	date_creation datetime NOT NULL,
	method varchar(64) NOT NULL,
	tool_name varchar(128) NULL,
	arguments text NULL,
	duration_ms integer DEFAULT 0 NOT NULL,
	success tinyint DEFAULT 1 NOT NULL,
	error_message varchar(255) NULL,
	client_name varchar(128) NULL
) ENGINE=innodb;
