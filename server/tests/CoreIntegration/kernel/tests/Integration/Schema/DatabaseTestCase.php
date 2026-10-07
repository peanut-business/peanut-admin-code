<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use PDO;
use PDOException;
use PDOStatement;
use ThinkPhpTestConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;

require_once __DIR__ . '/KernelMigrationRunner.php';

abstract class DatabaseTestCase extends TestCase
{
    /** @var array<string,string> */
    protected array $databaseConfig;
    protected string $databaseName;

    protected PDO $admin;
    protected PDO $database;
    protected KernelMigrationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run the selected native PHPUnit contract with an active qualification lease and PEANUT_INTEGRATION=1.');
        }

        $root = dirname(__DIR__, 6);
        require_once $root . '/database/environment-guard.php';
        $proof = requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
        $config = guardedDatabaseConfig($proof);
        if ($config['resource_id'] !== 'peanut-admin-p0e-mysql84-gate') {
            throw new RuntimeException('KERNEL_INTEGRATION_REQUIRES_LEASED_SYNTHETIC_DATABASE');
        }
        $this->databaseConfig = $config;
        $this->databaseName = $config['database'];
        $app = new App($root);
        $cache = require $root . '/config/cache.php';
        if (!is_array($cache)) {
            throw new RuntimeException('The backend cache configuration is invalid.');
        }
        $app->config->set($cache, 'cache');
        $app->cache->clear();

        $this->admin = $this->connect();
        $this->admin->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
        $this->admin->exec(
            'CREATE DATABASE `' . $this->databaseName
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );

        $this->database = $this->connect($this->databaseName);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->runner = new KernelMigrationRunner(
            $this->databaseName,
            $config['host'],
            (int) $config['port'],
            $config['user'],
            $config['password'],
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
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
            'mysql:host=%s;port=%d%s;charset=utf8mb4',
            $config['host'],
            (int) $config['port'],
            $database === null ? '' : ";dbname={$database}",
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
