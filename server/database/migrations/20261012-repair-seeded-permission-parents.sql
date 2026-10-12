-- Repair only the unchanged historical transaction seeds with the known wrong
-- parent fingerprint. Preserve IDs, permission bindings and custom menu edits.
-- Ambiguous/missing parent or permission identities are not repaired.
CREATE TEMPORARY TABLE `pa_seeded_permission_parent_candidates` AS
SELECT
  SUM(CASE WHEN `type`='M' AND `paths`='/app-setting' THEN 1 ELSE 0 END) AS group_count,
  MIN(CASE WHEN `type`='M' AND `paths`='/app-setting' THEN `id` END) AS group_id,
  SUM(CASE WHEN `perms`='setting/transaction/config' THEN 1 ELSE 0 END) AS config_count,
  MIN(CASE WHEN `perms`='setting/transaction/config' THEN `id` END) AS config_id,
  SUM(CASE WHEN `perms`='setting/transaction/save' THEN 1 ELSE 0 END) AS save_count,
  MIN(CASE WHEN `perms`='setting/transaction/save' THEN `id` END) AS save_id
FROM `pa_system_menu`;

UPDATE `pa_system_menu` child
JOIN `pa_seeded_permission_parent_candidates` candidates
  ON candidates.config_count=1 AND candidates.group_count=1
  AND child.`id`=candidates.config_id
JOIN `pa_system_menu` wrong_parent
  ON wrong_parent.`id`=child.`pid` AND wrong_parent.`menu_key`=child.`parent_key`
JOIN `pa_system_menu` parent
  ON parent.`id`=candidates.group_id AND parent.`type`='M' AND parent.`paths`='/app-setting'
SET child.`pid`=parent.`id`, child.`parent_key`=parent.`menu_key`
WHERE child.`perms`='setting/transaction/config'
  AND child.`name`='交易设置' AND child.`type`='C'
  AND child.`paths`='/app-setting/transaction'
  AND child.`component`='app-setting/transaction/index'
  AND child.`icon`='' AND child.`sort`=0
  AND child.`is_cache`=0 AND child.`is_show`=1 AND child.`is_disable`=0
  AND child.`module_key`='application' AND child.`menu_key` LIKE 'custom.%'
  AND child.`upstream_defaults_json` IS NULL AND child.`menu_conflict_json` IS NULL
  AND wrong_parent.`menu_key`='core.organization' AND wrong_parent.`paths`='/system'
  AND parent.`menu_key` IS NOT NULL AND parent.`menu_key`<>'';

UPDATE `pa_system_menu` child
JOIN `pa_seeded_permission_parent_candidates` candidates
  ON candidates.save_count=1 AND candidates.config_count=1 AND candidates.group_count=1
  AND child.`id`=candidates.save_id
JOIN `pa_system_menu` wrong_parent
  ON wrong_parent.`id`=child.`pid` AND wrong_parent.`menu_key`=child.`parent_key`
JOIN `pa_system_menu` parent
  ON parent.`id`=candidates.config_id AND parent.`perms`='setting/transaction/config' AND parent.`type`='C'
  AND parent.`paths`='/app-setting/transaction'
JOIN `pa_system_menu` group_parent
  ON group_parent.`id`=candidates.group_id AND group_parent.`id`=parent.`pid` AND group_parent.`menu_key`=parent.`parent_key`
  AND group_parent.`type`='M' AND group_parent.`paths`='/app-setting'
SET child.`pid`=parent.`id`, child.`parent_key`=parent.`menu_key`
WHERE child.`perms`='setting/transaction/save'
  AND child.`name`='交易设置保存' AND child.`type`='A'
  AND child.`paths`='' AND child.`component`='' AND child.`icon`='' AND child.`sort`=0
  AND child.`is_cache`=0 AND child.`is_show`=1 AND child.`is_disable`=0
  AND child.`module_key`='application' AND child.`menu_key` LIKE 'custom.%'
  AND child.`upstream_defaults_json` IS NULL AND child.`menu_conflict_json` IS NULL
  AND wrong_parent.`menu_key`='system.menu.add' AND wrong_parent.`perms`='menu/add';

DROP TEMPORARY TABLE `pa_seeded_permission_parent_candidates`;
