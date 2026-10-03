-- Adopts the product dictionary tables and installs the previously optional versioned reference-code ledger.
-- Existing table names, ids and constraints remain authoritative.

CREATE TABLE IF NOT EXISTS `pa_dict_type` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '字典名称',
  `type`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '字典类型（英文标识）',
  `is_disable`  TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否禁用：0启用 1禁用',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `active_type` VARCHAR(100)
    GENERATED ALWAYS AS (CASE WHEN `delete_time` IS NULL THEN `type` ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  UNIQUE KEY `uk_dict_type_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_dict_type_tenant_active_type` (`tenant_id`, `active_type`),
  KEY `idx_dict_type_tenant_status_name` (`tenant_id`, `is_disable`, `name`, `id`),
  CONSTRAINT `fk_dict_type_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典类型';

CREATE TABLE IF NOT EXISTS `pa_dict_data` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '数据名称',
  `value`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '数据值',
  `type_id`     INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '字典类型id',
  `type_value`  VARCHAR(100) NOT NULL DEFAULT '' COMMENT '冗余：字典类型标识（随类型编辑级联更新）',
  `sort`        SMALLINT     NOT NULL DEFAULT 0  COMMENT '排序',
  `is_disable`  TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否禁用：0启用 1禁用',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_id` (`type_id`),
  UNIQUE KEY `uk_dict_data_tenant_id` (`tenant_id`, `id`),
  KEY `idx_dict_data_tenant_type_status_sort`
    (`tenant_id`, `type_id`, `is_disable`, `sort`, `id`),
  CONSTRAINT `fk_dict_data_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_dict_data_tenant_type`
    FOREIGN KEY (`tenant_id`, `type_id`)
    REFERENCES `pa_dict_type` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典数据';

CREATE TABLE IF NOT EXISTS `pa_system_dict_type` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(100) NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `is_disable` TINYINT(1) NOT NULL DEFAULT 0,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_dict_type_code` (`code`),
  KEY `idx_system_dict_type_status` (`is_disable`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统只读字典类型';

CREATE TABLE IF NOT EXISTS `pa_system_dict_data` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type_code` VARCHAR(100) NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `value` VARCHAR(255) NOT NULL DEFAULT '',
  `sort` SMALLINT NOT NULL DEFAULT 0,
  `is_disable` TINYINT(1) NOT NULL DEFAULT 0,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_dict_data_type_value` (`type_code`, `value`),
  KEY `idx_system_dict_data_lookup` (`type_code`, `is_disable`, `sort`, `id`),
  CONSTRAINT `fk_system_dict_data_type`
    FOREIGN KEY (`type_code`) REFERENCES `pa_system_dict_type` (`code`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统只读字典数据';


CREATE TABLE IF NOT EXISTS `pa_reference_code_set` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `set_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `definition_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lifecycle` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reference_code_set` (`module_key`, `set_key`),
  KEY `idx_reference_code_set_lifecycle` (`lifecycle`, `module_key`, `set_key`),
  CONSTRAINT `chk_reference_code_set_lifecycle` CHECK (`lifecycle` IN ('active', 'retired')),
  CONSTRAINT `chk_reference_code_set_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pa_reference_code_entry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `set_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lifecycle` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `retired_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reference_code_entry` (`tenant_id`, `set_id`, `code`),
  KEY `idx_reference_code_entry_lookup` (`tenant_id`, `set_id`, `lifecycle`, `code`),
  CONSTRAINT `fk_reference_code_entry_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_set` FOREIGN KEY (`set_id`) REFERENCES `pa_reference_code_set` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_created_member` FOREIGN KEY (`created_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_updated_member` FOREIGN KEY (`updated_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_reference_code_entry_lifecycle` CHECK (`lifecycle` IN ('active', 'retired')),
  CONSTRAINT `chk_reference_code_entry_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_reference_code_entry_retired_shape` CHECK (
    (`lifecycle` = 'active' AND `retired_at` IS NULL)
    OR (`lifecycle` = 'retired' AND `retired_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `pa_reference_code_entry_version` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `label` VARCHAR(160) NOT NULL,
  `metadata_json` JSON NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `effective_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `changed_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reference_code_entry_version` (`entry_id`, `revision`),
  KEY `idx_reference_code_entry_version_effective` (`entry_id`, `effective_at`, `expires_at`, `revision`),
  KEY `idx_reference_code_entry_version_status` (`entry_id`, `status`, `effective_at`, `expires_at`, `revision`),
  CONSTRAINT `fk_reference_code_entry_version_entry` FOREIGN KEY (`entry_id`) REFERENCES `pa_reference_code_entry` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_version_member` FOREIGN KEY (`changed_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_reference_code_entry_version_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_reference_code_entry_version_status` CHECK (`status` IN ('active', 'inactive')),
  CONSTRAINT `chk_reference_code_entry_version_sort` CHECK (`sort_order` BETWEEN -1000000 AND 1000000),
  CONSTRAINT `chk_reference_code_entry_version_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
