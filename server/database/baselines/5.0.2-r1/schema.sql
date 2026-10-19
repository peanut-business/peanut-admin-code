-- Fixed clean install 5.0.2-r1. Source-only artifact; never a database dump.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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
  CONSTRAINT `chk_department_status` CHECK (`status` IN ('active', 'disabled', 'archived'))
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
  )
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

SET FOREIGN_KEY_CHECKS = 1;

-- peanut-baseline-application-schema-boundary

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `pa_system_menu` (
  `menu_key` VARCHAR(160) NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统菜单';

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
  `uri`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT '相对路径',
  `storage`     VARCHAR(20)  NOT NULL DEFAULT 'local' COMMENT '存储引擎',
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `delete_time` INT UNSIGNED NULL     DEFAULT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cid` (`cid`),
  KEY `idx_type` (`type`),
  KEY `idx_type_cid_source` (`type`,`cid`,`source`),
  UNIQUE KEY `uk_file_tenant_id` (`tenant_id`, `id`),
  KEY `idx_file_tenant_type_cid_source` (`tenant_id`, `type`, `cid`, `source`, `id`),
  CONSTRAINT `fk_file_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
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
  CONSTRAINT `fk_member_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
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
  CONSTRAINT `fk_article_tenant_cate` FOREIGN KEY (`tenant_id`, `cid`) REFERENCES `pa_article_cate` (`tenant_id`, `id`) ON DELETE RESTRICT
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
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
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
  CONSTRAINT `chk_task_job_completion` CHECK ((`status` IN ('succeeded','dead','cancelled')) = (`completed_at` IS NOT NULL))
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
  CONSTRAINT `chk_task_event_key` CHECK (`event_key` REGEXP '^tenant\\.task\\.[a-z_]+$')
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

CREATE TABLE `pa_file_object` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `storage_provider_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `storage_key` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL,
  `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ready',
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `archived_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_object_key` (`file_key`),
  UNIQUE KEY `uk_file_object_storage` (`tenant_id`, `storage_provider_key`, `storage_key`),
  KEY `idx_file_object_status` (`tenant_id`, `status`, `id`),
  KEY `idx_file_object_sha256` (`tenant_id`, `sha256`),
  CONSTRAINT `fk_file_object_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_object_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_object_key` CHECK (`file_key` REGEXP '^file_[0-9a-f]{32}$'),
  CONSTRAINT `chk_file_object_sha256` CHECK (`sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_file_object_status` CHECK (`status` IN ('ready', 'archived')),
  CONSTRAINT `chk_file_object_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_file_object_archive_shape` CHECK ((`status` = 'ready' AND `archived_at` IS NULL) OR (`status` = 'archived' AND `archived_at` IS NOT NULL))
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

CREATE TABLE `pa_module_migration` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(96) NOT NULL,
  `migration_key` VARCHAR(160) NOT NULL,
  `module_version` VARCHAR(32) NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `batch_no` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(24) NOT NULL,
  `started_at` DATETIME(3) NOT NULL,
  `finished_at` DATETIME(3) NULL,
  `resolution_kind` VARCHAR(16) NULL DEFAULT NULL,
  `provenance_json` JSON NULL,
  `error_code` VARCHAR(96) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_migration` (`module_key`, `migration_key`),
  KEY `idx_module_migration_batch` (`batch_no`, `status`),
  CONSTRAINT `chk_module_migration_resolution` CHECK (`resolution_kind` IS NULL OR `resolution_kind` IN ('executed','adopted')),
  CONSTRAINT `chk_module_migration_provenance` CHECK ((`resolution_kind` <=> 'adopted') = (`provenance_json` IS NOT NULL)),
  CONSTRAINT `chk_module_migration_status` CHECK (`status` IN (
    'applying','applied','rolled_back','failed'
  ))
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

SET FOREIGN_KEY_CHECKS = 1;
