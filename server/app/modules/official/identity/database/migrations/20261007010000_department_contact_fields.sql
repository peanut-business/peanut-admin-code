SET @peanut_department_leader_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `pa_department` ADD COLUMN `leader` VARCHAR(50) NOT NULL DEFAULT '''' AFTER `name`',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pa_department' AND COLUMN_NAME = 'leader'
);
PREPARE peanut_department_leader_stmt FROM @peanut_department_leader_ddl;
EXECUTE peanut_department_leader_stmt;
DEALLOCATE PREPARE peanut_department_leader_stmt;

SET @peanut_department_mobile_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `pa_department` ADD COLUMN `mobile` VARCHAR(20) NOT NULL DEFAULT '''' AFTER `leader`',
    'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pa_department' AND COLUMN_NAME = 'mobile'
);
PREPARE peanut_department_mobile_stmt FROM @peanut_department_mobile_ddl;
EXECUTE peanut_department_mobile_stmt;
DEALLOCATE PREPARE peanut_department_mobile_stmt;
