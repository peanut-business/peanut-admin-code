-- peanut-release: 5.0.1
-- Preserve historical tenant grants; edit already included moving a department.
-- Unknown catalog provenance and non-tenant policy references fail before writes.
CREATE TEMPORARY TABLE `pa_dept_permission_map` (
  `legacy_key` VARCHAR(160) NOT NULL,
  `canonical_key` VARCHAR(160) NOT NULL,
  PRIMARY KEY (`legacy_key`,`canonical_key`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
INSERT INTO `pa_dept_permission_map` VALUES
  ('dept/lists','core.department.read'),
  ('dept/detail','core.department.read'),
  ('dept/all','core.department.read'),
  ('dept/leaderdept','core.department.read'),
  ('dept/add','core.department.create'),
  ('dept/edit','core.department.update'),
  ('dept/edit','core.department.move'),
  ('dept/delete','core.department.archive'),
  ('dept/status','core.department.update');

CREATE TEMPORARY TABLE `pa_dept_permission_assertion` (
  `phase` VARCHAR(64) NOT NULL,
  `valid` INT NOT NULL,
  CONSTRAINT `chk_dept_permission_assertion` CHECK (`valid`=1)
);
INSERT INTO `pa_dept_permission_assertion`
SELECT 'canonical_catalog', IF(COUNT(*)=5 AND SUM(`module_key`='core' AND `status`='active')=5,1,0)
FROM `pa_permission`
WHERE `key` IN ('core.department.read','core.department.create','core.department.update','core.department.move','core.department.archive');
INSERT INTO `pa_dept_permission_assertion`
SELECT 'legacy_provenance', IF(COUNT(*)=0,1,0)
FROM `pa_permission` p
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`)
  AND (p.`module_key` NOT IN ('application','peanut.admin') OR p.`manifest_version`<>'fresh-schema-v1');
INSERT INTO `pa_dept_permission_assertion`
SELECT 'platform_grants', IF(COUNT(*)=0,1,0)
FROM `pa_platform_role_permission` r JOIN `pa_permission` p ON p.`id`=r.`permission_id`
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`);
INSERT INTO `pa_dept_permission_assertion`
SELECT 'resource_operation_grants', IF(COUNT(*)=0,1,0)
FROM `pa_resource_operation_permission` r JOIN `pa_permission` p ON p.`id`=r.`permission_id`
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`);
INSERT INTO `pa_dept_permission_assertion`
SELECT 'resource_operation_selection', IF(COUNT(*)=0,1,0)
FROM `pa_resource_operation_target_type` r JOIN `pa_permission` p ON p.`id`=r.`policy_selection_permission_id`
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`);
-- A legacy native projection has no verified default fingerprint in the fresh
-- schema. Stop for review rather than retiring a possibly custom native menu.
INSERT INTO `pa_dept_permission_assertion`
SELECT 'native_menu_references', IF(COUNT(*)=0,1,0)
FROM `pa_menu_definition` m JOIN `pa_permission` p ON p.`id`=m.`required_permission_id`
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`);

CREATE TEMPORARY TABLE `pa_dept_action_seed` (
  `action_key` VARCHAR(160) NOT NULL,
  `action_name` VARCHAR(50) NOT NULL,
  `sort_order` SMALLINT NOT NULL,
  PRIMARY KEY (`action_key`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
INSERT INTO `pa_dept_action_seed` VALUES
  ('core.department.create','新增部门',40),
  ('core.department.update','编辑部门',30),
  ('core.department.move','移动部门',20),
  ('core.department.archive','归档部门',10);
CREATE TEMPORARY TABLE `pa_dept_action_assertion` (
  `phase` VARCHAR(64) NOT NULL,
  `valid` INT NOT NULL,
  CONSTRAINT `chk_dept_action_assertion` CHECK (`valid`=1)
);
INSERT INTO `pa_dept_action_assertion`
SELECT 'action_identity_conflict',IF(COUNT(*)=0,1,0)
FROM `pa_system_menu` m JOIN `pa_dept_action_seed` seed
  ON m.`menu_key`=seed.`action_key` OR m.`perms`=seed.`action_key`
WHERE NOT (m.`menu_key`<=>seed.`action_key` AND m.`perms`=seed.`action_key`
  AND m.`parent_key`<=>'core.department.list' AND m.`module_key`='core'
  AND m.`pid`=0 AND m.`type`='A' AND m.`name`=seed.`action_name`
  AND m.`sort`=seed.`sort_order` AND m.`icon`='' AND m.`paths`='' AND m.`component`=''
  AND m.`is_cache`=0 AND m.`is_show`=1 AND m.`is_disable`=0
  AND m.`upstream_defaults_json` IS NULL AND m.`menu_conflict_json` IS NULL);
INSERT INTO `pa_dept_action_assertion`
SELECT 'action_identity_ambiguous',IF(COUNT(*)=0,1,0) FROM (
  SELECT seed.`action_key` FROM `pa_dept_action_seed` seed
  JOIN `pa_system_menu` m ON m.`menu_key`=seed.`action_key` OR m.`perms`=seed.`action_key`
  GROUP BY seed.`action_key` HAVING COUNT(*)>1
) conflicts;

-- Enumerate only the complete, unchanged historical seed fingerprints before
-- any write. Auxiliary identities are generated; their names, module, parent,
-- permission and every display/default field establish the seed fingerprint.
CREATE TEMPORARY TABLE `pa_dept_default_page_ids` AS
SELECT m.`id`,m.`menu_key` FROM `pa_system_menu` m
JOIN `pa_system_menu` parent ON parent.`id`=m.`pid` AND parent.`menu_key`=m.`parent_key`
WHERE m.`menu_key`='system.dept.lists' AND m.`module_key`='peanut.admin'
  AND m.`type`='C' AND m.`name`='部门管理' AND m.`perms`='dept/lists'
  AND m.`paths`='/system/dept' AND m.`component`='system/dept/index'
  AND m.`icon`='icon-mind-mapping' AND m.`sort`=70
  AND m.`is_cache`=0 AND m.`is_show`=1 AND m.`is_disable`=0
  AND parent.`menu_key`='core.organization' AND parent.`type`='M' AND parent.`paths`='/system'
  AND m.`menu_conflict_json` IS NULL
  AND (m.`upstream_defaults_json` IS NULL OR m.`upstream_defaults_json`=JSON_OBJECT('pid',m.`pid`,'type',m.`type`,'name',m.`name`,'icon',m.`icon`,'sort',m.`sort`,'perms',m.`perms`,'paths',m.`paths`,'component',m.`component`,'is_cache',m.`is_cache`,'is_show',m.`is_show`,'is_disable',m.`is_disable`));
CREATE TEMPORARY TABLE `pa_dept_default_menu_ids` AS
SELECT `id`,`menu_key` FROM `pa_dept_default_page_ids`;
INSERT INTO `pa_dept_default_menu_ids` (`id`,`menu_key`)
SELECT m.`id`,m.`menu_key` FROM `pa_system_menu` m
JOIN `pa_dept_default_page_ids` parent ON parent.`id`=m.`pid` AND parent.`menu_key`=m.`parent_key`
WHERE m.`type`='A' AND m.`paths`='' AND m.`component`='' AND m.`icon`=''
  AND m.`is_cache`=0 AND m.`is_show`=1 AND m.`is_disable`=0
  AND m.`upstream_defaults_json` IS NULL AND m.`menu_conflict_json` IS NULL
  AND ((m.`module_key`='peanut.admin' AND
      ((m.`menu_key`='system.dept.add' AND m.`perms`='dept/add' AND m.`name`='新增部门' AND m.`sort`=30)
        OR (m.`menu_key`='system.dept.edit' AND m.`perms`='dept/edit' AND m.`name`='编辑部门' AND m.`sort`=20)
        OR (m.`menu_key`='system.dept.delete' AND m.`perms`='dept/delete' AND m.`name`='删除部门' AND m.`sort`=10)))
    OR (m.`module_key`='application' AND m.`sort`=0 AND
      ((m.`perms`='dept/all' AND m.`name`='部门选择')
        OR (m.`perms`='dept/detail' AND m.`name`='部门详情')
        OR (m.`perms`='dept/leaderdept' AND m.`name`='负责人部门')
        OR (m.`perms`='dept/status' AND m.`name`='部门状态'))));
INSERT INTO `pa_dept_permission_assertion`
SELECT 'legacy_menu_custom_reference',IF(COUNT(*)=0,1,0)
FROM `pa_system_menu` m
LEFT JOIN `pa_dept_default_menu_ids` matched ON matched.`id`=m.`id`
WHERE matched.`id` IS NULL AND
  (m.`perms` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`)
    OR m.`menu_key` IN ('system.dept.lists','system.dept.add','system.dept.edit','system.dept.delete'));
