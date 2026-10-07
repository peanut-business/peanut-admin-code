CREATE TABLE `pa_data_permission_condition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `data_permission_group_id` BIGINT UNSIGNED NOT NULL,
  `condition_definition_id` BIGINT UNSIGNED NOT NULL,
  `target_set_id` BIGINT UNSIGNED NULL,
  `target_set_key` BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`target_set_id`, 0)) STORED,
  `config_json` JSON NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_condition` (`tenant_id`, `data_permission_group_id`, `condition_definition_id`, `target_set_key`),
  CONSTRAINT `fk_data_condition_group` FOREIGN KEY (`tenant_id`, `data_permission_group_id`) REFERENCES `pa_data_permission_group` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_condition_definition` FOREIGN KEY (`condition_definition_id`) REFERENCES `pa_data_condition_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_condition_target_set` FOREIGN KEY (`tenant_id`, `target_set_id`) REFERENCES `pa_data_permission_target_set` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_permission_condition_status` CHECK (`status` IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
