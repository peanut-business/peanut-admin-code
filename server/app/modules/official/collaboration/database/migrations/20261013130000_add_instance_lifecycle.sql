CREATE TABLE `pa_collaboration_runtime` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `instance_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `edition` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `config_revision` BIGINT UNSIGNED NOT NULL,
 `config_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `phase` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `drain_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
 `plan_json` JSON NULL,
 `receipt_json` JSON NULL,
 `updated_at` DATETIME(3) NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_collaboration_runtime_instance` (`instance_id`,`edition`),
 CONSTRAINT `chk_collaboration_runtime_phase` CHECK (`phase` IN ('prepared','active','draining','durably_drained','stopped'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
