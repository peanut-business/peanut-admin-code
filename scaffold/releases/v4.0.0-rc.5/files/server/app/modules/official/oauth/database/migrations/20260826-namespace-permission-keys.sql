-- Replace historical OAuth/channel Admin URI permissions with Module-key
-- namespaced keys while preserving existing RBAC grants.

CREATE TEMPORARY TABLE `pa_official_oauth_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL,
  `new_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`old_key`),
  UNIQUE KEY `uk_official_oauth_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_oauth_permission_key_map` (`old_key`, `new_key`) VALUES
  ('setting/web-page/config',                    'official.oauth.web-page.config'),
  ('setting/web-page/save',                      'official.oauth.web-page.save'),
  ('setting/mini-program/config',                'official.oauth.mini-program.config'),
  ('setting/mini-program/save',                  'official.oauth.mini-program.save'),
  ('setting/official-account/config',            'official.oauth.official-account.config'),
  ('setting/official-account/save',              'official.oauth.official-account.save'),
  ('setting/official-account/menu',              'official.oauth.official-account.menu.detail'),
  ('setting/official-account/menu/save',         'official.oauth.official-account.menu.save'),
  ('setting/official-account/menu/publish',      'official.oauth.official-account.menu.publish'),
  ('setting/official-account/reply/lists',       'official.oauth.official-account.reply.list'),
  ('setting/official-account/reply/detail',      'official.oauth.official-account.reply.detail'),
  ('setting/official-account/reply/add',         'official.oauth.official-account.reply.add'),
  ('setting/official-account/reply/edit',        'official.oauth.official-account.reply.edit'),
  ('setting/official-account/reply/delete',      'official.oauth.official-account.reply.delete'),
  ('setting/official-account/reply/status',      'official.oauth.official-account.reply.update-status'),
  ('setting/open-platform/config',               'official.oauth.open-platform.config'),
  ('setting/open-platform/save',                 'official.oauth.open-platform.save');

CREATE TEMPORARY TABLE `pa_official_oauth_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_oauth_permission_key_assertion`
    CHECK (`mapped_count` = 17 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_oauth_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` permission
    WHERE BINARY permission.`key` = BINARY mapping.`old_key`
       OR BINARY permission.`key` = BINARY mapping.`new_key`
  )),
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` permission
    WHERE (BINARY permission.`key` = BINARY mapping.`old_key`
        OR BINARY permission.`key` = BINARY mapping.`new_key`)
      AND permission.`module_key` NOT IN ('peanut.admin', 'official.oauth')
  ))
FROM `pa_official_oauth_permission_key_map` mapping;

UPDATE `pa_system_menu` menu
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY menu.`perms` = BINARY mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` old_permission
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
LEFT JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`
SET old_permission.`key` = mapping.`new_key`,
    old_permission.`module_key` = 'official.oauth',
    old_permission.`status` = 'active',
    old_permission.`retired_at` = NULL,
    old_permission.`updated_at` = UTC_TIMESTAMP(3)
WHERE new_permission.`id` IS NULL;

INSERT IGNORE INTO `pa_role_permission`
  (`tenant_id`, `role_id`, `permission_id`, `granted_by_member_id`, `granted_at`)
SELECT binding.`tenant_id`, binding.`role_id`, new_permission.`id`,
       binding.`granted_by_member_id`, binding.`granted_at`
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, new_permission.`id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`
SET permission.`module_key` = 'official.oauth',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_oauth_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.oauth')
FROM `pa_permission` permission
JOIN `pa_official_oauth_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`;

DROP TEMPORARY TABLE `pa_official_oauth_permission_key_assertion`;
DROP TEMPORARY TABLE `pa_official_oauth_permission_key_map`;
