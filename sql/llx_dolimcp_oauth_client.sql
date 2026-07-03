-- DoliMCP OAuth 2.1 registered clients (RFC 7591 Dynamic Client Registration)
CREATE TABLE llx_dolimcp_oauth_client(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	client_id varchar(80) NOT NULL,
	client_secret_hash varchar(128) NULL,
	client_name varchar(255) NULL,
	redirect_uris text NOT NULL,
	token_endpoint_auth_method varchar(32) DEFAULT 'none',
	entity integer DEFAULT 1 NOT NULL,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
