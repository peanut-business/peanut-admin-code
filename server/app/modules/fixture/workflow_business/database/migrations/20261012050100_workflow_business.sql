CREATE TABLE IF NOT EXISTS `pa_fixture_workflow_object` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `object_key` VARCHAR(64) NOT NULL,
  `scene` VARCHAR(32) NOT NULL,
  `owner_member_id` BIGINT UNSIGNED NOT NULL,
  `department_id` BIGINT UNSIGNED NULL,
  `business_department_key` VARCHAR(128) NULL,
  `title` VARCHAR(120) NOT NULL,
  `amount_minor` BIGINT UNSIGNED NOT NULL,
  `sku` VARCHAR(64) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `state` VARCHAR(32) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `confirmed_revision_key` VARCHAR(64) NULL,
  `last_revision_key` VARCHAR(64) NULL,
  `artifact_revision` BIGINT UNSIGNED NULL,
  `completion_ready` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `completion_form_json` LONGTEXT NOT NULL,
  `result_code` VARCHAR(32) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_fixture_workflow_object` (`tenant_id`, `object_key`),
  KEY `idx_fixture_workflow_scope` (`tenant_id`, `scene`, `owner_member_id`, `department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `pa_fixture_workflow_payload` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `object_key` VARCHAR(64) NOT NULL,
  `revision_key` VARCHAR(64) NOT NULL,
  `payload_json` LONGTEXT NOT NULL,
  `payload_sha256` CHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_fixture_workflow_payload` (`tenant_id`, `revision_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `pa_fixture_workflow_effect` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `effect_key` VARCHAR(160) NOT NULL,
  `request_hash` CHAR(64) NOT NULL,
  `action_key` VARCHAR(128) NOT NULL,
  `object_key` VARCHAR(64) NOT NULL,
  `receipt_json` LONGTEXT NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_fixture_workflow_effect` (`tenant_id`, `effect_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `pa_fixture_workflow_stock_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `effect_key` VARCHAR(160) NOT NULL,
  `object_key` VARCHAR(64) NOT NULL,
  `sku` VARCHAR(64) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_fixture_workflow_stock_effect` (`tenant_id`, `effect_key`),
  KEY `idx_fixture_workflow_stock_object` (`tenant_id`, `object_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `pa_fixture_workflow_stock_balance` (
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `sku` VARCHAR(64) NOT NULL,
  `quantity` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`tenant_id`, `sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `pa_fixture_workflow_task_actor` (
  `reference_key` CHAR(64) NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `resource_key` VARCHAR(96) NOT NULL,
  `operation` VARCHAR(32) NOT NULL,
  `object_key` VARCHAR(64) NOT NULL,
  `request_id` VARCHAR(128) NOT NULL,
  `session_key` VARCHAR(128) NOT NULL,
  `client_key` VARCHAR(128) NOT NULL,
  `account_security_revision` BIGINT UNSIGNED NOT NULL,
  `tenant_security_revision` BIGINT UNSIGNED NOT NULL,
  `member_security_revision` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`reference_key`), KEY `idx_fixture_workflow_task_actor` (`tenant_id`, `member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
