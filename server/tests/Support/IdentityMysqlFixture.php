<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;

/** Installs the real Identity owner schema into an already guarded empty MySQL fixture. */
final class IdentityMysqlFixture
{
    public static function install(PDO $pdo): void
    {
        require_once dirname(__DIR__, 2)
            . '/app/modules/peanut/identity/database/migrations/20261101010001_initial_identity_schema.php';
        $migration = new InitialIdentitySchema('testing', 20261101010001);
        $migration->setAdapter(new MysqlAdapter([
            'name' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
            'connection' => $pdo,
        ]));
        $migration->up();
    }
}
