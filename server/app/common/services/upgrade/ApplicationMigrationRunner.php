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
     * @return array{status:string,target_version:string,applied:list<string>,pending:list<string>}
     */
    public function run(array $files, string $targetVersion, string $defaultReleaseVersion, bool $dryRun = false, ?callable $beforeMigration = null, ?callable $beforeUnlock = null, ?callable $afterMigration = null): array
    {
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
            (new PeanutMigrationAdapter($pdo, null))->assertSourceIdentities(array_map(
                static fn(string $file): string => basename($file, '.sql'), $files,
            ));
            $pending = $this->pending($pdo, $files, $targetVersion, $defaultReleaseVersion);
            if ($dryRun) {
                $result = [
                    'status' => $pending === [] ? 'up_to_date' : 'ready',
                    'target_version' => $targetVersion,
                    'applied' => [],
                    'pending' => array_column($pending, 'id'),
                ];
                $this->complete($pdo, $lockName, $result, $beforeUnlock);
                return $result;
            }

            $applied = [];
            foreach ($pending as $migration) {
                $this->assertLock($pdo, $lockName);
                if ($beforeMigration !== null) {
                    $beforeMigration($pdo, $migration);
                }
                // Admission failures occur before the durable applying marker.
                $this->assertLock($pdo, $lockName);
                $now = gmdate('Y-m-d H:i:s');
                $pdo->prepare(
                    'INSERT INTO pa_schema_migration (migration_id,release_version,checksum,status,started_at,finished_at,error_code) VALUES (?,?,?,?,?,NULL,NULL) '
                    . "ON DUPLICATE KEY UPDATE release_version=VALUES(release_version),checksum=VALUES(checksum),status='applying',started_at=VALUES(started_at),finished_at=NULL,error_code=NULL",
                )->execute([$migration['id'], $migration['release_version'], $migration['checksum'], 'applying', $now]);
                try {
                    PeanutMigrationRun::apply($pdo, null, $migration['id'], $migration['sql'], $migration['checksum'],
                        function () use ($pdo, $lockName, $afterMigration, $migration): void {
                            $this->assertLock($pdo, $lockName);
                            if ($afterMigration !== null) { $afterMigration($pdo, $migration); }
                            $this->assertLock($pdo, $lockName);
                        });
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
            ];
            $this->complete($pdo, $lockName, $result, $beforeUnlock);
            return $result;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
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
            if (preg_match('/^[0-9]{8}-[a-z0-9][a-z0-9_-]*$/D', $id) !== 1) {
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
    private function pending(PDO $pdo, array $files, string $targetVersion, string $defaultReleaseVersion): array
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
            $statement = $pdo->prepare('SELECT checksum,status FROM pa_schema_migration WHERE migration_id = ?');
            $statement->execute([$id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if (!hash_equals((string) $row['checksum'], $checksum)) {
                    throw new RuntimeException('MIGRATION_CHECKSUM_CHANGED: ' . $id);
                }
                if ($row['status'] === 'applied') {
                    continue;
                }
                if ($row['status'] === 'failed') {
                    throw new RuntimeException('MIGRATION_PREVIOUSLY_FAILED: ' . $id);
                }
                if ($row['status'] === 'applying') {
                    throw new RuntimeException('MIGRATION_INCOMPLETE: ' . $id);
                }
                throw new RuntimeException('MIGRATION_STATUS_UNKNOWN: ' . $id);
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
