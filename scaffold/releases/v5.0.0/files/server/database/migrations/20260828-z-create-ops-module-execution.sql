-- peanut-release: 3.0.10
-- CR12 keeps delivery-owned Module package changes in the canonical Ops task
-- ledger. HTTP may submit only an opaque, deployment-staged request key.

ALTER TABLE `pa_ops_task`
  DROP CHECK `chk_ops_task_type`,
  ADD CONSTRAINT `chk_ops_task_type`
    CHECK (`task_type` IN ('ops.backup.create','ops.restore.verify','ops.upgrade.execute','ops.module.execute'));

CREATE TABLE `pa_ops_module_request` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_key` CHAR(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `environment` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_resource_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `delivery_resource_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `package_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `archive_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `signature_key_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `confirm_plan_json` JSON NULL,
  `confirm_plan_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `request_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `claimed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_module_request_key` (`request_key`),
  UNIQUE KEY `uk_ops_module_request_sha` (`request_sha256`),
  CONSTRAINT `chk_ops_module_request_operation`
    CHECK (`operation` IN ('update','retire','purge')),
  CONSTRAINT `chk_ops_module_request_state`
    CHECK (`state` IN ('prepared','claimed')),
  CONSTRAINT `chk_ops_module_request_archive`
    CHECK ((`operation` = 'update') = (`archive_sha256` IS NOT NULL)),
  CONSTRAINT `chk_ops_module_request_plan`
    CHECK ((`operation` IN ('retire','purge')) = (`confirm_plan_json` IS NOT NULL AND `confirm_plan_sha256` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_ops_module_execution` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_key` CHAR(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_step` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `backup_task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `backup_reference_key` CHAR(39) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `restore_task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `restore_evidence_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `maintenance_key` CHAR(44) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `maintenance_revision` BIGINT UNSIGNED NULL,
  `operation_result_json` JSON NULL,
  `operation_result_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `recovery_pointer_json` JSON NULL,
  `recovery_pointer_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_module_execution_task` (`task_key`),
  UNIQUE KEY `uk_ops_module_execution_request` (`request_key`),
  CONSTRAINT `fk_ops_module_execution_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_request` FOREIGN KEY (`request_key`) REFERENCES `pa_ops_module_request` (`request_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_backup_task` FOREIGN KEY (`backup_task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_backup_reference` FOREIGN KEY (`backup_reference_key`) REFERENCES `pa_ops_backup_evidence` (`backup_reference_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_restore_task` FOREIGN KEY (`restore_task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_maintenance` FOREIGN KEY (`maintenance_key`) REFERENCES `pa_ops_maintenance_window` (`maintenance_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_module_execution_step`
    CHECK (`current_step` IN ('preflight','backup','restore_verification','maintenance','execution','smoke','recovery_pointer','completed')),
  CONSTRAINT `chk_ops_module_execution_recovery`
    CHECK ((`recovery_pointer_json` IS NULL) = (`recovery_pointer_sha256` IS NULL)),
  CONSTRAINT `chk_ops_module_execution_result`
    CHECK ((`operation_result_json` IS NULL) = (`operation_result_sha256` IS NULL)),
  CONSTRAINT `chk_ops_module_execution_completion`
    CHECK ((`current_step` = 'completed') = (`completed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `pa_permission`
  (`key`,`module_key`,`type`,`name`,`description`,`risk_level`,`status`,`manifest_version`,`created_at`,`updated_at`,`retired_at`)
VALUES
  ('platform.ops.module.manage','peanut.admin','api','platform.ops.module.manage','Submit a deployment-staged Module package operation.','critical','active','cr12-v1',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),NULL)
ON DUPLICATE KEY UPDATE
  `module_key`=VALUES(`module_key`),`type`=VALUES(`type`),`name`=VALUES(`name`),
  `description`=VALUES(`description`),`risk_level`=VALUES(`risk_level`),
  `status`='active',`manifest_version`=VALUES(`manifest_version`),
  `updated_at`=VALUES(`updated_at`),`retired_at`=NULL;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`,`permission_id`,`granted_at`)
SELECT role.`id`, permission.`id`, UTC_TIMESTAMP(3)
FROM `pa_platform_role` role
JOIN `pa_permission` permission ON permission.`key`='platform.ops.module.manage'
WHERE role.`key`='platform.bootstrap-owner' AND role.`is_builtin`=1 AND role.`status`='active';
