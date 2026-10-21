<?php

declare(strict_types=1);

use app\common\services\upgrade\SourceApplicationDump;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$reflection = new ReflectionClass(SourceApplicationDump::class);
$prefix = implode("\n", $reflection->getConstant('PREFIX')) . "\n";
$suffix = implode("\n", $reflection->getConstant('SUFFIX')) . "\n";
$table = static function (string $name, string $insert = '') use ($reflection): string {
    return 'DROP TABLE IF EXISTS `' . $name . "`;\n"
        . implode("\n", $reflection->getConstant('TABLE_PREFIX')) . "\n"
        . 'CREATE TABLE `' . $name . "` (`id` bigint, `value` text COMMENT 'literal; -- # /* */') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;\n"
        . $reflection->getConstant('TABLE_SUFFIX') . "\n" . $insert;
};
$insert = "INSERT INTO `pa_one` VALUES (1,'literal; DROP TABLE `pa_two`; -- \\' quote',NULL,0x00FF,-1.2e3);\n";
$one = $table('pa_one', $insert); $two = $table('pa_two');
$bytes = $prefix . $one . $two . $suffix;
$parsed = SourceApplicationDump::read($bytes, ['pa_one', 'pa_two']);
if ($parsed['tables']['pa_one']['insert_rows'] !== 1 || $parsed['tables']['pa_two']['insert_rows'] !== 0) { throw new RuntimeException('Native row count lost.'); }
foreach ($parsed['tables'] as $block) {
    if (substr($bytes, $block['source_offset'], $block['source_bytes']) !== $block['bytes']) { throw new RuntimeException('Original byte segment identity lost.'); }
}
if ($parsed['prefix'] . implode('', array_column($parsed['tables'], 'bytes')) . $parsed['suffix'] !== $bytes) { throw new RuntimeException('Dump bytes omitted by parsing.'); }
$selected = SourceApplicationDump::select($parsed, ['pa_one']);
if (!str_contains($selected, $insert) || str_contains($selected, 'DROP TABLE IF EXISTS `pa_two`')
    || str_contains($selected, 'FOREIGN_KEY_CHECKS=0') || str_contains($selected, 'FOREIGN_KEY_CHECKS=@')
    || !str_starts_with($selected, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n")) { throw new RuntimeException('Selected original bytes or constraint protection lost.'); }
$invalid = [
    $bytes . "DROP DATABASE `unrelated`;\n",
    $prefix . $one . $one . $two . $suffix,
    $prefix . $one . $suffix,
    str_replace('SET NAMES utf8mb4', 'SET NAMES latin1', $bytes),
    $prefix . $table('pa_one', 'INSERT INTO `pa_two` VALUES (1);') . $two . $suffix,
    $prefix . $table('pa_one', 'INSERT INTO `pa_one` SELECT 1;') . $two . $suffix,
    $prefix . $table('pa_one', 'INSERT INTO `pa_one` VALUES (SLEEP(1));') . $two . $suffix,
    $prefix . $table('pa_one', 'INSERT INTO `pa_one` VALUES (1),(2);') . $two . $suffix,
    $prefix . $one . "CREATE TRIGGER t BEFORE INSERT ON `pa_one` FOR EACH ROW SET @x=1;" . $two . $suffix,
    $prefix . $table('pa_one', "INSERT INTO `pa_one` VALUES ('unterminated);") . $two . $suffix,
    str_replace('ENGINE=InnoDB', 'ENGINE=MyISAM', $bytes),
    str_replace('`id` bigint', '`id` bigint /* unsupported executable context */', $bytes),
    str_replace('COLLATE=utf8mb4_0900_ai_ci;', 'COLLATE=utf8mb4_0900_ai_ci AS SELECT * FROM unrelated.secret;', $bytes),
    str_replace('COLLATE=utf8mb4_0900_ai_ci;', "COLLATE=utf8mb4_0900_ai_ci DATA DIRECTORY='/outside';", $bytes),
];
foreach ($invalid as $changed) {
    try { SourceApplicationDump::read($changed, ['pa_one', 'pa_two']); }
    catch (RuntimeException $error) {
        if ($error->getMessage() === 'APPLICATION_SCHEMA_DUMP_FORMAT_UNSUPPORTED') { continue; }
        throw $error;
    }
    throw new RuntimeException('Unsupported dump accepted.');
}
echo "Source dump contract: quoted delimiters and exact selected bytes preserved; 14 unsupported mutations rejected; no SQL executed.\n";

// Referenced parent recovery must retain keys and reject changes outside fixed migration columns.
$parent = static function (string $name, string $columns, string $rows, int $allocator = 3, string $check = '') use ($reflection): string {
    return 'DROP TABLE IF EXISTS `' . $name . "`;\n"
        . implode("\n", $reflection->getConstant('TABLE_PREFIX')) . "\n"
        . 'CREATE TABLE `' . $name . "` (\n" . $columns . "  PRIMARY KEY (`id`)" . ($check === '' ? '' : ",\n" . $check)
        . "\n) ENGINE=InnoDB AUTO_INCREMENT=" . $allocator . " DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;\n"
        . $reflection->getConstant('TABLE_SUFFIX') . "\n" . $rows;
};
$columns = "  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `key` varchar(160) NOT NULL,\n  `module_key` varchar(96) NOT NULL,\n  `updated_at` datetime(3) NOT NULL,\n";
$oldRow = "INSERT INTO `pa_permission` VALUES (1,'existing','application','2026-01-01 00:00:00.000');\n";
$newRow = "INSERT INTO `pa_permission` VALUES (1,'existing','platform','2026-02-01 00:00:00.000');\n";
$added = "INSERT INTO `pa_permission` VALUES (3,'readiness/checklist','peanut.admin','2026-02-01 00:00:00.000');\n";
$oldPermission = $parent('pa_permission', $columns, $oldRow);
$ledger = $table('pa_schema_migration');
$parseParents = static fn(string $permission): array => SourceApplicationDump::read($prefix . $permission . $ledger . $suffix, ['pa_permission', 'pa_schema_migration']);
$before = $parseParents($oldPermission);
$after = $parseParents($parent('pa_permission', $columns, $newRow . $added, 4));
$restore = SourceApplicationDump::restorePrefix($before, $after, ['pa_permission', 'pa_schema_migration']);
if (str_contains($restore, 'DROP TABLE IF EXISTS `pa_permission`')
    || !str_contains($restore, "UPDATE `pa_permission` SET `module_key`='application',`updated_at`='2026-01-01 00:00:00.000' WHERE `id`=1;")
    || !str_contains($restore, "DELETE FROM `pa_permission` WHERE `id`=3 AND `key`='readiness/checklist';")
    || !str_contains($restore, 'ALTER TABLE `pa_permission` AUTO_INCREMENT=3;')
    || str_contains($restore, 'FOREIGN_KEY_CHECKS=0')) { throw new RuntimeException('Referenced identity recovery lost.'); }
$badParents = [
    $parent('pa_permission', $columns, str_replace("'existing'", "'changed-key'", $newRow)),
    $parent('pa_permission', $columns, $newRow . str_replace("'readiness/checklist'", "'unknown'", $added)),
    $parent('pa_permission', $columns, ''),
    $parent('pa_permission', str_replace('varchar(96)', 'varchar(97)', $columns), $newRow),
];
foreach ($badParents as $bad) {
    try { SourceApplicationDump::restorePrefix($before, $parseParents($bad), ['pa_permission', 'pa_schema_migration']); }
    catch (RuntimeException $error) { if ($error->getMessage() === 'APPLICATION_SCHEMA_DUMP_FORMAT_UNSUPPORTED') { continue; } throw $error; }
    throw new RuntimeException('Unknown parent mutation accepted.');
}
$sessionColumns = "  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `client_key` varchar(64) NOT NULL,\n";
$sessionRow = "INSERT INTO `pa_tenant_session` VALUES (1,'admin-web');\n";
$oldCheck = "  CONSTRAINT `chk_tenant_session_client` CHECK ((`client_key` = _utf8mb4'admin-web'))";
$newCheck = "  CONSTRAINT `chk_tenant_session_client` CHECK ((char_length(`client_key`) between 1 and 64))";
$sessionImage = static fn(string $check, string $row): array => SourceApplicationDump::read($prefix . $ledger
    . $parent('pa_tenant_session', $sessionColumns, $row, 3, $check) . $suffix, ['pa_schema_migration', 'pa_tenant_session']);
$restore = SourceApplicationDump::restorePrefix($sessionImage($oldCheck, $sessionRow), $sessionImage($newCheck, $sessionRow), ['pa_tenant_session']);
if (!str_contains($restore, 'DROP CHECK `chk_tenant_session_client`, ADD ' . trim($oldCheck))
    || str_contains($restore, 'DROP TABLE IF EXISTS `pa_tenant_session`') || str_contains($restore, 'UPDATE `pa_tenant_session`')) {
    throw new RuntimeException('Referenced session recovery lost.');
}
$restore = SourceApplicationDump::restorePrefix($sessionImage('', $sessionRow), $sessionImage($newCheck, $sessionRow), ['pa_tenant_session']);
if (!str_contains($restore, 'ALTER TABLE `pa_tenant_session` DROP CHECK `chk_tenant_session_client`;')
    || str_contains($restore, 'ADD CONSTRAINT')) { throw new RuntimeException('Originally absent CHECK recovery lost.'); }
$refused = false;
try { SourceApplicationDump::restorePrefix($sessionImage($oldCheck, $sessionRow), $sessionImage($newCheck, str_replace('admin-web', 'other', $sessionRow)), ['pa_tenant_session']); }
catch (RuntimeException $error) {
    if ($error->getMessage() !== 'APPLICATION_SCHEMA_DUMP_FORMAT_UNSUPPORTED') { throw $error; }
    $refused = true;
}
if (!$refused) { throw new RuntimeException('Session row mutation accepted.'); }
echo "Application prefix derivation: stable parent identities, original literals/allocator, constrained session CHECK and unknown-change refusal; no SQL executed.\n";

// Fixed Identity derivation: whole original catalogs, shared parent data and exact absence.
$identityTables = [...$reflection->getConstant('IDENTITY_CATALOGS'), ...$reflection->getConstant('IDENTITY_STATE'), 'pa_department', 'pa_tenant_audit_event'];
$identityTables = array_values(array_diff($identityTables, ['pa_reference_code_set']));
$simpleColumns = "  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `module_key` varchar(96) NOT NULL,\n  `key` varchar(160) NOT NULL,\n  `payload` blob,\n";
$oldIdentity = [];
foreach ($identityTables as $name) { $oldIdentity[$name] = $parent($name, $simpleColumns, '', 1, '  UNIQUE KEY `unique_identity` (`key`)'); }
$permissionOriginal = "INSERT INTO `pa_permission` VALUES (9,'legacy','stable',0x00FF);\n";
$oldIdentity['pa_permission'] = $parent('pa_permission', $simpleColumns, $permissionOriginal, 10, '  UNIQUE KEY `unique_identity` (`key`)');
$moduleLedgerColumns = str_replace("  `payload` blob,", "  `migration_key` varchar(160) NOT NULL,\n  `payload` blob,", $simpleColumns);
$moduleLedgerOriginal = "INSERT INTO `pa_module_migration` VALUES (1,'other.module','old','other.module:original',NULL);\n";
$oldIdentity['pa_module_migration'] = $parent('pa_module_migration', $moduleLedgerColumns, $moduleLedgerOriginal, 2);
$bindingColumns = "  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `resource_operation_id` bigint unsigned NOT NULL,\n  `permission_id` bigint unsigned NOT NULL,\n";
$oldIdentity['pa_resource_operation_permission'] = $parent('pa_resource_operation_permission', $bindingColumns, "INSERT INTO `pa_resource_operation_permission` VALUES (1,7,9);\n", 2);
$conditionColumns = "  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `resource_operation_id` bigint unsigned NOT NULL,\n  `condition_definition_id` bigint unsigned NOT NULL,\n  `selector_resource_key` varchar(160) DEFAULT NULL,\n  `selector_resource_key_norm` varchar(160) GENERATED ALWAYS AS (coalesce(`selector_resource_key`,_utf8mb4'')) STORED,\n  `status` varchar(16) NOT NULL,\n";
$oldIdentity['pa_resource_operation_condition'] = $parent('pa_resource_operation_condition', $conditionColumns, '', 1);
$departmentColumns = "  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `tenant_id` bigint unsigned NOT NULL,\n  `name` varchar(160) NOT NULL,\n  `custom` blob,\n";
$auditColumns = "  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `tenant_id` bigint unsigned NOT NULL,\n  `event_type` varchar(64) NOT NULL,\n  `payload` text,\n";
$oldIdentity['pa_department'] = $parent('pa_department', $departmentColumns, "INSERT INTO `pa_department` VALUES (1,1,'original',0x00FF);\n", 2);
$oldIdentity['pa_tenant_audit_event'] = $parent('pa_tenant_audit_event', $auditColumns, "INSERT INTO `pa_tenant_audit_event` VALUES (1,1,'old','keep');\n", 2);
$parseIdentity = static function (array $blocks) use ($prefix, $suffix): array {
    ksort($blocks, SORT_STRING);
    return SourceApplicationDump::read($prefix . implode('', $blocks) . $suffix, array_keys($blocks));
};
$newIdentity = $oldIdentity;
$moduleLedgerCurrent = $moduleLedgerOriginal . "INSERT INTO `pa_module_migration` VALUES (2,'official.identity','identity','official.identity:20260716020101_create_pa_data_permission_policy',NULL);\n";
$newIdentity['pa_module_migration'] = $parent('pa_module_migration', $moduleLedgerColumns, $moduleLedgerCurrent, 3);
$newIdentity['pa_permission'] = $parent('pa_permission', $simpleColumns,
    "INSERT INTO `pa_permission` VALUES (9,'official.identity','stable',0x01FF);\nINSERT INTO `pa_permission` VALUES (11,'official.identity','new',NULL);\n", 12, '  UNIQUE KEY `unique_identity` (`key`)');
$newIdentity['pa_resource_operation_permission'] = $parent('pa_resource_operation_permission', $bindingColumns, "INSERT INTO `pa_resource_operation_permission` VALUES (3,7,9);\n", 4);
$namedCondition = 'INSERT INTO `pa_resource_operation_condition` (`id`, `resource_operation_id`, `condition_definition_id`, `selector_resource_key`, `status`) VALUES (2,7,3,NULL,\'active\');' . "\n";
$newIdentity['pa_resource_operation_condition'] = $parent('pa_resource_operation_condition', $conditionColumns, $namedCondition, 3);
$newDepartmentColumns = str_replace("  `custom`", "  `leader` varchar(50) NOT NULL DEFAULT '',\n  `leader_member_id` bigint unsigned DEFAULT NULL,\n  `mobile` varchar(20) NOT NULL DEFAULT '',\n  `custom`", $departmentColumns);
$newIdentity['pa_department'] = $parent('pa_department', $newDepartmentColumns, "INSERT INTO `pa_department` VALUES (1,1,'original','',NULL,'',0x00FF);\n", 2, '  KEY `idx_department_leader_member` (`tenant_id`,`leader_member_id`)');
$newAuditColumns = $auditColumns . "  `revocation_fact_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,\n  `revocation_fact_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,\n";
$newIdentity['pa_tenant_audit_event'] = $parent('pa_tenant_audit_event', $newAuditColumns, "INSERT INTO `pa_tenant_audit_event` VALUES (1,1,'old','keep',NULL,NULL);\n", 2,
    "  UNIQUE KEY `uk_identity_revocation_fact` (`revocation_fact_key`),\n  KEY `idx_identity_revocation_scan` (`tenant_id`,`event_type`,`id`)");
foreach ($reflection->getConstant('IDENTITY_CREATED') as $name) { $newIdentity[$name] = $parent($name, $simpleColumns, '', 1); }
foreach ($reflection->getConstant('IDENTITY_STATE') as $name) {
    if ($name === 'pa_module_migration') { continue; }
    $newIdentity[$name] = $parent($name, $simpleColumns, 'INSERT INTO `' . $name . "` VALUES (1,'official.identity','identity',NULL);\n", 2, '  UNIQUE KEY `unique_identity` (`key`)');
}
$identityOriginal = $parseIdentity($oldIdentity);
$identityCurrent = $parseIdentity($newIdentity);
$inverse = SourceApplicationDump::restoreIdentity($identityOriginal, $identityCurrent);
foreach (["UPDATE `pa_permission` SET `module_key`='legacy',`payload`=0x00FF WHERE `id`=9;",
    'DELETE FROM `pa_resource_operation_permission` WHERE `id`=3;',
    'INSERT INTO `pa_resource_operation_permission` (`id`,`resource_operation_id`,`permission_id`) VALUES (1,7,9);',
    'DELETE FROM `pa_module_migration` WHERE `id`=2;',
    'ALTER TABLE `pa_department` DROP INDEX `idx_department_leader_member`, DROP COLUMN `mobile`, DROP COLUMN `leader_member_id`, DROP COLUMN `leader`;',
    'ALTER TABLE `pa_tenant_audit_event` DROP INDEX `uk_identity_revocation_fact`, DROP INDEX `idx_identity_revocation_scan`, DROP COLUMN `revocation_fact_sha256`, DROP COLUMN `revocation_fact_key`;'] as $required) {
    if (!str_contains($inverse, $required)) { throw new RuntimeException('Fixed Identity inverse lost: ' . $required); }
}
if (str_contains($inverse, 'FOREIGN_KEY_CHECKS=0') || str_contains($inverse, 'UNIQUE_CHECKS=0')
    || str_contains($inverse, 'REPLACE ') || str_contains($inverse, 'selector_resource_key_norm')
    || substr_count($inverse, 'DROP TABLE ') !== 6
    || strpos($inverse, 'DROP TABLE `pa_data_permission_condition`') > strpos($inverse, 'DROP TABLE `pa_data_permission_target_set`')
    || strpos($inverse, 'DELETE FROM `pa_plugin_module`') > strpos($inverse, 'DELETE FROM `pa_plugin_installation`')
    || strpos($inverse, 'DELETE FROM `pa_resource_operation_permission`') > strpos($inverse, 'INSERT INTO `pa_resource_operation_permission`')) {
    throw new RuntimeException('Identity inverse constraints, generated column or dependency order changed.');
}
$badIdentity = [];
$bad = $newIdentity; $bad['pa_permission'] = $parent('pa_permission', $simpleColumns, '', 12, '  UNIQUE KEY `unique_identity` (`key`)'); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_permission'] = str_replace("'stable'", "'changed'", $bad['pa_permission']); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_plugin_module'] = str_replace("'official.identity'", "'other.module'", $bad['pa_plugin_module']); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_source_read_grant'] = $parent('pa_source_read_grant', $simpleColumns, "INSERT INTO `pa_source_read_grant` VALUES (1,'official.identity','business',NULL);\n", 2); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_source_read_grant'] = $parent('pa_source_read_grant', $simpleColumns, '', 2); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_reference_code_set'] = $parent('pa_reference_code_set', $simpleColumns, '', 1); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_department'] = str_replace('0x00FF', '0x01FF', $bad['pa_department']); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_department'] = str_replace("'original',''", "'original','new'", $bad['pa_department']); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_department'] = str_replace('AUTO_INCREMENT=2', 'AUTO_INCREMENT=3', $bad['pa_department']); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_tenant_audit_event'] = str_replace("'keep'", "'changed'", $bad['pa_tenant_audit_event']); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_target_type'] = str_replace('varchar(96)', 'varchar(97)', $bad['pa_target_type']); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_module_migration'] = str_replace("'old'", "'changed'", $bad['pa_module_migration']); $badIdentity[] = $bad;
$bad = $newIdentity; $bad['pa_module_migration'] = str_replace('official.identity:20260716020101_create_pa_data_permission_policy', 'official.identity:unknown', $bad['pa_module_migration']); $badIdentity[] = $bad;
foreach ($badIdentity as $bad) {
    try { SourceApplicationDump::restoreIdentity($identityOriginal, $parseIdentity($bad)); }
    catch (RuntimeException $error) { if ($error->getMessage() === 'APPLICATION_SCHEMA_DUMP_FORMAT_UNSUPPORTED') { continue; } throw $error; }
    throw new RuntimeException('Unknown Identity restoration accepted.');
}
foreach ([str_replace('`selector_resource_key`, `status`', '`selector_resource_key_norm`, `status`', $namedCondition),
    str_replace('`condition_definition_id`, `selector_resource_key`', '`selector_resource_key`, `condition_definition_id`', $namedCondition),
    str_replace('`status`) VALUES', '`status`, `status`) VALUES', $namedCondition)] as $invalidNamed) {
    $bad = $newIdentity; $bad['pa_resource_operation_condition'] = $parent('pa_resource_operation_condition', $conditionColumns, $invalidNamed, 3);
    try { $parseIdentity($bad); }
    catch (RuntimeException $error) { if ($error->getMessage() === 'APPLICATION_SCHEMA_DUMP_FORMAT_UNSUPPORTED') { continue; } throw $error; }
    throw new RuntimeException('Generated-column or reordered named INSERT accepted.');
}
echo "Identity inverse derivation: fixed scope/absence, shared PK/original hex literals, replacement binding IDs, generated-column exclusion, default-only parent ADD inverse and dependency order; 13 unsafe images/3 named-column mutations refused; MySQL/restore NOT_RUN.\n";
if (in_array('--mysql', array_slice($argv, 1), true)) {
    require dirname(__DIR__) . '/Support/application-prefix-mysql.php';
}
