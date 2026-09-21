-- Adopts the existing settings schema under official.settings.
-- IF NOT EXISTS preserves installations created by server/database/init.sql.

CREATE TABLE IF NOT EXISTS `pa_setting_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `setting_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `schema_json` JSON NOT NULL,
  `required_flag` TINYINT UNSIGNED NOT NULL,
  `secret_flag` TINYINT UNSIGNED NOT NULL,
  `deployment_scope_flag` TINYINT UNSIGNED NOT NULL,
  `tenant_scope_flag` TINYINT UNSIGNED NOT NULL,
  `target_scope_flag` TINYINT UNSIGNED NOT NULL,
  `target_resource_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `target_operation` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `default_json` JSON NULL,
  `definition_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_definition` (`module_key`, `setting_key`),
  CONSTRAINT `chk_setting_definition_required` CHECK (`required_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_secret` CHECK (`secret_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_deployment_scope` CHECK (`deployment_scope_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_tenant_scope` CHECK (`tenant_scope_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_target_scope` CHECK (`target_scope_flag` IN (0, 1)),
  CONSTRAINT `chk_setting_definition_target` CHECK (
    (`target_scope_flag` = 1 AND `target_resource_key` IS NOT NULL AND `target_operation` IS NOT NULL)
    OR (`target_scope_flag` = 0 AND `target_resource_key` IS NULL AND `target_operation` IS NULL)
  ),
  CONSTRAINT `chk_setting_definition_default` CHECK (`secret_flag` = 0 OR `default_json` IS NULL),
  CONSTRAINT `chk_setting_definition_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pa_setting_deployment_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `value_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` JSON NULL,
  `ciphertext` VARBINARY(8192) NULL,
  `nonce` BINARY(24) NULL,
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `updated_by_operator_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_deployment_value` (`definition_id`),
  CONSTRAINT `fk_setting_deployment_definition` FOREIGN KEY (`definition_id`) REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_deployment_operator` FOREIGN KEY (`updated_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_deployment_state` CHECK (`value_state` IN ('set', 'unset')),
  CONSTRAINT `chk_setting_deployment_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_deployment_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND (
      (`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)
    ))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pa_setting_tenant_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `value_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` JSON NULL,
  `ciphertext` VARBINARY(8192) NULL,
  `nonce` BINARY(24) NULL,
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_tenant_value` (`tenant_id`, `definition_id`),
  KEY `idx_setting_tenant_definition` (`definition_id`),
  CONSTRAINT `fk_setting_tenant_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_tenant_definition` FOREIGN KEY (`definition_id`) REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_tenant_member` FOREIGN KEY (`updated_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_tenant_state` CHECK (`value_state` IN ('set', 'unset')),
  CONSTRAINT `chk_setting_tenant_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_tenant_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND (
      (`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)
    ))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pa_setting_target_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `target_resource_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` JSON NULL,
  `ciphertext` VARBINARY(8192) NULL,
  `nonce` BINARY(24) NULL,
  `key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_target_value` (`tenant_id`, `definition_id`, `target_resource_key`, `target_id`),
  KEY `idx_setting_target_definition` (`definition_id`),
  CONSTRAINT `fk_setting_target_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_target_definition` FOREIGN KEY (`definition_id`) REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_target_member` FOREIGN KEY (`updated_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_target_state` CHECK (`value_state` IN ('set', 'unset')),
  CONSTRAINT `chk_setting_target_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_target_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND (
      (`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)
    ))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pa_tenant_setting` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `namespace` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `config_json` JSON NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_setting_namespace` (`tenant_id`, `namespace`),
  CONSTRAINT `fk_tenant_setting_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_tenant_setting_namespace`
    CHECK (REGEXP_LIKE(`namespace`, '^[a-z][a-z0-9.-]{0,63}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Tenant-owned application capability settings';
