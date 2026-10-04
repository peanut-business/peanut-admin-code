<?php

declare(strict_types=1);

namespace tests\Unit\RegisteredMysqlSchemaBoundary;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

require_once dirname(__DIR__) . '/Support/RegisteredMysqlTestResource.php';

/** Tests metadata query selection only; live MySQL qualification remains separate. */
final class RegisteredMysqlSchemaBoundaryTest extends TestCase
{
    public function testEmptySchemaChecksTablesRoutinesAndEventsWithBoundDatabase(): void
    {
        $pdo = new MetadataPdo([]);
        (new ReflectionMethod(\RegisteredMysqlTestResource::class, 'assertEmptyOrAbsent'))
            ->invoke(null, $pdo, 'owned_test');
        self::assertCount(3, $pdo->queries);
        foreach (['TABLES', 'ROUTINES', 'EVENTS'] as $position => $catalog) {
            self::assertStringContainsString('information_schema.' . $catalog, $pdo->queries[$position]);
        }
        self::assertSame([['owned_test'], ['owned_test'], ['owned_test']], $pdo->parameters);
    }

    public function testStoredProcedureAloneRejectsNonEmptySchema(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REGISTERED_MYSQL_DATABASE_NOT_EMPTY');
        (new ReflectionMethod(\RegisteredMysqlTestResource::class, 'assertEmptyOrAbsent'))
            ->invoke(null, new MetadataPdo(['ROUTINES' => 1]), 'owned_test');
    }

    public function testScheduledEventAloneRejectsNonEmptySchema(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REGISTERED_MYSQL_DATABASE_NOT_EMPTY');
        (new ReflectionMethod(\RegisteredMysqlTestResource::class, 'assertEmptyOrAbsent'))
            ->invoke(null, new MetadataPdo(['EVENTS' => 1]), 'owned_test');
    }
}

final class MetadataPdo extends PDO
{
    public array $queries = [];
    public array $parameters = [];

    public function __construct(private array $counts) {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        preg_match('/information_schema\.([A-Z]+)/', $query, $matches);
        return new MetadataStatement($this, (int) ($this->counts[$matches[1] ?? ''] ?? 0));
    }
}

final class MetadataStatement extends PDOStatement
{
    public function __construct(private MetadataPdo $owner, private int $count) {}

    public function execute(?array $params = null): bool
    {
        $this->owner->parameters[] = $params;
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->count;
    }
}
