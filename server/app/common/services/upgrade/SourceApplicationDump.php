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
    // Parent-first catalog order; inverse deletes only new rows in reverse.
    private const IDENTITY_CATALOGS = ['pa_permission', 'pa_target_type', 'pa_data_condition_definition',
        'pa_protected_resource', 'pa_resource_operation', 'pa_resource_operation_target_type',
        'pa_resource_operation_permission', 'pa_resource_operation_condition', 'pa_menu_definition',
        'pa_setting_definition', 'pa_reference_code_set'];
    private const IDENTITY_STATE = ['pa_module_migration', 'pa_module_installation', 'pa_plugin_module', 'pa_plugin_installation'];
    private const IDENTITY_CREATED = ['pa_source_read_grant', 'pa_data_permission_target', 'pa_data_permission_condition',
        'pa_data_permission_target_set', 'pa_data_permission_group', 'pa_data_permission_policy'];

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
                self::insert($insert, $table, self::insertColumns(trim($create['bytes']), $table)); $block .= $insert['bytes']; ++$rows;
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

    /** Fixed application-prefix recovery. Referenced identities are never dropped or replaced. */
    public static function restorePrefix(array $original, array $current, array $tables): string
    {
        $parents = ['pa_permission', 'pa_role', 'pa_tenant', 'pa_tenant_session'];
        $changes = [];
        foreach ($original['tables'] as $table => $block) {
            if (!isset($current['tables'][$table])) { self::fail(); }
            if ($block['sha256'] !== $current['tables'][$table]['sha256']) { $changes[] = $table; }
        }
        if (array_keys($original['tables']) !== array_keys($current['tables']) || array_diff($changes, $tables) !== []) { self::fail(); }
        $updates = ''; $deletes = ''; $alter = '';
        foreach (array_intersect($parents, $changes) as $table) {
            $old = self::parentImage($original['tables'][$table]['bytes'], $table);
            $now = self::parentImage($current['tables'][$table]['bytes'], $table);
            if ($old['columns'] !== $now['columns']) { self::fail(); }
            if ($table === 'pa_tenant_session') {
                // This migration only replaces this CHECK; session and token identities stay intact.
                $pattern = '/^  CONSTRAINT `chk_tenant_session_client` CHECK \([^\r\n]+\),?\n/m';
                $oldShape = preg_replace($pattern, '', $old['shape'], -1, $oldCount);
                $nowShape = preg_replace($pattern, '', $now['shape'], -1, $nowCount);
                $oldShape = str_replace(",\n) ENGINE=", "\n) ENGINE=", $oldShape);
                $nowShape = str_replace(",\n) ENGINE=", "\n) ENGINE=", $nowShape);
                if ($oldShape !== $nowShape || $oldCount > 1 || $nowCount > 1 || $old['rows'] !== $now['rows']
                    || $old['allocator'] !== $now['allocator']) { self::fail(); }
                if ($old['shape'] !== $now['shape']) {
                    preg_match($pattern, $old['shape'], $constraint);
                    $operations = $nowCount === 1 ? ['DROP CHECK `chk_tenant_session_client`'] : [];
                    if ($oldCount === 1) { $operations[] = 'ADD ' . rtrim(trim($constraint[0]), ','); }
                    if ($operations === []) { self::fail(); }
                    $alter .= 'ALTER TABLE `pa_tenant_session` ' . implode(', ', $operations) . ";\n";
                }
                continue;
            }
            if ($old['shape'] !== $now['shape'] || array_diff_key($old['rows'], $now['rows']) !== []) { self::fail(); }
            $allowed = $table === 'pa_permission'
                ? ['module_key', 'type', 'name', 'description', 'risk_level', 'status', 'manifest_version', 'updated_at', 'retired_at']
                : ['authorization_revision', 'updated_at'];
            foreach ($old['rows'] as $id => $row) {
                $set = [];
                foreach ($row as $column => $literal) {
                    if ($literal === $now['rows'][$id][$column]) { continue; }
                    if (!in_array($column, $allowed, true)) { self::fail(); }
                    $set[] = '`' . $column . '`=' . $literal;
                }
                if ($set !== []) { $updates .= 'UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE `id`=' . $row['id'] . ";\n"; }
            }
            foreach (array_diff_key($now['rows'], $old['rows']) as $row) {
                if ($table !== 'pa_permission' || $row['key'] !== "'readiness/checklist'") { self::fail(); }
                $deletes .= 'DELETE FROM `pa_permission` WHERE `id`=' . $row['id'] . " AND `key`='readiness/checklist';\n";
            }
            if ($old['allocator'] !== $now['allocator']) {
                if ($old['allocator'] === null) { self::fail(); }
                $alter .= 'ALTER TABLE `' . $table . '` AUTO_INCREMENT=' . $old['allocator'] . ";\n";
            }
        }
        // Restore child bindings before removing the one permission identity this prefix can insert.
        // DROP/CREATE is restricted by the host's complete inbound-FK/trigger admission.
        $blocks = array_values(array_diff($changes, $parents, ['pa_schema_migration']));
        $sql = $blocks === [] ? '' : self::select($original, $blocks);
        // select() closes its native session settings; restore literal interpretation for row updates.
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\nSET NAMES utf8mb4;\nSET @restore_sql_mode=@@SQL_MODE;\nSET @restore_time_zone=@@TIME_ZONE;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET TIME_ZONE='+00:00';\nSTART TRANSACTION;\n";
        $sql .= $updates . $deletes . "COMMIT;\n" . $alter;
        if (in_array('pa_schema_migration', $changes, true)) { $sql .= self::select($original, ['pa_schema_migration']); }
        return $sql . "SET SQL_MODE=@restore_sql_mode;\nSET TIME_ZONE=@restore_time_zone;\n";
    }

    /**
     * Fixed Identity inverse derivation, never an executor. The host must prove
     * the real lifecycle/capsule, owned CREATEs, current FK closure and stopped
     * writers. No shared table or original parent identity is dropped/replaced.
     */
    public static function restoreIdentity(array $original, array $current): string
    {
        $catalogs = [...self::IDENTITY_CATALOGS, ...self::IDENTITY_STATE];
        $parents = ['pa_department', 'pa_tenant_audit_event'];
        $allowed = [...$catalogs, ...$parents, ...self::IDENTITY_CREATED];
        $oldTables = array_keys($original['tables']); $nowTables = array_keys($current['tables']);
        if (array_diff($oldTables, $allowed) !== [] || array_diff($nowTables, $allowed) !== []
            || array_intersect($oldTables, self::IDENTITY_CREATED) !== []
            || array_diff(array_diff([...$catalogs, ...$parents], ['pa_reference_code_set']), $oldTables) !== []
            || array_diff($oldTables, $nowTables) !== []
            || array_diff($nowTables, $oldTables, self::IDENTITY_CREATED) !== []) { self::fail(); }
        $ddl = ''; $updates = []; $deletes = []; $allocators = ''; $missingBindings = '';
        foreach (self::IDENTITY_CREATED as $table) {
            if (!isset($current['tables'][$table])) { continue; }
            $image = self::parentImage($current['tables'][$table]['bytes'], $table);
            if ($image['rows'] !== [] || !in_array($image['allocator'], [null, '1'], true)) { self::fail(); }
            $ddl .= 'DROP TABLE `' . $table . "`;\n";
        }
        foreach ($oldTables as $table) {
            $old = self::parentImage($original['tables'][$table]['bytes'], $table);
            $now = self::parentImage($current['tables'][$table]['bytes'], $table);
            if (in_array($table, $parents, true)) {
                $ddl .= self::identityParentInverse($table, $old, $now);
                continue;
            }
            if ($old['columns'] !== $now['columns'] || $old['shape'] !== $now['shape']) { self::fail(); }
            if (array_diff_key($old['rows'], $now['rows']) !== [] && $table !== 'pa_resource_operation_permission') { self::fail(); }
            // Unknown unique-key rewrites/swaps need reconciliation, not a broad DELETE/REPLACE.
            $keys = ['id'];
            preg_match_all('/^  UNIQUE KEY `[^`]+` \(([^\r\n]+)\),?$/m', $old['shape'], $matches);
            foreach ($matches[1] as $index) {
                preg_match_all('/`([a-z][a-z0-9_]*)`/', $index, $parts);
                $keys = [...$keys, ...$parts[1]];
            }
            if ($table === 'pa_resource_operation_condition') { $keys[] = 'selector_resource_key'; }
            foreach ($old['rows'] as $id => $row) {
                if (!isset($now['rows'][$id])) {
                    $missingBindings .= 'INSERT INTO `' . $table . '` (`' . implode('`,`', $old['columns']) . '`) VALUES (' . implode(',', $row) . ");\n";
                    continue;
                }
                $set = [];
                foreach ($row as $column => $literal) {
                    if ($now['rows'][$id][$column] === $literal) { continue; }
                    if (in_array($column, $keys, true) || $table === 'pa_module_migration'
                        || (in_array($table, self::IDENTITY_STATE, true)
                            && ($row['module_key'] ?? $row['plugin_key'] ?? null) !== "'official.identity'")) { self::fail(); }
                    $set[] = '`' . $column . '`=' . $literal;
                }
                if ($set !== []) { $updates[$table][] = 'UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE `id`=' . $row['id'] . ";\n"; }
            }
            foreach (array_diff_key($now['rows'], $old['rows']) as $row) {
                if (in_array($table, self::IDENTITY_STATE, true)
                    && ($row['module_key'] ?? $row['plugin_key'] ?? null) !== "'official.identity'") { self::fail(); }
                if ($table === 'pa_module_migration' && !in_array($row['migration_key'] ?? null,
                    array_map(static fn(string $key): string => "'" . $key . "'", array_keys(\PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::recoveryStages())), true)) { self::fail(); }
                $deletes[$table][] = 'DELETE FROM `' . $table . '` WHERE `id`=' . $row['id'] . ";\n";
            }
            if ($old['allocator'] !== $now['allocator']) {
                if (!preg_match('/^  `id` [^\r\n]*AUTO_INCREMENT/m', $old['shape'])) { self::fail(); }
                $allocators .= 'ALTER TABLE `' . $table . '` AUTO_INCREMENT=' . ($old['allocator'] ?? '1') . ";\n";
            }
        }
        $sql = "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\nSET NAMES utf8mb4;\nSET @restore_sql_mode=@@SQL_MODE;\nSET @restore_time_zone=@@TIME_ZONE;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET TIME_ZONE='+00:00';\n" . $ddl . "START TRANSACTION;\n";
        // Remove replacement relation IDs before restoring missing original IDs.
        foreach (['pa_resource_operation_condition', 'pa_resource_operation_permission', 'pa_resource_operation_target_type'] as $table) {
            $sql .= implode('', $deletes[$table] ?? []); unset($deletes[$table]);
        }
        foreach (self::IDENTITY_CATALOGS as $table) { $sql .= implode('', $updates[$table] ?? []); }
        $sql .= $missingBindings;
        foreach (array_reverse(self::IDENTITY_CATALOGS) as $table) { $sql .= implode('', $deletes[$table] ?? []); }
        // Installation and migration state follows business catalogs; plugin_module precedes its parent.
        foreach (self::IDENTITY_STATE as $table) { $sql .= implode('', $updates[$table] ?? []) . implode('', $deletes[$table] ?? []); }
        return $sql . "COMMIT;\n" . $allocators . "SET SQL_MODE=@restore_sql_mode;\nSET TIME_ZONE=@restore_time_zone;\n";
    }

    private static function identityParentInverse(string $table, array $old, array $now): string
    {
        $fields = $table === 'pa_department'
            ? ['leader' => "''", 'mobile' => "''", 'leader_member_id' => 'NULL']
            : ['revocation_fact_key' => 'NULL', 'revocation_fact_sha256' => 'NULL'];
        $indexes = $table === 'pa_department' ? ['idx_department_leader_member'] : ['uk_identity_revocation_fact', 'idx_identity_revocation_scan'];
        $added = array_values(array_diff($now['columns'], $old['columns']));
        if (array_diff($old['columns'], $now['columns']) !== [] || array_diff($added, array_keys($fields)) !== []
            || array_keys($old['rows']) !== array_keys($now['rows']) || $old['allocator'] !== $now['allocator']) { self::fail(); }
        foreach ($old['rows'] as $id => $row) {
            foreach ($row as $name => $literal) { if ($now['rows'][$id][$name] !== $literal) { self::fail(); } }
            foreach ($added as $name) { if ($now['rows'][$id][$name] !== $fields[$name]) { self::fail(); } }
        }
        $shape = $now['shape']; $operations = [];
        foreach ($indexes as $index) {
            $pattern = '/^  (?:UNIQUE )?KEY `' . $index . '` \([^\r\n]+\),?\n/m';
            if (preg_match($pattern, $old['shape']) === 1) { continue; }
            $shape = preg_replace($pattern, '', $shape, -1, $count);
            if ($count > 1) { self::fail(); }
            if ($count === 1) { $operations[] = 'DROP INDEX `' . $index . '`'; }
        }
        foreach (array_reverse($added) as $name) {
            $shape = preg_replace('/^  `' . $name . '` [^\r\n]+\n/m', '', $shape, -1, $count);
            if ($count !== 1) { self::fail(); }
            $operations[] = 'DROP COLUMN `' . $name . '`';
        }
        $normalize = static fn(string $value): string => str_replace(",\n) ENGINE=", "\n) ENGINE=", $value);
        if ($normalize($shape) !== $normalize($old['shape'])) { self::fail(); }
        return $operations === [] ? '' : 'ALTER TABLE `' . $table . '` ' . implode(', ', $operations) . ";\n";
    }

    /** Strict original-id row literals shared by the fixed application and Identity consumers. */
    private static function parentImage(string $block, string $table): array
    {
        [$statements] = self::statements($block); $create = null; $rows = [];
        foreach ($statements as $statement) {
            $text = trim($statement['bytes']);
            if (str_starts_with($text, 'CREATE TABLE `')) { $create = $text; }
        }
        if (!is_string($create) || !str_contains($create, "\n  PRIMARY KEY (`id`)")
            || preg_match_all('/^  `([a-z][a-z0-9_]*)` [^\r\n]+$/m', $create, $matches) === false) { self::fail(); }
        $columns = self::insertColumns($create, $table) ?? $matches[1];
        if ($columns === [] || count(array_unique($columns)) !== count($columns) || $columns[0] !== 'id') { self::fail(); }
        foreach ($statements as $statement) {
            $text = trim($statement['bytes']);
            if (!str_starts_with($text, 'INSERT ')) { continue; }
            $values = self::insert($statement, $table, self::insertColumns($create, $table));
            if (count($values) !== count($columns) || preg_match('/\A[1-9][0-9]*\z/D', $values[0]) !== 1) { self::fail(); }
            $id = $values[0];
            if (isset($rows[$id])) { self::fail(); }
            $rows[$id] = array_combine($columns, $values);
        }
        $allocator = preg_match('/\) ENGINE=InnoDB AUTO_INCREMENT=([1-9][0-9]*) /', $create, $match) === 1 ? $match[1] : null;
        return ['columns' => $columns, 'rows' => $rows, 'allocator' => $allocator,
            'shape' => preg_replace('/(\) ENGINE=InnoDB) AUTO_INCREMENT=[1-9][0-9]* /', '$1 ', $create)];
    }

    private static function take(array $statements, int &$position, string $expected): string
    {
        $statement = $statements[$position++] ?? null;
        if (!is_array($statement) || trim($statement['bytes']) !== $expected) { self::fail(); }
        return $statement['bytes'];
    }

    /** --skip-extended-insert emits one tuple containing only native scalar literals. */
    private static function insert(array $statement, string $table, ?array $columns = null): array
    {
        $text = trim($statement['bytes']);
        $prefix = 'INSERT INTO `' . $table . '`' . ($columns === null ? '' : ' (`' . implode('`, `', $columns) . '`)') . ' VALUES (';
        if ($statement['comments'] !== 0 || !str_starts_with($text, $prefix) || !str_ends_with($text, ');')) { self::fail(); }
        $literal = "(?:NULL|[-+]?(?:[0-9]+(?:\\.[0-9]*)?|\\.[0-9]+)(?:[eE][-+]?[0-9]+)?|0x[0-9A-Fa-f]*|(?:_binary\\s*)?'(?:[^'\\\\]|\\\\.|'')*')";
        $values = substr($text, strlen($prefix), -2);
        if (preg_match('~\\A' . $literal . '(?:,' . $literal . ')*\\z~Ds', $values) !== 1) { self::fail(); }
        preg_match_all('~\\G(' . $literal . ')(?:,|\\z)~s', $values, $matches);
        if (implode(',', $matches[1]) !== $values || ($columns !== null && count($matches[1]) !== count($columns))) { self::fail(); }
        return $matches[1];
    }

    /** MySQL 8.4 emits only named writable columns for this fixed generated table. */
    private static function insertColumns(string $create, string $table): ?array
    {
        if ($table !== 'pa_resource_operation_condition') { return null; }
        preg_match_all('/^  `([a-z][a-z0-9_]*)` ([^\r\n]+)$/m', $create, $matches, PREG_SET_ORDER);
        $columns = []; $generated = [];
        foreach ($matches as $match) {
            if (str_contains($match[2], 'GENERATED ALWAYS')) { $generated[] = $match[1]; }
            else { $columns[] = $match[1]; }
        }
        if ($generated !== ['selector_resource_key_norm'] || $columns === [] || $columns[0] !== 'id') { self::fail(); }
        return $columns;
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
