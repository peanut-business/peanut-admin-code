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

    public function bindSource(string $key, string $sql, string $sha256, callable $completion): void
    {
        if ($key === '' || trim($sql) === '' || !hash_equals($sha256, hash('sha256', $sql))) {
            throw new RuntimeException('MIGRATION_NATIVE_SOURCE_INVALID');
        }
        $this->key = $key;
        $this->sql = $sql;
        $this->sha256 = $sha256;
        $this->completion = Closure::fromCallable($completion);
    }

    public function getName(): string { return $this->key; }
    public function checksum(): string { return $this->sha256; }
    public function assertCompletion(): void { ($this->completion)(); }

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
            $statement = $this->query($this->sql);
            try {
                do {
                    if ($statement->columnCount() > 0) { $statement->fetchAll(PDO::FETCH_ASSOC); }
                } while ($statement->nextRowset());
            } finally {
                $statement->closeCursor();
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('MIGRATION_TRANSACTION_INCOMPLETE: ' . $this->key);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('MIGRATION_NATIVE_DOWN_FORBIDDEN');
    }
}
