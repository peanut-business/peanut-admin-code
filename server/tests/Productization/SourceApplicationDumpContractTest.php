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
