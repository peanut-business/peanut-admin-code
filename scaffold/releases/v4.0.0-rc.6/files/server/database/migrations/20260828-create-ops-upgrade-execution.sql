-- peanut-release: 3.0.9
-- PC42 keeps application upgrades in the canonical Ops task ledger while
-- recording immutable per-step stop points and the final recovery pointer.

ALTER TABLE `pa_ops_task`
  DROP CHECK `chk_ops_task_type`,
  ADD CONSTRAINT `chk_ops_task_type`
    CHECK (`task_type` IN ('ops.backup.create','ops.restore.verify','ops.upgrade.execute'));

CREATE TABLE `pa_ops_upgrade_execution` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_commit` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_tree` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_release_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `source_application_manifest_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_release_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_commit` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_tree` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_descriptor_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_step` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `backup_task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `backup_reference_key` CHAR(39) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `restore_task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `restore_evidence_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `maintenance_key` CHAR(44) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `maintenance_revision` BIGINT UNSIGNED NULL,
  `recovery_pointer_json` JSON NULL,
  `recovery_pointer_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_upgrade_task` (`task_key`),
  KEY `idx_ops_upgrade_target` (`target_commit`, `created_at`, `id`),
  CONSTRAINT `fk_ops_upgrade_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_upgrade_backup_task` FOREIGN KEY (`backup_task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_upgrade_backup_reference` FOREIGN KEY (`backup_reference_key`) REFERENCES `pa_ops_backup_evidence` (`backup_reference_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_upgrade_restore_task` FOREIGN KEY (`restore_task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_upgrade_maintenance` FOREIGN KEY (`maintenance_key`) REFERENCES `pa_ops_maintenance_window` (`maintenance_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_upgrade_step` CHECK (`current_step` IN ('preflight','backup','restore_verification','maintenance','deployment','smoke','recovery_pointer','completed')),
  CONSTRAINT `chk_ops_upgrade_recovery_pointer` CHECK ((`recovery_pointer_json` IS NULL) = (`recovery_pointer_sha256` IS NULL)),
  CONSTRAINT `chk_ops_upgrade_completion` CHECK ((`current_step` = 'completed') = (`completed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_ops_upgrade_step` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `step_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `step_order` TINYINT UNSIGNED NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `input_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `output_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `started_at` DATETIME(3) NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_upgrade_step` (`task_key`, `step_key`),
  UNIQUE KEY `uk_ops_upgrade_step_order` (`task_key`, `step_order`),
  CONSTRAINT `fk_ops_upgrade_step_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_upgrade_step_key` CHECK (`step_key` IN ('preflight','backup','restore_verification','maintenance','deployment','smoke','recovery_pointer')),
  CONSTRAINT `chk_ops_upgrade_step_status` CHECK (`status` IN ('pending','running','succeeded','failed')),
  CONSTRAINT `chk_ops_upgrade_step_time` CHECK ((`status` = 'pending' AND `started_at` IS NULL AND `completed_at` IS NULL) OR (`status` = 'running' AND `started_at` IS NOT NULL AND `completed_at` IS NULL) OR (`status` IN ('succeeded','failed') AND `started_at` IS NOT NULL AND `completed_at` IS NOT NULL)),
  CONSTRAINT `chk_ops_upgrade_step_error` CHECK ((`status` = 'failed') = (`last_error_code` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `pa_permission`
  (`key`,`module_key`,`type`,`name`,`description`,`risk_level`,`status`,`manifest_version`,`created_at`,`updated_at`,`retired_at`)
VALUES
  ('platform.ops.upgrade.manage','peanut.admin','api','platform.ops.upgrade.manage','Execute the fixed registered application upgrade workflow.','critical','active','pc42-v1',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),NULL)
ON DUPLICATE KEY UPDATE
  `module_key`=VALUES(`module_key`),`type`=VALUES(`type`),`name`=VALUES(`name`),
  `description`=VALUES(`description`),`risk_level`=VALUES(`risk_level`),
  `status`='active',`manifest_version`=VALUES(`manifest_version`),
  `updated_at`=VALUES(`updated_at`),`retired_at`=NULL;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`,`permission_id`,`granted_at`)
SELECT role.`id`, permission.`id`, UTC_TIMESTAMP(3)
FROM `pa_platform_role` role
JOIN `pa_permission` permission ON permission.`key`='platform.ops.upgrade.manage'
WHERE role.`key`='platform.bootstrap-owner' AND role.`is_builtin`=1 AND role.`status`='active';
