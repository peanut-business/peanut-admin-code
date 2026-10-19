-- peanut-release: 5.0.2
-- Corrects only the exact metadata rendering used by the original provenance prerequisite.
-- The original migration and any original failed row remain unchanged.
SET @peanut_module_provenance_columns = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pa_module_migration'
    AND IS_NULLABLE = 'YES' AND COLUMN_DEFAULT IS NULL
    AND ((COLUMN_NAME = 'resolution_kind' AND COLUMN_TYPE = 'varchar(16)')
      OR (COLUMN_NAME = 'provenance_json' AND DATA_TYPE = 'json'))
);
SET @peanut_module_provenance_checks = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS t
  JOIN information_schema.CHECK_CONSTRAINTS c
    ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME
  WHERE t.CONSTRAINT_SCHEMA = DATABASE() AND t.TABLE_NAME = 'pa_module_migration'
    AND t.CONSTRAINT_TYPE = 'CHECK' AND t.ENFORCED = 'YES'
    AND ((t.CONSTRAINT_NAME = 'chk_module_migration_resolution'
      AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(c.CHECK_CLAUSE, '`', ''), ' ', ''), CHAR(10), ''), '_utf8mb4', '')) IN (
        '((resolution_kindisnull)or(resolution_kindin(''executed'',''adopted'')))',
        REPLACE('((resolution_kindisnull)or(resolution_kindin(''executed'',''adopted'')))', CHAR(39), CONCAT(CHAR(92), CHAR(39)))))
      OR (t.CONSTRAINT_NAME = 'chk_module_migration_provenance'
      AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(c.CHECK_CLAUSE, '`', ''), ' ', ''), CHAR(10), ''), '_utf8mb4', '')) IN (
        '((resolution_kind<=>''adopted'')=(provenance_jsonisnotnull))',
        REPLACE('((resolution_kind<=>''adopted'')=(provenance_jsonisnotnull))', CHAR(39), CONCAT(CHAR(92), CHAR(39))))))
);
SET @peanut_module_provenance_ddl = IF(
  @peanut_module_provenance_columns = 2 AND @peanut_module_provenance_checks = 2,
  'SELECT 1',
  'ALTER TABLE `pa_module_migration` ADD COLUMN `resolution_kind` VARCHAR(16) NULL DEFAULT NULL, ADD COLUMN `provenance_json` JSON NULL, ADD CONSTRAINT `chk_module_migration_resolution` CHECK (`resolution_kind` IS NULL OR `resolution_kind` IN (''executed'',''adopted'')), ADD CONSTRAINT `chk_module_migration_provenance` CHECK ((`resolution_kind` <=> ''adopted'') = (`provenance_json` IS NOT NULL))'
);
PREPARE peanut_module_provenance_stmt FROM @peanut_module_provenance_ddl;
EXECUTE peanut_module_provenance_stmt;
DEALLOCATE PREPARE peanut_module_provenance_stmt;
SET @peanut_module_provenance_columns = NULL;
SET @peanut_module_provenance_checks = NULL;
SET @peanut_module_provenance_ddl = NULL;
