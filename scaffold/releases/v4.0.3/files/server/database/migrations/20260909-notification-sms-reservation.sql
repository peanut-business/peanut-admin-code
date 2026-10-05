-- peanut-release: 3.0.13
-- LMA-020: durable verification-SMS reservation and Provider outcome state.
-- Existing delivery logs remain immutable facts; only the newest successful
-- verification send still inside its 60-second window is activated.

ALTER TABLE `pa_notice_log`
  ADD COLUMN `reservation_key` CHAR(37) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'opaque verification SMS reservation capability' AFTER `verify_code_hash`,
  ADD COLUMN `idempotency_key_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'request-scoped idempotency identity digest' AFTER `reservation_key`,
  ADD COLUMN `request_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'immutable scene and receiver request digest' AFTER `idempotency_key_hash`,
  ADD COLUMN `receiver_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'receiver digest used by the active reservation key' AFTER `request_digest`,
  ADD COLUMN `reservation_until` INT UNSIGNED NULL
    COMMENT 'exclusive resend reservation deadline' AFTER `receiver_hash`,
  ADD COLUMN `reservation_active` TINYINT UNSIGNED NULL
    COMMENT '1 while reserved, successful, or unknown inside the resend window' AFTER `reservation_until`;

UPDATE `pa_notice_log` l
JOIN (
  SELECT `tenant_id`, `channel`, `receiver`, MAX(`id`) AS `id`
  FROM `pa_notice_log`
  WHERE `channel` = 1
    AND `scene_id` > 0
    AND `status` = 1
    AND `send_time` > UNIX_TIMESTAMP() - 60
  GROUP BY `tenant_id`, `channel`, `receiver`
) latest ON latest.`id` = l.`id`
SET l.`receiver_hash` = SHA2(l.`receiver`, 256),
    l.`reservation_until` = l.`send_time` + 60,
    l.`reservation_active` = 1;

ALTER TABLE `pa_notice_log`
  ADD UNIQUE KEY `uk_notice_sms_reservation_key` (`reservation_key`),
  ADD UNIQUE KEY `uk_notice_sms_idempotency` (`tenant_id`, `idempotency_key_hash`),
  ADD UNIQUE KEY `uk_notice_sms_active_receiver`
    (`tenant_id`, `channel`, `receiver_hash`, `reservation_active`),
  ADD KEY `idx_notice_sms_reservation_expiry`
    (`tenant_id`, `reservation_active`, `reservation_until`, `id`),
  ADD CONSTRAINT `chk_notice_sms_reservation_active`
    CHECK (`reservation_active` IS NULL OR (
      `reservation_active` = 1
      AND `receiver_hash` IS NOT NULL
      AND `reservation_until` IS NOT NULL
      AND `status` IN (0, 1, 3)
    ));
