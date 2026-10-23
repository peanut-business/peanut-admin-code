<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use app\platform\services\plugin\PluginLifecycleService;
use PDO;
use RuntimeException;

/** One explicit development move, not a general instance alias or an adoption retry. */
final class FileAdoptionRelocation
{
    public const ID = '20261024-relocate-file-adoption';
    public const SQL_SHA = 'ad3871cdd6ce7bf2fb35a09c55eebc7dc8216dbe9775157664283674ffe74f61';
    public const KEY = 'official.file:20260823-unify-storage-service';
    public const PROOF_SHA = 'ef6e5d8cd968a28a2d13d3ce3722275ef0720185049a94274a883868f3972def';
    private const MOVE_SHA = '135317ccf27e48fbcd41424c06d333b1c5f273d31e23da04cb38e2f2c0d4061a';
    private const DUMP_SHA = 'b233a34b4675da06bf4550d8319c2b5a71e6d2a7830740429450f30ce453207c';
    public const READ_TABLES = ['pa_file', 'pa_file_object', 'pa_storage_account', 'pa_storage_space', 'pa_storage_route',
        'pa_file_image_asset', 'pa_file_derivative', 'pa_file_delivery_nonce', 'pa_file_delivery_grant', 'pa_file_reference', 'pa_file_immutable_snapshot'];

