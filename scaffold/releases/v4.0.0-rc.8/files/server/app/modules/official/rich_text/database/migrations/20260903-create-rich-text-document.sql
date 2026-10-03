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
