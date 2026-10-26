<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class InitialBaseline extends Migrator
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialBaseline requires the native PDO adapter.');
        }
        $sql = file_get_contents(__DIR__ . '/../schema/baseline-schema.sql');
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('The initial schema source is missing.');
        }
        $pdo = $adapter->getConnection();
        $foreignKeys = (int) $pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
        $emulated = (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            // Product tables include mutual and forward references; restore the caller's session setting.
            $this->execute('SET FOREIGN_KEY_CHECKS = 0');
            $statement = $this->query($sql);
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
            try {
                $this->execute('SET FOREIGN_KEY_CHECKS = ' . $foreignKeys);
            } finally {
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('The initial application schema cannot be rolled back. Reinstallation requires an explicitly selected empty database.');
    }
}
