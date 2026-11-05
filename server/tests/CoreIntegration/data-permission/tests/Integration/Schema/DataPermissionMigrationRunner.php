<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Schema;

use PDO;
use Phinx\Db\Adapter\MysqlAdapter;

/** Data permission tables are owned by the current Identity native migration. */
final readonly class DataPermissionMigrationRunner
{
    public function __construct(private PDO $connection) {}

    public function migrate(): void
    {
        require_once dirname(__DIR__, 5) . '/Support/IdentityMysqlFixture.php';
        \IdentityMysqlFixture::install($this->connection);
    }

    public function rollbackAll(): void
    {
        require_once dirname(__DIR__, 6)
            . '/app/modules/peanut/identity/database/migrations/20261101010001_initial_identity_schema.php';
        $migration = new \InitialIdentitySchema('testing', 20261101010001);
        $migration->setAdapter(new MysqlAdapter([
            'name' => $this->connection->query('SELECT DATABASE()')->fetchColumn(),
            'connection' => $this->connection,
        ]));
        $migration->down();
    }
}
