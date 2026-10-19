<?php

declare(strict_types=1);

namespace app\common\infrastructure\migration;

use PDO;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\MigrationInterface;
use Phinx\Db\Table;
use RuntimeException;

/** Official adapter storage extension for Peanut's existing, sole migration history. */
final class PeanutMigrationAdapter extends MysqlAdapter
{
    /** @var array<int,string> */
    private array $identities = [];
    public function __construct(private readonly PDO $master, private readonly ?string $moduleKey)
    {
        parent::__construct([], null, new \think\console\Output('buffer'));
        $this->setConnection($master);
    }

    /** Binding an admitted connection must never create or rewrite a history table. */
    public function setConnection(PDO $connection): AdapterInterface
    {
        if ($connection !== $this->master) {
            throw new RuntimeException('MIGRATION_NATIVE_MASTER_CHANGED');
        }
        $this->connection = $connection;
        return $this;
    }

    public function connect(): void
    {
        throw new RuntimeException('MIGRATION_NATIVE_RECONNECT_FORBIDDEN');
    }

    /** Immutable SQL documents already own their transaction boundaries; MySQL DDL commits implicitly. */
    public function hasTransactions(): bool
    {
        return false;
    }

    /** Official storage bootstrap is explicit and fresh-only; binding/reading never calls it. */
    public function createSchemaTable(): void
    {
        if ($this->moduleKey !== null || $this->master->inTransaction()
            || (int) $this->master->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
            throw new RuntimeException('MIGRATION_NATIVE_EMPTY_SCHEMA_REQUIRED');
        }
        (new Table('pa_schema_migration', ['id' => false, 'primary_key' => ['migration_id'], 'engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci'], $this))
            ->addColumn('migration_id', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('release_version', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('checksum', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('status', 'enum', ['values' => ['applying', 'applied', 'failed'], 'default' => 'applying', 'null' => false])
            ->addColumn('started_at', 'datetime', ['null' => false])
            ->addColumn('finished_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('error_code', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addIndex(['status', 'migration_id'], ['name' => 'idx_schema_migration_status'])
            ->addIndex(['release_version', 'migration_id'], ['name' => 'idx_schema_migration_release'])
            ->create();
    }

    public static function version(string $key): int
    {
        // The complete text identity remains authoritative in the existing ledger.
        // This stable 60-bit projection is only the official engine's integer key.
        return (int) hexdec(substr(hash('sha256', $key), 0, 15)) + 1;
    }

    public function getVersionLog(): array
    {
        $table = $this->moduleKey === null ? 'pa_schema_migration' : 'pa_module_migration';
        $keyColumn = $this->moduleKey === null ? 'migration_id' : 'migration_key';
        $statement = $this->master->prepare('SELECT * FROM ' . $table
            . ($this->moduleKey === null ? '' : ' WHERE module_key=?'));
        $statement->execute($this->moduleKey === null ? [] : [$this->moduleKey]);
        $versions = [];
        $this->identities = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row[$keyColumn];
            $version = self::version($key);
            if (isset($this->identities[$version]) && $this->identities[$version] !== $key) {
                throw new RuntimeException('MIGRATION_NATIVE_VERSION_COLLISION');
            }
            $this->identities[$version] = $key;
            if ($row['status'] !== 'applied') { continue; }
            $versions[$version] = [
                'version' => $version, 'migration_name' => $key,
                'start_time' => $row['started_at'], 'end_time' => $row['finished_at'],
                'breakpoint' => false, 'checksum' => $row['checksum'],
            ];
        }
        ksort($versions, SORT_NUMERIC);
        return $versions;
    }

    /** Check the union of source and all historical identities before any lifecycle write. @param list<string> $keys */
    public function assertSourceIdentities(array $keys): void
    {
        $this->getVersionLog();
        foreach ($keys as $key) {
            $version = self::version($key);
            if (isset($this->identities[$version]) && $this->identities[$version] !== $key) {
                throw new RuntimeException('MIGRATION_NATIVE_VERSION_COLLISION');
            }
            $this->identities[$version] = $key;
        }
    }

    public function assertApplying(ImmutableSqlMigration|CleanInstallMigration $migration): void
    {
        $sql = $this->moduleKey === null
            ? 'SELECT checksum,status FROM pa_schema_migration WHERE migration_id=?'
            : 'SELECT checksum,status FROM pa_module_migration WHERE migration_key=? AND module_key=?';
        $statement = $this->master->prepare($sql);
        $statement->execute($this->moduleKey === null ? [$migration->getName()] : [$migration->getName(), $this->moduleKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['status'] !== 'applying' || !hash_equals($migration->checksum(), (string) $row['checksum'])) {
            throw new RuntimeException('MIGRATION_NATIVE_APPLYING_REQUIRED');
        }
    }

    /** The official executor is the sole writer of an executed migration's successful state. */
    public function migrated(MigrationInterface $migration, string $direction, string $startTime, string $endTime): AdapterInterface
    {
        if ((!$migration instanceof ImmutableSqlMigration && !$migration instanceof CleanInstallMigration) || $direction !== MigrationInterface::UP
            || $this->master->inTransaction()) {
            throw new RuntimeException('MIGRATION_NATIVE_COMPLETION_INVALID');
        }
        $migration->assertCompletion();
        $values = [gmdate('Y-m-d H:i:s'), $migration->getName(), $migration->checksum()];
        if ($this->moduleKey === null) {
            $sql = "UPDATE pa_schema_migration SET status='applied',finished_at=?,error_code=NULL"
                . " WHERE migration_id=? AND checksum=? AND status='applying'";
        } else {
            $sql = "UPDATE pa_module_migration SET status='applied',resolution_kind='executed',provenance_json=NULL,finished_at=?,error_code=NULL"
                . " WHERE migration_key=? AND checksum=? AND status='applying' AND module_key=?";
            $values[] = $this->moduleKey;
        }
        $statement = $this->master->prepare($sql);
        $statement->execute($values);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('MIGRATION_NATIVE_COMPLETION_CHANGED');
        }
        return $this;
    }
}
