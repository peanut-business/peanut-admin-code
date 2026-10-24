<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use PDO;
use RuntimeException;
use Throwable;
use app\common\infrastructure\migration\PeanutMigrationAdapter;
use app\common\infrastructure\migration\PeanutMigrationRun;

/**
 * Applies the application's immutable SQL chain through its durable ledger.
 *
 * Fresh installation and the standalone product upgrader share this runner.
 * It deliberately knows nothing about installation identities, tenant creation,
 * file switching or deployment orchestration.
 */
final class ApplicationMigrationRunner
{
    public const PROVENANCE_ORIGINAL = '20261016-module-migration-provenance';
    public const PROVENANCE_ORIGINAL_SHA = 'd27a6f52dd3e02b9c0c45a804c059f54062a732c9e1a742dfe12e78b80d96cce';
    public const PROVENANCE_CORRECTION = '20261019-module-migration-provenance-metadata';
    public const PROVENANCE_CORRECTION_SHA = '5e8abee207d363cdbb48f01c3980a611b01f4457150666a67e67f690dd547ad9';
    private readonly ?object $nativeConnection;
    private ?string $connectionId = null;

    /** @param array{host:string,port:string,database:string,user:string,password:string} $database */
    public function __construct(private readonly array $database, private readonly ?PDO $masterPdo = null)
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $database['database'] ?? '') !== 1) {
            throw new RuntimeException('DB_NAME 只能包含字母、数字和下划线');
        }
        $this->nativeConnection = $masterPdo === null ? null : \think\facade\Db::connect();
        if ($masterPdo !== null) {
            $this->assertConnection($masterPdo);
        }
    }

    /**
     * @param list<string> $files
     * @param null|callable(PDO,array):mixed $beforeMigration Returning true requests same-PDO whole-batch SQL clock bounds.
     * @param null|callable(PDO,array,?array):void $afterMigration Receives those bounds before the sole applied write.
     * @return array{status:string,target_version:string,applied:list<string>,pending:list<string>}
     */
    public function run(array $files, string $targetVersion, string $defaultReleaseVersion, bool $dryRun = false, ?callable $beforeMigration = null, ?callable $beforeUnlock = null, ?callable $afterMigration = null, ?callable $admitRolledBackFailure = null): array
    {
        $files = self::effectiveFiles($files);
        $pdo = $this->connection();
        $exists = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pa_schema_migration'",
        )->fetchColumn();
        if ($exists !== 1) {
            throw new RuntimeException('MIGRATION_LEDGER_MISSING: 保留现有数据库与安装身份，核对受支持的基线及恢复方案');
        }

        $lockName = 'peanut_migrate_' . substr(hash('sha256', $this->database['database']), 0, 48);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('无法获取迁移锁，请稍后重试');
        }

        try {
            $this->assertLock($pdo, $lockName);
            $baselineSelection = \app\common\infrastructure\migration\CleanInstallBaseline::selection($pdo, $files, $targetVersion);
            $files = $baselineSelection['files'];
            $supersession = self::provenanceSupersession($pdo, $files, $targetVersion);
            $identities = array_map(static fn(string $file): string => basename($file, '.sql'), $files);
            if ($baselineSelection['baseline'] !== null) { $identities[] = $baselineSelection['baseline']['key']; }
            if ($supersession !== null) { $identities[] = self::PROVENANCE_ORIGINAL; }
            (new PeanutMigrationAdapter($pdo, null))->assertSourceIdentities($identities);
            $pending = $this->pending($pdo, $files, $targetVersion, $defaultReleaseVersion, $admitRolledBackFailure);
            $selected = $baselineSelection['baseline'] === null ? [] : [$baselineSelection['baseline']['key']];
            foreach ($files as $file) {
                $identity = self::releaseIdentity((string) file_get_contents($file), $defaultReleaseVersion);
                if (!$identity['peanut_release'] || version_compare($identity['release_version'], $targetVersion, '<=')) { $selected[] = basename($file, '.sql'); }
            }
            if ($dryRun) {
                $result = [
                    'status' => $pending === [] ? 'up_to_date' : 'ready',
                    'target_version' => $targetVersion,
                    'applied' => [],
                    'pending' => array_column($pending, 'id'),
                    'selected' => $selected,
                    'baseline' => $baselineSelection['baseline'],
                    'supersessions' => $supersession === null ? [] : [$supersession],
                ];
                $this->complete($pdo, $lockName, $result, $beforeUnlock);
                return $result;
            }

            $applied = [];
            foreach ($pending as $migration) {
                $this->assertLock($pdo, $lockName);
                $captureSqlClock = $beforeMigration !== null && $beforeMigration($pdo, $migration) === true;
                // Admission failures occur before the durable applying marker.
                $this->assertLock($pdo, $lockName);
                $now = gmdate('Y-m-d H:i:s');
                $pdo->prepare(
                    'INSERT INTO pa_schema_migration (migration_id,release_version,checksum,status,started_at,finished_at,error_code) VALUES (?,?,?,?,?,NULL,NULL) '
                    . "ON DUPLICATE KEY UPDATE release_version=VALUES(release_version),checksum=VALUES(checksum),status='applying',started_at=VALUES(started_at),finished_at=NULL,error_code=NULL",
                )->execute([$migration['id'], $migration['release_version'], $migration['checksum'], 'applying', $now]);
                try {
                    PeanutMigrationRun::apply($pdo, null, $migration['id'], $migration['sql'], $migration['checksum'],
                        function (?array $executionBounds) use ($pdo, $lockName, $afterMigration, $migration): void {
                            $this->assertLock($pdo, $lockName);
                            if ($afterMigration !== null) { $afterMigration($pdo, $migration, $executionBounds); }
                            $this->assertLock($pdo, $lockName);
                        }, $captureSqlClock);
                    $applied[] = $migration['id'];
                } catch (Throwable $exception) {
                    try {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $pdo->prepare(
                            "UPDATE pa_schema_migration SET status='failed',finished_at=?,error_code=? WHERE migration_id=?",
                        )->execute([gmdate('Y-m-d H:i:s'), substr($exception->getMessage(), 0, 255), $migration['id']]);
                    } catch (Throwable $recordingException) {
                        throw new RuntimeException(
                            'MIGRATION_FAILED: ' . $migration['id'] . '; FAILURE_RECORDING_FAILED: ' . $recordingException->getMessage(),
                            0,
                            $exception,
                        );
                    }
                    throw new RuntimeException('MIGRATION_FAILED: ' . $migration['id'], 0, $exception);
                }
            }

            $result = [
                'status' => $applied === [] ? 'up_to_date' : 'applied',
                'target_version' => $targetVersion,
                'applied' => $applied,
                'pending' => [],
                'selected' => $selected,
                'baseline' => $baselineSelection['baseline'],
            ];
            $resolved = self::provenanceSupersession($pdo, $files, $targetVersion);
            if (($resolved['original_ledger'] ?? null) !== ($supersession['original_ledger'] ?? null)) {
                throw new RuntimeException('MIGRATION_ORIGINAL_HISTORY_CHANGED');
            }
            $result['supersessions'] = $resolved === null ? [] : [$resolved];
            $this->complete($pdo, $lockName, $result, $beforeUnlock);
            return $result;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    /** One fixed source correction, not a caller-controlled repair map. Original SQL stays in the package. */
    public static function effectiveFiles(array $files): array
    {
        $correction = null;
        foreach ($files as $file) {
            if (basename($file, '.sql') === self::PROVENANCE_CORRECTION) { $correction = $file; }
        }
        if ($correction === null) { return $files; }
        $original = dirname($correction) . '/' . self::PROVENANCE_ORIGINAL . '.sql';
        foreach ([$original => self::PROVENANCE_ORIGINAL_SHA, $correction => self::PROVENANCE_CORRECTION_SHA] as $file => $sha) {
            if (!is_file($file) || is_link($file) || hash_file('sha256', $file) !== $sha) { throw new RuntimeException('MIGRATION_CORRECTION_SOURCE_CHANGED'); }
        }
        return array_values(array_filter($files, static fn(string $file): bool => basename($file, '.sql') !== self::PROVENANCE_ORIGINAL));
    }

    /** Explicit history relation; an old failed row is never returned as applied or executed. */
    public static function provenanceSupersession(PDO $pdo, array $files, string $targetVersion): ?array
    {
        $files = self::effectiveFiles($files);
        if (version_compare($targetVersion, '5.0.2', '<') || !in_array(self::PROVENANCE_CORRECTION, array_map(static fn(string $file): string => basename($file, '.sql'), $files), true)) { return null; }
        $query = $pdo->prepare('SELECT * FROM pa_schema_migration WHERE migration_id IN (?,?) ORDER BY migration_id');
        try { $query->execute([self::PROVENANCE_ORIGINAL, self::PROVENANCE_CORRECTION]); $rows = array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'migration_id'); }
        finally { $query->closeCursor(); }
        $old = $rows[self::PROVENANCE_ORIGINAL] ?? null; $new = $rows[self::PROVENANCE_CORRECTION] ?? null;
        if ($old !== null && ($old['checksum'] !== self::PROVENANCE_ORIGINAL_SHA || $old['release_version'] !== '5.0.2' || !in_array($old['status'], ['applied', 'failed'], true)
            || ($old['status'] === 'applied' && $old['error_code'] !== null)
            || $old['finished_at'] === null || ($old['status'] === 'failed' && $old['error_code'] !== "SQLSTATE[HY000]: General error: 3822 Duplicate check constraint name 'chk_module_migration_resolution'."))) {
            throw new RuntimeException('MIGRATION_CORRECTION_ORIGINAL_CONFLICT');
        }
        if ($new !== null && ($new['checksum'] !== self::PROVENANCE_CORRECTION_SHA || $new['release_version'] !== '5.0.2' || $new['status'] !== 'applied'
            || $new['finished_at'] === null || $new['error_code'] !== null)) { throw new RuntimeException('MIGRATION_CORRECTION_INCOMPLETE'); }
        $shape = self::provenanceShape($pdo);
        if (($old !== null || $new !== null) && $shape !== 'exact_noop') { throw new RuntimeException('MIGRATION_CORRECTION_PRESTATE_CONFLICT'); }
        return ['original_key' => self::PROVENANCE_ORIGINAL, 'original_sha256' => self::PROVENANCE_ORIGINAL_SHA,
            'original_ledger' => $old, 'original_status' => $old['status'] ?? 'absent', 'original_execution_claim' => 'not_inferred',
            'correction_key' => self::PROVENANCE_CORRECTION, 'correction_sha256' => self::PROVENANCE_CORRECTION_SHA,
            'correction_ledger' => $new, 'state' => $new === null ? 'awaiting_correction' : 'resolved_by_correction',
            'original_row_preserved' => true, 'schema' => $shape];
    }

    /** Accept only the two complete observed renderings, or the exact pre-addition state. */
    public static function provenanceShape(PDO $pdo): string
    {
        $columns = $pdo->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_module_migration' AND COLUMN_NAME IN ('resolution_kind','provenance_json')")->fetchAll(PDO::FETCH_ASSOC);
        $checks = $pdo->query("SELECT t.CONSTRAINT_NAME,t.ENFORCED,c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME='pa_module_migration' AND t.CONSTRAINT_NAME IN ('chk_module_migration_resolution','chk_module_migration_provenance')")->fetchAll(PDO::FETCH_ASSOC);
        if ($columns === [] && $checks === []) { return 'before_additions'; }
        if (count($columns) !== 2 || count($checks) !== 2) { throw new RuntimeException('MIGRATION_CORRECTION_SCHEMA_DIFFERENT'); }
        foreach ($columns as $row) {
            if ($row['COLUMN_TYPE'] !== ($row['COLUMN_NAME'] === 'resolution_kind' ? 'varchar(16)' : 'json') || $row['IS_NULLABLE'] !== 'YES' || $row['COLUMN_DEFAULT'] !== null) { throw new RuntimeException('MIGRATION_CORRECTION_SCHEMA_DIFFERENT'); }
        }
        $required = ['chk_module_migration_resolution' => "((resolution_kindisnull)or(resolution_kindin('executed','adopted')))",
            'chk_module_migration_provenance' => "((resolution_kind<=>'adopted')=(provenance_jsonisnotnull))"];
        foreach ($checks as $row) {
            $normal = strtolower(str_replace(['`', ' ', "\n", '_utf8mb4'], '', $row['CHECK_CLAUSE']));
            $expected = $required[$row['CONSTRAINT_NAME']] ?? '';
            if ($row['ENFORCED'] !== 'YES' || !in_array($normal, [$expected, str_replace("'", "\\'", $expected)], true)) { throw new RuntimeException('MIGRATION_CORRECTION_SCHEMA_DIFFERENT'); }
        }
        return 'exact_noop';
    }

    private function complete(PDO $pdo, string $lockName, array $result, ?callable $beforeUnlock): void
    {
        $this->assertLock($pdo, $lockName);
        if ($beforeUnlock !== null) {
            $beforeUnlock($pdo, $result);
        }
        $this->assertLock($pdo, $lockName);
    }

    private function assertConnection(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            throw new RuntimeException('MIGRATION_OUTER_TRANSACTION_FORBIDDEN');
        }
        if ($this->masterPdo !== null && ($pdo !== $this->masterPdo
            || \think\facade\Db::connect() !== $this->nativeConnection
            || $this->nativeConnection->getPdo() !== $pdo)) {
            throw new RuntimeException('MIGRATION_NATIVE_MASTER_CHANGED');
        }
        $identity = $pdo->query('SELECT CONNECTION_ID() AS connection_id, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($identity) || ($identity['database_name'] ?? null) !== $this->database['database']
            || ($this->connectionId !== null && $this->connectionId !== (string) $identity['connection_id'])) {
            throw new RuntimeException('MIGRATION_CONNECTION_IDENTITY_CHANGED');
        }
        $this->connectionId = (string) $identity['connection_id'];
    }

    private function assertLock(PDO $pdo, string $lockName): void
    {
        $this->assertConnection($pdo);
        $statement = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        $statement->execute([$lockName]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('MIGRATION_SCHEMA_LOCK_LOST');
        }
    }

    /**
     * @param list<string> $migrationIds
     * @return array<string,string>
     */
    public function statuses(array $migrationIds): array
    {
        if ($migrationIds === []) {
            return [];
        }
        foreach ($migrationIds as $id) {
            if (preg_match('/^[0-9]{8}-[a-z0-9][a-z0-9_-]*$/D', $id) !== 1
                && !in_array($id, ['20261019-clean-install-5.0.2-r1', '20261019-clean-install-5.0.2-r2'], true)) {
                throw new RuntimeException('MIGRATION_ID_INVALID');
            }
        }
        $pdo = $this->connection();
        $placeholders = implode(',', array_fill(0, count($migrationIds), '?'));
        $statement = $pdo->prepare(
            "SELECT migration_id,status FROM pa_schema_migration WHERE migration_id IN ({$placeholders})",
        );
        $statement->execute($migrationIds);
        $statuses = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statuses[(string) $row['migration_id']] = (string) $row['status'];
        }
        ksort($statuses, SORT_STRING);
        return $statuses;
    }

    /** @return array{release_version:string,peanut_release:bool} */
    public static function releaseIdentity(string $sql, string $applicationVersion): array
    {
        preg_match_all('/^\s*--\s*peanut-release\b[^\r\n]*$/mi', $sql, $markerLines);
        if (count($markerLines[0]) === 0) {
            return ['release_version' => $applicationVersion, 'peanut_release' => false];
        }
        if (count($markerLines[0]) !== 1
            || preg_match(
                '/^\s*--\s*peanut-release:\s*((0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*))\s*$/iD',
                $markerLines[0][0],
                $matches,
            ) !== 1
        ) {
            throw new RuntimeException('MIGRATION_RELEASE_MARKER_INVALID');
        }
        return ['release_version' => $matches[1], 'peanut_release' => true];
    }

    /**
     * @param list<string> $files
     * @return list<array{id:string,file:string,sql:string,checksum:string,release_version:string,status:?string}>
     */
    private function pending(PDO $pdo, array $files, string $targetVersion, string $defaultReleaseVersion, ?callable $admitRolledBackFailure = null): array
    {
        $version = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
        if (preg_match($version, $targetVersion) !== 1
            || preg_match($version, $defaultReleaseVersion) !== 1) {
            throw new RuntimeException('MIGRATION_TARGET_CONTRACT_INVALID');
        }
        $pending = [];
        $previous = '';
        foreach ($files as $file) {
            if (!is_string($file) || !is_file($file) || is_link($file)
                || preg_match('/^[0-9]{8}-[a-z0-9][a-z0-9_-]*\.sql$/D', basename($file)) !== 1) {
                throw new RuntimeException('MIGRATION_FILE_INVALID');
            }
            $id = basename($file, '.sql');
            if ($previous !== '' && strcmp($previous, $id) >= 0) {
                throw new RuntimeException('MIGRATION_CHAIN_ORDER_INVALID');
            }
            $previous = $id;
            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('迁移文件为空：' . $id);
            }
            $checksum = hash('sha256', $sql);
            $identity = self::releaseIdentity($sql, $defaultReleaseVersion);
            if ($identity['peanut_release'] && version_compare($identity['release_version'], $targetVersion, '>')) {
                continue;
            }
            $statement = $pdo->prepare('SELECT * FROM pa_schema_migration WHERE migration_id = ?');
            $statement->execute([$id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if (!hash_equals((string) $row['checksum'], $checksum)) {
                    throw new RuntimeException('MIGRATION_CHECKSUM_CHANGED: ' . $id);
                }
                if ($row['status'] === 'applied') {
                    continue;
                }
                if ($row['status'] === 'failed' && ($admitRolledBackFailure === null || $admitRolledBackFailure($pdo, $id, $row) !== true)) {
                    throw new RuntimeException('MIGRATION_PREVIOUSLY_FAILED: ' . $id);
                }
                if ($row['status'] === 'applying') {
                    throw new RuntimeException('MIGRATION_INCOMPLETE: ' . $id);
                }
                if ($row['status'] !== 'failed') { throw new RuntimeException('MIGRATION_STATUS_UNKNOWN: ' . $id); }
            }
            $pending[] = [
                'id' => $id,
                'file' => $file,
                'sql' => $sql,
                'checksum' => $checksum,
                'release_version' => $identity['release_version'],
                'status' => is_array($row) ? (string) $row['status'] : null,
            ];
        }
        return $pending;
    }

    private function connection(): PDO
    {
        if ($this->masterPdo !== null) {
            $this->assertConnection($this->masterPdo);
            return $this->masterPdo;
        }
        $this->connectionId = null;
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $this->database['host'],
                $this->database['port'],
                $this->database['database'],
            ),
            $this->database['user'],
            $this->database['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ],
        );
    }
}
