-- Native Hocuspocus protocol 2. Existing journal/history tables are retained as historical data.
ALTER TABLE `pa_collaboration_room`
 ADD COLUMN `protocol_version` INT UNSIGNED NOT NULL DEFAULT 1,
 ADD COLUMN `holder_key` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD COLUMN `last_target_progress` BIGINT UNSIGNED NOT NULL DEFAULT 0,
 MODIFY COLUMN `candidate_binary_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 MODIFY COLUMN `chain_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE `pa_collaboration_barrier`
 ADD COLUMN `drained_receipt_json` JSON NULL,
 MODIFY COLUMN `target_accepted_seq` BIGINT UNSIGNED NULL;

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
 PRIMARY KEY (`id`), UNIQUE KEY `uk_collaboration_grant_key` (`grant_key`),
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
