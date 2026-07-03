ALTER TABLE llx_dolimcp_oauth_token ADD UNIQUE INDEX uk_dolimcp_oauth_token_hash (token_hash);
ALTER TABLE llx_dolimcp_oauth_token ADD INDEX idx_dolimcp_oauth_token_user (fk_user);
ALTER TABLE llx_dolimcp_oauth_token ADD INDEX idx_dolimcp_oauth_token_expires (expires_at);
