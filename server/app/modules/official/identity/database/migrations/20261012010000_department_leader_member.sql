SET @peanut_department_leader_member_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `pa_department` ADD COLUMN `leader_member_id` BIGINT UNSIGNED NULL AFTER `leader`, ADD INDEX `idx_department_leader_member` (`tenant_id`, `leader_member_id`)',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pa_department' AND COLUMN_NAME = 'leader_member_id'
);
PREPARE peanut_department_leader_member_stmt FROM @peanut_department_leader_member_ddl;
EXECUTE peanut_department_leader_member_stmt;
DEALLOCATE PREPARE peanut_department_leader_member_stmt;
