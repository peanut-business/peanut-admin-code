-- Transient delivery authorization evidence, not a second member ACL.
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
 PRIMARY KEY (`token_id_hash`), KEY `idx_file_grant_expiry` (`expires_at`),
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
 PRIMARY KEY (`id`), UNIQUE KEY `uk_file_reference_owner` (`tenant_id`,`file_key`,`owner_module_key`,`owner_type`,`owner_id`),
 KEY `idx_file_reference_owner` (`tenant_id`,`owner_module_key`,`owner_type`,`owner_id`),
 CONSTRAINT `fk_file_reference_object` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
