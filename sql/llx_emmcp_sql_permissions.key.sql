ALTER TABLE llx_emmcp_sql_permissions ADD UNIQUE INDEX uk_emmcp_sql_perm_user (entity, fk_user);
ALTER TABLE llx_emmcp_sql_permissions ADD CONSTRAINT fk_emmcp_sql_perm_user FOREIGN KEY (fk_user) REFERENCES llx_user (rowid) ON DELETE CASCADE;
