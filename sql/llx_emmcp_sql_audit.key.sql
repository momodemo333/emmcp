ALTER TABLE llx_emmcp_sql_audit ADD INDEX idx_emmcp_sql_audit_user (fk_user);
ALTER TABLE llx_emmcp_sql_audit ADD INDEX idx_emmcp_sql_audit_date (date_creation);
ALTER TABLE llx_emmcp_sql_audit ADD INDEX idx_emmcp_sql_audit_entity (entity);
