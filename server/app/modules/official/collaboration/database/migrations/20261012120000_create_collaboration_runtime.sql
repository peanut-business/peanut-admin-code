-- Collaboration owns service replay, original human ticket references and the accepted update journal.
-- It never writes Document state through these tables. Epoch/store proof is joined by the public Document command.
CREATE TABLE `pa_collaboration_service_nonce` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `service_instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `key_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `nonce` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `request_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `created_at` DATETIME(3) NOT NULL, `expires_at` DATETIME(3) NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_collaboration_service_nonce` (`service_instance_id`,`key_id`,`nonce`),
 KEY `idx_collaboration_nonce_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_ticket` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL,
 `instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `edition` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `document_id` BIGINT UNSIGNED NOT NULL,
 `jti` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `ticket_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `mode` VARCHAR(8) CHARACTER SET ascii NOT NULL,
 `issued_identity_json` JSON NOT NULL, `owner_reference_json` JSON NOT NULL,
 `authorization_revision` VARCHAR(255) NOT NULL, `document_auth_revision` BIGINT UNSIGNED NOT NULL,
 `issued_state_seq` BIGINT UNSIGNED NOT NULL,
 `created_at` DATETIME(3) NOT NULL, `expires_at` DATETIME(3) NOT NULL,
 `consumed_at` DATETIME(3) NULL, `revoked_at` DATETIME(3) NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_collaboration_ticket_jti` (`jti`),
 UNIQUE KEY `uk_collaboration_ticket_digest` (`ticket_sha256`),
 UNIQUE KEY `uk_collaboration_ticket_tenant_id` (`tenant_id`,`id`),
 KEY `idx_collaboration_ticket_expiry` (`expires_at`),
 CONSTRAINT `chk_collaboration_ticket_mode` CHECK (`mode` IN ('read','write')),
 CONSTRAINT `chk_collaboration_ticket_edition` CHECK (`edition` IN ('engine','standalone','multi-tenant')),
 CONSTRAINT `fk_collaboration_ticket_document` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_room` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL,
 `instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `edition` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `document_id` BIGINT UNSIGNED NOT NULL,
 `room_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `binding_json` JSON NOT NULL,
 `service_instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `lease_epoch` BIGINT UNSIGNED NOT NULL,
 `lease_expires_at` DATETIME(3) NOT NULL,
 `status` VARCHAR(16) NOT NULL, `accepted_through` BIGINT UNSIGNED NOT NULL DEFAULT 0,
 `persisted_through` BIGINT UNSIGNED NOT NULL DEFAULT 0,
 `persisted_state_seq` BIGINT UNSIGNED NOT NULL,
 `baseline_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `candidate_binary_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `chain_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `last_store_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 `last_store_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, `last_receipt_json` JSON NULL,
 `pending_update_count` INT UNSIGNED NOT NULL DEFAULT 0,
 `pending_journal_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
 `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_collaboration_room` (`tenant_id`,`instance_id`,`edition`,`document_id`),
 UNIQUE KEY `uk_collaboration_room_key` (`room_key`),
 UNIQUE KEY `uk_collaboration_room_tenant_id` (`tenant_id`,`id`),
 CONSTRAINT `chk_collaboration_room_status` CHECK (`status` IN ('active','draining','recovery','closed')),
 CONSTRAINT `chk_collaboration_room_sequence` CHECK (`persisted_through` <= `accepted_through` AND `lease_epoch` > 0),
 CONSTRAINT `fk_collaboration_room_document` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_connection` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL,
 `connection_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `ticket_id` BIGINT UNSIGNED NOT NULL, `room_id` BIGINT UNSIGNED NOT NULL,
 `lease_epoch` BIGINT UNSIGNED NOT NULL, `mode` VARCHAR(8) CHARACTER SET ascii NOT NULL,
 `status` VARCHAR(16) NOT NULL, `authorization_revision` VARCHAR(255) NOT NULL,
 `lease_expires_at` DATETIME(3) NOT NULL, `created_at` DATETIME(3) NOT NULL, `revoked_at` DATETIME(3) NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_collaboration_connection` (`connection_key`),
 UNIQUE KEY `uk_collaboration_connection_tenant_id` (`tenant_id`,`id`),
 CONSTRAINT `chk_collaboration_connection_status` CHECK (`status` IN ('active','revoked','closed')),
 CONSTRAINT `chk_collaboration_connection_mode` CHECK (`mode` IN ('read','write')),
 CONSTRAINT `fk_collaboration_connection_ticket` FOREIGN KEY (`tenant_id`,`ticket_id`) REFERENCES `pa_collaboration_ticket` (`tenant_id`,`id`),
 CONSTRAINT `fk_collaboration_connection_room` FOREIGN KEY (`tenant_id`,`room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_admission` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL,
 `room_id` BIGINT UNSIGNED NOT NULL, `connection_id` BIGINT UNSIGNED NOT NULL,
 `lease_epoch` BIGINT UNSIGNED NOT NULL, `accepted_seq` BIGINT UNSIGNED NOT NULL,
 `update_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `admission_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `input_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `update_binary` MEDIUMBLOB NOT NULL, `update_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
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
 `accepted_at` DATETIME(3) NOT NULL, `persisted_state_seq` BIGINT UNSIGNED NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_collaboration_admission_sequence` (`tenant_id`,`room_id`,`accepted_seq`),
 UNIQUE KEY `uk_collaboration_admission_update` (`tenant_id`,`room_id`,`update_id`),
 UNIQUE KEY `uk_collaboration_admission_key` (`admission_key`),
 CONSTRAINT `chk_collaboration_admission_size` CHECK (`update_bytes` > 0 AND `update_bytes`=OCTET_LENGTH(`update_binary`) AND `lease_epoch` > 0 AND `accepted_seq` > 0),
 CONSTRAINT `fk_collaboration_admission_room` FOREIGN KEY (`tenant_id`,`room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`,`id`),
 CONSTRAINT `fk_collaboration_admission_connection` FOREIGN KEY (`tenant_id`,`connection_id`) REFERENCES `pa_collaboration_connection` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pa_collaboration_barrier` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `tenant_id` BIGINT UNSIGNED NOT NULL,
 `room_id` BIGINT UNSIGNED NOT NULL,
 `connection_id` BIGINT UNSIGNED NOT NULL,
 `barrier_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `lease_epoch` BIGINT UNSIGNED NOT NULL, `target_accepted_seq` BIGINT UNSIGNED NOT NULL,
 `purpose` VARCHAR(16) NOT NULL, `status` VARCHAR(16) NOT NULL,
 `input_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `committed_state_seq` BIGINT UNSIGNED NULL, `committed_state_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 `created_at` DATETIME(3) NOT NULL, `expires_at` DATETIME(3) NOT NULL, `completed_at` DATETIME(3) NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_collaboration_barrier` (`tenant_id`,`room_id`,`barrier_key`),
 CONSTRAINT `chk_collaboration_barrier_status` CHECK (`status` IN ('draining','committed','recovery')),
 CONSTRAINT `chk_collaboration_barrier_purpose` CHECK (`purpose` IN ('version','archive','restore','shutdown')),
 CONSTRAINT `fk_collaboration_barrier_room` FOREIGN KEY (`tenant_id`,`room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`,`id`),
 CONSTRAINT `fk_collaboration_barrier_connection` FOREIGN KEY (`tenant_id`,`connection_id`) REFERENCES `pa_collaboration_connection` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
