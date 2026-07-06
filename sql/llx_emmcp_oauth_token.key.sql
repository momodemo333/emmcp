ALTER TABLE llx_emmcp_oauth_token ADD UNIQUE INDEX uk_emmcp_oauth_token_hash (token_hash);
ALTER TABLE llx_emmcp_oauth_token ADD INDEX idx_emmcp_oauth_token_user (fk_user);
ALTER TABLE llx_emmcp_oauth_token ADD INDEX idx_emmcp_oauth_token_expires (expires_at);
