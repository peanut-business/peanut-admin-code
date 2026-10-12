CREATE TABLE `pa_document` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL,
 `owner_module_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `owner_object_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `owner_object_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `slot` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `content_kind` VARCHAR(16) NOT NULL,
 `body_type` VARCHAR(32) NULL, `schema_version` VARCHAR(32) NOT NULL,
 `status` VARCHAR(32) NOT NULL DEFAULT 'active', `auth_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
 `state_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0, `collaboration_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 0,
 `lease_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 0,
 `created_by_member_id` BIGINT UNSIGNED NOT NULL, `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
 `create_time` BIGINT UNSIGNED NOT NULL, `update_time` BIGINT UNSIGNED NOT NULL, `archived_at` DATETIME(3) NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_document_owner_slot` (`tenant_id`,`owner_module_key`,`owner_object_type`,`owner_object_id`,`slot`),
 UNIQUE KEY `uk_document_tenant_id` (`tenant_id`,`id`), KEY `idx_document_state` (`tenant_id`,`status`),
 CONSTRAINT `chk_document_kind` CHECK (`content_kind` IN ('rich_text','file_resource')),
 CONSTRAINT `chk_document_status` CHECK (`status` IN ('active','archiving','archived','blocked_recovery')),
 CONSTRAINT `chk_document_collaboration` CHECK (`collaboration_enabled` IN (0,1) AND (`content_kind`='rich_text' OR `collaboration_enabled`=0)),
 CONSTRAINT `fk_document_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`),
 CONSTRAINT `fk_document_creator` FOREIGN KEY (`tenant_id`,`created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`,`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_document_updater` FOREIGN KEY (`tenant_id`,`updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`,`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_document_state` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL, `document_id` BIGINT UNSIGNED NOT NULL,
 `yjs_state_binary` MEDIUMBLOB NULL, `state_seq` BIGINT UNSIGNED NOT NULL, `state_sha256` CHAR(64) CHARACTER SET ascii NOT NULL,
 `projected_json` JSON NOT NULL, `projection_schema_version` VARCHAR(32) NOT NULL, `persisted_at` DATETIME(3) NOT NULL,
 `last_store_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_document_state` (`tenant_id`,`document_id`),
 CONSTRAINT `fk_document_state_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_document_revision` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL, `document_id` BIGINT UNSIGNED NOT NULL,
 `revision_no` BIGINT UNSIGNED NOT NULL, `source_state_seq` BIGINT UNSIGNED NOT NULL, `content_kind` VARCHAR(16) NOT NULL,
 `yjs_snapshot` MEDIUMBLOB NULL, `content_snapshot_json` JSON NULL,
 `immutable_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, `immutable_file_sha256` CHAR(64) CHARACTER SET ascii NULL,
 `immutable_file_mime_type` VARCHAR(127) CHARACTER SET ascii NULL,
 `created_at` DATETIME(3) NOT NULL, `created_by_member_id` BIGINT UNSIGNED NOT NULL,
 `reason` VARCHAR(255) NOT NULL, `snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_document_revision` (`tenant_id`,`document_id`,`revision_no`),
 CONSTRAINT `chk_document_revision_kind` CHECK ((`content_kind`='rich_text' AND `content_snapshot_json` IS NOT NULL AND `immutable_file_key` IS NULL) OR (`content_kind`='file_resource' AND `immutable_file_key` IS NOT NULL AND `immutable_file_sha256` IS NOT NULL)),
 CONSTRAINT `fk_document_revision_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
 CONSTRAINT `fk_document_revision_creator` FOREIGN KEY (`tenant_id`,`created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`,`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_document_revision_file` FOREIGN KEY (`immutable_file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_document_file_ref` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL, `document_id` BIGINT UNSIGNED NOT NULL,
 `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `variant_key` VARCHAR(32) CHARACTER SET ascii NULL,
 `usage` VARCHAR(32) NOT NULL, `derived_state_seq` BIGINT UNSIGNED NOT NULL, `updated_at` DATETIME(3) NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_document_file_ref` (`tenant_id`,`document_id`,`file_key`,`usage`),
 CONSTRAINT `fk_document_file_ref_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
 CONSTRAINT `fk_document_file_ref_object` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_document_resource_binding` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL, `document_id` BIGINT UNSIGNED NOT NULL,
 `primary_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `verified_mime_type` VARCHAR(127) CHARACTER SET ascii NOT NULL,
 `source_sha256` CHAR(64) CHARACTER SET ascii NOT NULL, `source_file_revision` BIGINT UNSIGNED NOT NULL, `state_seq` BIGINT UNSIGNED NOT NULL, `bound_at` DATETIME(3) NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_document_resource_binding` (`tenant_id`,`document_id`),
 CONSTRAINT `fk_document_binding_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`),
 CONSTRAINT `fk_document_binding_object` FOREIGN KEY (`primary_file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durable command receipts, not an actor ACL. Same request key cannot change input/action.
CREATE TABLE `pa_document_operation` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL, `document_id` BIGINT UNSIGNED NOT NULL,
 `operation_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `action` VARCHAR(32) NOT NULL,
 `input_sha256` CHAR(64) CHARACTER SET ascii NOT NULL, `result_json` JSON NOT NULL, `created_at` DATETIME(3) NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_document_operation` (`tenant_id`,`document_id`,`operation_id`),
 CONSTRAINT `fk_document_operation_owner` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
