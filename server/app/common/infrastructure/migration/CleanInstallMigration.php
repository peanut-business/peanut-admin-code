<?php

declare(strict_types=1);

namespace app\common\infrastructure\migration;

use Closure;
use PDO;
use RuntimeException;
use think\migration\Migrator;

/** Official Migration for one admitted fixed baseline and its protected runtime business inputs. */
final class CleanInstallMigration extends Migrator
{
    private CleanInstallBaseline $baseline;
    private Closure $initializeIdentity;
    private Closure $initializeCatalog;
    private Closure $completion;

    public function bindSource(CleanInstallBaseline $baseline, callable $initializeIdentity, callable $initializeCatalog, callable $completion): void
    {
        $this->baseline = $baseline;
        $this->initializeIdentity = Closure::fromCallable($initializeIdentity);
        $this->initializeCatalog = Closure::fromCallable($initializeCatalog);
        $this->completion = Closure::fromCallable($completion);
    }

    public function getName(): string { return $this->baseline->manifest['key']; }
    public function checksum(): string { return $this->baseline->checksum; }
    public function assertCompletion(): void { ($this->completion)(); }

    public function up(): void
    {
        $adapter = $this->getAdapter();
        if (!$adapter instanceof PeanutMigrationAdapter) { throw new RuntimeException('MIGRATION_NATIVE_ADAPTER_REQUIRED'); }
        $adapter->assertApplying($this);
        $pdo = $adapter->getConnection();
        $emulated = (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $foreignKeys = (int) $pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $this->sql($this->baseline->coreSchema);
            ($this->initializeIdentity)();
            $this->sql($this->baseline->applicationSchema);
            $this->sql($this->baseline->initialData);
            ($this->initializeCatalog)();
            $this->sql($this->baseline->versionedData);
            if ($pdo->inTransaction()) { throw new RuntimeException('MIGRATION_TRANSACTION_INCOMPLETE'); }
        } finally {
            $pdo->exec('SET @pa_default_tenant_id = NULL, @pa_default_tenant_code = NULL');
            $pdo->exec('SET FOREIGN_KEY_CHECKS = ' . $foreignKeys);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
        }
    }

    private function sql(string $sql): void
    {
        $statement = $this->query($sql);
        try {
            do { if ($statement->columnCount() > 0) { $statement->fetchAll(PDO::FETCH_ASSOC); } } while ($statement->nextRowset());
        } finally { $statement->closeCursor(); }
    }

    public function down(): void { throw new RuntimeException('MIGRATION_NATIVE_DOWN_FORBIDDEN'); }
}
