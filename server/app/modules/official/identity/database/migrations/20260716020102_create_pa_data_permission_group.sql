CREATE TABLE `pa_data_permission_group` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `data_permission_policy_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `match_mode` VARCHAR(8) NOT NULL DEFAULT 'all',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_group_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_data_group_name` (`tenant_id`, `data_permission_policy_id`, `name`),
  CONSTRAINT `fk_data_group_policy` FOREIGN KEY (`tenant_id`, `data_permission_policy_id`) REFERENCES `pa_data_permission_policy` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_group_match` CHECK (`match_mode` = 'all'),
  CONSTRAINT `chk_data_group_status` CHECK (`status` IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
