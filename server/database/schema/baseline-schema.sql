-- Fresh application schema. Applied only by InitialBaseline.
SET NAMES utf8mb4;

CREATE TABLE `pa_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `display_name` VARCHAR(120) NOT NULL,
  `avatar_uri` VARCHAR(512) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `security_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `locked_until` DATETIME(3) NULL,
  `last_login_at` DATETIME(3) NULL,
  `closed_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_status` (`status`, `id`),
  CONSTRAINT `chk_account_status` CHECK (`status` IN ('active', 'locked', 'disabled', 'closed')),
  CONSTRAINT `chk_account_closed` CHECK ((`status` = 'closed' AND `closed_at` IS NOT NULL) OR `status` <> 'closed')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_credential` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(32) NOT NULL,
  `identifier_type` VARCHAR(32) NOT NULL,
  `identifier_normalized` VARCHAR(255) NOT NULL,
  `secret_hash` VARCHAR(255) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME(3) NULL,
  `verified_at` DATETIME(3) NOT NULL,
  `last_used_at` DATETIME(3) NULL,
  `secret_changed_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `revoked_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_credential_identifier` (`identifier_type`, `identifier_normalized`),
  KEY `idx_credential_account` (`account_id`, `status`),
  CONSTRAINT `fk_credential_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_credential_kind` CHECK (`kind` = 'email_password'),
  CONSTRAINT `chk_credential_identifier_type` CHECK (`identifier_type` = 'email'),
  CONSTRAINT `chk_credential_status` CHECK (`status` IN ('active', 'locked', 'revoked')),
  CONSTRAINT `chk_credential_revoked` CHECK ((`status` = 'revoked' AND `revoked_at` IS NOT NULL) OR `status` <> 'revoked')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `display_name` VARCHAR(160) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'provisioning',
  `locale` VARCHAR(16) NOT NULL DEFAULT 'zh-CN',
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Shanghai',
  `security_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `authorization_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `activated_at` DATETIME(3) NULL,
  `suspended_at` DATETIME(3) NULL,
  `closed_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`code`),
  KEY `idx_tenant_status` (`status`, `id`),
  CONSTRAINT `chk_tenant_status` CHECK (`status` IN ('provisioning', 'active', 'suspended', 'closed')),
  CONSTRAINT `chk_tenant_closed` CHECK ((`status` = 'closed' AND `closed_at` IS NOT NULL) OR `status` <> 'closed')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_platform_operator` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `display_name` VARCHAR(120) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `security_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `suspended_at` DATETIME(3) NULL,
  `closed_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_operator_account` (`account_id`),
  KEY `idx_platform_operator_status` (`status`, `id`),
  CONSTRAINT `fk_platform_operator_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_operator_status` CHECK (`status` IN ('active', 'suspended', 'closed')),
  CONSTRAINT `chk_platform_operator_closed` CHECK ((`status` = 'closed' AND `closed_at` IS NOT NULL) OR `status` <> 'closed')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_permission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `type` VARCHAR(32) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `description` VARCHAR(500) NULL,
  `risk_level` VARCHAR(16) NOT NULL DEFAULT 'normal',
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `manifest_version` VARCHAR(32) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `retired_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permission_key` (`key`),
  KEY `idx_permission_module` (`module_key`, `status`, `type`),
  CONSTRAINT `chk_permission_type` CHECK (`type` IN ('menu', 'action', 'api')),
  CONSTRAINT `chk_permission_risk` CHECK (`risk_level` IN ('normal', 'sensitive', 'critical')),
  CONSTRAINT `chk_permission_status` CHECK (`status` IN ('active', 'retired')),
  CONSTRAINT `chk_permission_retired` CHECK ((`status` = 'retired' AND `retired_at` IS NOT NULL) OR `status` <> 'retired')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_platform_role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(96) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NULL,
  `is_builtin` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `archived_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_role_key` (`key`),
  KEY `idx_platform_role_status` (`status`, `id`),
  CONSTRAINT `chk_platform_role_builtin` CHECK (`is_builtin` IN (0, 1)),
  CONSTRAINT `chk_platform_role_status` CHECK (`status` IN ('active', 'disabled', 'archived'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_platform_role_permission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  `granted_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_role_permission` (`platform_role_id`, `permission_id`),
  CONSTRAINT `fk_platform_role_permission_role` FOREIGN KEY (`platform_role_id`) REFERENCES `pa_platform_role` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_role_permission_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_platform_operator_role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_operator_id` BIGINT UNSIGNED NOT NULL,
  `platform_role_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by_operator_id` BIGINT UNSIGNED NULL,
  `assigned_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_operator_role` (`platform_operator_id`, `platform_role_id`),
  CONSTRAINT `fk_platform_operator_role_operator` FOREIGN KEY (`platform_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_operator_role_role` FOREIGN KEY (`platform_role_id`) REFERENCES `pa_platform_role` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_operator_role_assigner` FOREIGN KEY (`assigned_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_department` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `leader` VARCHAR(50) NOT NULL DEFAULT '',
  `mobile` VARCHAR(20) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `archived_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_department_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_department_code` (`tenant_id`, `code`),
  KEY `idx_department_parent` (`tenant_id`, `parent_id`, `status`, `sort_order`),
  CONSTRAINT `fk_department_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_department_parent` FOREIGN KEY (`tenant_id`, `parent_id`) REFERENCES `pa_department` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_department_status` CHECK (`status` IN ('active', 'disabled', 'archived')),
  `leader_member_id` BIGINT UNSIGNED NULL,
  INDEX `idx_department_leader_member` (`tenant_id`, `leader_member_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant_member` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `member_no` VARCHAR(64) NULL,
  `display_name` VARCHAR(120) NULL,
  `member_type` VARCHAR(32) NOT NULL DEFAULT 'internal',
  `primary_department_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `security_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `authorization_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `joined_at` DATETIME(3) NULL,
  `suspended_at` DATETIME(3) NULL,
  `left_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  CONSTRAINT `fk_tenant_member_department` FOREIGN KEY (`tenant_id`, `primary_department_id`) REFERENCES `pa_department` (`tenant_id`, `id`) ON DELETE RESTRICT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_member_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_tenant_member_account` (`tenant_id`, `account_id`),
  UNIQUE KEY `uk_tenant_member_no` (`tenant_id`, `member_no`),
  KEY `idx_member_department` (`tenant_id`, `primary_department_id`, `status`),
  CONSTRAINT `fk_tenant_member_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_member_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_member_type` CHECK (`member_type` IN ('internal', 'external')),
  CONSTRAINT `chk_tenant_member_status` CHECK (`status` IN ('pending', 'active', 'suspended', 'left'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `key` VARCHAR(96) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NULL,
  `is_builtin` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `authorization_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `archived_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_role_key` (`tenant_id`, `key`),
  KEY `idx_role_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_role_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_role_builtin` CHECK (`is_builtin` IN (0, 1)),
  CONSTRAINT `chk_role_status` CHECK (`status` IN ('active', 'disabled', 'archived'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_role_permission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  `granted_by_member_id` BIGINT UNSIGNED NULL,
  `granted_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permission` (`tenant_id`, `role_id`, `permission_id`),
  CONSTRAINT `fk_role_permission_role` FOREIGN KEY (`tenant_id`, `role_id`) REFERENCES `pa_role` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_role_permission_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_role_permission_granter` FOREIGN KEY (`tenant_id`, `granted_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_member_role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `tenant_member_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by_member_id` BIGINT UNSIGNED NULL,
  `assigned_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_role` (`tenant_id`, `tenant_member_id`, `role_id`),
  CONSTRAINT `fk_member_role_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_role_role` FOREIGN KEY (`tenant_id`, `role_id`) REFERENCES `pa_role` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_role_assigner` FOREIGN KEY (`tenant_id`, `assigned_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant_module` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'disabled',
  `source` VARCHAR(32) NOT NULL DEFAULT 'manual',
  `config_json` JSON NULL,
  `config_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `authorization_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `effective_at` DATETIME(3) NULL,
  `expires_at` DATETIME(3) NULL,
  `enabled_at` DATETIME(3) NULL,
  `disabled_at` DATETIME(3) NULL,
  `disabled_reason` VARCHAR(255) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_module` (`tenant_id`, `module_key`),
  KEY `idx_tenant_module_status` (`tenant_id`, `status`, `module_key`),
  CONSTRAINT `fk_tenant_module_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_module_status` CHECK (`status` IN ('disabled', 'enabled', 'expired')),
  CONSTRAINT `chk_tenant_module_source` CHECK (`source` IN ('manual', 'product_profile', 'license')),
  CONSTRAINT `chk_tenant_module_dates` CHECK (`expires_at` IS NULL OR `effective_at` IS NULL OR `expires_at` > `effective_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_platform_audit_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(96) NOT NULL,
  `action` VARCHAR(160) NOT NULL,
  `outcome` VARCHAR(16) NOT NULL,
  `reason_code` VARCHAR(96) NULL,
  `operator_id` BIGINT UNSIGNED NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `target_type` VARCHAR(96) NULL,
  `target_id` VARCHAR(128) NULL,
  `request_id` VARCHAR(64) NOT NULL,
  `operation_id` VARCHAR(64) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `before_json` JSON NULL,
  `after_json` JSON NULL,
  `metadata_json` JSON NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_platform_audit_time` (`occurred_at`, `id`),
  KEY `idx_platform_audit_operator` (`operator_id`, `occurred_at`),
  KEY `idx_platform_audit_target` (`target_type`, `target_id`, `occurred_at`),
  KEY `idx_platform_audit_request` (`request_id`),
  CONSTRAINT `chk_platform_audit_outcome` CHECK (`outcome` IN ('success', 'denied', 'error'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant_audit_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(96) NOT NULL,
  `action` VARCHAR(160) NOT NULL,
  `outcome` VARCHAR(16) NOT NULL,
  `reason_code` VARCHAR(96) NULL,
  `actor_tenant_id` BIGINT UNSIGNED NULL,
  `actor_tenant_member_id` BIGINT UNSIGNED NULL,
  `actor_account_id` BIGINT UNSIGNED NULL,
  `actor_platform_operator_id` BIGINT UNSIGNED NULL,
  `actor_type` VARCHAR(32) NOT NULL,
  `target_resource_type` VARCHAR(160) NULL,
  `target_resource_id` VARCHAR(128) NULL,
  `boundary_target_type` VARCHAR(160) NULL,
  `boundary_target_id` VARCHAR(128) NULL,
  `target_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `target_set_digest` CHAR(64) NULL,
  `authorization_basis_json` JSON NULL,
  `request_id` VARCHAR(64) NOT NULL,
  `operation_id` VARCHAR(64) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `before_json` JSON NULL,
  `after_json` JSON NULL,
  `metadata_json` JSON NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_audit_time` (`tenant_id`, `occurred_at`, `id`),
  KEY `idx_tenant_audit_member` (`tenant_id`, `actor_tenant_member_id`, `occurred_at`),
  KEY `idx_tenant_audit_actor_tenant` (`actor_tenant_id`, `occurred_at`, `id`),
  KEY `idx_tenant_audit_target` (`tenant_id`, `target_resource_type`, `target_resource_id`, `occurred_at`),
  KEY `idx_tenant_audit_boundary_target` (`tenant_id`, `boundary_target_type`, `boundary_target_id`, `occurred_at`),
  KEY `idx_tenant_audit_request` (`request_id`),
  CONSTRAINT `chk_tenant_audit_outcome` CHECK (`outcome` IN ('success', 'denied', 'error')),
  CONSTRAINT `chk_tenant_audit_actor_type` CHECK (`actor_type` IN ('member', 'tenant_system', 'platform_operator')),
  CONSTRAINT `chk_tenant_audit_actor` CHECK (
    (`actor_type` = 'member' AND `actor_tenant_id` = `tenant_id` AND `actor_tenant_member_id` IS NOT NULL AND `actor_account_id` IS NOT NULL AND `actor_platform_operator_id` IS NULL)
    OR (`actor_type` = 'tenant_system' AND `actor_tenant_id` = `tenant_id` AND `actor_tenant_member_id` IS NULL AND `actor_account_id` IS NULL AND `actor_platform_operator_id` IS NULL)
    OR (`actor_type` = 'platform_operator' AND `actor_tenant_id` IS NULL AND `actor_tenant_member_id` IS NULL AND `actor_account_id` IS NOT NULL AND `actor_platform_operator_id` IS NOT NULL)
  ),
  `revocation_fact_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revocation_fact_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  UNIQUE KEY `uk_identity_revocation_fact` (`revocation_fact_key`),
  KEY `idx_identity_revocation_scan` (`tenant_id`, `event_type`, `id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_login_challenge` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `challenge_key` CHAR(26) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `purpose` VARCHAR(32) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `source_session_key` CHAR(26) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `used_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `client_key` VARCHAR(64) NOT NULL,
  CONSTRAINT `chk_login_challenge_client` CHECK (REGEXP_LIKE(`client_key`, '^[a-z][a-z0-9-]{0,63}$', 'c')),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login_challenge_key` (`challenge_key`),
  UNIQUE KEY `uk_login_challenge_token` (`token_hash`),
  KEY `idx_login_challenge_account` (`account_id`, `status`, `expires_at`),
  CONSTRAINT `fk_login_challenge_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_login_challenge_purpose` CHECK (`purpose` IN ('tenant_login', 'tenant_switch')),
  CONSTRAINT `chk_login_challenge_status` CHECK (`status` IN ('active', 'used', 'revoked', 'expired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_key` CHAR(26) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `tenant_member_id` BIGINT UNSIGNED NOT NULL,
  `client_key` VARCHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `account_security_revision` BIGINT UNSIGNED NOT NULL,
  `tenant_security_revision` BIGINT UNSIGNED NOT NULL,
  `member_security_revision` BIGINT UNSIGNED NOT NULL,
  `issued_at` DATETIME(3) NOT NULL,
  `last_seen_at` DATETIME(3) NOT NULL,
  `idle_expires_at` DATETIME(3) NOT NULL,
  `absolute_expires_at` DATETIME(3) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `revoked_at` DATETIME(3) NULL,
  `revoke_reason` VARCHAR(64) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_session_key` (`session_key`),
  KEY `idx_tenant_session_member` (`tenant_id`, `tenant_member_id`, `status`, `absolute_expires_at`),
  KEY `idx_tenant_session_account` (`account_id`, `status`, `absolute_expires_at`),
  CONSTRAINT `fk_tenant_session_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_session_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_session_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_session_client` CHECK (
    CHAR_LENGTH(`client_key`) BETWEEN 1 AND 64
    AND REGEXP_LIKE(`client_key`, '^[a-z]', 'c')
    AND NOT REGEXP_LIKE(`client_key`, '[^a-z0-9-]', 'c')
  ),
  CONSTRAINT `chk_tenant_session_status` CHECK (`status` IN ('active', 'revoked', 'expired')),
  CONSTRAINT `chk_tenant_session_expiry` CHECK (`idle_expires_at` <= `absolute_expires_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant_session_token` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `token_type` VARCHAR(16) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `parent_token_id` BIGINT UNSIGNED NULL,
  `replaced_by_token_id` BIGINT UNSIGNED NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `used_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_session_token_hash` (`token_hash`),
  KEY `idx_tenant_session_token_active` (`session_id`, `token_type`, `status`, `expires_at`),
  CONSTRAINT `fk_tenant_token_session` FOREIGN KEY (`session_id`) REFERENCES `pa_tenant_session` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_token_parent` FOREIGN KEY (`parent_token_id`) REFERENCES `pa_tenant_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tenant_token_replacement` FOREIGN KEY (`replaced_by_token_id`) REFERENCES `pa_tenant_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_token_type` CHECK (`token_type` IN ('access', 'refresh')),
  CONSTRAINT `chk_tenant_token_status` CHECK (`status` IN ('active', 'used', 'revoked', 'expired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_platform_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_key` CHAR(26) NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `platform_operator_id` BIGINT UNSIGNED NOT NULL,
  `client_key` VARCHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `account_security_revision` BIGINT UNSIGNED NOT NULL,
  `operator_security_revision` BIGINT UNSIGNED NOT NULL,
  `issued_at` DATETIME(3) NOT NULL,
  `last_seen_at` DATETIME(3) NOT NULL,
  `idle_expires_at` DATETIME(3) NOT NULL,
  `absolute_expires_at` DATETIME(3) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `revoked_at` DATETIME(3) NULL,
  `revoke_reason` VARCHAR(64) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_session_key` (`session_key`),
  KEY `idx_platform_session_operator` (`platform_operator_id`, `status`, `absolute_expires_at`),
  KEY `idx_platform_session_account` (`account_id`, `status`, `absolute_expires_at`),
  CONSTRAINT `fk_platform_session_account` FOREIGN KEY (`account_id`) REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_session_operator` FOREIGN KEY (`platform_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_session_client` CHECK (`client_key` = 'platform-web'),
  CONSTRAINT `chk_platform_session_status` CHECK (`status` IN ('active', 'revoked', 'expired')),
  CONSTRAINT `chk_platform_session_expiry` CHECK (`idle_expires_at` <= `absolute_expires_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_platform_session_token` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `token_type` VARCHAR(16) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `parent_token_id` BIGINT UNSIGNED NULL,
  `replaced_by_token_id` BIGINT UNSIGNED NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `used_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_session_token_hash` (`token_hash`),
  KEY `idx_platform_session_token_active` (`session_id`, `token_type`, `status`, `expires_at`),
  CONSTRAINT `fk_platform_token_session` FOREIGN KEY (`session_id`) REFERENCES `pa_platform_session` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_token_parent` FOREIGN KEY (`parent_token_id`) REFERENCES `pa_platform_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_platform_token_replacement` FOREIGN KEY (`replaced_by_token_id`) REFERENCES `pa_platform_session_token` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_platform_token_type` CHECK (`token_type` IN ('access', 'refresh')),
  CONSTRAINT `chk_platform_token_status` CHECK (`status` IN ('active', 'used', 'revoked', 'expired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_auth_security_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `audience` VARCHAR(16) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `outcome` VARCHAR(16) NOT NULL,
  `reason_code` VARCHAR(96) NULL,
  `account_id` BIGINT UNSIGNED NULL,
  `credential_id` BIGINT UNSIGNED NULL,
  `session_key` CHAR(26) NULL,
  `identifier_hmac` CHAR(64) NULL,
  `request_id` VARCHAR(64) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent_hash` CHAR(64) NULL,
  `metadata_json` JSON NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auth_event_account` (`account_id`, `occurred_at`),
  KEY `idx_auth_event_identifier` (`identifier_hmac`, `occurred_at`),
  KEY `idx_auth_event_ip` (`ip_address`, `occurred_at`),
  KEY `idx_auth_event_request` (`request_id`),
  KEY `idx_auth_event_time` (`occurred_at`, `id`),
  CONSTRAINT `chk_auth_event_audience` CHECK (`audience` IN ('tenant', 'platform')),
  CONSTRAINT `chk_auth_event_outcome` CHECK (`outcome` IN ('success', 'denied', 'error'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_ops_task` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `task_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `handler_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` JSON NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
  `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `idempotency_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `concurrency_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `submitted_by_operator_id` BIGINT UNSIGNED NOT NULL,
  `available_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_task_key` (`task_key`),
  UNIQUE KEY `uk_ops_task_idempotency` (`submitted_by_operator_id`, `idempotency_digest`),
  KEY `idx_ops_task_status` (`status`, `available_at`, `id`),
  KEY `idx_ops_task_concurrency` (`concurrency_key`, `status`, `id`),
  CONSTRAINT `fk_ops_task_operator` FOREIGN KEY (`submitted_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_task_status` CHECK (`status` IN ('queued','running','succeeded','dead','cancelled')),
  CONSTRAINT `chk_ops_task_attempts` CHECK (`max_attempts` BETWEEN 1 AND 10 AND `attempt_count` <= `max_attempts`),
  CONSTRAINT `chk_ops_task_completion` CHECK ((`status` IN ('succeeded','dead','cancelled')) = (`completed_at` IS NOT NULL)),
  CONSTRAINT `chk_ops_task_type`
    CHECK (`task_type` IN ('ops.backup.create','ops.restore.verify','ops.upgrade.execute','ops.module.execute'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_ops_maintenance_window` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `maintenance_key` CHAR(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `reason_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `starts_at` DATETIME(3) NOT NULL,
  `ends_at` DATETIME(3) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `idempotency_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by_operator_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `closed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_maintenance_key` (`maintenance_key`),
  UNIQUE KEY `uk_ops_maintenance_idempotency` (`created_by_operator_id`, `idempotency_digest`),
  KEY `idx_ops_maintenance_state` (`state`, `starts_at`, `id`),
  CONSTRAINT `fk_ops_maintenance_operator` FOREIGN KEY (`created_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_maintenance_state` CHECK (`state` IN ('scheduled','active','closed')),
  CONSTRAINT `chk_ops_maintenance_range` CHECK (`ends_at` > `starts_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_system_menu` (
  `menu_key` VARCHAR(160) NOT NULL,
  `parent_key` VARCHAR(160) NULL,
  `module_key` VARCHAR(96) NOT NULL DEFAULT 'application',
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pid`        INT UNSIGNED NOT NULL DEFAULT 0,
  `type`       CHAR(1)      NOT NULL DEFAULT 'C' COMMENT 'M目录 C菜单 A按钮',
  `name`       VARCHAR(50)  NOT NULL DEFAULT '',
  `icon`       VARCHAR(100) NOT NULL DEFAULT '',
  `sort`       SMALLINT     NOT NULL DEFAULT 0,
  `perms`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT '权限标识',
  `paths`      VARCHAR(200) NOT NULL DEFAULT '' COMMENT '路由路径',
  `component`  VARCHAR(200) NOT NULL DEFAULT '' COMMENT '前端组件',
  `is_cache`   TINYINT(1)   NOT NULL DEFAULT 0,
  `is_show`    TINYINT(1)   NOT NULL DEFAULT 1,
  `is_disable` TINYINT(1)   NOT NULL DEFAULT 0,
  `upstream_defaults_json` JSON NULL,
  `menu_conflict_json` JSON NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_menu_key` (`menu_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000000000 COMMENT='系统菜单';

CREATE TABLE `pa_operation_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `username`    VARCHAR(50)  NOT NULL DEFAULT '',
  `ip`          VARCHAR(50)  NOT NULL DEFAULT '',
  `uri`         VARCHAR(200) NOT NULL DEFAULT '',
  `method`      VARCHAR(10)  NOT NULL DEFAULT '',
  `request_id` VARCHAR(128) NOT NULL DEFAULT '',
  `params`      TEXT,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation_log_tenant_id` (`tenant_id`, `id`),
  KEY `idx_operation_log_tenant_created` (`tenant_id`, `create_time`, `id`),
  CONSTRAINT `fk_operation_log_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

CREATE TABLE `pa_config` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`        VARCHAR(30)  NOT NULL DEFAULT '',
  `name`        VARCHAR(60)  NOT NULL DEFAULT '',
  `value`       TEXT,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_name` (`type`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='实例级配置（仅 Platform/部署拥有）';

CREATE TABLE `pa_jobs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '岗位名称',
  `active_name` VARCHAR(50)
    GENERATED ALWAYS AS (IF(`delete_time` IS NULL, `name`, NULL)) STORED,
  `code`        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '岗位编码',
  `active_code` VARCHAR(64)
    GENERATED ALWAYS AS (IF(`delete_time` IS NULL, `code`, NULL)) STORED,
  `sort`        SMALLINT     NOT NULL DEFAULT 0  COMMENT '排序',
  `is_disable`  TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否禁用：0启用 1禁用',
  `status`      TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '状态：0停用 1正常',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `remark` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '备注',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_code` (`code`),
  UNIQUE KEY `uk_jobs_tenant_id` (`tenant_id`, `id`),
  KEY `idx_jobs_tenant_code` (`tenant_id`, `code`),
  CONSTRAINT `fk_jobs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  UNIQUE KEY `uk_jobs_tenant_active_name` (`tenant_id`, `active_name`),
  UNIQUE KEY `uk_jobs_tenant_active_code` (`tenant_id`, `active_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='岗位';

CREATE TABLE `pa_dict_type` (
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

CREATE TABLE `pa_dict_data` (
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

CREATE TABLE `pa_file_cate` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pid`         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '父级ID',
  `type`        TINYINT(2)   NOT NULL DEFAULT 10 COMMENT '类型：10图片 20视频 30文件',
  `name`        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '分类名称',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  UNIQUE KEY `uk_file_cate_tenant_id` (`tenant_id`, `id`),
  KEY `idx_file_cate_tenant_type_parent` (`tenant_id`, `type`, `pid`, `id`),
  CONSTRAINT `fk_file_cate_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件分类';

CREATE TABLE `pa_file` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cid`         INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '分类ID',
  `source_id`   INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '上传者ID',
  `source`      TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '来源：0后台 1用户',
  `type`        TINYINT(2)   NOT NULL DEFAULT 10 COMMENT '类型：10图片 20视频 30文件',
  `name`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '文件名称',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cid` (`cid`),
  KEY `idx_type` (`type`),
  KEY `idx_type_cid_source` (`type`,`cid`,`source`),
  UNIQUE KEY `uk_file_tenant_id` (`tenant_id`, `id`),
  KEY `idx_file_tenant_type_cid_source` (`tenant_id`, `type`, `cid`, `source`, `id`),
  UNIQUE KEY `uk_file_object_reference` (`file_key`),
  CONSTRAINT `fk_file_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_object_reference` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件';

CREATE TABLE `pa_crontab` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '任务名称',
  `type`        TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '类型：1定时任务',
  `command`     VARCHAR(100) NOT NULL DEFAULT '' COMMENT '命令（think console 命令名）',
  `params`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '命令参数（空格分隔）',
  `status`      TINYINT(1)   NOT NULL DEFAULT 2  COMMENT '状态：1运行 2停止 3错误',
  `expression`  VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'crontab 运行规则',
  `error`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最近一次错误信息',
  `last_time`   INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '最后执行时间',
  `time`        DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '最近一次耗时（秒）',
  `max_time`    DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '最大耗时（秒）',
  `sort`        INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '排序',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  UNIQUE KEY `uk_crontab_tenant_id` (`tenant_id`, `id`),
  KEY `idx_crontab_tenant_status_last` (`tenant_id`, `status`, `last_time`, `id`),
  CONSTRAINT `fk_crontab_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务';

CREATE TABLE `pa_member` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sn`          VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '会员编号',
  `account`     VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '用户账号',
  `account_unique` VARCHAR(50) GENERATED ALWAYS AS (NULLIF(`account`, '')) STORED,
  `password`    VARCHAR(100) NOT NULL DEFAULT '' COMMENT '用户密码',
  `nickname`    VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '头像',
  `real_name`   VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '真实姓名',
  `mobile`      VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '手机号',
  `channel`     TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '注册来源：1小程序 2公众号 3手机H5 4电脑PC 5苹果APP 6安卓APP',
  `email`       VARCHAR(100) NOT NULL DEFAULT '' COMMENT '邮箱',
  `sex`         TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '性别：0未知 1男 2女',
  `birthday`    DATE                  DEFAULT NULL COMMENT '生日',
  `status`      TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '状态：0禁用 1正常',
  `login_time`  INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '最后登录时间',
  `login_ip`    VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `is_new_user` TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否新用户：0否 1是',
  `user_money`  DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户可用余额',
  `total_recharge_amount` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计充值金额',
  `points`      INT UNSIGNED  NOT NULL DEFAULT 0  COMMENT '积分',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `mobile_unique` VARCHAR(20) GENERATED ALWAYS AS (NULLIF(`mobile`,'')) STORED,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account`),
  KEY `idx_mobile` (`mobile`),
  KEY `idx_status` (`status`),
  KEY `idx_channel` (`channel`),
  KEY `idx_create_time` (`create_time`),
  UNIQUE KEY `uk_member_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_member_tenant_sn` (`tenant_id`, `sn`),
  UNIQUE KEY `uk_member_tenant_account` (`tenant_id`, `account_unique`),
  UNIQUE KEY `uk_member_tenant_mobile` (`tenant_id`, `mobile_unique`),
  KEY `idx_member_tenant_status_channel` (`tenant_id`, `status`, `channel`, `id`),
  CONSTRAINT `fk_member_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  `session_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT '会员安全域会话修订；改密、重置或停用时递增',
  CONSTRAINT `chk_member_security_session_revision` CHECK (`session_revision` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员';

CREATE TABLE `pa_member_tag` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '标签名称',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_tag_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_member_tag_tenant_name` (`tenant_id`, `name`),
  KEY `idx_member_tag_tenant_live` (`tenant_id`, `delete_time`, `id`),
  CONSTRAINT `fk_member_tag_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员标签';

CREATE TABLE `pa_member_tag_relation` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `tag_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tag_id` (`tag_id`),
  UNIQUE KEY `uk_member_tag_relation_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_member_tag_relation_tenant_pair` (`tenant_id`, `member_id`, `tag_id`),
  KEY `idx_member_tag_relation_tenant_tag` (`tenant_id`, `tag_id`, `member_id`),
  CONSTRAINT `fk_member_tag_relation_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_tag_relation_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_tag_relation_tag` FOREIGN KEY (`tenant_id`, `tag_id`) REFERENCES `pa_member_tag` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员标签关联';

CREATE TABLE `pa_member_balance_log` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `sn`            VARCHAR(32)   NOT NULL DEFAULT '' COMMENT '流水号',
  `member_id`     INT UNSIGNED  NOT NULL DEFAULT 0  COMMENT '会员ID',
  `change_object` TINYINT(2) UNSIGNED NOT NULL DEFAULT 1 COMMENT '变动对象：1用户余额',
  `change_type`   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '变动类型：100/101/200/201',
  `action`        TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '动作：1增加 2减少',
  `left_amount`   DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0 COMMENT '变动后余额',
  `source_type`   TINYINT(2) NOT NULL DEFAULT 0 COMMENT 'Peanut旧字段',
  `extra`         TEXT NULL COMMENT '扩展数据JSON',
  `admin_id`      INT UNSIGNED  NOT NULL DEFAULT 0  COMMENT '操作管理员',
  `create_time`   INT UNSIGNED  NOT NULL DEFAULT 0,
  `update_time`   INT UNSIGNED  NULL DEFAULT NULL,
  `delete_time`   INT UNSIGNED  NULL DEFAULT NULL,
  `change_amount` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0 COMMENT '变动金额（无符号）',
  `source_sn` VARCHAR(255) NULL DEFAULT NULL COMMENT '来源单号',
  `source_sn_unique` VARCHAR(255) GENERATED ALWAYS AS (NULLIF(`source_sn`, '')) STORED,
  `remark` VARCHAR(255) NULL DEFAULT '' COMMENT '备注',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_change_type` (`change_type`),
  KEY `idx_create_time` (`create_time`),
  UNIQUE KEY `uk_member_balance_log_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_member_balance_log_tenant_sn` (`tenant_id`, `sn`),
  UNIQUE KEY `uk_member_balance_log_tenant_source` (`tenant_id`, `source_sn_unique`),
  KEY `idx_member_balance_log_tenant_member_time` (`tenant_id`, `member_id`, `create_time`, `id`),
  CONSTRAINT `fk_member_balance_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_balance_log_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员余额变动记录';

CREATE TABLE `pa_notice_template` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '模板名称',
  `code`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '模板标识（英文）',
  `channel`     TINYINT(2)   NOT NULL DEFAULT 1  COMMENT '渠道：1短信 2邮件 3推送',
  `title`       VARCHAR(200) NOT NULL DEFAULT '' COMMENT '标题（邮件/推送用）',
  `content`     TEXT                              COMMENT '内容（支持变量{xxx}）',
  `is_disable`  TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '是否禁用：0启用 1禁用',
  `remark`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_channel` (`channel`),
  UNIQUE KEY `uk_notice_template_tenant_code` (`tenant_id`, `code`),
  UNIQUE KEY `uk_notice_template_tenant_id` (`tenant_id`, `id`),
  KEY `idx_notice_template_tenant_channel` (`tenant_id`, `channel`, `is_disable`, `id`),
  CONSTRAINT `fk_notice_template_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知模板';

CREATE TABLE `pa_notice_scene` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(50) NOT NULL DEFAULT '' COMMENT '业务场景标识',
  `name`            VARCHAR(100) NOT NULL DEFAULT '' COMMENT '业务场景名称',
  `description`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '场景说明',
  `recipient`       VARCHAR(50) NOT NULL DEFAULT '用户' COMMENT '接收对象',
  `variables`       JSON NULL COMMENT '可用模板变量',
  `sms_template_id` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '服务商短信模板ID',
  `sms_content`     VARCHAR(500) NOT NULL DEFAULT '' COMMENT '短信内容模板',
  `sms_status`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '短信通知 0-关闭 1-开启',
  `create_time`     INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time`     INT UNSIGNED NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sms_status` (`sms_status`),
  UNIQUE KEY `uk_notice_scene_tenant_code` (`tenant_id`, `code`),
  UNIQUE KEY `uk_notice_scene_tenant_id` (`tenant_id`, `id`),
  KEY `idx_notice_scene_tenant_sms` (`tenant_id`, `sms_status`, `id`),
  CONSTRAINT `fk_notice_scene_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知业务场景';

CREATE TABLE `pa_notice_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '模板ID',
  `scene_id`    INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '业务场景ID',
  `channel`     TINYINT(2)   NOT NULL DEFAULT 1  COMMENT '渠道：1短信 2邮件 3推送',
  `provider`    VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '发送服务商',
  `receiver`    VARCHAR(200) NOT NULL DEFAULT '' COMMENT '接收者（手机号/邮箱/设备token）',
  `title`       VARCHAR(200) NOT NULL DEFAULT '' COMMENT '标题快照',
  `content`     TEXT                              COMMENT '内容快照（已替换变量）',
  `is_verified` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否已验证',
  `check_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '校验次数',
  `verified_time` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '验证时间',
  `status`      TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '状态：0待发 1成功 2失败',
  `error`       VARCHAR(500) NOT NULL DEFAULT '' COMMENT '错误信息',
  `extra`       TEXT                              COMMENT '额外数据（JSON）',
  `send_time`   INT UNSIGNED NOT NULL DEFAULT 0  COMMENT '发送时间',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `verify_code_hash` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '验证码单向慢哈希',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_template_id` (`template_id`),
  KEY `idx_channel` (`channel`),
  KEY `idx_status` (`status`),
  KEY `idx_send_time` (`send_time`),
  UNIQUE KEY `uk_notice_log_tenant_id` (`tenant_id`, `id`),
  KEY `idx_notice_log_tenant_scene_receiver` (`tenant_id`, `scene_id`, `channel`, `receiver`, `status`, `send_time`, `id`),
  KEY `idx_notice_log_tenant_list` (`tenant_id`, `status`, `channel`, `send_time`, `id`),
  CONSTRAINT `fk_notice_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  `reservation_key` CHAR(37) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'opaque verification SMS reservation capability',
  `idempotency_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'request-scoped idempotency identity digest',
  `request_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'immutable scene and receiver request digest',
  `receiver_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'receiver digest used by the active reservation key',
  `reservation_until` INT UNSIGNED NULL
    COMMENT 'exclusive resend reservation deadline',
  `reservation_active` TINYINT UNSIGNED NULL
    COMMENT '1 while reserved, successful, or unknown inside the resend window',
  UNIQUE KEY `uk_notice_sms_reservation_key` (`reservation_key`),
  UNIQUE KEY `uk_notice_sms_idempotency` (`tenant_id`, `idempotency_key_hash`),
  UNIQUE KEY `uk_notice_sms_active_receiver`
    (`tenant_id`, `channel`, `receiver_hash`, `reservation_active`),
  KEY `idx_notice_sms_reservation_expiry`
    (`tenant_id`, `reservation_active`, `reservation_until`, `id`),
  CONSTRAINT `chk_notice_sms_reservation_active`
    CHECK (`reservation_active` IS NULL OR (
      `reservation_active` = 1
      AND `receiver_hash` IS NOT NULL
      AND `reservation_until` IS NOT NULL
      AND `status` IN (0, 1, 3)
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知发送记录';

CREATE TABLE `pa_hot_search` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(200)    NOT NULL DEFAULT '' COMMENT '搜索词',
  `sort`        SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序（倒序）',
  `create_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hot_search_tenant_id` (`tenant_id`, `id`),
  KEY `idx_hot_search_tenant_sort` (`tenant_id`, `sort`, `id`),
  CONSTRAINT `fk_hot_search_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='热门搜索词';

CREATE TABLE `pa_article_cate` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `is_show`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否显示 0否1是',
  `create_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED    NULL     DEFAULT NULL,
  `name` VARCHAR(90) NOT NULL DEFAULT '' COMMENT '分类名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序（倒序）',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_article_cate_tenant_id` (`tenant_id`, `id`),
  KEY `idx_article_cate_tenant_visible` (`tenant_id`, `is_show`, `sort`, `id`),
  CONSTRAINT `fk_article_cate_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章分类';

CREATE TABLE `pa_article` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `desc`        VARCHAR(255)    NULL DEFAULT '' COMMENT '简介',
  `abstract`    TEXT            NULL COMMENT '文章摘要',
  `click_virtual` INT           NULL DEFAULT 0 COMMENT '虚拟浏览量',
  `click_actual`  INT           NULL DEFAULT 0 COMMENT '实际浏览量',
  `is_show`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否显示 0否1是',
  `create_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED    NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED    NULL     DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '文章标题',
  `author` VARCHAR(255) NULL DEFAULT '' COMMENT '作者',
  `content` TEXT NULL COMMENT '文章内容',
  `sort` INT NULL DEFAULT 0 COMMENT '排序（倒序）',
  `image` VARCHAR(2048) NULL DEFAULT NULL COMMENT '文章图片：local 相对 URI 或云/CDN 绝对 URL',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `cid` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文章分类',
  PRIMARY KEY (`id`),
  KEY `idx_cid` (`cid`),
  UNIQUE KEY `uk_article_tenant_id` (`tenant_id`, `id`),
  KEY `idx_article_tenant_visible_cate` (`tenant_id`, `is_show`, `cid`, `sort`, `id`),
  CONSTRAINT `fk_article_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_tenant_cate` FOREIGN KEY (`tenant_id`, `cid`) REFERENCES `pa_article_cate` (`tenant_id`, `id`) ON DELETE RESTRICT,
  `body_document_id` BIGINT UNSIGNED NULL,
  `body_revision_id` BIGINT UNSIGNED NULL,
  `body_source_state_seq` BIGINT UNSIGNED NULL,
  `body_snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  CONSTRAINT `chk_article_body_binding` CHECK (
  (`body_revision_id` IS NULL AND `body_source_state_seq` IS NULL AND `body_snapshot_sha256` IS NULL)
  OR (`body_document_id` IS NOT NULL AND `body_revision_id` IS NOT NULL AND `body_source_state_seq` IS NOT NULL AND `body_snapshot_sha256` IS NOT NULL AND `body_snapshot_sha256` REGEXP '^[a-f0-9]{64}$')
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章';

CREATE TABLE `pa_article_collect` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员ID',
  `article_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文章ID',
  `status`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '收藏状态 0-未收藏 1-已收藏',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_id` (`member_id`),
  UNIQUE KEY `uk_article_collect_tenant_member_article` (`tenant_id`, `member_id`, `article_id`),
  KEY `idx_article_collect_tenant_member_status` (`tenant_id`, `member_id`, `status`, `id`),
  CONSTRAINT `fk_article_collect_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_collect_tenant_article` FOREIGN KEY (`tenant_id`, `article_id`) REFERENCES `pa_article` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_collect_tenant_member`
    FOREIGN KEY (`tenant_id`, `member_id`)
    REFERENCES `pa_member` (`tenant_id`, `id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章收藏';

CREATE TABLE `pa_recharge_order` (
  `id`                    INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `user_id`               INT UNSIGNED   NOT NULL DEFAULT 0 COMMENT '用户ID',
  `pay_status`            TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '支付状态：0未支付 1已支付',
  `order_amount`          DECIMAL(10,2)  NOT NULL DEFAULT 0 COMMENT '充值金额',
  `order_terminal`        TINYINT(1)     NULL DEFAULT 1 COMMENT '支付终端',
  `refund_status`         TINYINT(1)     NOT NULL DEFAULT 0 COMMENT '是否已发起退款：0否 1是',
  `refund_transaction_id` VARCHAR(255)   NULL DEFAULT NULL COMMENT '退款交易流水号',
  `delete_time`           INT UNSIGNED   NULL DEFAULT NULL,
  `sn` VARCHAR(64) NOT NULL COMMENT '充值订单编号',
  `pay_way` TINYINT(2) NOT NULL DEFAULT 2 COMMENT '支付方式：1余额 2微信 3支付宝',
  `pay_time` INT UNSIGNED NULL DEFAULT NULL COMMENT '支付时间',
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  `pay_sn` VARCHAR(255) NULL DEFAULT NULL COMMENT '支付请求编号',
  `transaction_id` VARCHAR(128) NULL DEFAULT NULL COMMENT '第三方交易流水号',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_pay_status` (`pay_status`),
  KEY `idx_refund_status` (`refund_status`),
  KEY `idx_create_time` (`create_time`),
  UNIQUE KEY `uk_pay_sn` (`pay_sn`),
  UNIQUE KEY `uk_transaction_id` (`transaction_id`),
  UNIQUE KEY `uk_recharge_order_tenant_id` (`tenant_id`, `id`),
  KEY `idx_recharge_order_tenant_member_time` (`tenant_id`, `user_id`, `create_time`, `id`),
  CONSTRAINT `fk_recharge_order_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_recharge_order_member` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  `payment_binding_id` BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'External channel binding used by this payment attempt',
  `payment_grant_id` BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Payment Tenant channel grant used by this payment attempt',
  `payment_merchant_account_ref` VARCHAR(191) NOT NULL DEFAULT ''
    COMMENT 'non-secret merchant account reference snapshot',
  `payment_merchant_group_ref` VARCHAR(191) NOT NULL DEFAULT ''
    COMMENT 'non-secret merchant group reference snapshot',
  KEY `idx_recharge_order_payment_binding` (`tenant_id`, `payment_binding_id`, `id`),
  KEY `idx_recharge_order_payment_grant` (`tenant_id`, `payment_grant_id`, `id`),
  CONSTRAINT `fk_recharge_order_payment_binding`
    FOREIGN KEY (`payment_binding_id`) REFERENCES `pa_external_channel_binding` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_recharge_order_payment_grant`
    FOREIGN KEY (`payment_grant_id`) REFERENCES `pa_payment_tenant_channel_grant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值订单';

CREATE TABLE `pa_refund_record` (
  `id`             INT          NOT NULL AUTO_INCREMENT,
  `order_type`     VARCHAR(255) DEFAULT 'order',
  `order_amount`   DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `refund_amount`  DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `transaction_id` VARCHAR(255) DEFAULT NULL,
  `refund_way`     TINYINT(1)   NOT NULL DEFAULT 1,
  `refund_type`    TINYINT(1)   NOT NULL DEFAULT 1,
  `refund_status`  TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `refund_msg`     TEXT         DEFAULT NULL,
  `create_time`    INT UNSIGNED DEFAULT 0,
  `update_time`    INT          DEFAULT NULL,
  `sn` VARCHAR(32) NOT NULL DEFAULT '',
  `order_sn` VARCHAR(64) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `order_id` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_user_id`      (`user_id`),
  KEY `idx_order_sn`     (`order_sn`),
  KEY `idx_refund_status`(`refund_status`),
  KEY `idx_create_time`  (`create_time`),
  UNIQUE KEY `uk_refund_record_tenant_id` (`tenant_id`, `id`),
  KEY `idx_refund_record_tenant_order_amount` (`tenant_id`, `order_type`, `order_id`, `refund_amount`, `id`),
  KEY `idx_refund_record_tenant_status_time` (`tenant_id`, `refund_status`, `create_time`, `id`),
  CONSTRAINT `fk_refund_record_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_record_member` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_record_order` FOREIGN KEY (`tenant_id`, `order_id`) REFERENCES `pa_recharge_order` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退款记录';

CREATE TABLE `pa_generator_table` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '配置所有者管理员ID',
  `table_name`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '数据库物理表名',
  `table_comment` VARCHAR(300) NOT NULL DEFAULT '' COMMENT '表说明',
  `module_name`   VARCHAR(32)  NOT NULL DEFAULT 'generated' COMMENT '生成模块名',
  `entity_name`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '生成实体类名',
  `template_type` VARCHAR(10)  NOT NULL DEFAULT 'crud' COMMENT '模板类型 crud/tree',
  `author`         VARCHAR(100) NOT NULL DEFAULT '' COMMENT '作者',
  `tree_config`   JSON NULL COMMENT '树结构字段配置',
  `relations`     JSON NULL COMMENT '模型关系配置',
  `create_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_table` (`admin_id`,`table_name`),
  KEY `idx_owner_update` (`admin_id`,`update_time`),
  `data_owner` VARCHAR(20) NULL COMMENT 'Explicit generated data owner',
  `target_edition` VARCHAR(20) NULL COMMENT 'Explicit generated deployment edition',
  CONSTRAINT `chk_generator_data_owner`
    CHECK (`data_owner` IS NULL OR `data_owner` IN ('tenant-orm','platform','instance','shared')),
  CONSTRAINT `chk_generator_target_edition`
    CHECK (`target_edition` IS NULL OR `target_edition` IN ('standalone','multi-tenant'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安全代码生成器表配置';

CREATE TABLE `pa_generator_column` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `table_id`       INT UNSIGNED NOT NULL DEFAULT 0,
  `column_name`    VARCHAR(64)  NOT NULL DEFAULT '',
  `column_comment` VARCHAR(300) NOT NULL DEFAULT '',
  `column_type`    VARCHAR(100) NOT NULL DEFAULT '',
  `php_type`       VARCHAR(20)  NOT NULL DEFAULT 'string',
  `is_required`    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_pk`          TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_insert`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_update`      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_lists`       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `is_query`       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `query_type`     VARCHAR(20) NOT NULL DEFAULT '=',
  `view_type`      VARCHAR(20) NOT NULL DEFAULT 'input',
  `dict_type`      VARCHAR(100) NOT NULL DEFAULT '',
  `sort`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `create_time`    INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_table_column` (`table_id`,`column_name`),
  KEY `idx_table_sort` (`table_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安全代码生成器字段配置';

CREATE TABLE `pa_generator_download` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '令牌所有者管理员ID',
  `token_hash`    CHAR(64) NOT NULL DEFAULT '' COMMENT '下载令牌SHA-256',
  `archive_path`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '生成根目录内相对路径',
  `download_name` VARCHAR(120) NOT NULL DEFAULT '',
  `expire_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  `used_time`     INT UNSIGNED NOT NULL DEFAULT 0,
  `create_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_owner_expire` (`admin_id`,`expire_time`,`used_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代码生成器一次性下载令牌';

CREATE TABLE `pa_decorate_page` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` tinyint unsigned NOT NULL COMMENT '1首页 2个人中心 3客服 4PC首页 5系统风格',
  `name` varchar(100) NOT NULL DEFAULT '',
  `data` longtext NOT NULL,
  `meta` longtext NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decorate_page_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_decorate_page_tenant_type` (`tenant_id`, `type`),
  CONSTRAINT `fk_decorate_page_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='装修页面';

CREATE TABLE `pa_decorate_tabbar` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `position` tinyint unsigned NOT NULL,
  `name` varchar(20) NOT NULL DEFAULT '',
  `link` varchar(1000) NOT NULL DEFAULT '{}',
  `is_show` tinyint unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `selected` VARCHAR(2048) NOT NULL DEFAULT '' COMMENT '选中图标：local 相对 URI 或云/CDN 绝对 URL',
  `unselected` VARCHAR(2048) NOT NULL DEFAULT '' COMMENT '未选图标：local 相对 URI 或云/CDN 绝对 URL',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decorate_tabbar_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_decorate_tabbar_tenant_position` (`tenant_id`, `position`),
  CONSTRAINT `fk_decorate_tabbar_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='装修 Tabbar';

CREATE TABLE `pa_official_account_reply` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '规则名称',
  `keyword` varchar(255) NOT NULL DEFAULT '' COMMENT '关键词',
  `reply_type` tinyint unsigned NOT NULL COMMENT '1关注 2关键词 3默认',
  `matching_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1全匹配 2模糊匹配',
  `content_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1文本',
  `content` text NOT NULL COMMENT '回复内容',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '0停用 1启用',
  `sort` int unsigned NOT NULL DEFAULT 0 COMMENT '匹配优先级，升序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `singleton_active_key` tinyint unsigned GENERATED ALWAYS AS (
    CASE
      WHEN `delete_time` = 0 AND `status` = 1 AND `reply_type` IN (1, 3) THEN `reply_type`
      ELSE NULL
    END
  ) STORED,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_oa_reply_state` (`reply_type`,`status`,`delete_time`,`sort`,`id`),
  UNIQUE KEY `uk_oa_reply_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_oa_reply_tenant_singleton_active` (`tenant_id`,`singleton_active_key`),
  KEY `idx_oa_reply_tenant_state` (`tenant_id`,`reply_type`,`status`,`delete_time`,`sort`,`id`),
  CONSTRAINT `fk_oa_reply_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='微信公众号自动回复';

CREATE TABLE `pa_payment_scene` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `terminal`    TINYINT UNSIGNED NOT NULL COMMENT '终端：1小程序 2公众号 3H5 4PC 5iOS 6Android',
  `pay_way`     TINYINT UNSIGNED NOT NULL COMMENT '支付渠道：2微信 3支付宝',
  `status`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '状态：0关闭 1开启',
  `is_default`  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否默认：0否 1是',
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_terminal_pay_way` (`terminal`,`pay_way`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值终端支付场景';

CREATE TABLE `pa_oauth_principal` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`    VARCHAR(32) NOT NULL COMMENT '提供商',
  `union_scope` VARCHAR(64) NOT NULL COMMENT '联合身份作用域',
  `union_id`    VARCHAR(191) NOT NULL COMMENT '联合身份',
  `member_id`   INT UNSIGNED NOT NULL COMMENT '会员ID',
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_id` (`member_id`),
  UNIQUE KEY `uk_oauth_principal_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_oauth_principal_tenant_union` (`tenant_id`, `provider`, `union_scope`, `union_id`),
  KEY `idx_oauth_principal_tenant_member` (`tenant_id`, `member_id`),
  CONSTRAINT `fk_oauth_principal_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_principal_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuth联合身份归属';

CREATE TABLE `pa_oauth_identity` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider`     VARCHAR(32) NOT NULL COMMENT '提供商',
  `client_key`   VARCHAR(64) NOT NULL COMMENT '客户端配置标识',
  `subject`      VARCHAR(191) NOT NULL COMMENT '客户端内外部身份',
  `principal_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '联合身份ID',
  `member_id`    INT UNSIGNED NOT NULL COMMENT '会员ID',
  `terminal`     TINYINT UNSIGNED NOT NULL COMMENT '业务终端',
  `create_time`  INT UNSIGNED NULL DEFAULT NULL,
  `update_time`  INT UNSIGNED NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_terminal` (`member_id`,`terminal`),
  KEY `idx_principal_id` (`principal_id`),
  UNIQUE KEY `uk_oauth_identity_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_oauth_identity_tenant_subject` (`tenant_id`, `provider`, `client_key`, `subject`),
  UNIQUE KEY `uk_oauth_identity_tenant_member_client` (`tenant_id`, `member_id`, `provider`, `client_key`),
  KEY `idx_oauth_identity_tenant_member_terminal` (`tenant_id`, `member_id`, `terminal`),
  KEY `idx_oauth_identity_tenant_principal` (`tenant_id`, `principal_id`),
  CONSTRAINT `fk_oauth_identity_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_identity_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_identity_principal` FOREIGN KEY (`tenant_id`, `principal_id`) REFERENCES `pa_oauth_principal` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuth客户端身份';

CREATE TABLE `pa_oauth_attempt` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `state_hash`  CHAR(64) NOT NULL COMMENT 'state SHA-256',
  `scene`       VARCHAR(32) NOT NULL COMMENT 'oa/open_pc',
  `return_path` VARCHAR(500) NOT NULL DEFAULT '/' COMMENT '站内返回路径',
  `expires_at`  INT UNSIGNED NOT NULL,
  `used_at`     INT UNSIGNED NULL DEFAULT NULL,
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expires_at` (`expires_at`),
  UNIQUE KEY `uk_oauth_attempt_tenant_state` (`tenant_id`, `state_hash`),
  KEY `idx_oauth_attempt_tenant_expires` (`tenant_id`, `expires_at`),
  CONSTRAINT `fk_oauth_attempt_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuth一次性state';

CREATE TABLE `pa_tenant_setting` (
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

CREATE TABLE `pa_tenant_entry_binding` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `host` VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_entry_binding` (`host`, `client_key`),
  KEY `idx_tenant_entry_lookup` (`host`, `client_key`, `status`, `tenant_id`),
  CONSTRAINT `fk_tenant_entry_binding_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_entry_binding_status`
    CHECK (`status` IN ('active', 'disabled')),
  CONSTRAINT `chk_tenant_entry_binding_host`
    CHECK (`host` = LOWER(`host`) AND CHAR_LENGTH(`host`) BETWEEN 1 AND 253),
  CONSTRAINT `chk_tenant_entry_binding_client`
    CHECK (REGEXP_LIKE(`client_key`, '^[a-z][a-z0-9-]{0,63}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant_owner_invitation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `email_normalized` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(120) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `delivery_status` VARCHAR(32) NOT NULL DEFAULT 'pending_delivery',
  `delivery_provider` VARCHAR(64) NULL,
  `delivery_message_id` VARCHAR(190) NULL,
  `delivery_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `delivery_error_code` VARCHAR(96) NULL,
  `last_delivery_at` DATETIME(3) NULL,
  `generation` INT UNSIGNED NOT NULL DEFAULT 1,
  `expires_at` DATETIME(3) NOT NULL,
  `accepted_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `accepted_account_id` BIGINT UNSIGNED NULL,
  `accepted_member_id` BIGINT UNSIGNED NULL,
  `invited_by_operator_id` BIGINT UNSIGNED NOT NULL,
  `revoked_by_operator_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `pending_tenant_id` BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN `status` = 'pending' THEN `tenant_id` ELSE NULL END
  ) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_invitation_token_hash` (`token_hash`),
  UNIQUE KEY `uk_owner_invitation_pending_tenant` (`pending_tenant_id`),
  KEY `idx_owner_invitation_tenant_time` (`tenant_id`, `created_at`, `id`),
  KEY `idx_owner_invitation_status_expiry` (`status`, `expires_at`, `id`),
  CONSTRAINT `fk_owner_invitation_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_account` FOREIGN KEY (`accepted_account_id`)
    REFERENCES `pa_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_member` FOREIGN KEY (`tenant_id`, `accepted_member_id`)
    REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_inviter` FOREIGN KEY (`invited_by_operator_id`)
    REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_owner_invitation_revoker` FOREIGN KEY (`revoked_by_operator_id`)
    REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_owner_invitation_status` CHECK (
    `status` IN ('pending', 'accepted', 'revoked', 'expired')
  ),
  CONSTRAINT `chk_owner_invitation_delivery_status` CHECK (
    `delivery_status` IN ('pending_delivery', 'sent', 'failed')
  ),
  CONSTRAINT `chk_owner_invitation_accepted` CHECK (
    (`status` = 'accepted' AND `accepted_at` IS NOT NULL
      AND `accepted_account_id` IS NOT NULL AND `accepted_member_id` IS NOT NULL)
    OR (`status` <> 'accepted' AND `accepted_at` IS NULL
      AND `accepted_account_id` IS NULL AND `accepted_member_id` IS NULL)
  ),
  CONSTRAINT `chk_owner_invitation_revoked` CHECK (
    (`status` = 'revoked' AND `revoked_at` IS NOT NULL AND `revoked_by_operator_id` IS NOT NULL)
    OR (`status` <> 'revoked' AND `revoked_at` IS NULL AND `revoked_by_operator_id` IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_tenant_idempotency_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `tenant_member_id` BIGINT UNSIGNED NOT NULL,
  `operation_key` VARCHAR(160) NOT NULL,
  `idempotency_key_hash` CHAR(64) NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `response_status` SMALLINT UNSIGNED NULL,
  `response_body_json` JSON NULL,
  `resource_type` VARCHAR(160) NULL,
  `resource_id` VARCHAR(128) NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`, `tenant_member_id`, `operation_key`, `idempotency_key_hash`),
  KEY `idx_tenant_idempotency_expiry` (`expires_at`, `status`, `id`),
  CONSTRAINT `fk_tenant_idempotency_member`
    FOREIGN KEY (`tenant_id`, `tenant_member_id`)
    REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_tenant_idempotency_status`
    CHECK (`status` IN ('processing','completed','failed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_system_dict_type` (
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

CREATE TABLE `pa_system_dict_data` (
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

CREATE TABLE `pa_customer_service_setting` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    BIGINT UNSIGNED NOT NULL,
  `qr_file_id`   INT UNSIGNED NULL DEFAULT NULL,
  `wechat`       VARCHAR(100) NOT NULL DEFAULT '',
  `phone`        VARCHAR(50) NOT NULL DEFAULT '',
  `service_time` VARCHAR(100) NOT NULL DEFAULT '',
  `create_time`  INT UNSIGNED NULL DEFAULT NULL,
  `update_time`  INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_service_setting_tenant` (`tenant_id`),
  KEY `idx_customer_service_setting_tenant_qr` (`tenant_id`, `qr_file_id`),
  CONSTRAINT `fk_customer_service_setting_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_customer_service_setting_qr_file` FOREIGN KEY (`tenant_id`, `qr_file_id`) REFERENCES `pa_file` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tenant客服联系方式';

CREATE TABLE `pa_decorate_tabbar_setting` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `style` LONGTEXT NOT NULL,
  `create_time` INT UNSIGNED NULL DEFAULT NULL,
  `update_time` INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decorate_tabbar_setting_tenant` (`tenant_id`),
  CONSTRAINT `chk_decorate_tabbar_setting_style` CHECK (JSON_VALID(`style`) AND JSON_TYPE(`style`) = 'OBJECT'),
  CONSTRAINT `fk_decorate_tabbar_setting_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tenant 装修 Tabbar 样式';

CREATE TABLE `pa_task_job` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `task_type` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `handler_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` JSON NOT NULL,
  `payload_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `trusted_envelope` TEXT NOT NULL,
  `idempotency_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  `priority` SMALLINT NOT NULL DEFAULT 0,
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL,
  `available_at` DATETIME(3) NOT NULL,
  `lease_owner_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `lease_token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `lease_expires_at` DATETIME(3) NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_by_member_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_job_key` (`job_key`),
  UNIQUE KEY `uk_task_job_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_task_job_idempotency` (`tenant_id`, `created_by_member_id`, `task_type`, `idempotency_key_hash`),
  KEY `idx_task_job_claim` (`tenant_id`, `status`, `available_at`, `priority`, `id`),
  KEY `idx_task_job_lease` (`status`, `lease_expires_at`, `id`),
  CONSTRAINT `fk_task_job_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_task_job_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_job_key` CHECK (`job_key` REGEXP '^job_[0-9a-f]{32}$'),
  CONSTRAINT `chk_task_job_payload_hash` CHECK (`payload_hash` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_task_job_status` CHECK (`status` IN ('queued','running','succeeded','dead','cancelled')),
  CONSTRAINT `chk_task_job_attempts` CHECK (`max_attempts` BETWEEN 1 AND 10 AND `attempt_count` <= `max_attempts`),
  CONSTRAINT `chk_task_job_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_task_job_lease` CHECK ((`status` = 'running') = (`lease_owner_hash` IS NOT NULL AND `lease_token_hash` IS NOT NULL AND `lease_expires_at` IS NOT NULL)),
  CONSTRAINT `chk_task_job_completion` CHECK ((`status` IN ('succeeded','dead','cancelled')) = (`completed_at` IS NOT NULL)),
  `authorization_profile` VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT 'member',
  `producer_fact_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  UNIQUE KEY `uk_task_negative_fact` (`authorization_profile`,`producer_fact_key`),
  CONSTRAINT `chk_task_job_profile` CHECK ((`authorization_profile`='member' AND `created_by_member_id` IS NOT NULL AND `producer_fact_key` IS NULL) OR (`authorization_profile`='identity_negative_fact' AND `created_by_member_id` IS NULL AND `producer_fact_key` IS NOT NULL AND `task_type`='identity.revocation.propagate' AND `handler_key`='identity.revocation.propagate'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_task_job_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `job_id` BIGINT UNSIGNED NOT NULL,
  `attempt_number` SMALLINT UNSIGNED NOT NULL,
  `worker_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `started_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_attempt_number` (`tenant_id`, `job_id`, `attempt_number`),
  KEY `idx_task_attempt_status` (`tenant_id`, `status`, `started_at`, `id`),
  CONSTRAINT `fk_task_attempt_job` FOREIGN KEY (`tenant_id`, `job_id`) REFERENCES `pa_task_job` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_attempt_status` CHECK (`status` IN ('running','succeeded','retry','dead','abandoned')),
  CONSTRAINT `chk_task_attempt_completion` CHECK ((`status` = 'running') = (`completed_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_task_job_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `job_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `metadata_json` JSON NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_event_job` (`tenant_id`, `job_id`, `id`),
  KEY `idx_task_event_time` (`tenant_id`, `occurred_at`, `id`),
  CONSTRAINT `fk_task_event_job` FOREIGN KEY (`tenant_id`, `job_id`) REFERENCES `pa_task_job` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_task_event_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_event_key` CHECK (`event_key` REGEXP '^tenant\\.task\\.[a-z_]+$'),
  `event_reference` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  UNIQUE KEY `uk_task_event_reference` (`job_id`,`event_reference`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_import_export_operation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `operation_key` VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `provider_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `direction` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `format` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'csv',
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  `input_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `result_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `error_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `task_job_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `schema_revision` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mapping_json` JSON NOT NULL,
  `processed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `accepted_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `rejected_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `attempt_number` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `idempotency_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `retention_until` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_import_export_key` (`operation_key`),
  UNIQUE KEY `uk_import_export_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_import_export_idempotency` (`tenant_id`, `created_by_member_id`, `direction`, `provider_key`, `idempotency_key_hash`),
  KEY `idx_import_export_status` (`tenant_id`, `status`, `id`),
  KEY `idx_import_export_task` (`tenant_id`, `task_job_key`),
  KEY `idx_import_export_retention` (`status`, `retention_until`, `id`),
  CONSTRAINT `fk_import_export_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_import_export_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_import_export_key` CHECK (`operation_key` REGEXP '^iox_[0-9a-f]{32}$'),
  CONSTRAINT `chk_import_export_direction` CHECK (`direction` IN ('import','export')),
  CONSTRAINT `chk_import_export_format` CHECK (`format` = 'csv'),
  CONSTRAINT `chk_import_export_status` CHECK (`status` IN ('queued','running','cancel_requested','succeeded','failed','cancelled','expired')),
  CONSTRAINT `chk_import_export_input` CHECK ((`direction` = 'import') = (`input_file_key` IS NOT NULL)),
  CONSTRAINT `chk_import_export_files` CHECK ((`result_file_key` IS NULL OR `result_file_key` REGEXP '^file_[0-9a-f]{32}$') AND (`error_file_key` IS NULL OR `error_file_key` REGEXP '^file_[0-9a-f]{32}$')),
  CONSTRAINT `chk_import_export_task` CHECK (`task_job_key` IS NULL OR `task_job_key` REGEXP '^job_[0-9a-f]{32}$'),
  CONSTRAINT `chk_import_export_hashes` CHECK (`idempotency_key_hash` REGEXP '^[0-9a-f]{64}$' AND `request_hash` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_import_export_progress` CHECK (`accepted_rows` + `rejected_rows` <= `processed_rows` AND `processed_rows` <= 100000 AND `total_rows` <= 100000),
  CONSTRAINT `chk_import_export_attempt` CHECK (`attempt_number` <= 10),
  CONSTRAINT `chk_import_export_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_import_export_completion` CHECK ((`status` IN ('succeeded','failed','cancelled','expired')) = (`completed_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_import_export_row_error` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `operation_id` BIGINT UNSIGNED NOT NULL,
  `row_number` INT UNSIGNED NOT NULL,
  `column_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `column_key_unique` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (COALESCE(`column_key`, '')) STORED,
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_import_export_row_error` (`tenant_id`, `operation_id`, `row_number`, `column_key_unique`, `error_code`),
  KEY `idx_import_export_row_error` (`tenant_id`, `operation_id`, `row_number`, `id`),
  CONSTRAINT `fk_import_export_row_operation` FOREIGN KEY (`tenant_id`, `operation_id`) REFERENCES `pa_import_export_operation` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_import_export_row_number` CHECK (`row_number` >= 2 AND `row_number` <= 100001),
  CONSTRAINT `chk_import_export_column` CHECK (`column_key` IS NULL OR `column_key` REGEXP '^[a-z][a-z0-9_]{0,63}$'),
  CONSTRAINT `chk_import_export_error_code` CHECK (`error_code` REGEXP '^[A-Z][A-Z0-9_]{2,63}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_storage_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `driver` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `credential_ciphertext` MEDIUMTEXT NULL,
  `credential_key_version` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `credential_rotated_at` DATETIME(3) NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_storage_account_key` (`account_key`),
  CONSTRAINT `chk_storage_account_driver` CHECK (`driver` IN ('local','qiniu','aliyun','qcloud')),
  CONSTRAINT `chk_storage_account_status` CHECK (`status` IN ('active','disabled')),
  CONSTRAINT `chk_storage_account_credentials` CHECK ((`driver`='local' AND `credential_ciphertext` IS NULL AND `credential_key_version` IS NULL AND `credential_rotated_at` IS NULL) OR (`driver`<>'local' AND `credential_ciphertext` IS NOT NULL AND `credential_key_version` IS NOT NULL AND `credential_rotated_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_storage_space` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `space_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `access_type` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `bucket` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `region` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `endpoint` VARCHAR(500) NULL,
  `access_domain` VARCHAR(500) NULL,
  `local_path` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_storage_space_key` (`space_key`),
  UNIQUE KEY `uk_storage_space_location` (`account_id`,`bucket`,`region`),
  CONSTRAINT `fk_storage_space_account` FOREIGN KEY (`account_id`) REFERENCES `pa_storage_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_storage_space_access` CHECK (`access_type` IN ('public','private')),
  CONSTRAINT `chk_storage_space_status` CHECK (`status` IN ('active','read_only','disabled')),
  CONSTRAINT `chk_storage_space_shape` CHECK ((`local_path` IS NOT NULL AND `bucket` IS NULL AND `region` IS NULL AND `endpoint` IS NULL) OR (`local_path` IS NULL AND `bucket` IS NOT NULL)),
  CONSTRAINT `chk_storage_space_local_path` CHECK (`local_path` IS NULL OR `local_path` IN ('public/storage','private/storage'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_storage_route` (
  `route_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `access_type` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `space_id` BIGINT UNSIGNED NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`route_key`),
  KEY `idx_storage_route_space` (`space_id`),
  CONSTRAINT `fk_storage_route_space` FOREIGN KEY (`space_id`) REFERENCES `pa_storage_space` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_storage_route_access` CHECK (`access_type` IN ('public','private')),
  CONSTRAINT `chk_storage_route_key` CHECK (`route_key` REGEXP '^[a-z][a-z0-9._-]{2,95}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_file_object` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `purpose` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'export.csv',
  `access_type` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'private',
  `storage_space_id` BIGINT UNSIGNED NOT NULL,
  `object_key` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `disposition` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'attachment',
  `original_name` VARCHAR(255) NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ready',
  `created_by_member_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_object_key` (`file_key`),
  UNIQUE KEY `uk_file_object_storage` (`storage_space_id`, `object_key`),
  KEY `idx_file_object_purpose` (`tenant_id`, `purpose`, `status`, `id`),
  KEY `idx_file_object_status` (`tenant_id`, `status`, `id`),
  KEY `idx_file_object_sha256` (`tenant_id`, `sha256`),
  CONSTRAINT `fk_file_object_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_object_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_object_space` FOREIGN KEY (`storage_space_id`) REFERENCES `pa_storage_space` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_object_key` CHECK (`file_key` REGEXP '^file_[0-9a-f]{32}$'),
  CONSTRAINT `chk_file_object_sha256` CHECK (`sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_file_object_purpose` CHECK (`purpose` REGEXP '^[a-z][a-z0-9._-]{2,95}$'),
  CONSTRAINT `chk_file_object_access` CHECK (`access_type` IN ('public','private')),
  CONSTRAINT `chk_file_object_disposition` CHECK (`disposition` IN ('inline','attachment')),
  CONSTRAINT `chk_file_object_revision` CHECK (`revision` >= 1),
  UNIQUE KEY `uk_file_object_tenant_key` (`tenant_id`, `file_key`),
  `visibility_batch_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  CONSTRAINT `chk_file_object_status` CHECK (`status` IN ('pending_write','write_failed','ready','archived','restricting','verifying','restricted_attention')),
  CONSTRAINT `chk_file_object_archive_shape` CHECK (((`status` IN ('pending_write','write_failed','ready','restricting','verifying','restricted_attention')) AND `archived_at` IS NULL) OR (`status`='archived' AND `archived_at` IS NOT NULL)),
  CONSTRAINT `chk_file_object_visibility_operation` CHECK ((`status` IN ('restricting','verifying','restricted_attention') AND `visibility_batch_key` IS NOT NULL) OR (`status` IN ('pending_write','write_failed','ready','archived') AND `visibility_batch_key` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_module_installation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(96) NOT NULL,
  `installed_version` VARCHAR(32) NOT NULL,
  `manifest_schema_version` INT UNSIGNED NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `status` VARCHAR(24) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `installed_at` DATETIME(3) NULL,
  `activated_at` DATETIME(3) NULL,
  `upgraded_at` DATETIME(3) NULL,
  `last_error_code` VARCHAR(96) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_installation_key` (`module_key`),
  KEY `idx_module_installation_status` (`status`, `module_key`),
  CONSTRAINT `chk_module_installation_status` CHECK (`status` IN ('installing','active','upgrading','maintenance','failed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_transaction_setting` (
  `id`                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`                    BIGINT UNSIGNED NOT NULL,
  `cancel_unpaid_orders`         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `cancel_unpaid_orders_times`   INT UNSIGNED NOT NULL DEFAULT 30,
  `verification_orders`          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `verification_orders_times`    INT UNSIGNED NOT NULL DEFAULT 24,
  `create_time`                  INT UNSIGNED NULL DEFAULT NULL,
  `update_time`                  INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transaction_setting_tenant` (`tenant_id`),
  CONSTRAINT `chk_transaction_setting_cancel_mode` CHECK (`cancel_unpaid_orders` IN (0, 1)),
  CONSTRAINT `chk_transaction_setting_cancel_time` CHECK (`cancel_unpaid_orders_times` > 0),
  CONSTRAINT `chk_transaction_setting_verify_mode` CHECK (`verification_orders` IN (0, 1)),
  CONSTRAINT `chk_transaction_setting_verify_time` CHECK (`verification_orders_times` > 0),
  CONSTRAINT `fk_transaction_setting_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tenant交易运营政策';

CREATE TABLE `pa_external_channel_binding` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `provider` VARCHAR(64) NOT NULL,
  `callback_key` CHAR(64) NOT NULL COMMENT 'server generated opaque callback route key',
  `identity_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of canonical public provider identity',
  `identity_hint` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'non-secret audit/display hint',
  `config_json` JSON NOT NULL COMMENT 'server-controlled provider verifier/transport config',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_external_callback_key` (`provider`, `callback_key`),
  UNIQUE KEY `uk_external_provider_identity` (`provider`, `identity_hash`),
  UNIQUE KEY `uk_external_tenant_provider` (`tenant_id`, `provider`),
  KEY `idx_external_tenant_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_external_binding_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tenant-owned external provider binding and callback verifier configuration';

CREATE TABLE `pa_plugin_installation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_key` VARCHAR(96) NOT NULL,
  `installed_version` VARCHAR(32) NOT NULL,
  `source` VARCHAR(255) NOT NULL,
  `artifact_sha256` CHAR(64) NOT NULL,
  `lock_digest` CHAR(64) NOT NULL,
  `composer_identity_json` JSON NOT NULL,
  `npm_identity_json` JSON NOT NULL,
  `frontend_identity_json` JSON NOT NULL,
  `status` VARCHAR(24) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `installed_at` DATETIME(3) NULL,
  `activated_at` DATETIME(3) NULL,
  `upgraded_at` DATETIME(3) NULL,
  `uninstalled_at` DATETIME(3) NULL,
  `last_error_code` VARCHAR(96) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_installation_key` (`plugin_key`),
  KEY `idx_plugin_installation_status` (`status`, `plugin_key`),
  CONSTRAINT `chk_plugin_installation_status` CHECK (`status` IN (
    'installing','active','upgrading','maintenance','failed','uninstalled'
  ))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_plugin_module` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_key` VARCHAR(96) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `module_version` VARCHAR(32) NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin_module_key` (`module_key`),
  KEY `idx_plugin_module_plugin` (`plugin_key`, `module_key`),
  CONSTRAINT `fk_plugin_module_plugin` FOREIGN KEY (`plugin_key`)
    REFERENCES `pa_plugin_installation` (`plugin_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_protected_resource` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `ownership` VARCHAR(32) NOT NULL,
  `provider_key` VARCHAR(160) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_version` VARCHAR(32) NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `retired_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_protected_resource_key` (`key`),
  KEY `idx_protected_resource_module` (`module_key`, `status`),
  CONSTRAINT `chk_protected_resource_ownership` CHECK (`ownership` IN ('tenant_owned', 'business_target_owned', 'shared_master', 'global_reference', 'platform_internal')),
  CONSTRAINT `chk_protected_resource_status` CHECK (`status` IN ('active', 'retired')),
  CONSTRAINT `chk_protected_resource_retired` CHECK ((`status` = 'retired' AND `retired_at` IS NOT NULL) OR `status` <> 'retired')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_target_type` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `resolver_key` VARCHAR(160) NOT NULL,
  `catalog_provider_key` VARCHAR(160) NOT NULL,
  `id_format` VARCHAR(16) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_version` VARCHAR(32) NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_target_type_key` (`key`),
  KEY `idx_target_type_module` (`module_key`, `status`),
  CONSTRAINT `chk_target_type_id_format` CHECK (`id_format` IN ('decimal', 'uuid', 'ulid', 'string')),
  CONSTRAINT `chk_target_type_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_resource_operation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `protected_resource_id` BIGINT UNSIGNED NOT NULL,
  `operation` VARCHAR(64) NOT NULL,
  `access_mode` VARCHAR(32) NOT NULL,
  `target_cardinality` VARCHAR(32) NOT NULL,
  `permission_match` VARCHAR(8) NOT NULL DEFAULT 'all',
  `audit_level` VARCHAR(32) NOT NULL DEFAULT 'deny_and_write',
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation` (`protected_resource_id`, `operation`),
  CONSTRAINT `fk_resource_operation_resource` FOREIGN KEY (`protected_resource_id`)
    REFERENCES `pa_protected_resource` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_resource_operation_access` CHECK (`access_mode` IN ('tenant_wide', 'rule_filtered', 'explicit_targets', 'global_reference_read', 'system_internal')),
  CONSTRAINT `chk_resource_operation_cardinality` CHECK (`target_cardinality` IN ('none', 'one_required', 'many_readable', 'aggregate_read', 'policy_publish', 'bulk_write')),
  CONSTRAINT `chk_resource_operation_permission_match` CHECK (`permission_match` IN ('all', 'any')),
  CONSTRAINT `chk_resource_operation_audit` CHECK (`audit_level` IN ('deny', 'write', 'deny_and_write', 'all')),
  CONSTRAINT `chk_resource_operation_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_resource_operation_target_type` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_operation_id` BIGINT UNSIGNED NOT NULL,
  `target_type_id` BIGINT UNSIGNED NOT NULL,
  `target_role` VARCHAR(64) NOT NULL DEFAULT 'primary',
  `input_mode` VARCHAR(16) NOT NULL DEFAULT 'explicit',
  `policy_selection_permission_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation_target_type` (`resource_operation_id`, `target_role`, `target_type_id`),
  CONSTRAINT `fk_operation_target_operation` FOREIGN KEY (`resource_operation_id`)
    REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_target_type` FOREIGN KEY (`target_type_id`)
    REFERENCES `pa_target_type` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_target_selection_permission` FOREIGN KEY (`policy_selection_permission_id`)
    REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_operation_target_input` CHECK (`input_mode` IN ('explicit', 'derived', 'either')),
  CONSTRAINT `chk_operation_target_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_resource_operation_permission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_operation_id` BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation_permission` (`resource_operation_id`, `permission_id`),
  CONSTRAINT `fk_operation_permission_operation` FOREIGN KEY (`resource_operation_id`)
    REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_permission_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_data_condition_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `category` VARCHAR(32) NOT NULL,
  `target_mode` VARCHAR(32) NOT NULL,
  `config_schema_json` JSON NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_version` VARCHAR(32) NOT NULL,
  `manifest_digest` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_condition_key` (`key`),
  CONSTRAINT `chk_data_condition_category` CHECK (`category` IN ('tenant', 'self', 'department', 'selected', 'relation')),
  CONSTRAINT `chk_data_condition_target_mode` CHECK (`target_mode` IN ('none', 'department', 'resource')),
  CONSTRAINT `chk_data_condition_definition_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_resource_operation_condition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_operation_id` BIGINT UNSIGNED NOT NULL,
  `condition_definition_id` BIGINT UNSIGNED NOT NULL,
  `selector_resource_key` VARCHAR(160) NULL,
  `selector_resource_key_norm` VARCHAR(160) GENERATED ALWAYS AS (COALESCE(`selector_resource_key`, '')) STORED,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_operation_condition` (`resource_operation_id`, `condition_definition_id`, `selector_resource_key_norm`),
  CONSTRAINT `fk_operation_condition_operation` FOREIGN KEY (`resource_operation_id`)
    REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_operation_condition_definition` FOREIGN KEY (`condition_definition_id`)
    REFERENCES `pa_data_condition_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_operation_condition_status` CHECK (`status` IN ('active', 'retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_menu_definition` (
  `is_show` TINYINT NOT NULL DEFAULT 1,
  `is_cache` TINYINT NOT NULL DEFAULT 0,
  `is_disable` TINYINT NOT NULL DEFAULT 0,
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(160) NOT NULL,
  `module_key` VARCHAR(96) NOT NULL,
  `scope` VARCHAR(16) NOT NULL,
  `parent_key` VARCHAR(160) NULL,
  `type` VARCHAR(16) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `route_name` VARCHAR(160) NULL,
  `route_path` VARCHAR(255) NULL,
  `component_key` VARCHAR(160) NULL,
  `icon` VARCHAR(64) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `required_permission_id` BIGINT UNSIGNED NULL,
  `client_keys_json` JSON NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `manifest_digest` CHAR(64) NOT NULL,
  `upstream_defaults_json` JSON NULL,
  `menu_conflict_json` JSON NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_menu_definition_key` (`key`),
  UNIQUE KEY `uk_menu_route_name` (`scope`, `route_name`),
  KEY `idx_menu_module` (`module_key`, `scope`, `status`, `sort_order`),
  CONSTRAINT `fk_menu_permission` FOREIGN KEY (`required_permission_id`)
    REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_menu_scope` CHECK (`scope` IN ('platform','tenant')),
  CONSTRAINT `chk_menu_type` CHECK (`type` IN ('group','page','link')),
  CONSTRAINT `chk_menu_status` CHECK (`status` IN ('active','retired')),
  CONSTRAINT `chk_menu_page` CHECK (`type` <> 'page' OR (
    `route_name` IS NOT NULL AND `component_key` IS NOT NULL
    AND `required_permission_id` IS NOT NULL
  ))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_setting_definition` (
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
  CONSTRAINT `chk_setting_definition_target` CHECK ((
    `target_scope_flag` = 1 AND `target_resource_key` IS NOT NULL
    AND `target_operation` IS NOT NULL
  ) OR (
    `target_scope_flag` = 0 AND `target_resource_key` IS NULL
    AND `target_operation` IS NULL
  )),
  CONSTRAINT `chk_setting_definition_default` CHECK (`secret_flag` = 0 OR `default_json` IS NULL),
  CONSTRAINT `chk_setting_definition_status` CHECK (`status` IN ('active','retired'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_setting_deployment_value` (
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
  CONSTRAINT `fk_setting_deployment_definition` FOREIGN KEY (`definition_id`)
    REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_deployment_operator` FOREIGN KEY (`updated_by_operator_id`)
    REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_deployment_state` CHECK (`value_state` IN ('set','unset')),
  CONSTRAINT `chk_setting_deployment_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_deployment_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND ((`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_setting_tenant_value` (
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
  CONSTRAINT `fk_setting_tenant_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_tenant_definition` FOREIGN KEY (`definition_id`)
    REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_tenant_member` FOREIGN KEY (`updated_by_member_id`)
    REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_tenant_state` CHECK (`value_state` IN ('set','unset')),
  CONSTRAINT `chk_setting_tenant_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_tenant_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND ((`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_setting_target_value` (
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
  CONSTRAINT `fk_setting_target_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_target_definition` FOREIGN KEY (`definition_id`)
    REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_target_member` FOREIGN KEY (`updated_by_member_id`)
    REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_target_state` CHECK (`value_state` IN ('set','unset')),
  CONSTRAINT `chk_setting_target_interval` CHECK (`expires_at` IS NULL OR `expires_at` > `effective_at`),
  CONSTRAINT `chk_setting_target_storage` CHECK (
    (`value_state` = 'unset' AND `value_json` IS NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
    OR (`value_state` = 'set' AND ((`value_json` IS NOT NULL AND `ciphertext` IS NULL AND `nonce` IS NULL AND `key_id` IS NULL)
      OR (`value_json` IS NULL AND `ciphertext` IS NOT NULL AND OCTET_LENGTH(`ciphertext`) > 0 AND `nonce` IS NOT NULL AND `key_id` IS NOT NULL)))
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_refund_log` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `sn`            VARCHAR(32)  DEFAULT NULL,
  `record_id`     INT          NOT NULL,
  `handle_id`     INT          NOT NULL DEFAULT 0,
  `order_amount`  DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `refund_amount` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `refund_status` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `refund_msg`    TEXT         DEFAULT NULL,
  `create_time`   INT UNSIGNED DEFAULT 0,
  `update_time`   INT          DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`sn`),
  KEY `idx_record_id` (`record_id`),
  KEY `idx_refund_status` (`refund_status`),
  KEY `idx_create_time` (`create_time`),
  UNIQUE KEY `uk_refund_log_tenant_id` (`tenant_id`, `id`),
  KEY `idx_refund_log_tenant_record_time` (`tenant_id`, `record_id`, `create_time`, `id`),
  CONSTRAINT `fk_refund_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_log_member` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_refund_log_record` FOREIGN KEY (`tenant_id`, `record_id`) REFERENCES `pa_refund_record` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退款日志';

CREATE TABLE `pa_oauth_completion_ticket` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash`       CHAR(64) NOT NULL COMMENT '票据 SHA-256',
  `member_id`        INT UNSIGNED NOT NULL,
  `need_profile`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `need_mobile`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at`       INT UNSIGNED NOT NULL,
  `used_at`          INT UNSIGNED NULL DEFAULT NULL,
  `create_time`      INT UNSIGNED NULL DEFAULT NULL,
  `update_time`      INT UNSIGNED NULL DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `binding_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_expires_at` (`expires_at`),
  UNIQUE KEY `uk_oauth_ticket_tenant_token` (`tenant_id`, `token_hash`),
  KEY `idx_oauth_ticket_tenant_member` (`tenant_id`, `member_id`),
  KEY `idx_oauth_ticket_tenant_expires` (`tenant_id`, `expires_at`),
  CONSTRAINT `fk_oauth_ticket_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_ticket_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  KEY `idx_oauth_ticket_binding` (`binding_id`,`expires_at`),
  CONSTRAINT `fk_oauth_ticket_external_binding`
    FOREIGN KEY (`binding_id`) REFERENCES `pa_external_channel_binding` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OAuth首登补全票据';

CREATE TABLE `pa_payment_tenant_channel_grant` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `provider` VARCHAR(64) NOT NULL,
  `external_binding_id` BIGINT UNSIGNED NOT NULL,
  `merchant_account_ref` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'non-secret merchant account reference',
  `merchant_group_ref` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'non-secret merchant group reference',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `revoked_at` INT UNSIGNED NULL DEFAULT NULL,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `active_key` TINYINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN `status` = 1 AND `revoked_at` IS NULL THEN 1 ELSE NULL END
  ) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_grant_tenant_provider_active` (`tenant_id`, `provider`, `active_key`),
  UNIQUE KEY `uk_payment_grant_tenant_binding` (`tenant_id`, `provider`, `external_binding_id`),
  KEY `idx_payment_grant_binding_status` (`external_binding_id`, `status`, `id`),
  CONSTRAINT `fk_payment_grant_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payment_grant_external_binding`
    FOREIGN KEY (`external_binding_id`) REFERENCES `pa_external_channel_binding` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Payment-owned Tenant grant to use an external merchant channel';

CREATE TABLE `pa_ops_backup_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `backup_reference_key` CHAR(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `manifest_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_commit` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_tree` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_release_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `consistency_started_at` DATETIME(3) NOT NULL,
  `consistency_completed_at` DATETIME(3) NOT NULL,
  `verified_at` DATETIME(3) NOT NULL,
  `manifest_json` JSON NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_backup_reference` (`backup_reference_key`),
  UNIQUE KEY `uk_ops_backup_task` (`task_key`),
  KEY `idx_ops_backup_verified` (`verified_at`, `id`),
  CONSTRAINT `fk_ops_backup_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_backup_provider` CHECK (`provider_key` = 'peanut.paired-db-files'),
  CONSTRAINT `chk_ops_backup_window` CHECK (`consistency_completed_at` >= `consistency_started_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_ops_restore_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `backup_reference_key` CHAR(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `manifest_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evidence_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_commit` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_tree` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_deployment_resource_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_database_resource_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_runtime_resource_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `table_count` INT UNSIGNED NOT NULL,
  `schema_migration_count` INT UNSIGNED NOT NULL,
  `critical_table_count` INT UNSIGNED NOT NULL,
  `account_count` BIGINT UNSIGNED NOT NULL,
  `tenant_count` BIGINT UNSIGNED NOT NULL,
  `tenant_member_count` BIGINT UNSIGNED NOT NULL,
  `storage_file_count` BIGINT UNSIGNED NOT NULL,
  `storage_bytes` BIGINT UNSIGNED NOT NULL,
  `protected_runtime_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `verified_at` DATETIME(3) NOT NULL,
  `evidence_json` JSON NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_restore_task` (`task_key`),
  KEY `idx_ops_restore_verified` (`verified_at`, `id`),
  KEY `idx_ops_restore_backup` (`backup_reference_key`),
  CONSTRAINT `fk_ops_restore_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_restore_backup` FOREIGN KEY (`backup_reference_key`) REFERENCES `pa_ops_backup_evidence` (`backup_reference_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_restore_provider` CHECK (`provider_key` = 'peanut.paired-db-files'),
  CONSTRAINT `chk_ops_restore_target` CHECK (`target_key` = 'isolated-new-target'),
  CONSTRAINT `chk_ops_restore_critical_tables` CHECK (`critical_table_count` = 6)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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

CREATE TABLE `pa_provider_qualification_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evidence_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NULL,
  `scope_reference` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evidence_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `outcome` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `config_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status_code` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `observed_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `recorded_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_qualification_evidence_key` (`evidence_key`),
  KEY `idx_provider_qualification_subject_observed`
    (`provider_key`,`scope_type`,`tenant_id`,`observed_at`),
  KEY `idx_provider_qualification_expiry` (`expires_at`,`id`),
  CONSTRAINT `fk_provider_qualification_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_provider_qualification_scope`
    CHECK ((`scope_type`='tenant' AND `tenant_id` IS NOT NULL)
      OR (`scope_type`='instance' AND `tenant_id` IS NULL)),
  CONSTRAINT `chk_provider_qualification_type`
    CHECK (`evidence_type` IN ('connectivity','callback','production')),
  CONSTRAINT `chk_provider_qualification_outcome`
    CHECK (`outcome` IN ('passed','failed')),
  CONSTRAINT `chk_provider_qualification_digest`
    CHECK (`config_digest` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_provider_qualification_expiry_order`
    CHECK (`expires_at` > `observed_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Append-only safe Provider qualification evidence';

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

CREATE TABLE `pa_data_permission_policy` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `protected_resource_id` BIGINT UNSIGNED NOT NULL,
  `resource_operation_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `valid_from` DATETIME(3) NULL,
  `valid_until` DATETIME(3) NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `reason` VARCHAR(300) NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_policy_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_data_policy` (`tenant_id`, `role_id`, `resource_operation_id`),
  KEY `idx_data_policy_active` (`tenant_id`, `role_id`, `status`, `valid_until`),
  CONSTRAINT `fk_data_policy_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_role` FOREIGN KEY (`tenant_id`, `role_id`) REFERENCES `pa_role` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_resource` FOREIGN KEY (`protected_resource_id`) REFERENCES `pa_protected_resource` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_operation` FOREIGN KEY (`resource_operation_id`) REFERENCES `pa_resource_operation` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_creator` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_policy_updater` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_policy_status` CHECK (`status` IN ('active', 'disabled', 'archived')),
  CONSTRAINT `chk_data_policy_dates` CHECK (`valid_until` IS NULL OR `valid_from` IS NULL OR `valid_until` > `valid_from`),
  CONSTRAINT `chk_data_policy_archived` CHECK ((`status` = 'archived' AND `archived_at` IS NOT NULL) OR `status` <> 'archived')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_data_permission_target_set` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `target_mode` VARCHAR(32) NOT NULL,
  `target_resource_key` VARCHAR(160) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_data_target_set_tenant_id` (`tenant_id`, `id`),
  KEY `idx_data_target_set_status` (`tenant_id`, `target_resource_key`, `status`, `id`),
  CONSTRAINT `fk_data_target_set_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_set_creator` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_data_target_set_updater` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_data_target_set_mode` CHECK (`target_mode` IN ('department', 'resource')),
  CONSTRAINT `chk_data_target_set_resource` CHECK ((`target_mode` = 'department' AND `target_resource_key` = 'core.department') OR (`target_mode` = 'resource' AND `target_resource_key` <> 'core.department')),
  CONSTRAINT `chk_data_target_set_status` CHECK (`status` IN ('active', 'disabled', 'archived')),
  CONSTRAINT `chk_data_target_set_archived` CHECK ((`status` = 'archived' AND `archived_at` IS NOT NULL) OR `status` <> 'archived')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_rich_text_document` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `document_json` JSON NOT NULL,
  `collaboration_state` LONGBLOB NULL,
  `revision` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rich_text_document_tenant_id` (`tenant_id`,`id`),
  KEY `idx_rich_text_document_tenant_updated` (`tenant_id`,`delete_time`,`update_time`,`id`),
  CONSTRAINT `fk_rich_text_document_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rich_text_document_created_by`
    FOREIGN KEY (`tenant_id`,`created_by_member_id`)
    REFERENCES `pa_tenant_member` (`tenant_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rich_text_document_updated_by`
    FOREIGN KEY (`tenant_id`,`updated_by_member_id`)
    REFERENCES `pa_tenant_member` (`tenant_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_rich_text_document_revision` CHECK (`revision` > 0)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Tenant-owned structured rich-text documents';

CREATE TABLE `pa_notification_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `subject_template` VARCHAR(255) NOT NULL,
  `body_template` TEXT NOT NULL,
  `channels_json` JSON NOT NULL,
  `variable_keys_json` JSON NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_template` (`tenant_id`, `template_key`),
  KEY `idx_notification_template_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_notification_template_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_template_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_template_key` CHECK (`template_key` REGEXP '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_notification_template_status` CHECK (`status` IN ('active','inactive')),
  CONSTRAINT `chk_notification_template_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB;

CREATE TABLE `pa_notification_message` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `template_revision` BIGINT UNSIGNED NOT NULL,
  `recipient_member_id` BIGINT UNSIGNED NOT NULL,
  `recipient_account_id` BIGINT UNSIGNED NOT NULL,
  `recipient_display_name` VARCHAR(160) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unread',
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `read_at` DATETIME(3) NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_message_key` (`message_key`),
  UNIQUE KEY `uk_notification_message_tenant_id` (`tenant_id`, `id`),
  KEY `idx_notification_inbox` (`tenant_id`, `recipient_member_id`, `status`, `id`),
  KEY `idx_notification_template` (`tenant_id`, `template_key`, `id`),
  CONSTRAINT `fk_notification_message_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_recipient_member` FOREIGN KEY (`tenant_id`, `recipient_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_creator_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_message_key` CHECK (`message_key` REGEXP '^notice_[0-9a-f]{32}$'),
  CONSTRAINT `chk_notification_message_template_revision` CHECK (`template_revision` >= 1),
  CONSTRAINT `chk_notification_message_status` CHECK (`status` IN ('unread','read','archived')),
  CONSTRAINT `chk_notification_message_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_notification_message_read` CHECK ((`status` = 'unread') = (`read_at` IS NULL)),
  CONSTRAINT `chk_notification_message_archive` CHECK ((`status` = 'archived') = (`archived_at` IS NOT NULL)),
  `producer_key` VARCHAR(160) NULL,
  `producer_event_key` VARCHAR(160) NULL,
  `producer_business_key` VARCHAR(160) NULL,
  `producer_digest` CHAR(64) NULL,
  `producer_payload_sha256` CHAR(64) NULL,
  UNIQUE KEY `uq_notification_producer_recipient` (`tenant_id`, `producer_digest`, `recipient_member_id`)
) ENGINE=InnoDB;

CREATE TABLE `pa_notification_attachment` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_attachment` (`tenant_id`, `message_id`, `file_key`),
  CONSTRAINT `fk_notification_attachment_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_notification_message` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_attachment_file` CHECK (`file_key` REGEXP '^file_[0-9a-f]{32}$'),
  CONSTRAINT `chk_notification_attachment_sha` CHECK (`sha256` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB;

CREATE TABLE `pa_notification_outbox` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `outbox_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `channel` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recipient_phone_masked` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `recipient_phone_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `dispatch_job_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `provider_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `provider_message_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `provider_receipt_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `delivered_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_outbox_key` (`outbox_key`),
  UNIQUE KEY `uk_notification_outbox_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_notification_outbox_channel` (`tenant_id`, `message_id`, `channel`),
  KEY `idx_notification_outbox_pending` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_notification_outbox_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_notification_message` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_outbox_key` CHECK (`outbox_key` REGEXP '^outbox_[0-9a-f]{32}$'),
  CONSTRAINT `chk_notification_outbox_channel` CHECK (`channel` IN ('inbox','sms')),
  CONSTRAINT `chk_notification_outbox_status` CHECK (`status` IN ('pending','queued','processing','retryable','delivered','permanent_failed')),
  CONSTRAINT `chk_notification_outbox_phone` CHECK ((`channel` = 'sms') = (`recipient_phone_masked` IS NOT NULL AND `recipient_phone_digest` IS NOT NULL)),
  CONSTRAINT `chk_notification_outbox_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_notification_outbox_delivery` CHECK ((`status` = 'delivered') = (`delivered_at` IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE `pa_sms_rate_bucket` (
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `bucket_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_seconds` SMALLINT UNSIGNED NOT NULL,
  `window_started_at` DATETIME(3) NOT NULL,
  `send_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`tenant_id`, `bucket_key`),
  CONSTRAINT `fk_sms_rate_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_sms_rate_bucket` CHECK (`bucket_key` REGEXP '^(tenant|recipient:[0-9a-f]{64})$'),
  CONSTRAINT `chk_sms_rate_window` CHECK (`window_seconds` BETWEEN 1 AND 3600)
) ENGINE=InnoDB;

CREATE TABLE `pa_notification_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `metadata_json` JSON NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notification_event_message` (`tenant_id`, `message_id`, `id`),
  KEY `idx_notification_event_time` (`tenant_id`, `occurred_at`, `id`),
  CONSTRAINT `fk_notification_event_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_notification_message` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_event_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_event_key` CHECK (`event_key` REGEXP '^tenant\\.notification\\.[a-z_]+$')
) ENGINE=InnoDB;

CREATE TABLE `pa_reference_code_set` (
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

CREATE TABLE `pa_reference_code_entry` (
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

CREATE TABLE `pa_reference_code_entry_version` (
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

CREATE TABLE `pa_member_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `member_id` INT UNSIGNED NOT NULL,
  `session_revision` BIGINT UNSIGNED NOT NULL,
  `issued_at` BIGINT UNSIGNED NOT NULL,
  `expires_at` BIGINT UNSIGNED NOT NULL,
  `revoked_at` BIGINT UNSIGNED NULL,
  `revoke_reason` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_session_hash` (`session_hash`),
  KEY `idx_member_session_subject` (`tenant_id`, `member_id`, `revoked_at`, `expires_at`, `id`),
  CONSTRAINT `fk_member_session_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_session_member`
    FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_member_session_hash` CHECK (`session_hash` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_member_session_record_revision` CHECK (`session_revision` >= 1),
  CONSTRAINT `chk_member_session_expiry` CHECK (`expires_at` > `issued_at`),
  CONSTRAINT `chk_member_session_revocation` CHECK (
    (`revoked_at` IS NULL AND `revoke_reason` IS NULL)
    OR (`revoked_at` IS NOT NULL AND `revoked_at` >= `issued_at` AND `revoke_reason` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='会员端可撤销会话';

CREATE TABLE `pa_artifact` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `artifact_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `artifact_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `next_revision_number` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `latest_finalized_revision_id` BIGINT UNSIGNED NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_artifact_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_artifact_identity` (`tenant_id`, `artifact_type`, `artifact_key`),
  KEY `idx_artifact_tenant_type` (`tenant_id`, `artifact_type`, `id`),
  KEY `idx_artifact_latest_finalized` (`tenant_id`, `latest_finalized_revision_id`),
  CONSTRAINT `fk_artifact_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_updated_member` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_artifact_type` CHECK (`artifact_type` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_artifact_key` CHECK (`artifact_key` <> ''),
  CONSTRAINT `chk_artifact_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_artifact_next_revision_number` CHECK (`next_revision_number` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_artifact_revision` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `artifact_id` BIGINT UNSIGNED NOT NULL,
  `revision_key` VARCHAR(41) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision_number` BIGINT UNSIGNED NOT NULL,
  `parent_revision_id` BIGINT UNSIGNED NULL,
  `parent_revision_number` BIGINT UNSIGNED NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `payload_schema_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `payload_schema_version` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `payload_ref` VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `payload_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `attachment_manifest_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `canonical_envelope_json` JSON NULL,
  `canonical_envelope_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `finalized_by_member_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME(3) NOT NULL,
  `finalized_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_artifact_revision_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_artifact_revision_key` (`tenant_id`, `revision_key`),
  UNIQUE KEY `uk_artifact_revision_artifact_id` (`tenant_id`, `artifact_id`, `id`),
  UNIQUE KEY `uk_artifact_revision_parent_identity` (`tenant_id`, `artifact_id`, `id`, `revision_number`),
  UNIQUE KEY `uk_artifact_revision_number` (`tenant_id`, `artifact_id`, `revision_number`),
  KEY `idx_artifact_revision_artifact_state` (`tenant_id`, `artifact_id`, `state`, `revision_number`),
  KEY `idx_artifact_revision_parent` (`tenant_id`, `artifact_id`, `parent_revision_id`, `parent_revision_number`),
  CONSTRAINT `fk_artifact_revision_artifact` FOREIGN KEY (`tenant_id`, `artifact_id`) REFERENCES `pa_artifact` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_revision_parent` FOREIGN KEY (`tenant_id`, `artifact_id`, `parent_revision_id`, `parent_revision_number`) REFERENCES `pa_artifact_revision` (`tenant_id`, `artifact_id`, `id`, `revision_number`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_revision_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_revision_finalized_member` FOREIGN KEY (`tenant_id`, `finalized_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_artifact_revision_key` CHECK (`revision_key` REGEXP '^revision_[0-9a-f]{32}$'),
  CONSTRAINT `chk_artifact_revision_number` CHECK (`revision_number` >= 1),
  CONSTRAINT `chk_artifact_revision_parent` CHECK (
    (`parent_revision_id` IS NULL AND `parent_revision_number` IS NULL)
    OR (`parent_revision_id` IS NOT NULL AND `parent_revision_number` IS NOT NULL AND `parent_revision_number` < `revision_number`)
  ),
  CONSTRAINT `chk_artifact_revision_state` CHECK (`state` IN ('pending', 'finalized')),
  CONSTRAINT `chk_artifact_revision_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_artifact_revision_schema_key` CHECK (`payload_schema_key` IS NULL OR `payload_schema_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_artifact_revision_schema_version` CHECK (`payload_schema_version` IS NULL OR `payload_schema_version` <> ''),
  CONSTRAINT `chk_artifact_revision_payload_ref` CHECK (`payload_ref` IS NULL OR `payload_ref` <> ''),
  CONSTRAINT `chk_artifact_revision_payload_digest` CHECK (`payload_sha256` IS NULL OR `payload_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_artifact_revision_attachment_digest` CHECK (`attachment_manifest_sha256` IS NULL OR `attachment_manifest_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_artifact_revision_envelope_digest` CHECK (`canonical_envelope_sha256` IS NULL OR `canonical_envelope_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_artifact_revision_pending_shape` CHECK (
    (`state` = 'pending' AND `payload_schema_key` IS NULL AND `payload_schema_version` IS NULL AND `payload_ref` IS NULL AND `payload_sha256` IS NULL AND `attachment_manifest_sha256` IS NULL AND `canonical_envelope_json` IS NULL AND `canonical_envelope_sha256` IS NULL AND `finalized_by_member_id` IS NULL AND `finalized_at` IS NULL)
    OR (`state` = 'finalized' AND `payload_schema_key` IS NOT NULL AND `payload_schema_version` IS NOT NULL AND `payload_ref` IS NOT NULL AND `payload_sha256` IS NOT NULL AND `canonical_envelope_json` IS NOT NULL AND `canonical_envelope_sha256` IS NOT NULL AND `finalized_by_member_id` IS NOT NULL AND `finalized_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_entitlement_grant` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `grant_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `current_policy_revision_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_grant_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_entitlement_grant_key` (`tenant_id`, `grant_key`),
  KEY `idx_entitlement_grant_current_policy` (`tenant_id`, `current_policy_revision_id`),
  CONSTRAINT `fk_entitlement_grant_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_grant_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_grant_updated_member` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_grant_key` CHECK (`grant_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_grant_state` CHECK (`state` IN ('active', 'suspended')),
  CONSTRAINT `chk_entitlement_grant_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_entitlement_policy_revision` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `grant_id` BIGINT UNSIGNED NOT NULL,
  `policy_revision_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `meter_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `limit_amount` BIGINT NOT NULL,
  `period_kind` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `effective_from` DATETIME(3) NOT NULL,
  `effective_until` DATETIME(3) NOT NULL,
  `reservation_ttl_seconds` INT UNSIGNED NOT NULL,
  `canonical_snapshot_json` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `canonical_snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_policy_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_entitlement_policy_revision_key` (`tenant_id`, `policy_revision_key`),
  UNIQUE KEY `uk_entitlement_policy_grant_revision` (`tenant_id`, `grant_id`, `policy_revision_key`),
  KEY `idx_entitlement_policy_grant` (`tenant_id`, `grant_id`, `created_at`),
  CONSTRAINT `fk_entitlement_policy_grant` FOREIGN KEY (`tenant_id`, `grant_id`) REFERENCES `pa_entitlement_grant` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_policy_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_policy_revision_key` CHECK (`policy_revision_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_policy_meter_key` CHECK (`meter_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_policy_unit_key` CHECK (`unit_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_policy_limit` CHECK (`limit_amount` >= 1),
  CONSTRAINT `chk_entitlement_policy_period` CHECK (`period_kind` IN ('lifetime', 'utc_day', 'utc_month')),
  CONSTRAINT `chk_entitlement_policy_effective_interval` CHECK (`effective_until` > `effective_from`),
  CONSTRAINT `chk_entitlement_policy_ttl` CHECK (`reservation_ttl_seconds` BETWEEN 30 AND 86400),
  CONSTRAINT `chk_entitlement_policy_snapshot_json` CHECK (JSON_VALID(`canonical_snapshot_json`)),
  CONSTRAINT `chk_entitlement_policy_digest` CHECK (`canonical_snapshot_sha256` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_entitlement_usage_window` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `policy_revision_id` BIGINT UNSIGNED NOT NULL,
  `meter_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_start` DATETIME(3) NOT NULL,
  `window_end` DATETIME(3) NOT NULL,
  `committed_amount` BIGINT NOT NULL DEFAULT 0,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_window_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_entitlement_window_identity` (`tenant_id`, `policy_revision_id`, `meter_key`, `target_type`, `target_key`, `window_start`),
  KEY `idx_entitlement_window_target` (`tenant_id`, `meter_key`, `target_type`, `target_key`, `window_start`),
  CONSTRAINT `fk_entitlement_window_policy` FOREIGN KEY (`tenant_id`, `policy_revision_id`) REFERENCES `pa_entitlement_policy_revision` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_window_meter_key` CHECK (`meter_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_window_target_type` CHECK (`target_type` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_window_target_key` CHECK (`target_key` REGEXP '^[ -~]+$'),
  CONSTRAINT `chk_entitlement_window_interval` CHECK (`window_end` > `window_start`),
  CONSTRAINT `chk_entitlement_window_committed` CHECK (`committed_amount` >= 0),
  CONSTRAINT `chk_entitlement_window_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_entitlement_reservation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `usage_window_id` BIGINT UNSIGNED NOT NULL,
  `reservation_key` VARCHAR(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `meter_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount` BIGINT NOT NULL,
  `state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `settled_by_member_id` BIGINT UNSIGNED NULL,
  `reserved_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `settled_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_reservation_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_entitlement_reservation_key` (`tenant_id`, `reservation_key`),
  UNIQUE KEY `uk_entitlement_reservation_window_id` (`tenant_id`, `usage_window_id`, `id`),
  KEY `idx_entitlement_reservation_window_state` (`tenant_id`, `usage_window_id`, `state`, `expires_at`),
  CONSTRAINT `fk_entitlement_reservation_window` FOREIGN KEY (`tenant_id`, `usage_window_id`) REFERENCES `pa_entitlement_usage_window` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_reservation_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_reservation_settled_member` FOREIGN KEY (`tenant_id`, `settled_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_reservation_key` CHECK (`reservation_key` REGEXP '^reservation_[0-9a-f]{32}$'),
  CONSTRAINT `chk_entitlement_reservation_meter_key` CHECK (`meter_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_reservation_target_type` CHECK (`target_type` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_entitlement_reservation_target_key` CHECK (`target_key` REGEXP '^[ -~]+$'),
  CONSTRAINT `chk_entitlement_reservation_amount` CHECK (`amount` >= 1),
  CONSTRAINT `chk_entitlement_reservation_state` CHECK (`state` IN ('pending', 'committed', 'released', 'expired')),
  CONSTRAINT `chk_entitlement_reservation_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_entitlement_reservation_expiry` CHECK (`expires_at` > `reserved_at`),
  CONSTRAINT `chk_entitlement_reservation_settlement` CHECK (
    (`state` = 'pending' AND `settled_by_member_id` IS NULL AND `settled_at` IS NULL)
    OR (`state` IN ('committed', 'released', 'expired') AND `settled_by_member_id` IS NOT NULL AND `settled_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_entitlement_usage_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `usage_window_id` BIGINT UNSIGNED NOT NULL,
  `reservation_id` BIGINT UNSIGNED NOT NULL,
  `event_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount` BIGINT NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_ledger_event_key` (`tenant_id`, `event_key`),
  UNIQUE KEY `uk_entitlement_ledger_reservation_event` (`tenant_id`, `reservation_id`, `event_type`),
  KEY `idx_entitlement_ledger_window` (`tenant_id`, `usage_window_id`, `id`),
  CONSTRAINT `fk_entitlement_ledger_window` FOREIGN KEY (`tenant_id`, `usage_window_id`) REFERENCES `pa_entitlement_usage_window` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_ledger_reservation` FOREIGN KEY (`tenant_id`, `usage_window_id`, `reservation_id`) REFERENCES `pa_entitlement_reservation` (`tenant_id`, `usage_window_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_ledger_actor_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_ledger_event_key` CHECK (`event_key` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_entitlement_ledger_event_type` CHECK (`event_type` IN ('reserved', 'committed', 'released', 'expired')),
  CONSTRAINT `chk_entitlement_ledger_amount` CHECK (`amount` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_workflow_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `module_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workflow_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `draft_graph_json` JSON NOT NULL,
  `draft_graph_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `latest_version` INT UNSIGNED NOT NULL DEFAULT 0,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `retired_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_definition_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_definition_identity` (`tenant_id`, `module_key`, `workflow_key`),
  KEY `idx_workflow_definition_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_workflow_definition_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_definition_module_key` CHECK (`module_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_workflow_definition_workflow_key` CHECK (`workflow_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_workflow_definition_status` CHECK (`status` IN ('draft', 'active', 'retired')),
  CONSTRAINT `chk_workflow_definition_digest` CHECK (`draft_graph_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_workflow_definition_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_workflow_definition_retired_shape` CHECK ((`status` = 'retired' AND `retired_at` IS NOT NULL) OR (`status` <> 'retired' AND `retired_at` IS NULL)),
  `managed_template_json` JSON NULL,
  `layout_json` JSON NULL,
  `active_version` INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_workflow_definition_version` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `graph_json` JSON NOT NULL,
  `graph_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `published_by_member_id` BIGINT UNSIGNED NOT NULL,
  `published_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_definition_version_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_definition_version_number` (`tenant_id`, `definition_id`, `version`),
  UNIQUE KEY `uk_workflow_definition_version_digest` (`tenant_id`, `definition_id`, `graph_sha256`),
  KEY `idx_workflow_definition_version_published` (`tenant_id`, `published_at`, `id`),
  CONSTRAINT `fk_workflow_definition_version_definition` FOREIGN KEY (`tenant_id`, `definition_id`) REFERENCES `pa_workflow_definition` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_definition_version_number` CHECK (`version` >= 1),
  CONSTRAINT `chk_workflow_definition_version_digest` CHECK (`graph_sha256` REGEXP '^[0-9a-f]{64}$'),
  `contract_version` INT UNSIGNED NOT NULL DEFAULT 2
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_workflow_instance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `definition_version` INT UNSIGNED NOT NULL,
  `subject_type` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
  `initiated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `last_actor_member_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  `cancelled_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_instance_key` (`instance_key`),
  UNIQUE KEY `uk_workflow_instance_tenant_id` (`tenant_id`, `id`),
  KEY `idx_workflow_instance_status` (`tenant_id`, `status`, `updated_at`, `id`),
  KEY `idx_workflow_instance_subject` (`tenant_id`, `subject_type`, `subject_key`, `id`),
  CONSTRAINT `fk_workflow_instance_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_workflow_instance_version` FOREIGN KEY (`tenant_id`, `definition_id`, `definition_version`) REFERENCES `pa_workflow_definition_version` (`tenant_id`, `definition_id`, `version`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_instance_key` CHECK (`instance_key` REGEXP '^instance_[0-9a-f]{32}$'),
  CONSTRAINT `chk_workflow_instance_version` CHECK (`definition_version` >= 1),
  CONSTRAINT `chk_workflow_instance_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_workflow_instance_digest` CHECK (`subject_revision_sha256` REGEXP '^[0-9a-f]{64}$'),
  `module_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workflow_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_department_key` VARCHAR(160) NULL,
  `snapshot_json` JSON NOT NULL,
  `cycle_no` INT UNSIGNED NOT NULL DEFAULT 1,
  `result_code` VARCHAR(64) NULL,
  `blocked_reason` VARCHAR(500) NULL,
  `substantive_at` DATETIME(3) NULL,
  `active_marker` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` IN ('running','blocked') THEN 1 ELSE NULL END) STORED,
  UNIQUE KEY `uk_workflow_instance_active_subject` (`tenant_id`,`module_key`,`workflow_key`,`subject_type`,`subject_key`,`active_marker`),
  CONSTRAINT `chk_workflow_instance_status` CHECK (`status` IN ('running','blocked','completed','cancelled','terminated'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_workflow_work_item` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_item_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `round_no` INT UNSIGNED NOT NULL,
  `assignment_source_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assignment_source_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assignee_member_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `decision` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `completed_by_member_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  `cancelled_at` DATETIME(3) NULL,
  `pending_marker` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = 'pending' THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_work_item_key` (`work_item_key`),
  UNIQUE KEY `uk_workflow_work_item_tenant_id` (`tenant_id`, `id`),
  KEY `idx_workflow_work_item_assignee` (`tenant_id`, `assignee_member_id`, `status`, `created_at`, `id`),
  KEY `idx_workflow_work_item_instance` (`tenant_id`, `instance_id`, `status`, `id`),
  CONSTRAINT `fk_workflow_work_item_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_work_item_key` CHECK (`work_item_key` REGEXP '^work_[0-9a-f]{32}$'),
  CONSTRAINT `chk_workflow_work_item_round` CHECK (`round_no` >= 1),
  CONSTRAINT `chk_workflow_work_item_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_workflow_work_item_status` CHECK (`status` IN ('pending', 'completed', 'cancelled')),
  CONSTRAINT `chk_workflow_work_item_terminal_shape` CHECK ((`status` = 'pending' AND `decision` IS NULL AND `completed_by_member_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NULL) OR (`status` = 'completed' AND `decision` IS NOT NULL AND `completed_by_member_id` IS NOT NULL AND `completed_at` IS NOT NULL AND `cancelled_at` IS NULL) OR (`status` = 'cancelled' AND `decision` IS NULL AND `completed_by_member_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NOT NULL)),
  `node_execution_id` BIGINT UNSIGNED NOT NULL,
  `owner_member_id` BIGINT UNSIGNED NULL,
  `owner_source` VARCHAR(16) NOT NULL DEFAULT 'automatic',
  `assignment_state` VARCHAR(16) NOT NULL DEFAULT 'unassigned',
  `seat_no` INT UNSIGNED NOT NULL,
  `sources_json` JSON NOT NULL,
  UNIQUE KEY `uk_workflow_work_item_seat` (`tenant_id`,`node_execution_id`,`seat_no`),
  UNIQUE KEY `uk_workflow_work_item_assignee_once` (`tenant_id`,`node_execution_id`,`assignee_member_id`),
  CONSTRAINT `fk_workflow_work_item_node` FOREIGN KEY (`tenant_id`,`node_execution_id`) REFERENCES `pa_workflow_node_execution` (`tenant_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_work_item_assignment` CHECK ((`assignment_state`='unassigned' AND `assignee_member_id` IS NULL) OR (`assignment_state`='assigned' AND `assignee_member_id` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_workflow_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL,
  `event_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transition_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `from_node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `to_node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `subject_revision_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `comment_text` VARCHAR(2000) NULL,
  `comment_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `attachment_snapshots_json` JSON NOT NULL,
  `metadata_json` JSON NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_event_sequence` (`tenant_id`, `instance_id`, `sequence_no`),
  KEY `idx_workflow_event_instance_time` (`tenant_id`, `instance_id`, `occurred_at`, `id`),
  KEY `idx_workflow_event_key_time` (`tenant_id`, `event_key`, `occurred_at`, `id`),
  CONSTRAINT `fk_workflow_event_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_event_sequence` CHECK (`sequence_no` >= 1),
  CONSTRAINT `chk_workflow_event_key` CHECK (`event_key` REGEXP '^tenant\\.workflow\\.[a-z_]+$'),
  CONSTRAINT `chk_workflow_event_subject_digest` CHECK (`subject_revision_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_workflow_event_comment_shape` CHECK ((`comment_text` IS NULL AND `comment_sha256` IS NULL) OR (`comment_text` IS NOT NULL AND `comment_sha256` REGEXP '^[0-9a-f]{64}$')),
  CONSTRAINT `chk_workflow_event_actor_shape` CHECK ((`actor_type` = 'member' AND `actor_member_id` IS NOT NULL) OR (`actor_type` = 'tenant_system' AND `actor_member_id` IS NULL)),
  `notification_fact_json` JSON NULL,
  `notification_outbox_json` JSON NULL,
  `notification_last_error` VARCHAR(64) NULL,
  `node_execution_id` BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_integration_machine_identity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `identity_key` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `family_key` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `scopes_json` JSON NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `token_prefix` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_last_four` CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` DATETIME(3) NULL,
  `last_used_at` DATETIME(3) NULL,
  `rotated_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_machine_key` (`tenant_id`, `identity_key`),
  UNIQUE KEY `uk_integration_machine_digest` (`token_digest`),
  KEY `idx_integration_machine_status` (`tenant_id`, `status`, `expires_at`, `id`),
  KEY `idx_integration_machine_family` (`tenant_id`, `family_key`, `id`),
  CONSTRAINT `fk_integration_machine_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_integration_machine_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_machine_status` CHECK (`status` IN ('active', 'rotated', 'revoked')),
  CONSTRAINT `chk_integration_machine_terminal` CHECK ((`status` = 'active' AND `rotated_at` IS NULL AND `revoked_at` IS NULL) OR (`status` = 'rotated' AND `rotated_at` IS NOT NULL AND `revoked_at` IS NULL) OR (`status` = 'revoked' AND `revoked_at` IS NOT NULL AND `rotated_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_integration_webhook_endpoint` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `endpoint_key` CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `url` VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `events_json` JSON NOT NULL,
  `secret_ciphertext` TEXT NOT NULL,
  `secret_key_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'active',
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `disabled_at` DATETIME(3) NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_webhook_key` (`tenant_id`, `endpoint_key`),
  UNIQUE KEY `uk_integration_webhook_id` (`tenant_id`, `id`),
  KEY `idx_integration_webhook_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_integration_webhook_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_integration_webhook_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_webhook_status` CHECK (`status` IN ('active', 'disabled')),
  CONSTRAINT `chk_integration_webhook_disabled` CHECK ((`status` = 'active' AND `disabled_at` IS NULL) OR (`status` = 'disabled' AND `disabled_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_integration_webhook_delivery` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `endpoint_id` BIGINT UNSIGNED NOT NULL,
  `delivery_key` CHAR(41) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` JSON NULL,
  `payload_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
  `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` DATETIME(3) NOT NULL,
  `lease_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `lease_expires_at` DATETIME(3) NULL,
  `last_status_code` SMALLINT UNSIGNED NULL,
  `last_error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `delivered_at` DATETIME(3) NULL,
  `payload_expires_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_delivery_key` (`tenant_id`, `delivery_key`),
  UNIQUE KEY `uk_integration_delivery_event` (`tenant_id`, `endpoint_id`, `event_key`),
  UNIQUE KEY `uk_integration_delivery_id` (`tenant_id`, `id`),
  KEY `idx_integration_delivery_claim` (`tenant_id`, `status`, `available_at`, `id`),
  KEY `idx_integration_delivery_retention` (`status`, `updated_at`, `id`),
  CONSTRAINT `fk_integration_delivery_endpoint` FOREIGN KEY (`tenant_id`, `endpoint_id`) REFERENCES `pa_integration_webhook_endpoint` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_delivery_status` CHECK (`status` IN ('pending', 'delivering', 'retryable', 'delivered', 'permanent_failed')),
  CONSTRAINT `chk_integration_delivery_attempts` CHECK (`attempt_count` <= 8),
  CONSTRAINT `chk_integration_delivery_lease` CHECK ((`status` = 'delivering' AND `lease_digest` IS NOT NULL AND `lease_expires_at` IS NOT NULL) OR (`status` <> 'delivering' AND `lease_digest` IS NULL AND `lease_expires_at` IS NULL)),
  CONSTRAINT `chk_integration_delivery_result` CHECK ((`status` = 'delivered' AND `delivered_at` IS NOT NULL) OR (`status` <> 'delivered' AND `delivered_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_integration_webhook_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `attempt_number` TINYINT UNSIGNED NOT NULL,
  `outcome` VARCHAR(24) NOT NULL,
  `response_status` SMALLINT UNSIGNED NULL,
  `error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `duration_ms` INT UNSIGNED NOT NULL,
  `attempted_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_attempt_number` (`tenant_id`, `delivery_id`, `attempt_number`),
  KEY `idx_integration_attempt_time` (`tenant_id`, `attempted_at`, `id`),
  CONSTRAINT `fk_integration_attempt_delivery` FOREIGN KEY (`tenant_id`, `delivery_id`) REFERENCES `pa_integration_webhook_delivery` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_attempt_outcome` CHECK (`outcome` IN ('delivered', 'retryable', 'permanent_failed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_integration_security_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `target_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `metadata_json` JSON NOT NULL,
  `request_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_integration_event_time` (`tenant_id`, `occurred_at`, `id`),
  KEY `idx_integration_event_target` (`tenant_id`, `target_type`, `target_key_hash`, `id`),
  CONSTRAINT `fk_integration_event_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_integration_event_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_event_key` CHECK (`event_key` REGEXP '^tenant\\.integration\\.[a-z_]+$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_file_image_asset` (
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `width` INT UNSIGNED NOT NULL,
  `height` INT UNSIGNED NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`tenant_id`, `file_key`),
  CONSTRAINT `fk_file_image_asset_object` FOREIGN KEY (`tenant_id`, `file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_image_asset_dimensions` CHECK (`width` BETWEEN 1 AND 50000 AND `height` BETWEEN 1 AND 50000 AND `width` * `height` <= 100000000),
  CONSTRAINT `chk_file_image_asset_media_type` CHECK (`media_type` IN ('image/jpeg','image/png'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_file_derivative` (
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `source_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `variant_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `derivative_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `width` INT UNSIGNED NOT NULL,
  `height` INT UNSIGNED NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`tenant_id`, `source_file_key`, `variant_key`),
  UNIQUE KEY `uk_file_derivative_object` (`tenant_id`, `derivative_file_key`),
  CONSTRAINT `fk_file_derivative_source` FOREIGN KEY (`tenant_id`, `source_file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_derivative_object` FOREIGN KEY (`tenant_id`, `derivative_file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_derivative_key` CHECK (`variant_key` REGEXP '^[a-z][a-z0-9-]{0,31}$'),
  CONSTRAINT `chk_file_derivative_dimensions` CHECK (`width` BETWEEN 1 AND 4096 AND `height` BETWEEN 1 AND 4096),
  CONSTRAINT `chk_file_derivative_media_type` CHECK (`media_type` IN ('image/jpeg','image/png')),
  CONSTRAINT `chk_file_derivative_distinct` CHECK (`source_file_key` <> `derivative_file_key`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_file_delivery_nonce` (
  `token_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `consumed_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`token_id_hash`),
  KEY `idx_file_delivery_nonce_expiry` (`expires_at`),
  CONSTRAINT `chk_file_delivery_nonce_hash` CHECK (`token_id_hash` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_source_read_grant` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_tenant_id` BIGINT UNSIGNED NOT NULL,
  `recipient_tenant_id` BIGINT UNSIGNED NOT NULL,
  `capability` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `effect` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `object_id` BIGINT UNSIGNED NULL,
  `object_key` BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`object_id`, 0)) STORED,
  `fields_json` JSON NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `valid_from` DATETIME(3) NOT NULL,
  `valid_until` DATETIME(3) NULL,
  `granted_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revoked_by_member_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `revoked_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source_read_grant_identity` (`source_tenant_id`, `recipient_tenant_id`, `capability`, `action`, `effect`, `object_key`),
  KEY `idx_source_read_grant_recipient` (`recipient_tenant_id`, `capability`, `action`, `status`, `source_tenant_id`),
  CONSTRAINT `fk_source_read_grant_source_tenant` FOREIGN KEY (`source_tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_source_read_grant_recipient_tenant` FOREIGN KEY (`recipient_tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_source_read_grant_creator` FOREIGN KEY (`source_tenant_id`, `granted_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_source_read_grant_revoker` FOREIGN KEY (`source_tenant_id`, `revoked_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_source_read_grant_tenants` CHECK (`source_tenant_id` <> `recipient_tenant_id`),
  CONSTRAINT `chk_source_read_grant_capability` CHECK (`capability` REGEXP '^[a-z][a-z0-9.-]{2,159}$'),
  CONSTRAINT `chk_source_read_grant_action` CHECK (`action` REGEXP '^[a-z][a-z0-9.-]{1,63}$'),
  CONSTRAINT `chk_source_read_grant_effect` CHECK (`effect` IN ('allow', 'deny')),
  CONSTRAINT `chk_source_read_grant_status` CHECK (`status` IN ('active', 'revoked')),
  CONSTRAINT `chk_source_read_grant_fields` CHECK ((`effect` = 'allow' AND JSON_LENGTH(`fields_json`) > 0) OR (`effect` = 'deny' AND JSON_LENGTH(`fields_json`) = 0)),
  CONSTRAINT `chk_source_read_grant_window` CHECK (`valid_until` IS NULL OR `valid_until` > `valid_from`),
  CONSTRAINT `chk_source_read_grant_revoked` CHECK ((`status` = 'revoked' AND `revoked_by_member_id` IS NOT NULL AND `revoked_at` IS NOT NULL) OR (`status` = 'active' AND `revoked_by_member_id` IS NULL AND `revoked_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_notification_task_actor` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `reference_key` CHAR(64) NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `resource_key` VARCHAR(160) NOT NULL,
  `operation` VARCHAR(64) NOT NULL,
  `targets_sha256` CHAR(64) NOT NULL,
  `request_id` VARCHAR(128) NOT NULL,
  `session_key` VARCHAR(128) NOT NULL,
  `client_key` VARCHAR(128) NOT NULL,
  `account_security_revision` BIGINT UNSIGNED NOT NULL,
  `tenant_security_revision` BIGINT UNSIGNED NOT NULL,
  `member_security_revision` BIGINT UNSIGNED NOT NULL,
  `outbox_key` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_task_actor` (`tenant_id`, `reference_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `pa_workflow_node_execution` (
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

CREATE TABLE `pa_workflow_task_identity_link` (
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

CREATE TABLE `pa_file_delivery_grant` (
  `token_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_tenant_id` BIGINT UNSIGNED NOT NULL,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_revision` BIGINT UNSIGNED NOT NULL,
  `visibility` VARCHAR(8) NOT NULL,
  `operation` VARCHAR(32) NOT NULL,
  `subject_json` JSON NULL,
  `authorization_revision` CHAR(64) NULL,
  `document_id` BIGINT UNSIGNED NULL,
  `reference_digest` VARCHAR(128) NULL,
  `source_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_revision` BIGINT UNSIGNED NOT NULL,
  `source_visibility` VARCHAR(8) NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`token_id_hash`),
  KEY `idx_file_grant_expiry` (`expires_at`),
  CONSTRAINT `chk_file_grant_operation` CHECK (`operation` IN ('public','preview','download_original')),
  CONSTRAINT `chk_file_grant_visibility` CHECK (`visibility` IN ('public','private')),
  CONSTRAINT `chk_file_grant_source_visibility` CHECK (`source_visibility` IN ('public','private'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_file_reference` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_module_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reference_digest` VARCHAR(128) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_reference_owner` (`tenant_id`,`file_key`,`owner_module_key`,`owner_type`,`owner_id`),
  KEY `idx_file_reference_owner` (`tenant_id`,`owner_module_key`,`owner_type`,`owner_id`),
  CONSTRAINT `fk_file_reference_object` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_document` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `owner_module_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_object_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_object_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `slot` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_kind` VARCHAR(16) NOT NULL,
  `body_type` VARCHAR(32) NULL,
  `schema_version` VARCHAR(32) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `auth_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `state_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `collaboration_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `lease_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `create_time` BIGINT UNSIGNED NOT NULL,
  `update_time` BIGINT UNSIGNED NOT NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_owner_slot` (`tenant_id`,`owner_module_key`,`owner_object_type`,`owner_object_id`,`slot`),
  UNIQUE KEY `uk_document_tenant_id` (`tenant_id`,`id`),
  KEY `idx_document_state` (`tenant_id`,`status`),
  CONSTRAINT `chk_document_kind` CHECK (`content_kind` IN ('rich_text','file_resource')),
  CONSTRAINT `chk_document_status` CHECK (`status` IN ('active','archiving','archived','blocked_recovery')),
  CONSTRAINT `chk_document_collaboration` CHECK (`collaboration_enabled` IN (0,1) AND (`content_kind`='rich_text' OR `collaboration_enabled`=0)),
  CONSTRAINT `fk_document_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`),
  CONSTRAINT `fk_document_creator` FOREIGN KEY (`tenant_id`,`created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_document_updater` FOREIGN KEY (`tenant_id`,`updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`,`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_document_state` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `yjs_state_binary` MEDIUMBLOB NULL,
  `state_seq` BIGINT UNSIGNED NOT NULL,
  `state_sha256` CHAR(64) CHARACTER SET ascii NOT NULL,
  `projected_json` JSON NOT NULL,
  `projection_schema_version` VARCHAR(32) NOT NULL,
  `persisted_at` DATETIME(3) NOT NULL,
  `last_store_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_state` (`tenant_id`,`document_id`),
  CONSTRAINT `fk_document_state_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_document_revision` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `revision_no` BIGINT UNSIGNED NOT NULL,
  `source_state_seq` BIGINT UNSIGNED NOT NULL,
  `content_kind` VARCHAR(16) NOT NULL,
  `yjs_snapshot` MEDIUMBLOB NULL,
  `content_snapshot_json` JSON NULL,
  `immutable_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `immutable_file_sha256` CHAR(64) CHARACTER SET ascii NULL,
  `immutable_file_mime_type` VARCHAR(127) CHARACTER SET ascii NULL,
  `created_at` DATETIME(3) NOT NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_revision` (`tenant_id`,`document_id`,`revision_no`),
  CONSTRAINT `chk_document_revision_kind` CHECK ((`content_kind`='rich_text' AND `content_snapshot_json` IS NOT NULL AND `immutable_file_key` IS NULL) OR (`content_kind`='file_resource' AND `immutable_file_key` IS NOT NULL AND `immutable_file_sha256` IS NOT NULL)),
  CONSTRAINT `fk_document_revision_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
  CONSTRAINT `fk_document_revision_creator` FOREIGN KEY (`tenant_id`,`created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_document_revision_file` FOREIGN KEY (`immutable_file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_document_file_ref` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `variant_key` VARCHAR(32) CHARACTER SET ascii NULL,
  `usage` VARCHAR(32) NOT NULL,
  `derived_state_seq` BIGINT UNSIGNED NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_file_ref` (`tenant_id`,`document_id`,`file_key`,`usage`),
  CONSTRAINT `fk_document_file_ref_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
  CONSTRAINT `fk_document_file_ref_object` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_document_resource_binding` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `primary_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `verified_mime_type` VARCHAR(127) CHARACTER SET ascii NOT NULL,
  `source_sha256` CHAR(64) CHARACTER SET ascii NOT NULL,
  `source_file_revision` BIGINT UNSIGNED NOT NULL,
  `state_seq` BIGINT UNSIGNED NOT NULL,
  `bound_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_resource_binding` (`tenant_id`,`document_id`),
  CONSTRAINT `fk_document_binding_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
  CONSTRAINT `fk_document_binding_object` FOREIGN KEY (`primary_file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_document_operation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `operation_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` VARCHAR(32) NOT NULL,
  `input_sha256` CHAR(64) CHARACTER SET ascii NOT NULL,
  `result_json` JSON NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_operation` (`tenant_id`,`document_id`,`operation_id`),
  CONSTRAINT `fk_document_operation_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
  `phase` VARCHAR(16) NOT NULL DEFAULT 'completed',
  `initiator_json` JSON NULL,
  `command_input_json` JSON NULL,
  `barrier_plan_json` JSON NULL,
  `business_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `released_at` DATETIME(3) NULL,
  CONSTRAINT `chk_document_operation_phase` CHECK (`phase` IN ('prepared','attention','completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_file_immutable_snapshot` (
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `capture_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_revision` BIGINT UNSIGNED NOT NULL,
  `source_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `verified_mime_type` VARCHAR(127) CHARACTER SET ascii NOT NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'captured',
  `claimed_document_id` BIGINT UNSIGNED NULL,
  `claimed_at` DATETIME(3) NULL,
  PRIMARY KEY (`file_key`),
  UNIQUE KEY `uk_file_document_capture` (`tenant_id`,`capture_id`),
  CONSTRAINT `fk_file_immutable_target` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_immutable_status` CHECK (`status` IN ('captured','claimed','cleanup_pending','abandoned')),
  CONSTRAINT `fk_file_immutable_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_service_nonce` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nonce` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_service_nonce` (`service_instance_id`,`key_id`,`nonce`),
  KEY `idx_collaboration_nonce_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_ticket` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `edition` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `jti` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ticket_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mode` VARCHAR(8) CHARACTER SET ascii NOT NULL,
  `issued_identity_json` JSON NOT NULL,
  `owner_reference_json` JSON NOT NULL,
  `authorization_revision` VARCHAR(255) NOT NULL,
  `document_auth_revision` BIGINT UNSIGNED NOT NULL,
  `issued_state_seq` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `consumed_at` DATETIME(3) NULL,
  `revoked_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_ticket_jti` (`jti`),
  UNIQUE KEY `uk_collaboration_ticket_digest` (`ticket_sha256`),
  UNIQUE KEY `uk_collaboration_ticket_tenant_id` (`tenant_id`,`id`),
  KEY `idx_collaboration_ticket_expiry` (`expires_at`),
  CONSTRAINT `chk_collaboration_ticket_mode` CHECK (`mode` IN ('read','write')),
  CONSTRAINT `chk_collaboration_ticket_edition` CHECK (`edition` IN ('engine','standalone','multi-tenant')),
  CONSTRAINT `fk_collaboration_ticket_document` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_room` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `edition` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `room_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `binding_json` JSON NOT NULL,
  `service_instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_epoch` BIGINT UNSIGNED NOT NULL,
  `lease_expires_at` DATETIME(3) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `accepted_through` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `persisted_through` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `persisted_state_seq` BIGINT UNSIGNED NOT NULL,
  `baseline_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `candidate_binary_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `chain_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `last_store_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `last_store_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `last_receipt_json` JSON NULL,
  `pending_update_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `pending_journal_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_room` (`tenant_id`,`instance_id`,`edition`,`document_id`),
  UNIQUE KEY `uk_collaboration_room_key` (`room_key`),
  UNIQUE KEY `uk_collaboration_room_tenant_id` (`tenant_id`,`id`),
  CONSTRAINT `chk_collaboration_room_status` CHECK (`status` IN ('active','draining','recovery','closed')),
  CONSTRAINT `chk_collaboration_room_sequence` CHECK (`persisted_through` <= `accepted_through` AND `lease_epoch` > 0),
  CONSTRAINT `fk_collaboration_room_document` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
  `protocol_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `holder_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `last_target_progress` BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_connection` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `connection_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `lease_epoch` BIGINT UNSIGNED NOT NULL,
  `mode` VARCHAR(8) CHARACTER SET ascii NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `authorization_revision` VARCHAR(255) NOT NULL,
  `lease_expires_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `revoked_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_connection` (`connection_key`),
  UNIQUE KEY `uk_collaboration_connection_tenant_id` (`tenant_id`,`id`),
  CONSTRAINT `chk_collaboration_connection_status` CHECK (`status` IN ('active','revoked','closed')),
  CONSTRAINT `chk_collaboration_connection_mode` CHECK (`mode` IN ('read','write')),
  CONSTRAINT `fk_collaboration_connection_ticket` FOREIGN KEY (`tenant_id`,`ticket_id`) REFERENCES `pa_collaboration_ticket` (`tenant_id`,`id`),
  CONSTRAINT `fk_collaboration_connection_room` FOREIGN KEY (`tenant_id`,`room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_admission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `connection_id` BIGINT UNSIGNED NOT NULL,
  `lease_epoch` BIGINT UNSIGNED NOT NULL,
  `accepted_seq` BIGINT UNSIGNED NOT NULL,
  `update_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `admission_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `update_binary` MEDIUMBLOB NOT NULL,
  `update_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `update_bytes` INT UNSIGNED NOT NULL,
  `base_candidate_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `candidate_binary_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `previous_chain_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `chain_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `candidate_projection_json` JSON NOT NULL,
  `projection_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `projector_version` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reference_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `authorization_proof_json` JSON NOT NULL,
  `accepted_at` DATETIME(3) NOT NULL,
  `persisted_state_seq` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_admission_sequence` (`tenant_id`,`room_id`,`accepted_seq`),
  UNIQUE KEY `uk_collaboration_admission_update` (`tenant_id`,`room_id`,`update_id`),
  UNIQUE KEY `uk_collaboration_admission_key` (`admission_key`),
  CONSTRAINT `chk_collaboration_admission_size` CHECK (`update_bytes` > 0 AND `update_bytes`=OCTET_LENGTH(`update_binary`) AND `lease_epoch` > 0 AND `accepted_seq` > 0),
  CONSTRAINT `fk_collaboration_admission_room` FOREIGN KEY (`tenant_id`,`room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`,`id`),
  CONSTRAINT `fk_collaboration_admission_connection` FOREIGN KEY (`tenant_id`,`connection_id`) REFERENCES `pa_collaboration_connection` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_barrier` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `connection_id` BIGINT UNSIGNED NULL,
  `barrier_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_epoch` BIGINT UNSIGNED NOT NULL,
  `target_accepted_seq` BIGINT UNSIGNED NULL,
  `purpose` VARCHAR(16) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `input_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `committed_state_seq` BIGINT UNSIGNED NULL,
  `committed_state_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_barrier` (`tenant_id`,`room_id`,`barrier_key`),
  CONSTRAINT `chk_collaboration_barrier_status` CHECK (`status` IN ('draining','committed','recovery')),
  CONSTRAINT `chk_collaboration_barrier_purpose` CHECK (`purpose` IN ('version','archive','restore','shutdown')),
  CONSTRAINT `fk_collaboration_barrier_room` FOREIGN KEY (`tenant_id`,`room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`,`id`),
  CONSTRAINT `fk_collaboration_barrier_connection` FOREIGN KEY (`tenant_id`,`connection_id`) REFERENCES `pa_collaboration_connection` (`tenant_id`,`id`),
  `business_plan_json` JSON NULL,
  `business_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `business_result_json` JSON NULL,
  `released_at` DATETIME(3) NULL,
  `drained_receipt_json` JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_runtime` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `edition` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `config_revision` BIGINT UNSIGNED NOT NULL,
  `config_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `phase` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `drain_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `plan_json` JSON NULL,
  `receipt_json` JSON NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_runtime_instance` (`instance_id`,`edition`),
  CONSTRAINT `chk_collaboration_runtime_phase` CHECK (`phase` IN ('prepared','active','draining','durably_drained','stopped'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_article_body_save` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `article_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `request_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_account_id` BIGINT UNSIGNED NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NOT NULL,
  `phase` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `result_json` JSON NULL,
  `created_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_article_body_save_request` (`tenant_id`,`article_id`,`request_id`),
  CONSTRAINT `chk_article_body_save_digest` CHECK (`input_sha256` REGEXP '^[a-f0-9]{64}$'),
  CONSTRAINT `fk_article_body_save_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`),
  CONSTRAINT `fk_article_body_save_document` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
  CONSTRAINT `chk_article_body_save_phase` CHECK (`phase` IN ('prepared','completed','conflicted')),
  CONSTRAINT `chk_article_body_save_result` CHECK (
  (`phase`='prepared' AND `result_json` IS NULL AND `completed_at` IS NULL)
  OR (`phase` IN ('completed','conflicted') AND `result_json` IS NOT NULL AND `completed_at` IS NOT NULL)
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_grant` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `connection_id` BIGINT UNSIGNED NOT NULL,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `grant_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `purpose` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mode` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `service_instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `holder_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_epoch` BIGINT UNSIGNED NOT NULL,
  `authorization_revision` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `identity_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issued_at_ms` BIGINT UNSIGNED NOT NULL,
  `expires_at_ms` BIGINT UNSIGNED NOT NULL,
  `input_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `file_keys_json` JSON NULL,
  `reservation_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_grant_key` (`grant_key`),
  KEY `idx_collaboration_grant_window` (`room_id`,`lease_epoch`,`connection_id`,`authorization_revision`,`issued_at_ms`),
  KEY `idx_collaboration_grant_cleanup` (`room_id`,`lease_epoch`,`expires_at_ms`),
  CONSTRAINT `chk_collaboration_grant_purpose` CHECK (`purpose` IN ('connection','references')),
  CONSTRAINT `chk_collaboration_grant_mode` CHECK (`mode` IN ('read','write')),
  CONSTRAINT `chk_collaboration_grant_window` CHECK (`expires_at_ms` > `issued_at_ms`),
  CONSTRAINT `fk_collaboration_grant_document` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
  CONSTRAINT `fk_collaboration_grant_connection` FOREIGN KEY (`tenant_id`,`connection_id`) REFERENCES `pa_collaboration_connection` (`tenant_id`,`id`),
  CONSTRAINT `fk_collaboration_grant_ticket` FOREIGN KEY (`tenant_id`,`ticket_id`) REFERENCES `pa_collaboration_ticket` (`tenant_id`,`id`),
  CONSTRAINT `fk_collaboration_grant_room` FOREIGN KEY (`tenant_id`,`room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_file_visibility_batch` (
  `batch_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `request_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `confirmation_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `manifest_json` JSON NOT NULL,
  `status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`batch_key`),
  UNIQUE KEY `uk_file_visibility_owner_key` (`tenant_id`,`batch_key`),
  UNIQUE KEY `uk_file_visibility_request` (`tenant_id`,`request_key`),
  KEY `idx_file_visibility_status` (`tenant_id`,`status`,`created_at`),
  CONSTRAINT `chk_file_visibility_batch_status` CHECK (`status` IN ('pending','running','completed','attention')),
  CONSTRAINT `chk_file_visibility_request_hash` CHECK (`request_sha256` REGEXP '^[a-f0-9]{64}$'),
  CONSTRAINT `chk_file_visibility_confirmation_hash` CHECK (`confirmation_sha256` REGEXP '^[a-f0-9]{64}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_file_visibility_item` (
  `batch_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `source_json` JSON NOT NULL,
  `target_json` JSON NOT NULL,
  `receipts_json` JSON NOT NULL,
  `status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `step` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_code` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`batch_key`,`file_key`),
  KEY `idx_file_visibility_item_status` (`tenant_id`,`status`,`batch_key`),
  CONSTRAINT `fk_file_visibility_item_batch` FOREIGN KEY (`tenant_id`,`batch_key`) REFERENCES `pa_file_visibility_batch` (`tenant_id`,`batch_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_visibility_item_object` FOREIGN KEY (`tenant_id`,`file_key`) REFERENCES `pa_file_object` (`tenant_id`,`file_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_visibility_item_status` CHECK (`status` IN ('restricting','verifying','ready_private','restricted_attention')),
  CONSTRAINT `chk_file_visibility_item_step` CHECK (`step` IN ('copy','switch','close_source','verify','completed'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_member_job` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `tenant_member_id` BIGINT UNSIGNED NOT NULL,
  `job_id` INT UNSIGNED NOT NULL,
  `assigned_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_job` (`tenant_id`, `tenant_member_id`, `job_id`),
  KEY `idx_member_job_job` (`tenant_id`, `job_id`),
  CONSTRAINT `fk_member_job_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_job_job` FOREIGN KEY (`tenant_id`, `job_id`) REFERENCES `pa_jobs` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
