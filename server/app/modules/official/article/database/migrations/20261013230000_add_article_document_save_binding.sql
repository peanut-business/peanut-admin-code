ALTER TABLE `pa_article`
 ADD COLUMN `body_document_id` BIGINT UNSIGNED NULL,
 ADD COLUMN `body_revision_id` BIGINT UNSIGNED NULL,
 ADD COLUMN `body_source_state_seq` BIGINT UNSIGNED NULL,
 ADD COLUMN `body_snapshot_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD CONSTRAINT `chk_article_body_binding` CHECK (
  (`body_revision_id` IS NULL AND `body_source_state_seq` IS NULL AND `body_snapshot_sha256` IS NULL)
  OR (`body_document_id` IS NOT NULL AND `body_revision_id` IS NOT NULL AND `body_source_state_seq` IS NOT NULL AND `body_snapshot_sha256` IS NOT NULL AND `body_snapshot_sha256` REGEXP '^[a-f0-9]{64}$')
 );

CREATE TABLE `pa_article_body_save` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL,
 `article_id` BIGINT UNSIGNED NOT NULL, `document_id` BIGINT UNSIGNED NOT NULL,
 `request_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `input_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `actor_account_id` BIGINT UNSIGNED NOT NULL, `actor_member_id` BIGINT UNSIGNED NOT NULL,
 `phase` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `result_json` JSON NULL, `created_at` DATETIME(3) NOT NULL, `completed_at` DATETIME(3) NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_article_body_save_request` (`tenant_id`,`article_id`,`request_id`),
 CONSTRAINT `chk_article_body_save_phase` CHECK (`phase` IN ('prepared','completed')),
 CONSTRAINT `chk_article_body_save_digest` CHECK (`input_sha256` REGEXP '^[a-f0-9]{64}$'),
 CONSTRAINT `chk_article_body_save_result` CHECK ((`phase`='prepared' AND `result_json` IS NULL AND `completed_at` IS NULL) OR (`phase`='completed' AND `result_json` IS NOT NULL AND `completed_at` IS NOT NULL)),
 CONSTRAINT `fk_article_body_save_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`),
 CONSTRAINT `fk_article_body_save_document` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
