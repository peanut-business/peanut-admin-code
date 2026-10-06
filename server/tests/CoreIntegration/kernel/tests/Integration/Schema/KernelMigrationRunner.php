<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use PDO;
use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\Schema\AuthorizationSchema;
use RuntimeException;

final readonly class KernelMigrationRunner
{
    public function __construct(
        private string $database,
        private string $host,
        private int $port,
        private string $username,
        private string $password,
    ) {}

    public function migrate(): void
    {
        $pdo = $this->connect();
        foreach ($this->createStatements() as $table => $sql) {
            if (!$this->tableExists($pdo, $table)) {
                $pdo->exec($sql);
            }
        }

        require_once dirname(__DIR__, 6) . '/database/install.php';
        \ensureTenantChallengeClientKey($pdo);
        if (!$this->departmentForeignKeyExists($pdo)) {
            $pdo->exec(KernelSchema::addTenantMemberDepartmentForeignKeySql());
        }
    }

    public function rollbackAll(): void
    {
        $pdo = $this->connect();
        if ($this->departmentForeignKeyExists($pdo)) {
            $pdo->exec(KernelSchema::dropTenantMemberDepartmentForeignKeySql());
        }
        foreach (array_reverse(array_keys($this->createStatements())) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    private function connect(): PDO
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            throw new RuntimeException('KERNEL_MIGRATION_REQUIRES_INTEGRATION_AUTHORIZATION');
        }
        require_once dirname(__DIR__, 6) . '/database/environment-guard.php';
        $config = \guardedDatabaseConfig(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        if ($config['resource_id'] !== 'peanut-admin-p0e-mysql84-gate'
            || $config['database'] !== $this->database
            || $config['host'] !== $this->host
            || (int) $config['port'] !== $this->port
            || $config['user'] !== $this->username
            || $config['password'] !== $this->password) {
            throw new RuntimeException('KERNEL_MIGRATION_REQUIRES_EXACT_LEASED_DATABASE');
        }

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database),
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    /** @return array<string,string> */
    private function createStatements(): array
    {
        $statements = [];
        foreach ([KernelSchema::class, AuthorizationSchema::class, ModuleSchema::class] as $schema) {
            foreach ($schema::tableNames() as $table) {
                $statements[$table] = $schema::createSql($table);
            }
        }
        $statements['pa_tenant_idempotency_record'] = IdempotencySchema::tenant();
        $statements['pa_platform_idempotency_record'] = IdempotencySchema::platform();

        return $statements;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
        );
        $statement->execute([$this->database, $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function departmentForeignKeyExists(PDO $pdo): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints '
            . "WHERE constraint_schema = ? AND table_name = 'pa_tenant_member' "
            . "AND constraint_name = 'fk_tenant_member_department' AND constraint_type = 'FOREIGN KEY'",
        );
        $statement->execute([$this->database]);

        return (int) $statement->fetchColumn() === 1;
    }
}
