-- DoliMCP OAuth 2.1 grants: authorization codes, access tokens, refresh tokens.
-- Only sha256 hashes are stored, never the token value itself.
CREATE TABLE llx_dolimcp_oauth_token(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	token_type varchar(8) NOT NULL,
	token_hash varchar(64) NOT NULL,
	fk_client integer NOT NULL,
	fk_user integer NOT NULL,
	scope varchar(255) NULL,
	resource varchar(255) NULL,
	code_challenge varchar(128) NULL,
	redirect_uri text NULL,
	expires_at datetime NOT NULL,
	revoked tinyint DEFAULT 0 NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
