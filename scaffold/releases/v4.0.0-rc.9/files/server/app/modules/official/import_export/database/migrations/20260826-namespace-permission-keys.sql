-- Replace historical Import/Export Admin URI permissions with Module-key
-- namespaced keys while preserving existing RBAC grants.

CREATE TEMPORARY TABLE `pa_official_import_export_permission_key_map` (
  `old_key` VARCHAR(160) NOT NULL,
  `new_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`old_key`),
  UNIQUE KEY `uk_official_import_export_permission_new_key` (`new_key`)
);

INSERT INTO `pa_official_import_export_permission_key_map` (`old_key`, `new_key`) VALUES
  ('log/export',          'official.import-export.operation-log.export'),
  ('log/export/status',   'official.import-export.operation.status'),
  ('log/export/download', 'official.import-export.result.download');

CREATE TEMPORARY TABLE `pa_official_import_export_permission_key_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `mapped_count` INT NOT NULL,
  `bad_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_import_export_permission_key_assertion`
    CHECK (`mapped_count` = 3 AND `bad_owner_count` = 0)
);

INSERT INTO `pa_official_import_export_permission_key_assertion`
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
      AND permission.`module_key` NOT IN ('peanut.admin', 'official.import-export')
  ))
FROM `pa_official_import_export_permission_key_map` mapping;

UPDATE `pa_system_menu` menu
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY menu.`perms` = BINARY mapping.`old_key`
SET menu.`perms` = mapping.`new_key`;

UPDATE `pa_permission` old_permission
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
LEFT JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`
SET old_permission.`key` = mapping.`new_key`,
    old_permission.`module_key` = 'official.import-export',
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
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

INSERT IGNORE INTO `pa_platform_role_permission`
  (`platform_role_id`, `permission_id`, `granted_at`)
SELECT binding.`platform_role_id`, new_permission.`id`, binding.`granted_at`
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`
JOIN `pa_permission` new_permission
  ON BINARY new_permission.`key` = BINARY mapping.`new_key`;

DELETE binding
FROM `pa_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE binding
FROM `pa_platform_role_permission` binding
JOIN `pa_permission` old_permission ON old_permission.`id` = binding.`permission_id`
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

DELETE old_permission
FROM `pa_permission` old_permission
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY old_permission.`key` = BINARY mapping.`old_key`;

UPDATE `pa_permission` permission
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`
SET permission.`module_key` = 'official.import-export',
    permission.`status` = 'active',
    permission.`retired_at` = NULL,
    permission.`updated_at` = UTC_TIMESTAMP(3);

INSERT INTO `pa_official_import_export_permission_key_assertion`
  (`phase`, `mapped_count`, `bad_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(permission.`module_key` <> 'official.import-export')
FROM `pa_permission` permission
JOIN `pa_official_import_export_permission_key_map` mapping
  ON BINARY permission.`key` = BINARY mapping.`new_key`;

DROP TEMPORARY TABLE `pa_official_import_export_permission_key_assertion`;
DROP TEMPORARY TABLE `pa_official_import_export_permission_key_map`;
