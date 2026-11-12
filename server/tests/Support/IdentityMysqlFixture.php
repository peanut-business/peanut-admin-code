<?php

declare(strict_types=1);

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use think\console\Input;
use think\console\Output;

/** Applies current owner migrations to a guarded MySQL fixture using the native ledger. */
final class IdentityMysqlFixture
{
    /** @param list<string> $additionalMigrationDirectories */
    public static function install(PDO $pdo, array $additionalMigrationDirectories = []): void
    {
        $config = new Config([
            'paths' => ['migrations' => [
                ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/app/modules/peanut/identity/database/migrations',
                ...$additionalMigrationDirectories,
            ]],
            'environments' => [
                'default_migration_table' => 'pa_migrations',
                'testing' => ['name' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'connection' => $pdo],
            ],
        ]);
        (new Manager($config, new Input([]), new Output('buffer')))->migrate('testing');
    }
}