INSERT INTO `pa_dept_permission_assertion`
SELECT 'legacy_menu_ambiguous_seed',IF(COUNT(*)=0,1,0) FROM (
  SELECT m.`perms` FROM `pa_system_menu` m
  JOIN `pa_dept_default_menu_ids` matched ON matched.`id`=m.`id`
  GROUP BY m.`perms` HAVING COUNT(*)>1
) duplicates;
-- Separate snapshot avoids reopening the same MySQL temporary table twice.
-- Every unknown descendant has a first edge leaving this known set; reject that
-- edge through either stable parent_key or historical pid before hiding parents.
CREATE TEMPORARY TABLE `pa_dept_default_menu_parents` AS
SELECT `id`,`menu_key` FROM `pa_dept_default_menu_ids`;
INSERT INTO `pa_dept_permission_assertion`
SELECT 'legacy_menu_custom_descendant',IF(COUNT(*)=0,1,0)
FROM `pa_system_menu` child
JOIN `pa_dept_default_menu_parents` parent
  ON child.`pid`=parent.`id` OR child.`parent_key`=parent.`menu_key`
LEFT JOIN `pa_dept_default_menu_ids` matched ON matched.`id`=child.`id`
WHERE matched.`id` IS NULL;

CREATE TEMPORARY TABLE `pa_dept_grant_moves` AS
SELECT r.`id` AS legacy_grant_id,r.`tenant_id`,r.`role_id`,canonical.`id` AS permission_id,
  r.`granted_by_member_id`,r.`granted_at`
