<?php

declare(strict_types=1);

namespace app\common\infrastructure\migration;

use PDO;
use Phinx\Migration\MigrationInterface;
use RuntimeException;
use think\migration\command\migrate\Run;

/** Feeds one lifecycle-admitted SQL stage to the official selector and executor. */
final class PeanutMigrationRun extends Run
{
    /**
     * Lifecycle owns source order, release selection, preflight and applying/failed journals.
     * The official engine selects the version, runs the Migration and records success.
     */
    public static function apply(PDO $pdo, ?string $moduleKey, string $key, string $sql, string $sha256, callable $completion): void
    {
        if ($pdo->inTransaction()) { throw new RuntimeException('MIGRATION_OUTER_TRANSACTION_FORBIDDEN'); }
        $run = new self();
        $run->input = new \think\console\Input([]);
        $run->output = new \think\console\Output('buffer');
        $run->adapter = new PeanutMigrationAdapter($pdo, $moduleKey);
        $run->adapter->assertSourceIdentities([$key]);
        $version = PeanutMigrationAdapter::version($key);
        $migration = new ImmutableSqlMigration('peanut', $version, $run->input, $run->output);
        $migration->bindSource($key, $sql, $sha256, $completion);
        $run->migrations = [$version => $migration];
        $versions = $run->adapter->getVersionLog();
        if (isset($versions[$version])) {
            // The lifecycle has already selected a missing stage. Never silently skip changed history.
            throw new RuntimeException('MIGRATION_NATIVE_HISTORY_CHANGED');
        }
        $run->migrate($version);
    }

    protected function getVersions()
    {
        // Other text identities have no ordering relation to this admitted stage.
        // They remain in the sole ledger and are checked by the lifecycle's source chain.
        $log = $this->getAdapter()->getVersionLog();
        return array_values(array_intersect(array_keys($log), array_keys($this->migrations)));
    }

    protected function executeMigration(MigrationInterface $migration, $direction = MigrationInterface::UP)
    {
        if ($direction !== MigrationInterface::UP) {
            throw new RuntimeException('MIGRATION_NATIVE_DOWN_FORBIDDEN');
        }
        parent::executeMigration($migration, $direction);
    }
}
