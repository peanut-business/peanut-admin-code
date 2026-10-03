-- peanut-release: 3.0.6

CREATE TABLE `pa_payment_tenant_channel_grant` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `provider` VARCHAR(64) NOT NULL,
  `external_binding_id` BIGINT UNSIGNED NOT NULL,
  `merchant_account_ref` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'non-secret merchant account reference',
  `merchant_group_ref` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'non-secret merchant group reference',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `revoked_at` INT UNSIGNED NULL DEFAULT NULL,
  `create_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `update_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `active_key` TINYINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN `status` = 1 AND `revoked_at` IS NULL THEN 1 ELSE NULL END
  ) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_grant_tenant_provider_active` (`tenant_id`, `provider`, `active_key`),
  UNIQUE KEY `uk_payment_grant_tenant_binding` (`tenant_id`, `provider`, `external_binding_id`),
  KEY `idx_payment_grant_binding_status` (`external_binding_id`, `status`, `id`),
  CONSTRAINT `fk_payment_grant_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payment_grant_external_binding`
    FOREIGN KEY (`external_binding_id`) REFERENCES `pa_external_channel_binding` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Payment-owned Tenant grant to use an external merchant channel';

INSERT INTO `pa_payment_tenant_channel_grant`
  (`tenant_id`, `provider`, `external_binding_id`, `merchant_account_ref`, `merchant_group_ref`,
   `status`, `revoked_at`, `create_time`, `update_time`)
SELECT
  b.`tenant_id`,
  b.`provider`,
  b.`id`,
  b.`identity_hash`,
  '',
  1,
  NULL,
  COALESCE(NULLIF(b.`create_time`, 0), UNIX_TIMESTAMP()),
  UNIX_TIMESTAMP()
FROM `pa_external_channel_binding` b
WHERE b.`provider` IN ('payment.wechat', 'payment.alipay');

ALTER TABLE `pa_recharge_order`
  ADD COLUMN `payment_binding_id` BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'External channel binding used by this payment attempt' AFTER `transaction_id`,
  ADD COLUMN `payment_grant_id` BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Payment Tenant channel grant used by this payment attempt' AFTER `payment_binding_id`,
  ADD COLUMN `payment_merchant_account_ref` VARCHAR(191) NOT NULL DEFAULT ''
    COMMENT 'non-secret merchant account reference snapshot' AFTER `payment_grant_id`,
  ADD COLUMN `payment_merchant_group_ref` VARCHAR(191) NOT NULL DEFAULT ''
    COMMENT 'non-secret merchant group reference snapshot' AFTER `payment_merchant_account_ref`,
  ADD KEY `idx_recharge_order_payment_binding` (`tenant_id`, `payment_binding_id`, `id`),
  ADD KEY `idx_recharge_order_payment_grant` (`tenant_id`, `payment_grant_id`, `id`),
  ADD CONSTRAINT `fk_recharge_order_payment_binding`
    FOREIGN KEY (`payment_binding_id`) REFERENCES `pa_external_channel_binding` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_recharge_order_payment_grant`
    FOREIGN KEY (`payment_grant_id`) REFERENCES `pa_payment_tenant_channel_grant` (`id`) ON DELETE RESTRICT;

UPDATE `pa_recharge_order` ro
JOIN `pa_external_channel_binding` b
  ON b.`tenant_id` = ro.`tenant_id`
 AND b.`provider` = CASE ro.`pay_way`
    WHEN 2 THEN 'payment.wechat'
    WHEN 3 THEN 'payment.alipay'
    ELSE ''
  END
SET
  ro.`payment_binding_id` = b.`id`,
  ro.`payment_merchant_account_ref` = b.`identity_hash`
WHERE ro.`payment_binding_id` IS NULL
  AND ro.`pay_way` IN (2, 3);

UPDATE `pa_recharge_order` ro
JOIN `pa_payment_tenant_channel_grant` g
  ON g.`tenant_id` = ro.`tenant_id`
 AND g.`provider` = CASE ro.`pay_way`
    WHEN 2 THEN 'payment.wechat'
    WHEN 3 THEN 'payment.alipay'
    ELSE ''
  END
 AND g.`external_binding_id` = ro.`payment_binding_id`
SET
  ro.`payment_grant_id` = g.`id`,
  ro.`payment_merchant_group_ref` = g.`merchant_group_ref`
WHERE ro.`payment_grant_id` IS NULL
  AND ro.`payment_binding_id` IS NOT NULL
  AND ro.`pay_way` IN (2, 3);
