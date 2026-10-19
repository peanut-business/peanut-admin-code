<?php

declare(strict_types=1);

namespace app\common\infrastructure\migration;

use PDO;
use RuntimeException;
use Throwable;

/** Fixed source artifact and fresh-only lifecycle admission, using the sole native history. */
final class CleanInstallBaseline
{
    public const DIRECTORY = '5.0.2-r1';
    public const KEY = '20261019-clean-install-5.0.2-r1';

    private function __construct(public readonly array $manifest, public readonly string $checksum,
        public readonly string $coreSchema, public readonly string $applicationSchema, public readonly string $initialData, public readonly string $versionedData) {}

    public static function load(string $databaseDirectory): self
    {
        $directory = rtrim($databaseDirectory, '/') . '/baselines/' . self::DIRECTORY;
        $bytes = [];
        foreach (['baseline.lock', 'manifest.json', 'schema.sql', 'data.sql'] as $name) {
            $path = $directory . '/' . $name;
            if (!is_file($path) || is_link($path) || is_link($directory)) {
                throw new RuntimeException('INSTALL_BASELINE_SOURCE_MISSING');
            }
            $bytes[$name] = file_get_contents($path);
            if (!is_string($bytes[$name]) || $bytes[$name] === '') { throw new RuntimeException('INSTALL_BASELINE_SOURCE_EMPTY'); }
        }
        $lock = json_decode($bytes['baseline.lock'], true, 64, JSON_THROW_ON_ERROR);
        $manifest = json_decode($bytes['manifest.json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($lock) || !is_array($manifest) || self::canonical($lock) !== $bytes['baseline.lock']
            || self::canonical($manifest) !== $bytes['manifest.json']) { throw new RuntimeException('INSTALL_BASELINE_CANONICAL_JSON_REQUIRED'); }
        if (($lock['protocol'] ?? null) !== 'peanut.clean-install-baseline-lock.v1' || ($lock['schema_version'] ?? null) !== 1
            || ($manifest['protocol'] ?? null) !== 'peanut.clean-install-baseline.v1' || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['key'] ?? null) !== self::KEY || ($lock['key'] ?? null) !== self::KEY
            || ($manifest['source_product_version'] ?? null) !== '5.0.2' || ($manifest['revision'] ?? null) !== 1
            || ($manifest['fresh_only'] ?? null) !== true || ($manifest['ledger'] ?? null) !== 'pa_schema_migration'
            || !is_array($manifest['covered_migrations'] ?? null) || !is_array($manifest['tables'] ?? null)
            || ($manifest['data_boundary'] ?? null) !== '-- peanut-baseline-catalog-boundary'
            || ($manifest['schema_boundary'] ?? null) !== '-- peanut-baseline-application-schema-boundary') {
            throw new RuntimeException('INSTALL_BASELINE_CONTRACT_INVALID');
        }
        $locked = array_keys($lock['files'] ?? []); sort($locked, SORT_STRING);
        if ($locked !== ['data.sql', 'manifest.json', 'schema.sql']) { throw new RuntimeException('INSTALL_BASELINE_LOCK_INVALID'); }
        foreach ($locked as $name) {
            if (!hash_equals((string) $lock['files'][$name], hash('sha256', $bytes[$name]))) {
                throw new RuntimeException('INSTALL_BASELINE_CHECKSUM_CHANGED');
            }
            if ($name !== 'manifest.json' && ($manifest['payloads'][$name] ?? null) !== $lock['files'][$name]) {
                throw new RuntimeException('INSTALL_BASELINE_PAYLOAD_CONFLICT');
            }
        }
        $previous = '';
        foreach ($manifest['covered_migrations'] as $covered) {
            $key = $covered['key'] ?? '';
            if (!is_string($key) || preg_match('/^[0-9]{8}-[a-z0-9][a-z0-9_-]*$/D', $key) !== 1
                || strcmp($previous, $key) >= 0 || ($covered['path'] ?? null) !== 'server/database/migrations/' . $key . '.sql') {
                throw new RuntimeException('INSTALL_BASELINE_COVERAGE_INVALID');
            }
            $file = $databaseDirectory . '/migrations/' . $key . '.sql';
            if (!is_file($file) || is_link($file) || hash_file('sha256', $file) !== ($covered['sha256'] ?? null)) {
                throw new RuntimeException('INSTALL_BASELINE_COVERED_SOURCE_CHANGED');
            }
            $previous = $key;
        }
        $data = explode($manifest['data_boundary'], $bytes['data.sql']);
        $schema = explode($manifest['schema_boundary'], $bytes['schema.sql']);
        if (count($schema) !== 2 || trim($schema[0]) === '' || trim($schema[1]) === '') { throw new RuntimeException('INSTALL_BASELINE_SCHEMA_BOUNDARY_INVALID'); }
        if (count($data) !== 2 || trim($data[0]) === '' || trim($data[1]) === '') { throw new RuntimeException('INSTALL_BASELINE_DATA_BOUNDARY_INVALID'); }
        return new self($manifest, hash('sha256', $bytes['manifest.json']), $schema[0], $schema[1], $data[0], $data[1]);
    }

