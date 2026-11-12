ALTER TABLE llx_emmcp_auth_log ADD INDEX idx_emmcp_auth_log_date (datec);
ALTER TABLE llx_emmcp_auth_log ADD INDEX idx_emmcp_auth_log_entity_date (entity, datec);