    public static function input(string $path, string $root, bool $historical = false): array
    {
        $bytes = SourceApplicationBackup::protectedPluginBytes($path, $root);
        $input = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($input) || ($input['protocol'] ?? null) !== 'peanut.file-adoption-relocation-input.v1'
            || ($input['migration_id'] ?? null) !== self::ID || ($input['migration_sha256'] ?? null) !== self::SQL_SHA
            || ($input['original_proof_sha256'] ?? null) !== self::PROOF_SHA
            || ($input['review_status'] ?? null) !== 'accepted' || !is_string($input['review_ref'] ?? null) || $input['review_ref'] === ''
            || !is_int($input['expires_at'] ?? null) || (!$historical && $input['expires_at'] <= time())
            || ($input['target_root'] ?? null) !== $root || realpath($root) !== $root
            || !is_string($input['source_root'] ?? null) || $input['source_root'] === $root) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_INPUT_INVALID');
        }
        $move = self::reference($input['move_receipt'] ?? [], $root, self::MOVE_SHA);
        $receipt = json_decode($move, true, 64, JSON_THROW_ON_ERROR);
        if (($receipt['phase'] ?? null) !== 'verified' || ($receipt['source'] ?? null) !== 'peanut_admin_development'
            || ($receipt['target'] ?? null) !== 'peanut_dev_mt' || ($receipt['dump']['sha256'] ?? null) !== self::DUMP_SHA
            || ($receipt['server_uuid'] ?? null) !== ($input['target_instance']['database_identity']['server_uuid'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_MOVE_INVALID');
        }
        foreach (['all_table_counts_equal', 'all_table_checksums_equal', 'identities_equal', 'all_original_migration_rows_equal', 'source_unchanged'] as $field) {
            if (($receipt['comparison'][$field] ?? null) !== true) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_MOVE_INVALID'); }
        }
        self::reference($input['move_dump'] ?? [], $root, self::DUMP_SHA);
        $input['input_reference'] = ['path' => $path, 'sha256' => hash('sha256', $bytes)];
        return $input;
    }

    private static function reference(array $reference, string $root, string $sha): string
    {
        $bytes = SourceApplicationBackup::protectedPluginBytes($reference['path'] ?? '', $root, null);
        if (($reference['sha256'] ?? null) !== $sha || !hash_equals($sha, hash('sha256', $bytes))) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_REFERENCE_CHANGED');
        }
        return $bytes;
    }

    public static function path(string $root): string
    {
        return $root . '/private/installation/file-adoption-relocation/' . self::ID . '.json';
    }

    /** Validate every unchanged component, replacing exactly the two approved location fields. */
    public static function sourceIdentity(array $row, array $input, array $actual): array
    {
        if (($row['migration_key'] ?? null) !== self::KEY || ($row['resolution_kind'] ?? null) !== 'adopted'
            || ($row['status'] ?? null) !== 'applied' || hash('sha256', (string) ($row['provenance_json'] ?? '')) !== self::PROOF_SHA) {
            throw new RuntimeException('MODULE_MIGRATION_FILE_RELOCATION_ORIGIN_CHANGED');
        }
        $proof = json_decode($row['provenance_json'], true, 64, JSON_THROW_ON_ERROR);
        $source = $proof['instance'] ?? null;
        if (!is_array($source) || ($source['database'] ?? null) !== 'peanut_admin_development'
            || ($source['database_identity']['database'] ?? null) !== 'peanut_admin_development'
            || ($proof['apply_admission']['instance_root'] ?? null) !== ($input['source_root'] ?? null)
            || ($input['target_root'] ?? null) !== realpath(root_path()) || ($actual['database'] ?? null) !== 'peanut_dev_mt'
            || ($actual['database_identity']['database'] ?? null) !== 'peanut_dev_mt' || self::digest($actual) !== self::digest($input['target_instance'] ?? null)) {
            throw new RuntimeException('MODULE_MIGRATION_FILE_RELOCATION_TARGET_CHANGED');
        }
        $transferred = $source;
        $transferred['database'] = 'peanut_dev_mt';
        $transferred['database_identity']['database'] = 'peanut_dev_mt';
        if (self::digest($transferred) !== self::digest($actual)) { throw new RuntimeException('MODULE_MIGRATION_FILE_RELOCATION_IDENTITY_CHANGED'); }
        return ['instance' => $source, 'root' => $input['source_root']];
    }

    public static function before(PDO $pdo, array $migration, array $input): array
    {
        self::stage($migration);
        if (file_exists(self::path($input['target_root'])) || is_link(self::path($input['target_root']))
            || $pdo->inTransaction()) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_ALREADY_STARTED'); }
        $query = $pdo->prepare('SELECT * FROM pa_schema_migration WHERE migration_id=?');
        $query->execute([self::ID]);
        if ($query->fetch(PDO::FETCH_ASSOC) !== false) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_ALREADY_STARTED'); }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
        try {
            $row = self::row($pdo);
            PluginLifecycleService::fileAdoptionRelocationSource($pdo, $row, $input);
            $state = self::state($pdo);
            $pdo->commit();
        } catch (\Throwable $failure) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $failure; }
        return ['contract' => self::class, 'migration_id' => self::ID, 'source_sha256' => self::SQL_SHA,
            'input' => $input, 'state' => $state, 'readonly' => true];
    }

    public static function after(PDO $pdo, array $migration, array $before, array $plan, string $planPath): array
    {
        self::stage($migration);
        if ($pdo->inTransaction() || ($before['contract'] ?? null) !== self::class || self::digest(self::state($pdo)) !== self::digest($before['state'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_PRESERVATION_FAILED');
        }
        $input = $before['input'];
        if (self::digest(self::input($input['input_reference']['path'], $input['target_root'])) !== self::digest($input)) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_INPUT_CHANGED');
        }
        $marker = self::marker($pdo);
        if (($marker['status'] ?? null) !== 'applying' || ($marker['checksum'] ?? null) !== self::SQL_SHA) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_MARKER_INVALID');
        }
        $record = ['protocol' => 'peanut.file-adoption-relocation.v1', 'migration_id' => self::ID, 'migration_sha256' => self::SQL_SHA,
            'original_proof_sha256' => self::PROOF_SHA, 'input' => $input,
            'execution' => ['migration_id' => $marker['migration_id'], 'checksum' => $marker['checksum'], 'release_version' => $marker['release_version'], 'started_at' => $marker['started_at']],
            'plan_sha256' => $plan['document_sha256'], 'native_plan' => ['path' => $planPath, 'sha256' => hash('sha256', SourceApplicationBackup::protectedPluginBytes($planPath, $input['target_root']))],
            'binding' => $plan['binding'], 'preservation' => $before['state']];
        $directory = dirname(self::path($input['target_root']));
        if ((!is_dir($directory) && !mkdir($directory, 0700)) || realpath($directory) !== $directory
            || (fileperms($directory) & 0777) !== 0700 || fileowner($directory) !== posix_geteuid()) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_DIRECTORY_UNSAFE');
        }
        $path = self::path($input['target_root']); $stream = fopen($path, 'x'); $bytes = self::canonical($record) . "\n";
        if ($stream === false || !chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_WRITE_FAILED');
        }
        fclose($stream); self::syncDirectory($directory);
        SourceApplicationBackup::protectedPluginBytes($path, $input['target_root']);
        return ['contract' => self::class, 'migration_id' => self::ID, 'source_sha256' => self::SQL_SHA,
            'receipt' => ['path' => $path, 'sha256' => hash_file('sha256', $path)], 'preservation_sha256' => self::digest($before['state'])];
    }

    /** Read authority only after the official writer has independently completed the exact marker. */
    public static function resolve(PDO $pdo, array $row, array $actual): array
    {
        $root = realpath(root_path()); $record = SourceApplicationBackup::protectedPluginJson(self::path($root), $root);
        if (($record['protocol'] ?? null) !== 'peanut.file-adoption-relocation.v1' || ($record['migration_id'] ?? null) !== self::ID
            || ($record['migration_sha256'] ?? null) !== self::SQL_SHA || ($record['original_proof_sha256'] ?? null) !== self::PROOF_SHA
            || ($record['binding']['source']['root'] ?? null) !== dirname($root)
            || ($record['binding']['database']['database'] ?? null) !== 'peanut_dev_mt'
            || !is_string($record['plan_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $record['plan_sha256']) !== 1) {
            throw new RuntimeException('MODULE_MIGRATION_FILE_RELOCATION_RECEIPT_INVALID');
        }
        $reference = $record['native_plan'] ?? [];
        $bytes = SourceApplicationBackup::protectedPluginBytes($reference['path'] ?? '', $root);
        $plan = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (hash('sha256', $bytes) !== ($reference['sha256'] ?? null)
            || ($plan['protocol'] ?? null) !== 'peanut.source-development-schema-plan.v1'
            || ($plan['document_sha256'] ?? null) !== $record['plan_sha256']
            || self::digest($plan['binding'] ?? null) !== self::digest($record['binding'])
            || self::digest($plan['file_relocation_input'] ?? null) !== self::digest($record['input'] ?? null)
            || self::digest($plan['chain'][0]['prestate']['state'] ?? null) !== self::digest($record['preservation'] ?? null)
            || ($plan['baseline']['pending'] ?? null) !== [self::ID]) { throw new RuntimeException('MODULE_MIGRATION_FILE_RELOCATION_PLAN_CHANGED'); }
        $marker = self::marker($pdo); $expected = $record['execution'] ?? [];
        if (($marker['status'] ?? null) !== 'applied' || ($marker['finished_at'] ?? null) === null || ($marker['error_code'] ?? null) !== null
            || self::digest($expected) !== self::digest(array_intersect_key($marker, array_flip(['migration_id', 'checksum', 'release_version', 'started_at'])))
            || ($expected['checksum'] ?? null) !== self::SQL_SHA) {
            throw new RuntimeException('MODULE_MIGRATION_FILE_RELOCATION_MARKER_INVALID');
        }
        // No comparison of historical preservation rows: subsequent legitimate business writes remain legal.
        return self::sourceIdentity($row, $record['input'] ?? [], $actual);
    }

    public static function marker(PDO $pdo): ?array
    {
        $query = $pdo->prepare('SELECT migration_id,release_version,checksum,status,started_at,finished_at,error_code FROM pa_schema_migration WHERE migration_id=?');
        $query->execute([self::ID]); $row = $query->fetch(PDO::FETCH_ASSOC); $query->closeCursor();
        return $row === false ? null : $row;
    }

    public static function row(PDO $pdo): array
    {
        $query = $pdo->prepare('SELECT * FROM pa_module_migration WHERE module_key=? AND migration_key=?');
        $query->execute(['official.file', self::KEY]); $rows = $query->fetchAll(PDO::FETCH_ASSOC); $query->closeCursor();
        if (count($rows) !== 1) { throw new RuntimeException('MODULE_MIGRATION_FILE_RELOCATION_ORIGIN_CHANGED'); }
        return $rows[0];
    }

    public static function state(PDO $pdo): array
    {
        $root = realpath(root_path());
        $state = ['original_row_sha256' => self::digest(self::row($pdo)), 'anchors' => [], 'tables' => []];
        foreach (['installed.json', 'baseline.json'] as $file) {
            $state['anchors'][$file] = hash('sha256', SourceApplicationBackup::protectedPluginBytes($root . '/private/installation/' . $file, $root));
        }
        foreach (self::READ_TABLES as $table) {
            $schema = MySqlMetadataObservation::tableSchema($pdo, $table);
            $primary = $schema === null ? [] : array_values(array_filter($schema['indexes'], static fn(array $index): bool => $index['INDEX_NAME'] === 'PRIMARY'));
            if ($primary === []) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_TABLE_UNKNOWN'); }
            $order = implode(',', array_map(static fn(array $index): string => '`' . $index['COLUMN_NAME'] . '`', $primary));
            $hash = hash_init('sha256'); $count = 0;
            $query = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY ' . $order);
            while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) { hash_update($hash, self::canonical($row) . "\n"); ++$count; }
            $query->closeCursor();
            $allocator = $pdo->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $allocator->execute([$table]); $next = $allocator->fetchColumn(); $allocator->closeCursor();
            $state['tables'][$table] = ['schema_sha256' => self::digest($schema), 'count' => $count, 'rows_sha256' => hash_final($hash), 'allocator' => $next];
        }
        return $state;
    }

    /** Immutable failure capsule facts; includes partial private output without treating it as authority. */
    public static function failureFacts(PDO $pdo, array $plan): array
    {
        $before = $plan['chain'][0]['prestate'] ?? [];
        if (($plan['baseline']['pending'] ?? null) !== [self::ID] || ($before['contract'] ?? null) !== self::class
            || self::digest(self::state($pdo)) !== self::digest($before['state'] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_PRESERVATION_FAILED'); }
        $input = $before['input']; $root = $input['target_root'];
        $bytes = SourceApplicationBackup::protectedPluginBytes($input['input_reference']['path'], $root);
        if (hash('sha256', $bytes) !== $input['input_reference']['sha256']) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_INPUT_CHANGED'); }
        $path = self::path($root); $receipt = null;
        if (file_exists($path) || is_link($path)) {
            $bytes = SourceApplicationBackup::protectedPluginBytes($path, $root);
            $receipt = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
        }
        return ['state' => $before['state'], 'input_reference' => $input['input_reference'], 'receipt' => $receipt];
    }

    /** Only this capsule's failed new marker/private output can be restored; no SQL table replay. */
    public static function restoreFailure(PDO $pdo, array $plan, array $failure, callable $guard): array
    {
        $expected = $failure['file_relocation'] ?? null;
        $before = $plan['chain'][0]['prestate'] ?? [];
        if (!is_array($expected) || ($before['contract'] ?? null) !== self::class
            || ($plan['baseline']['pending'] ?? null) !== [self::ID] || ($failure['executed_prefix'] ?? null) !== []
            || ($failure['failed_migration']['migration_id'] ?? null) !== self::ID
            || ($failure['failed_migration']['status'] ?? null) !== 'failed'
            || ($failure['failed_migration']['checksum'] ?? null) !== self::SQL_SHA
            || self::digest($expected['state']) !== self::digest($before['state']) || self::digest(self::state($pdo)) !== self::digest($expected['state']) || $pdo->inTransaction()) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_RESTORE_REFUSED');
        }
        $root = $before['input']['target_root']; $path = self::path($root); $receipt = $expected['receipt'];
        $inputReference = $before['input']['input_reference'];
        if (hash('sha256', SourceApplicationBackup::protectedPluginBytes($inputReference['path'], $root)) !== $inputReference['sha256']) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_INPUT_CHANGED');
        }
        $quarantine = $receipt === null ? null : $path . '.failed-' . $receipt['sha256'];
        $ledger = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
        $others = array_values(array_filter($ledger, static fn(array $row): bool => $row['migration_id'] !== self::ID));
        if (self::digest($others) !== $plan['baseline']['application_rows_sha256']
            || count($others) !== $plan['baseline']['application_rows_count']) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_LATER_USE'); }
        $marker = self::marker($pdo);
        if ($marker !== null && self::digest($marker) !== self::digest($failure['failed_migration'])) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_RESTORE_REFUSED'); }
        $active = file_exists($path) || is_link($path); $isolated = $quarantine !== null && (file_exists($quarantine) || is_link($quarantine));
        if (($receipt === null && $active) || ($receipt !== null && ($active === $isolated)) || ($marker === null && $active)) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_RESTORE_REFUSED');
        }
        if ($receipt !== null) {
            $bytes = SourceApplicationBackup::protectedPluginBytes($active ? $path : $quarantine, $root);
            if (hash('sha256', $bytes) !== $receipt['sha256'] || strlen($bytes) !== $receipt['bytes']) {
                throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_RECEIPT_CHANGED');
            }
        }
        $guard();
        if ($marker !== null) {
            // Isolate first. A crash before DB commit leaves the same failed marker, never valid authority.
            if ($active && (!rename($path, $quarantine))) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_QUARANTINE_FAILED'); }
            if ($active) { self::syncDirectory(dirname($path)); }
            $pdo->beginTransaction();
            try {
                $query = $pdo->prepare('SELECT * FROM pa_schema_migration WHERE migration_id=? FOR UPDATE');
                $query->execute([self::ID]); $held = $query->fetch(PDO::FETCH_ASSOC); $query->closeCursor();
                if (self::digest($held) !== self::digest($marker)) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_RESTORE_REFUSED'); }
                $query = $pdo->prepare("DELETE FROM pa_schema_migration WHERE migration_id=? AND checksum=? AND status='failed' AND started_at=? AND finished_at <=> ? AND error_code <=> ?");
                $query->execute([self::ID, self::SQL_SHA, $marker['started_at'], $marker['finished_at'], $marker['error_code']]);
                if ($query->rowCount() !== 1 || self::digest(self::state($pdo)) !== self::digest($expected['state'])) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_PRESERVATION_FAILED'); }
                $actual = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
                if ($actual !== $others) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_LATER_USE'); }
                $pdo->commit();
            } catch (\Throwable $error) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
        }
        if (self::marker($pdo) !== null || file_exists($path) || is_link($path) || self::digest(self::state($pdo)) !== self::digest($expected['state'])) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_RESTORE_POSTIMAGE_INVALID');
        }
        return ['profile' => 'file-adoption-relocation', 'tables' => ['pa_schema_migration'], 'marker_restored_to_absent' => true,
            'receipt_quarantine' => $quarantine, 'receipt_sha256' => $receipt['sha256'] ?? null, 'original_capsule_preserved' => true,
            'business_rows_unchanged' => true, 'migration_retry' => 'not_run'];
    }

    private static function stage(array $migration): void
    {
        if (($migration['id'] ?? null) !== self::ID || ($migration['checksum'] ?? null) !== self::SQL_SHA
            || hash('sha256', (string) ($migration['sql'] ?? '')) !== self::SQL_SHA) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_SQL_CHANGED');
        }
    }

    private static function syncDirectory(string $directory): void
    {
        $stream = fopen($directory, 'r');
        if ($stream === false || !fsync($stream)) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_WRITE_FAILED'); }
        fclose($stream);
    }

    public static function digest(mixed $value): string { return hash('sha256', self::canonical($value)); }
    private static function canonical(mixed $value): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) { return $value; }
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            return array_map($normalize, $value);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
