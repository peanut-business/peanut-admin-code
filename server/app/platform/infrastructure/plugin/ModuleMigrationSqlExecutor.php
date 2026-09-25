<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use app\platform\exception\plugin\PluginLifecycleException;
use PDO;
use think\db\PDOConnection;
use think\facade\Db;

/**
 * Driver-level boundary for an installed Module's immutable SQL migration file.
 *
 * ThinkPHP's query builder cannot execute a multi-statement DDL document or drain
 * all result sets. Normal lifecycle state and catalog persistence must not use this
 * boundary.
 */
final class ModuleMigrationSqlExecutor
{
    public function execute(string $sql): void
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new PluginLifecycleException('MODULE_MIGRATION_DRIVER_UNAVAILABLE', 'Module SQL migrations require the registered PDO driver.');
        }
        $pdo = $connection->connect();
        $emulatedPrepares = (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        if (!$emulatedPrepares) {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        }
        try {
            $statement = $pdo->query($sql);
            try {
                do {
                    if ($statement->columnCount() > 0) {
                        $statement->fetchAll(PDO::FETCH_ASSOC);
                    }
                } while ($statement->nextRowset());
            } finally {
                $statement->closeCursor();
            }
        } finally {
            if (!$emulatedPrepares) {
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            }
        }
    }
}
