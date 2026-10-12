CREATE TABLE `pa_file_immutable_snapshot` (
 `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `tenant_id` BIGINT UNSIGNED NOT NULL, `capture_id` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `source_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `source_revision` BIGINT UNSIGNED NOT NULL,
 `source_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `verified_mime_type` VARCHAR(127) CHARACTER SET ascii NOT NULL,
 `created_by_member_id` BIGINT UNSIGNED NOT NULL, `created_at` DATETIME(3) NOT NULL,
 `status` VARCHAR(32) NOT NULL DEFAULT 'captured', `claimed_document_id` BIGINT UNSIGNED NULL,
 `claimed_at` DATETIME(3) NULL,
 PRIMARY KEY (`file_key`), UNIQUE KEY `uk_file_document_capture` (`tenant_id`,`capture_id`),
 CONSTRAINT `fk_file_immutable_target` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT,
 CONSTRAINT `chk_file_immutable_status` CHECK (`status` IN ('captured','claimed','cleanup_pending','abandoned')),
 CONSTRAINT `fk_file_immutable_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
