-- Align the two historical platform operation catalog owners without changing
-- permission IDs, role bindings or permission meanings. Unknown owners fail closed.
CREATE TEMPORARY TABLE `pa_platform_ops_permission_owner_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `permission_count` INT NOT NULL,
  `expected_owner_count` INT NOT NULL,
  CONSTRAINT `chk_platform_ops_permission_owner_assertion`
    CHECK (`permission_count` = 2 AND `expected_owner_count` = 2)
);

INSERT INTO `pa_platform_ops_permission_owner_assertion`
  (`phase`, `permission_count`, `expected_owner_count`)
SELECT 'before', COUNT(*),
  SUM(CASE WHEN `module_key` IN ('peanut.admin', 'platform') THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN ('platform.ops.module.manage', 'platform.ops.upgrade.manage');

UPDATE `pa_permission`
SET `module_key` = 'platform'
WHERE `module_key` = 'peanut.admin'
  AND `key` IN ('platform.ops.module.manage', 'platform.ops.upgrade.manage');

INSERT INTO `pa_platform_ops_permission_owner_assertion`
  (`phase`, `permission_count`, `expected_owner_count`)
SELECT 'after', COUNT(*),
  SUM(CASE WHEN `module_key` = 'platform' THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN ('platform.ops.module.manage', 'platform.ops.upgrade.manage');

DROP TEMPORARY TABLE `pa_platform_ops_permission_owner_assertion`;
