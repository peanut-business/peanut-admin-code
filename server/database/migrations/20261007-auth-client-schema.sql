-- peanut-release: 5.0.1
-- Align fresh and existing APP tables with the native TenantClient contract.
-- Replace the named CHECK atomically: existing rows must satisfy the new CHECK.
-- No session/token rows are rewritten and no tenant/client binding is changed.
SET @peanut_auth_client_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `pa_tenant_session` ADD CONSTRAINT `chk_tenant_session_client` CHECK (CHAR_LENGTH(`client_key`) BETWEEN 1 AND 64 AND REGEXP_LIKE(`client_key`, ''^[a-z]'', ''c'') AND NOT REGEXP_LIKE(`client_key`, ''[^a-z0-9-]'', ''c''))', 'ALTER TABLE `pa_tenant_session` DROP CHECK `chk_tenant_session_client`, ADD CONSTRAINT `chk_tenant_session_client` CHECK (CHAR_LENGTH(`client_key`) BETWEEN 1 AND 64 AND REGEXP_LIKE(`client_key`, ''^[a-z]'', ''c'') AND NOT REGEXP_LIKE(`client_key`, ''[^a-z0-9-]'', ''c''))')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pa_tenant_session'
    AND CONSTRAINT_NAME = 'chk_tenant_session_client'
    AND CONSTRAINT_TYPE = 'CHECK'
);
PREPARE peanut_auth_client_stmt FROM @peanut_auth_client_ddl;
EXECUTE peanut_auth_client_stmt;
DEALLOCATE PREPARE peanut_auth_client_stmt;

-- Equality on IP followed by the rate-window range uses the original native index pair.
-- Retain an exact existing index; converge an absent or differently defined named index.
SET @peanut_auth_ip_ddl = (
  SELECT CASE
    WHEN COUNT(*) = 0 THEN 'ALTER TABLE `pa_auth_security_event` ADD KEY `idx_auth_event_ip` (`ip_address`, `occurred_at`)'
    WHEN COUNT(*) = 2
      AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') = 'ip_address,occurred_at'
      AND MIN(NON_UNIQUE) = 1 AND MAX(NON_UNIQUE) = 1
      AND SUM(SUB_PART IS NOT NULL) = 0
      AND MIN(INDEX_TYPE) = 'BTREE' AND MAX(INDEX_TYPE) = 'BTREE'
      AND MIN(IS_VISIBLE) = 'YES' AND MAX(IS_VISIBLE) = 'YES'
      THEN 'SELECT 1'
    ELSE 'ALTER TABLE `pa_auth_security_event` DROP INDEX `idx_auth_event_ip`, ADD KEY `idx_auth_event_ip` (`ip_address`, `occurred_at`)'
  END
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pa_auth_security_event'
    AND INDEX_NAME = 'idx_auth_event_ip'
);
PREPARE peanut_auth_ip_stmt FROM @peanut_auth_ip_ddl;
EXECUTE peanut_auth_ip_stmt;
DEALLOCATE PREPARE peanut_auth_ip_stmt;
SET @peanut_auth_client_ddl = NULL;
SET @peanut_auth_ip_ddl = NULL;
