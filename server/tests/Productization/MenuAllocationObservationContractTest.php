<?php

declare(strict_types=1);

use app\common\services\upgrade\MySqlMetadataObservation;
use Phinx\Db\Adapter\MysqlAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/modules/peanut/identity/database/migrations/20261107005900_initialize_menu_allocation_metadata.php';
require_once dirname(__DIR__, 2) . '/app/modules/peanut/identity/database/migrations/20261107010000_reserve_custom_menu_ids.php';

final class MenuAllocationObservationContractTest extends TestCase
{
    public function testInitializeOpensMenuDefinitionWithoutWritingBusinessData(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('fetch')->with(PDO::FETCH_NUM);
        $statement->expects(self::once())->method('closeCursor');
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('query')->with('SHOW CREATE TABLE pa_system_menu')->willReturn($statement);
        $pdo->expects(self::never())->method('exec');
        $adapter = $this->createStub(MysqlAdapter::class);
        $adapter->method('getConnection')->willReturn($pdo);
        $migration = new InitializeMenuAllocationMetadata('fixture', 20261107005900);
        $migration->setAdapter($adapter);
        $migration->up();
    }

    public static function allocations(): array
    {
        return [['1000000000', false], ['1000000001', false], [1000000000, false],
            ['243', true], ['999999999', true], [null, false]];
    }

    #[DataProvider('allocations')]
    public function testReserveAdvancesOnlyLowerKnownAllocations(mixed $value, bool $advance): void
    {
        $adapter = $this->createMock(MysqlAdapter::class);
        $adapter->expects(self::once())->method('fetchRow')
            ->with("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pa_system_menu'")
            ->willReturn(['AUTO_INCREMENT' => $value]);
        $adapter->expects($advance ? self::once() : self::never())->method('execute')
            ->with('ALTER TABLE pa_system_menu AUTO_INCREMENT = 1000000000', []);
        if ($value === null) {
            $this->expectExceptionMessage('MENU_ID_ALLOCATION_UNAVAILABLE');
        }
        $migration = new ReserveCustomMenuIds('fixture', 20261107010000);
        $migration->setAdapter($adapter);
        $migration->up();
    }

    public static function observations(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('observations')]
    public function testUncachedMetadataObservationRestoresSessionEvenOnReadFailure(bool $fail): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn('86400');
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $pdo->expects(self::once())->method('query')->with('SELECT @@SESSION.information_schema_stats_expiry')->willReturn($statement);
        $writes = [];
        $pdo->expects(self::exactly(2))->method('exec')->willReturnCallback(static function (string $sql) use (&$writes): int {
            $writes[] = $sql;
            return 0;
        });
        try {
            $result = MySqlMetadataObservation::uncached($pdo, static function () use ($fail, &$writes): int {
                self::assertSame(['SET SESSION information_schema_stats_expiry=0'], $writes);
                if ($fail) {
                    throw new RuntimeException('READ_FAILED');
                }
                return 1000000000;
            });
            self::assertFalse($fail);
            self::assertSame(1000000000, $result);
        } catch (RuntimeException $error) {
            self::assertTrue($fail);
            self::assertSame('READ_FAILED', $error->getMessage());
        }
        self::assertSame(['SET SESSION information_schema_stats_expiry=0', 'SET SESSION information_schema_stats_expiry=86400'], $writes);
    }
}