    private static function canonical(array $value): string
    {
        $sort = static function (array $data) use (&$sort): array {
            if (!array_is_list($data)) { ksort($data, SORT_STRING); }
            foreach ($data as &$item) { if (is_array($item)) { $item = $sort($item); } }
            unset($item);
            return $data;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public function identity(): array
    {
        return ['key' => self::KEY, 'sha256' => $this->checksum, 'source_product_version' => $this->manifest['source_product_version'],
            'revision' => 1, 'coverage_semantics' => 'covered_by_baseline', 'covered_migrations' => $this->manifest['covered_migrations']];
    }

    /** Called only after existing installation state and empty-schema admission, with its installation lock held. */
    public function install(PDO $pdo, callable $initializeIdentity, callable $initializeCatalog, callable $completion): array
    {
        if ($pdo->inTransaction()) { throw new RuntimeException('INSTALL_BASELINE_OUTER_TRANSACTION'); }
        $adapter = new PeanutMigrationAdapter($pdo, null);
        $adapter->createSchemaTable();
        $pdo->prepare('INSERT INTO pa_schema_migration (migration_id,release_version,checksum,status,started_at) VALUES (?,?,?,?,?)')
            ->execute([self::KEY, $this->manifest['source_product_version'], $this->checksum, 'applying', gmdate('Y-m-d H:i:s')]);
        try {
            PeanutMigrationRun::applyBaseline($pdo, $this, $initializeIdentity, $initializeCatalog, $completion);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $pdo->prepare("UPDATE pa_schema_migration SET status='failed',finished_at=?,error_code=? WHERE migration_id=? AND checksum=? AND status='applying'")
                ->execute([gmdate('Y-m-d H:i:s'), substr($exception->getMessage(), 0, 255), self::KEY, $this->checksum]);
            throw $exception;
        }
        return $this->identity() + ['status' => 'applied'];
    }

    /** Coverage is usable only when the actual baseline succeeded; it never fabricates historical applied rows. */
    public static function selection(PDO $pdo, array $files, string $targetVersion, ?string $databaseDirectory = null): array
    {
        $query = $pdo->prepare('SELECT * FROM pa_schema_migration WHERE migration_id=?');
        $query->execute([self::KEY]);
        $row = $query->fetch(PDO::FETCH_ASSOC); $query->closeCursor();
        if ($row === false) { return ['files' => $files, 'baseline' => null]; }
        $databaseDirectory ??= $files === [] ? null : dirname(dirname($files[0]));
        if ($databaseDirectory === null) { throw new RuntimeException('INSTALL_BASELINE_SOURCE_UNRESOLVED'); }
        $baseline = self::load($databaseDirectory);
        if ($row['status'] !== 'applied' || $row['checksum'] !== $baseline->checksum || $row['release_version'] !== $baseline->manifest['source_product_version']
            || $row['finished_at'] === null || $row['error_code'] !== null || version_compare($targetVersion, $row['release_version'], '<')) {
            throw new RuntimeException('INSTALL_BASELINE_HISTORY_CONFLICT');
        }
        $covered = array_column($baseline->manifest['covered_migrations'], 'sha256', 'key');
        $query = $pdo->prepare('SELECT COUNT(*) FROM pa_schema_migration WHERE migration_id IN (' . implode(',', array_fill(0, count($covered), '?')) . ')');
        $query->execute(array_keys($covered));
        $mixed = (int) $query->fetchColumn(); $query->closeCursor();
        if ($mixed !== 0) { throw new RuntimeException('INSTALL_BASELINE_MIXED_LEGACY_HISTORY'); }
        $remaining = [];
        foreach ($files as $file) {
            $key = basename($file, '.sql');
            if (isset($covered[$key])) {
                if (!is_file($file) || is_link($file) || hash_file('sha256', $file) !== $covered[$key]) { throw new RuntimeException('INSTALL_BASELINE_COVERED_SOURCE_CHANGED'); }
            } else { $remaining[] = $file; }
        }
        return ['files' => $remaining, 'baseline' => $baseline->identity() + ['status' => 'applied']];
    }
}
