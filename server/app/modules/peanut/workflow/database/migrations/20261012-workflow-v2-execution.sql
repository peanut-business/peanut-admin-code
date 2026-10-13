-- V2 has no inferred conversion for legacy graphs, votes or responsibility sources.
-- Before applying: the readonly module preflight must report no legacy Workflow rows.
-- This SQL guard is a second fail-closed check. It never deletes application data.
CREATE TEMPORARY TABLE `pa_workflow_v2_empty_guard` (`row_count` BIGINT NOT NULL, CONSTRAINT `chk_workflow_v2_empty_guard` CHECK (`row_count` = 0));
INSERT INTO `pa_workflow_v2_empty_guard` SELECT COUNT(*) FROM `pa_workflow_definition`;
INSERT INTO `pa_workflow_v2_empty_guard` SELECT COUNT(*) FROM `pa_workflow_definition_version`;
INSERT INTO `pa_workflow_v2_empty_guard` SELECT COUNT(*) FROM `pa_workflow_instance`;
INSERT INTO `pa_workflow_v2_empty_guard` SELECT COUNT(*) FROM `pa_workflow_work_item`;
INSERT INTO `pa_workflow_v2_empty_guard` SELECT COUNT(*) FROM `pa_workflow_event`;
DROP TEMPORARY TABLE `pa_workflow_v2_empty_guard`;
ALTER TABLE `pa_workflow_definition` ADD COLUMN `layout_json` JSON NULL, ADD COLUMN `active_version` INT UNSIGNED NULL;
ALTER TABLE `pa_workflow_definition_version` ADD COLUMN `contract_version` INT UNSIGNED NOT NULL DEFAULT 2;
ALTER TABLE `pa_workflow_instance`
 DROP CHECK `chk_workflow_instance_status`, DROP CHECK `chk_workflow_instance_terminal_shape`,
 DROP INDEX `uk_workflow_instance_active_subject`, DROP COLUMN `active_marker`,
 ADD COLUMN `module_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ADD COLUMN `workflow_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ADD COLUMN `business_department_key` VARCHAR(160) NULL,
 ADD COLUMN `snapshot_json` JSON NOT NULL,
 ADD COLUMN `cycle_no` INT UNSIGNED NOT NULL DEFAULT 1,
 ADD COLUMN `result_code` VARCHAR(64) NULL,
 ADD COLUMN `blocked_reason` VARCHAR(500) NULL,
 ADD COLUMN `substantive_at` DATETIME(3) NULL,
 MODIFY COLUMN `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
 ADD COLUMN `active_marker` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` IN ('running','blocked') THEN 1 ELSE NULL END) STORED,
 ADD UNIQUE KEY `uk_workflow_instance_active_subject` (`tenant_id`,`module_key`,`workflow_key`,`subject_type`,`subject_key`,`active_marker`),
 ADD CONSTRAINT `chk_workflow_instance_status` CHECK (`status` IN ('running','blocked','completed','cancelled','terminated'));
CREATE TABLE IF NOT EXISTS `pa_workflow_node_execution` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '节点执行ID',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `node_execution_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `node_key` VARCHAR(64) NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL,
  `cycle_no` INT UNSIGNED NOT NULL,
  `previous_actor_member_id` BIGINT UNSIGNED NULL COMMENT '明确前序单人办理者',
  `node_type` VARCHAR(32) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `allocation_mode` VARCHAR(16) NULL,
  `aggregation_policy` VARCHAR(32) NULL,
  `input_json` JSON NOT NULL COMMENT '固定本次求值输入',
  `action_input_json` JSON NULL COMMENT '固定已解析业务动作输入',
  `started_at` DATETIME(3) NULL COMMENT '系统动作首次开始，失败后保留',
  `people_json` JSON NOT NULL COMMENT '冻结人员与来源',
  `output_json` JSON NULL,
  `selected_transition_key` VARCHAR(64) NULL,
  `blocked_reason` VARCHAR(500) NULL,
  `effect_key` VARCHAR(200) NULL,
  `effect_receipt_json` JSON NULL,
  `task_job_key` VARCHAR(64) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_node_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_node_key` (`node_execution_key`),
  UNIQUE KEY `uk_workflow_node_sequence` (`tenant_id`, `instance_id`, `sequence_no`),
  CONSTRAINT `fk_workflow_node_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_node_status` CHECK (`status` IN ('pending','active','blocked','completed','cancelled')),
  CONSTRAINT `chk_workflow_node_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='工作流节点执行';
ALTER TABLE `pa_workflow_work_item`
 DROP CHECK `chk_workflow_work_item_source`, DROP INDEX `uk_workflow_work_item_pending_assignee`,
 ADD COLUMN `node_execution_id` BIGINT UNSIGNED NOT NULL,
 ADD COLUMN `owner_member_id` BIGINT UNSIGNED NULL,
 ADD COLUMN `owner_source` VARCHAR(16) NOT NULL DEFAULT 'automatic',
 ADD COLUMN `assignment_state` VARCHAR(16) NOT NULL DEFAULT 'unassigned',
 ADD COLUMN `seat_no` INT UNSIGNED NOT NULL,
 ADD COLUMN `sources_json` JSON NOT NULL,
 MODIFY COLUMN `assignee_member_id` BIGINT UNSIGNED NULL,
 ADD UNIQUE KEY `uk_workflow_work_item_seat` (`tenant_id`,`node_execution_id`,`seat_no`),
 ADD UNIQUE KEY `uk_workflow_work_item_assignee_once` (`tenant_id`,`node_execution_id`,`assignee_member_id`),
 ADD CONSTRAINT `fk_workflow_work_item_node` FOREIGN KEY (`tenant_id`,`node_execution_id`) REFERENCES `pa_workflow_node_execution` (`tenant_id`,`id`) ON DELETE RESTRICT,
 ADD CONSTRAINT `chk_workflow_work_item_assignment` CHECK ((`assignment_state`='unassigned' AND `assignee_member_id` IS NULL) OR (`assignment_state`='assigned' AND `assignee_member_id` IS NOT NULL));
CREATE TABLE IF NOT EXISTS `pa_workflow_task_identity_link` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `work_item_id` BIGINT UNSIGNED NOT NULL,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `relation_type` VARCHAR(16) NOT NULL COMMENT '候选、参与、抄送',
  `sources_json` JSON NOT NULL COMMENT '来源集合及绑定修订',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_identity_relation` (`tenant_id`, `work_item_id`, `member_id`, `relation_type`),
  KEY `idx_workflow_identity_member` (`tenant_id`, `member_id`, `relation_type`, `work_item_id`),
  CONSTRAINT `fk_workflow_identity_item` FOREIGN KEY (`tenant_id`, `work_item_id`) REFERENCES `pa_workflow_work_item` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_identity_type` CHECK (`relation_type` IN ('candidate','participant','watcher'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='任务人员关系';
ALTER TABLE `pa_workflow_event` ADD COLUMN `node_execution_id` BIGINT UNSIGNED NULL;
