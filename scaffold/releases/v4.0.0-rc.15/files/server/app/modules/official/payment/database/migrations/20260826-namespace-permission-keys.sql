-- Collapse historical Payment Admin URI permissions into the Module-key
-- namespace while preserving Tenant and Platform role grants.

CREATE TEMPORARY TABLE `pa_official_payment_permission_key_map` (
  `old_key` VARCHAR(160) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `new_key` VARCHAR(160) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`old_key`),
  KEY `idx_official_payment_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_payment_permission_key_map` (`old_key`, `new_key`) VALUES
  ('setting/pay/config',               'official.payment.settings.detail'),
  ('setting/pay/save',                 'official.payment.settings.save'),
  ('setting/recharge/config',          'official.payment.recharge-settings.detail'),
  ('setting/recharge/save',            'official.payment.recharge-settings.save'),
  ('finance/recharge/lists',           'official.payment.recharge.list'),
  ('recharge.recharge/lists',          'official.payment.recharge.list'),
  ('finance/recharge/refund',          'official.payment.recharge.refund'),
  ('recharge.recharge/refund',         'official.payment.recharge.refund'),
  ('finance/recharge/refundagain',     'official.payment.refund.retry'),
  ('recharge.recharge/refundagain',    'official.payment.refund.retry'),
  ('finance/refund/stat',              'official.payment.refund.stat'),
  ('finance.refund/stat',              'official.payment.refund.stat'),
  ('finance/refund/record',            'official.payment.refund.list'),
  ('finance.refund/record',            'official.payment.refund.list'),
  ('finance/refund/log',               'official.payment.refund.log'),
  ('finance.refund/log',               'official.payment.refund.log');

CREATE TEMPORARY TABLE `pa_official_payment_new_key_list` (
  `new_key` VARCHAR(160) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`new_key`)
);

INSERT INTO `pa_official_payment_new_key_list` (`new_key`)
SELECT DISTINCT `new_key` FROM `pa_official_payment_permission_key_map`;

CREATE TEMPORARY TABLE `pa_official_payment_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_payment_permission_key_assertion`
    CHECK (`mapped_count` = 10 AND `bad_owner_count` = 0)
);

CREATE TEMPORARY TABLE `pa_official_payment_assertion_mapped_count` (
  `count` INT NOT NULL
);

INSERT INTO `pa_official_payment_assertion_mapped_count` (`count`)
SELECT COUNT(*)
FROM `pa_official_payment_new_key_list` nkl
WHERE EXISTS(
  SELECT 1
  FROM `pa_permission` permission
  WHERE BINARY permission.`key` = BINARY nkl.`new_key`
) OR EXISTS(
  SELECT 1
  FROM `pa_permission` permission
  JOIN `pa_official_payment_permission_key_map` map_exists
    ON LOWER(permission.`key`) = map_exists.`old_key`
  WHERE BINARY map_exists.`new_key` = BINARY nkl.`new_key`
);

CREATE TEMPORARY TABLE `pa_official_payment_assertion_bad_owner_count` (
  `count` INT NOT NULL
);

INSERT INTO `pa_official_payment_assertion_bad_owner_count` (`count`)
SELECT COUNT(*)
FROM `pa_permission` permission
LEFT JOIN `pa_official_payment_permission_key_map` map_left
  ON LOWER(permission.`key`) = map_left.`old_key`
WHERE (map_left.`old_key` IS NOT NULL
    OR permission.`key` IN (
      SELECT `new_key` FROM `pa_official_payment_new_key_list`
    ))
  AND permission.`module_key` NOT IN ('peanut.admin', 'official.payment');

INSERT INTO `pa_official_payment_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  (SELECT `count` FROM `pa_official_payment_assertion_mapped_count`),
  (SELECT `count` FROM `pa_official_payment_assertion_bad_owner_count`);

CREATE TEMPORARY TABLE `pa_official_payment_permission_target` (
  `new_key` VARCHAR(160) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`new_key`),
  UNIQUE KEY `uk_official_payment_permission_target_id` (`permission_id`)
);

INSERT INTO `pa_official_payment_permission_target` (`new_key`, `permission_id`)
SELECT
  nkl.`new_key`,
  COALESCE(
    (
      SELECT MIN(permission.`id`)
      FROM `pa_permission` permission
      WHERE BINARY permission.`key` = BINARY nkl.`new_key`
    ),
    (
      SELECT MIN(p2.`id`)
      FROM `pa_permission` p2
      WHERE EXISTS(
        SELECT 1 FROM `pa_official_payment_permission_key_map` m
        WHERE LOWER(p2.`key`) = m.`old_key`
          AND BINARY m.`new_key` = BINARY nkl.`new_key`
      )
    )
  )
FROM `pa_official_payment_new_key_list` nkl;

UPDATE `pa_system_menu` menu
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(menu.`perms`) = mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_payment_permission_target` target
  ON target.`permission_id` = permission.`id`
SET permission.`key` = target.`new_key`,
    permission.`module_key` = 'official.payment',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, target.`permission_id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission
  ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`
JOIN `pa_official_payment_permission_target` target
  ON BINARY target.`new_key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, target.`permission_id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission
  ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`
JOIN `pa_official_payment_permission_target` target
  ON BINARY target.`new_key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission
  ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission
  ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_payment_permission_key_map` mapping
  ON LOWER(old_permission.`key`) = mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_payment_permission_target` target
  ON target.`permission_id` = permission.`id`
SET permission.`module_key` = 'official.payment',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_payment_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.payment')
FROM `pa_permission` permission
JOIN `pa_official_payment_permission_target` target
  ON BINARY permission.`key` = BINARY target.`new_key`;

DROP TEMPORARY TABLE `pa_official_payment_permission_target`;
DROP TEMPORARY TABLE `pa_official_payment_permission_key_assertion`;
DROP TEMPORARY TABLE `pa_official_payment_assertion_bad_owner_count`;
DROP TEMPORARY TABLE `pa_official_payment_assertion_mapped_count`;
DROP TEMPORARY TABLE `pa_official_payment_new_key_list`;
DROP TEMPORARY TABLE `pa_official_payment_permission_key_map`;
