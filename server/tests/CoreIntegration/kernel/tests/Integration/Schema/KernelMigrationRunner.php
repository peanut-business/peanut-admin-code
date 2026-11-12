<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use PDO;
use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use Phinx\Db\Adapter\MysqlAdapter;

final readonly class KernelMigrationRunner
{
    public function __construct(
        private PDO $connection,
    ) {}

    public function migrate(): void
    {
        $pdo = $this->connection;
        require_once dirname(__DIR__, 5) . '/Support/IdentityMysqlFixture.php';
        \IdentityMysqlFixture::install($pdo);
        foreach ($this->createStatements() as $table => $sql) {
            if (!$this->tableExists($pdo, $table)) {
                $pdo->exec($sql);
            }
        }
    }

    public function rollbackAll(): void
    {
        $pdo = $this->connection;
        require_once ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 7)) . '/server')
            . '/app/modules/peanut/identity/database/migrations/20261101010001_initial_identity_schema.php';
        $migration = new \InitialIdentitySchema('testing', 20261101010001);
        $migration->setAdapter(new MysqlAdapter(['name' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'connection' => $pdo]));
        // The owning initial migration deliberately refuses destructive rollback.
        $migration->down();
    }

    /** @return array<string,string> */
    private function createStatements(): array
    {
        $statements = ['pa_module_migration' => ModuleSchema::createSql('pa_module_migration')];
        $statements['pa_tenant_idempotency_record'] = IdempotencySchema::tenant();
        $statements['pa_platform_idempotency_record'] = IdempotencySchema::platform();

        return $statements;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
        );
        $statement->execute([$pdo->query('SELECT DATABASE()')->fetchColumn(), $table]);

        return (int) $statement->fetchColumn() === 1;
    }
}
