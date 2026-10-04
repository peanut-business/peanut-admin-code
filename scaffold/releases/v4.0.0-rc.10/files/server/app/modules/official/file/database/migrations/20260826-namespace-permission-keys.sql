-- Replace the historical File Admin URI permissions with the Module-key
-- namespace. Permission ids and both RBAC binding tables are preserved when
-- possible; an already synchronized target key is merged deterministically.

CREATE TEMPORARY TABLE `pa_official_file_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL,
  `new_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`old_key`),
  UNIQUE KEY `uk_official_file_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_file_permission_key_map` (`old_key`, `new_key`) VALUES
  ('file/lists',      'official.file.list'),
  ('file/move',       'official.file.move'),
  ('file/rename',     'official.file.rename'),
  ('file/delete',     'official.file.delete'),
  ('file/cate/lists', 'official.file.category.list'),
  ('file/cate/add',   'official.file.category.add'),
  ('file/cate/edit',  'official.file.category.edit'),
  ('file/cate/delete','official.file.category.delete'),
  ('upload/image',    'official.file.upload.image'),
  ('upload/video',    'official.file.upload.video'),
  ('upload/file',     'official.file.upload.file');

CREATE TEMPORARY TABLE `pa_official_file_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_file_permission_key_assertion`
    CHECK (`mapped_count` = 11 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_file_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'before',
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE BINARY p.`key` = BINARY mapping.`old_key`
       OR BINARY p.`key` = BINARY mapping.`new_key`
  )),
  SUM(EXISTS(
    SELECT 1 FROM `pa_permission` p
    WHERE (BINARY p.`key` = BINARY mapping.`old_key`
        OR BINARY p.`key` = BINARY mapping.`new_key`)
      AND p.`module_key` NOT IN ('peanut.admin', 'official.file')
  ))
FROM `pa_official_file_permission_key_map` mapping;

UPDATE `pa_system_menu` menu
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY menu.`perms` = BINARY mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` old_permission
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
LEFT JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`
SET old_permission.`key` = mapping.`new_key`,
    old_permission.`module_key` = 'official.file',
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
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, new_permission.`id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`
SET permission.`module_key` = 'official.file',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_file_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.file')
FROM `pa_permission` permission
JOIN `pa_official_file_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`;

DROP TEMPORARY TABLE `pa_official_file_permission_key_assertion`;
DROP TEMPORARY TABLE `pa_official_file_permission_key_map`;
