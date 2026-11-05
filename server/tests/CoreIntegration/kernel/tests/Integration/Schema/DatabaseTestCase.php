<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use PDO;
use PDOException;
use PDOStatement;
use ThinkPhpTestConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\Container;

require_once __DIR__ . '/KernelMigrationRunner.php';
require_once dirname(__DIR__, 5) . '/Support/RegisteredMysqlTestResource.php';

abstract class DatabaseTestCase extends TestCase
{
    /** @var array<string,string> */
    protected array $databaseConfig;
    protected string $databaseName;

    protected PDO $admin;
    protected PDO $database;
    protected KernelMigrationRunner $runner;
    private bool $databaseCreated;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 6);
        $app = Container::getInstance();
        $cache = require $root . '/config/cache.php';
        if (!is_array($cache)) {
            throw new RuntimeException('The backend cache configuration is invalid.');
        }
        $app->config->set($cache, 'cache');
        $app->cache->clear();

        $this->databaseName = \RegisteredMysqlTestResource::configuredDatabaseName();
        [$this->database, $this->databaseCreated] = \RegisteredMysqlTestResource::openEmptyDatabase($this->databaseName);
        $this->databaseConfig = [
            'host' => env('DB_HOST'), 'port' => env('DB_PORT'),
            'database' => $this->databaseName, 'user' => env('DB_USER'), 'password' => env('DB_PASS'),
        ];
        $this->admin = $this->database;
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->runner = new KernelMigrationRunner($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->databaseCreated)) {
            \RegisteredMysqlTestResource::cleanup($this->database, $this->databaseName, $this->databaseCreated);
        }

        parent::tearDown();
    }

    /** @param array<string, int|string|null> $values */
    protected function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $quotedColumns = array_map(
            static fn(string $column): string => "`{$column}`",
            $columns,
        );
        $parameters = array_map(
            static fn(string $column): string => ":{$column}",
            $columns,
        );

        $statement = $this->database->prepare(sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $quotedColumns),
            implode(', ', $parameters),
        ));
        $statement->execute($values);

        return (int) $this->database->lastInsertId();
    }

    protected function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
        } catch (PDOException $exception) {
            self::assertNotSame('00000', $exception->getCode());

            return;
        }

        self::fail('Expected MySQL to reject the invalid row.');
    }

    /** Creates only the current license ceiling; role grants and deployment state stay independent.
     * @param list<string> $moduleKeys
     */
    protected function licenseTenantModules(int $tenantId, array $moduleKeys, string $status = 'active'): void
    {
        $statement = $this->database->prepare('SELECT plan_id FROM pa_tenant_license WHERE tenant_id = ?');
        $statement->execute([$tenantId]);
        $planId = $statement->fetchColumn();
        if ($planId === false) {
            $now = '2026-07-16 00:00:00.000';
            $planId = $this->insert('pa_plan', [
                'code' => 'fixture-' . $tenantId, 'name' => 'Fixture plan',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->insert('pa_tenant_license', [
                'tenant_id' => $tenantId, 'plan_id' => $planId, 'status' => $status,
                'effective_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($moduleKeys as $moduleKey) {
            $this->insert('pa_plan_module', ['plan_id' => $planId, 'module_key' => $moduleKey]);
            $ceiling = $this->database->prepare(
                'INSERT INTO pa_plan_permission (plan_id, permission_key) SELECT ?, `key` FROM pa_permission WHERE module_key = ?',
            );
            $ceiling->execute([$planId, $moduleKey]);
        }
    }

    /** @param list<string> $moduleKeys */
    protected function fixtureModuleRegistry(array $moduleKeys): \PeanutAdmin\Kernel\Module\CompiledModuleRegistry
    {
        $manifests = array_map(static fn(string $key) => \PeanutAdmin\Kernel\Module\ManifestDocument::fromObject(
            'fixtures/' . $key,
            (object) ['key' => $key, 'version' => '1.0.0', 'schema_version' => 1, 'tenant' => (object) ['enableable' => true]],
        ), $moduleKeys);
        return new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry($manifests, [], [], [], 'integration-fixture');
    }

    /** @param list<string> $moduleKeys */
    protected function fixtureEntitlements(array $moduleKeys): \PeanutAdmin\Modules\Identity\Module\TenantEntitlementService
    {
        return new \PeanutAdmin\Modules\Identity\Module\TenantEntitlementService($this->fixtureModuleRegistry($moduleKeys));
    }

    /** @param list<string> $moduleKeys */
    protected function fixtureAuthorizationRepository(array $moduleKeys): \PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository
    {
        $registry = $this->fixtureModuleRegistry($moduleKeys);
        $entitlements = new \PeanutAdmin\Modules\Identity\Module\TenantEntitlementService($registry);
        $queries = new \PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery(
            new \PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory(), $registry, $entitlements,
        );
        return new \PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository($entitlements, $queries, $registry);
    }

    protected function query(string $sql): PDOStatement
    {
        $statement = $this->database->query($sql);
        if ($statement === false) {
            throw new RuntimeException('MySQL query did not return a statement.');
        }

        return $statement;
    }

    protected function connect(?string $database = null, bool $foundRows = false): PDO
    {
        if ($database !== null && $database !== $this->databaseName) {
            throw new RuntimeException('KERNEL_INTEGRATION_DATABASE_OUTSIDE_LEASE');
        }
        $config = $this->databaseConfig;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            (int) $config['port'],
            $this->databaseName,
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($foundRows) {
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        }

        return new PDO(
            $dsn,
            $config['user'],
            $config['password'],
            $options,
        );
    }
}
