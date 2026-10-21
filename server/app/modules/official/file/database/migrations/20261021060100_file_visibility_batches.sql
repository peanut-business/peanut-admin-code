-- Durable, tenant-owned visibility intents. External storage effects are not a SQL transaction.
CREATE TABLE IF NOT EXISTS `pa_file_visibility_batch` (
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

CREATE TABLE IF NOT EXISTS `pa_file_visibility_item` (
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
