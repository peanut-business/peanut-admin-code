ALTER TABLE `pa_notification_message`
  ADD COLUMN `producer_key` VARCHAR(160) NULL,
  ADD COLUMN `producer_event_key` VARCHAR(160) NULL,
  ADD COLUMN `producer_business_key` VARCHAR(160) NULL,
  ADD COLUMN `producer_digest` CHAR(64) NULL,
  ADD COLUMN `producer_payload_sha256` CHAR(64) NULL,
  ADD UNIQUE KEY `uq_notification_producer_recipient` (`tenant_id`, `producer_digest`, `recipient_member_id`);

CREATE TABLE IF NOT EXISTS `pa_notification_task_actor` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `reference_key` CHAR(64) NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `resource_key` VARCHAR(160) NOT NULL,
  `operation` VARCHAR(64) NOT NULL,
  `targets_sha256` CHAR(64) NOT NULL,
  `request_id` VARCHAR(128) NOT NULL,
  `session_key` VARCHAR(128) NOT NULL,
  `client_key` VARCHAR(128) NOT NULL,
  `account_security_revision` BIGINT UNSIGNED NOT NULL,
  `tenant_security_revision` BIGINT UNSIGNED NOT NULL,
  `member_security_revision` BIGINT UNSIGNED NOT NULL,
  `outbox_key` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_task_actor` (`tenant_id`, `reference_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
