<?php

declare(strict_types=1);

namespace app\common\infrastructure\migration;

use Closure;
use PDO;
use RuntimeException;
use think\migration\Migrator;

/** A real official Migration carrying the unchanged SQL and its lifecycle completion checks. */
final class ImmutableSqlMigration extends Migrator
{
    private string $key;
    private string $sql;
    private string $sha256;
    private Closure $completion;
    private bool $captureSqlClock = false;
    private ?array $executionBounds = null;

    public function bindSource(string $key, string $sql, string $sha256, callable $completion, bool $captureSqlClock = false): void
    {
        if ($key === '' || trim($sql) === '' || !hash_equals($sha256, hash('sha256', $sql))) {
            throw new RuntimeException('MIGRATION_NATIVE_SOURCE_INVALID');
        }
        $this->key = $key;
        $this->sql = $sql;
        $this->sha256 = $sha256;
        $this->completion = Closure::fromCallable($completion);
        $this->captureSqlClock = $captureSqlClock;
    }

    public function getName(): string { return $this->key; }
    public function checksum(): string { return $this->sha256; }
    public function assertCompletion(): void { ($this->completion)($this->executionBounds); }

    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof PeanutMigrationAdapter) {
            throw new RuntimeException('MIGRATION_NATIVE_ADAPTER_REQUIRED');
        }
        $adapter->assertApplying($this);
        $pdo = $adapter->getConnection();
        $emulated = (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $before = $this->captureSqlClock ? $this->databaseClock($pdo) : null;
            $statement = $this->query($this->sql);
            try {
                do {
                    if ($statement->columnCount() > 0) { $statement->fetchAll(PDO::FETCH_ASSOC); }
                } while ($statement->nextRowset());
            } finally {
                $statement->closeCursor();
            }
            if ($before !== null) {
                $after = $this->databaseClock($pdo);
                if ($before['database'] !== $after['database'] || $before['connection_id'] !== $after['connection_id']) {
                    throw new RuntimeException('MIGRATION_NATIVE_CLOCK_CONNECTION_CHANGED');
                }
                // These two observations bracket the unchanged whole SQL batch,
                // including result draining, rather than an individual statement.
                $this->executionBounds = ['scope' => 'immutable-sql-batch', 'migration_key' => $this->key,
                    'sha256' => $this->sha256, 'pdo_object_id' => spl_object_id($pdo), 'before' => $before, 'after' => $after];
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('MIGRATION_TRANSACTION_INCOMPLETE: ' . $this->key);
        }
    }

    private function databaseClock(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT DATABASE() AS database_name, CONNECTION_ID() AS connection_id, UNIX_TIMESTAMP() AS unix_second, UTC_TIMESTAMP(3) AS `utc_timestamp`');
        try { $row = $statement->fetch(PDO::FETCH_ASSOC); }
        finally { $statement->closeCursor(); }
        if (!is_array($row) || !is_string($row['database_name'] ?? null) || $row['database_name'] === '') {
            throw new RuntimeException('MIGRATION_NATIVE_CLOCK_INVALID');
        }
        $point = ['database' => $row['database_name']];
        foreach (['connection_id', 'unix_second'] as $key) {
            $value = $row[$key] ?? null;
            if ((!is_int($value) && !is_string($value)) || preg_match('/^[0-9]+$/D', (string) $value) !== 1) {
                throw new RuntimeException('MIGRATION_NATIVE_CLOCK_INVALID');
            }
            $point[$key] = (string) $value;
        }
        if (!is_string($row['utc_timestamp'] ?? null) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\\.[0-9]{3}$/D', $row['utc_timestamp']) !== 1) {
            throw new RuntimeException('MIGRATION_NATIVE_CLOCK_INVALID');
        }
        $point['utc_timestamp'] = $row['utc_timestamp'];
        return $point;
    }

    public function down(): void
    {
        throw new RuntimeException('MIGRATION_NATIVE_DOWN_FORBIDDEN');
    }
}
