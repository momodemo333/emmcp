-- Covers the rate-limit count, which reads by user over a time window on every
-- single call and is the one query that must not degrade as the table grows.
ALTER TABLE llx_emmcp_mcp_log ADD INDEX idx_emmcp_mcp_log_user_date (entity, fk_user, date_creation);
ALTER TABLE llx_emmcp_mcp_log ADD INDEX idx_emmcp_mcp_log_date (date_creation);
