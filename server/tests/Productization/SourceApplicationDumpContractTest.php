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
if (in_array('--mysql', array_slice($argv, 1), true)) {
    require dirname(__DIR__) . '/Support/application-prefix-mysql.php';
}
