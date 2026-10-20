<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use RuntimeException;

/** Consumer of the fixed native selected-table MySQL 8.4 dump profile, never an SQL executor. */
final class SourceApplicationDump
{
    private const PREFIX = [
        '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;',
        '/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;',
        '/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;',
        '/*!50503 SET NAMES utf8mb4 */;',
        '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;',
        "/*!40103 SET TIME_ZONE='+00:00' */;",
        '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;',
        '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;',
        "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;",
        '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;',
    ];
    private const SUFFIX = [
        '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;',
        '/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;',
        '/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;',
        '/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;',
        '/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;',
        '/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;',
        '/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;',
        '/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;',
    ];
    private const TABLE_PREFIX = [
        '/*!40101 SET @saved_cs_client     = @@character_set_client */;',
        '/*!50503 SET character_set_client = utf8mb4 */;',
    ];
    private const TABLE_SUFFIX = '/*!40101 SET character_set_client = @saved_cs_client */;';

    /** All expected tables must occur exactly once and in native dump order. Data stays private. */
    public static function read(string $bytes, array $tables): array
    {
        if ($tables === [] || !array_is_list($tables) || count(array_unique($tables)) !== count($tables)
            || strlen($bytes) > 134217728 || str_contains($bytes, "\0")) { self::fail(); }
        foreach ($tables as $table) { if (!is_string($table) || preg_match('/\Apa_[a-z0-9_]+\z/D', $table) !== 1) { self::fail(); } }
        [$statements, $trailer] = self::statements($bytes);
        $position = 0; $prefix = ''; $blocks = [];
        foreach (self::PREFIX as $expected) { $prefix .= self::take($statements, $position, $expected); }
        $sourceOffset = strlen($prefix);
        foreach ($tables as $table) {
            $block = self::take($statements, $position, 'DROP TABLE IF EXISTS `' . $table . '`;');
            foreach (self::TABLE_PREFIX as $expected) { $block .= self::take($statements, $position, $expected); }
            $create = $statements[$position++] ?? null;
            if (!is_array($create) || $create['comments'] !== 0
                || !str_starts_with(trim($create['bytes']), 'CREATE TABLE `' . $table . '` (')
                || preg_match("~\\) ENGINE=InnoDB(?: AUTO_INCREMENT=[1-9][0-9]*)? DEFAULT CHARSET=[a-z0-9_]+ COLLATE=[a-z0-9_]+(?: COMMENT='(?:[^'\\\\]|\\\\.|'')*')?;\\z~Ds", trim($create['bytes'])) !== 1) { self::fail(); }
            $block .= $create['bytes'];
            $block .= self::take($statements, $position, self::TABLE_SUFFIX);
            $rows = 0;
            while (isset($statements[$position]) && str_starts_with(trim($statements[$position]['bytes']), 'INSERT ')) {
                $insert = $statements[$position++];
                self::insert($insert, $table); $block .= $insert['bytes']; ++$rows;
            }
            $blocks[$table] = ['bytes' => $block, 'sha256' => hash('sha256', $block), 'insert_rows' => $rows,
                'source_offset' => $sourceOffset, 'source_bytes' => strlen($block)];
            $sourceOffset += strlen($block);
        }
        $suffix = '';
        foreach (self::SUFFIX as $expected) { $suffix .= self::take($statements, $position, $expected); }
        if ($position !== count($statements)) { self::fail(); }
        return ['prefix' => $prefix, 'tables' => $blocks, 'suffix' => $suffix . $trailer,
            'source_sha256' => hash('sha256', $bytes), 'source_bytes' => strlen($bytes)];
    }

    /** Keep original table bytes; remove the profile's four constraint-disabling/restoring SETs. */
    public static function select(array $dump, array $tables): string
    {
        if ($tables === [] || !array_is_list($tables) || count(array_unique($tables)) !== count($tables)) { self::fail(); }
        $prefix = $dump['prefix']; $suffix = $dump['suffix'];
        foreach ([self::PREFIX[6], self::PREFIX[7]] as $statement) {
            if (substr_count($prefix, $statement) !== 1) { self::fail(); }
            $prefix = str_replace($statement, '', $prefix);
        }
        foreach ([self::SUFFIX[2], self::SUFFIX[3]] as $statement) {
            if (substr_count($suffix, $statement) !== 1) { self::fail(); }
            $suffix = str_replace($statement, '', $suffix);
        }
        $payload = "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n" . $prefix;
        foreach ($tables as $table) {
            if (!isset($dump['tables'][$table])) { self::fail(); }
            $payload .= $dump['tables'][$table]['bytes'];
        }
        return $payload . $suffix;
    }

    private static function take(array $statements, int &$position, string $expected): string
    {
        $statement = $statements[$position++] ?? null;
        if (!is_array($statement) || trim($statement['bytes']) !== $expected) { self::fail(); }
        return $statement['bytes'];
    }

    /** --skip-extended-insert emits one tuple containing only native scalar literals. */
    private static function insert(array $statement, string $table): void
    {
        $text = trim($statement['bytes']); $prefix = 'INSERT INTO `' . $table . '` VALUES (';
        if ($statement['comments'] !== 0 || !str_starts_with($text, $prefix) || !str_ends_with($text, ');')) { self::fail(); }
        $literal = "(?:NULL|[-+]?(?:[0-9]+(?:\\.[0-9]*)?|\\.[0-9]+)(?:[eE][-+]?[0-9]+)?|0x[0-9A-Fa-f]*|(?:_binary\\s*)?'(?:[^'\\\\]|\\\\.|'')*')";
        $values = substr($text, strlen($prefix), -2);
        if (preg_match('~\\A' . $literal . '(?:,' . $literal . ')*\\z~Ds', $values) !== 1) { self::fail(); }
    }

    /** Semicolons inside quoted literals/identifiers/version comments cannot split a statement. */
    private static function statements(string $bytes): array
    {
        $statements = []; $start = 0; $quote = null; $comment = false; $comments = 0; $length = strlen($bytes);
        for ($offset = 0; $offset < $length; ++$offset) {
            $char = $bytes[$offset]; $next = $bytes[$offset + 1] ?? '';
            if ($comment) {
                if ($char === '*' && $next === '/') { $comment = false; ++$offset; }
                continue;
            }
            if ($quote !== null) {
                if ($char === '\\') { if (++$offset >= $length) { self::fail(); } }
                elseif ($char === $quote) {
                    if ($next === $quote) { ++$offset; } else { $quote = null; }
                }
                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) { $quote = $char; continue; }
            if ($char === '/' && $next === '*') { $comment = true; ++$comments; ++$offset; continue; }
            if ($char === '#' || ($char === '-' && $next === '-')) { self::fail(); }
            if ($char === ';') {
                $statements[] = ['bytes' => substr($bytes, $start, $offset + 1 - $start), 'comments' => $comments];
                $start = $offset + 1; $comments = 0;
            }
        }
        $trailer = substr($bytes, $start);
        if ($quote !== null || $comment || trim($trailer) !== '') { self::fail(); }
        return [$statements, $trailer];
    }

    private static function fail(): never { throw new RuntimeException('APPLICATION_SCHEMA_DUMP_FORMAT_UNSUPPORTED'); }
}
