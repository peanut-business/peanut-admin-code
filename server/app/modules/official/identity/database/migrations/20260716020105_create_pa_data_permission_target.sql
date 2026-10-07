CREATE TABLE `pa_data_permission_target` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `target_set_id` BIGINT UNSIGNED NOT NULL,
  `target_id` VARCHAR(128) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `added_by_member_id` BIGINT UNSIGNED NOT NULL,
  `removed_by_member_id` BIGINT UNSIGNED NULL,
  `added_at` DATETIME(3) NOT NULL,
  `removed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_target` (`tenant_id`, `target_set_id`, `target_id`),
  KEY `idx_data_target_active` (`tenant_id`, `target_set_id`, `status`, `target_id`),
  CONSTRAINT `fk_data_target_set` FOREIGN KEY (`tenant_id`, `target_set_id`) REFERENCES `pa_data_permission_target_set` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_adder` FOREIGN KEY (`tenant_id`, `added_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_remover` FOREIGN KEY (`tenant_id`, `removed_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_target_status` CHECK (`status` IN ('active', 'removed')),
  CONSTRAINT `chk_data_target_removed` CHECK ((`status` = 'removed' AND `removed_at` IS NOT NULL AND `removed_by_member_id` IS NOT NULL) OR `status` <> 'removed')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