FROM `pa_role_permission` r
JOIN `pa_permission` legacy ON legacy.`id`=r.`permission_id`
JOIN `pa_dept_permission_map` mapping ON mapping.`legacy_key`=legacy.`key`
JOIN `pa_permission` canonical ON canonical.`key`=mapping.`canonical_key`
WHERE legacy.`status`='active';
CREATE TEMPORARY TABLE `pa_dept_affected_roles` AS
SELECT DISTINCT `tenant_id`,`role_id` FROM `pa_dept_grant_moves`;
CREATE TEMPORARY TABLE `pa_dept_affected_tenants` AS
SELECT DISTINCT `tenant_id` FROM `pa_dept_affected_roles`;

START TRANSACTION;
INSERT INTO `pa_system_menu`
  (`menu_key`,`parent_key`,`module_key`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT seed.`action_key`,'core.department.list','core',0,'A',seed.`action_name`,'',seed.`sort_order`,seed.`action_key`,'','',0,1,0
FROM `pa_dept_action_seed` seed
WHERE NOT EXISTS (SELECT 1 FROM `pa_system_menu` m WHERE m.`menu_key`=seed.`action_key`);
-- Deterministically keep the oldest historical grant when several reads merge.
INSERT INTO `pa_role_permission` (`tenant_id`,`role_id`,`permission_id`,`granted_by_member_id`,`granted_at`)
SELECT moved.`tenant_id`,moved.`role_id`,moved.`permission_id`,moved.`granted_by_member_id`,moved.`granted_at`
FROM (
  SELECT g.*, ROW_NUMBER() OVER (PARTITION BY `tenant_id`,`role_id`,`permission_id` ORDER BY `granted_at`,`legacy_grant_id`) AS grant_rank
  FROM `pa_dept_grant_moves` g
) moved
LEFT JOIN `pa_role_permission` existing ON existing.`tenant_id`=moved.`tenant_id`
  AND existing.`role_id`=moved.`role_id` AND existing.`permission_id`=moved.`permission_id`
WHERE moved.grant_rank=1 AND existing.`id` IS NULL;
DELETE r FROM `pa_role_permission` r
JOIN (SELECT DISTINCT legacy_grant_id FROM `pa_dept_grant_moves`) moved ON moved.legacy_grant_id=r.`id`;
UPDATE `pa_role` r JOIN `pa_dept_affected_roles` affected
  ON affected.`tenant_id`=r.`tenant_id` AND affected.`role_id`=r.`id`
SET r.`authorization_revision`=r.`authorization_revision`+1,r.`updated_at`=UTC_TIMESTAMP(3);
UPDATE `pa_tenant` t JOIN `pa_dept_affected_tenants` affected ON affected.`tenant_id`=t.`id`
SET t.`authorization_revision`=t.`authorization_revision`+1,t.`updated_at`=UTC_TIMESTAMP(3);
-- Retain identities and foreign-key references; the old routes no longer consume them.
UPDATE `pa_permission` p
SET p.`status`='retired',p.`retired_at`=COALESCE(p.`retired_at`,UTC_TIMESTAMP(3)),p.`updated_at`=UTC_TIMESTAMP(3)
WHERE p.`key` IN (SELECT `legacy_key` FROM `pa_dept_permission_map`) AND p.`status`<>'retired';

-- All known default nodes, including the four auxiliary role choices, retire
-- together. Unknown references and descendants were rejected before writes.
UPDATE `pa_system_menu` m JOIN `pa_dept_default_menu_ids` matched ON matched.`id`=m.`id`
SET m.`is_show`=0,m.`is_disable`=1;
COMMIT;

DROP TEMPORARY TABLE `pa_dept_default_menu_ids`;
DROP TEMPORARY TABLE `pa_dept_default_menu_parents`;
DROP TEMPORARY TABLE `pa_dept_default_page_ids`;
DROP TEMPORARY TABLE `pa_dept_affected_tenants`;
DROP TEMPORARY TABLE `pa_dept_affected_roles`;
DROP TEMPORARY TABLE `pa_dept_grant_moves`;
DROP TEMPORARY TABLE `pa_dept_permission_assertion`;
DROP TEMPORARY TABLE `pa_dept_permission_map`;
DROP TEMPORARY TABLE `pa_dept_action_assertion`;
DROP TEMPORARY TABLE `pa_dept_action_seed`;
