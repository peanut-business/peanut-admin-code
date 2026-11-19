<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Unit\Constraint;

use InvalidArgumentException;
use PDO;
use ThinkPhpTestConnection;
use PeanutAdmin\DataPermission\Constraint\AndConstraint;
use PeanutAdmin\DataPermission\Constraint\ColumnEquals;
use PeanutAdmin\DataPermission\Constraint\ColumnIn;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\ExistsByContract;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Constraint\TargetSetConstraintApplier;
use PeanutAdmin\DataPermission\Constraint\TenantEquals;
use PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PHPUnit\Framework\TestCase;
use think\db\BaseQuery;
use think\db\Query;
use think\db\Raw;
use think\facade\Db;

final class QueryConstraintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ThinkPhpTestConnection::fromPdo(new PDO('sqlite::memory:'));
    }

    public function testApplierProducesOnlyBoundStructuredQueryConditions(): void
    {
        $constraint = new AndConstraint([
            new TenantEquals(new ColumnReference('item.tenant_id'), 42),
            new ColumnEquals(new ColumnReference('item.owner_id'), 7),
            new ColumnIn(new ColumnReference('item.project_id'), ['A', 'B']),
        ]);
        $query = $this->applied($constraint);
        [$sql, $binds] = $this->compile($query);

        self::assertStringContainsString('`item`.`tenant_id`', $sql);
        self::assertStringContainsString('`item`.`project_id`', $sql);
        self::assertCount(4, $binds);
    }

    public function testLargeTargetSetsUseTheFixedExistsContract(): void
    {
        $query = $this->applied(new ExistsByContract(
            'data_permission.target-set',
            new ColumnReference('item.project_id'),
            42,
            99,
        ));

        [$sql, $binds] = $this->compile($query);
        self::assertStringContainsString('EXISTS (', $sql);
        self::assertStringContainsString('pa_data_permission_target', $sql);
        self::assertCount(3, $binds);
        $values = array_map(static fn(array $bind): mixed => $bind[0], array_values($binds));
        self::assertContains(42, $values);
        self::assertContains(99, $values);
        self::assertContains('active', $values);
    }

    public function testLargeRequestedTargetSetChunksColumnInAtFiveHundredItems(): void
    {
        $query = $this->applied(new ColumnIn(
            new ColumnReference('item.project_id'),
            array_map('strval', range(1, 1200)),
        ));

        [$sql, $binds] = $this->compile($query);
        self::assertStringContainsString('`item`.`project_id` IN', $sql);
        self::assertStringContainsString(' OR ', $sql);
        self::assertCount(1200, $binds);
    }

    public function testEmptyColumnInProducesAlwaysFalseCondition(): void
    {
        $query = $this->applied(new ColumnIn(new ColumnReference('item.id'), []));
        [$sql, $binds] = $this->compile($query);
        self::assertStringContainsString('1 = 0', $sql);
        self::assertSame([], $binds);
    }

    public function testColumnReferenceRejectsTenantControlledSqlFragments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColumnReference('item.id OR 1=1');
    }

    public function testUnknownConstraintTypesFailClosed(): void
    {
        $this->expectException(DataAuthorizationException::class);
        (new ThinkPhpQueryConstraintApplier($this->targetSetApplier()))->apply(
            Db::table('item')->alias('item'),
            new class implements QueryConstraint {},
        );
    }

    private function applied(QueryConstraint $constraint): Query
    {
        $query = new Query(Db::connect());
        $query->table('item');
        $query->alias('item');
        (new ThinkPhpQueryConstraintApplier($this->targetSetApplier()))->apply($query, $constraint);

        return $query;
    }

    private function targetSetApplier(): TargetSetConstraintApplier
    {
        if (class_exists(\PeanutAdmin\Modules\Identity\access\Infrastructure\ThinkPhpTargetSetConstraintApplier::class)) {
            return new \PeanutAdmin\Modules\Identity\access\Infrastructure\ThinkPhpTargetSetConstraintApplier();
        }
        return new class implements TargetSetConstraintApplier {
            public function apply(BaseQuery $query, ExistsByContract $constraint): void
            {
                $builder = $query->getConnection()->getBuilder();
                $outerColumn = $builder->parseKey($query, $constraint->outerColumn->value, true);
                $subquery = (new Query($query->getConnection()))
                    ->table('pa_data_permission_target')
                    ->field('1')
                    ->where('tenant_id', $constraint->tenantId)
                    ->where('target_set_id', $constraint->targetSetId)
                    ->where('status', 'active')
                    ->whereRaw("target_id = CAST({$outerColumn} AS CHAR)");
                $subquery->parseOptions();
                $query->whereExists(new Raw($builder->select($subquery), $subquery->getBind(false)));
            }
        };
    }

    /** @return array{string, array<string, array{mixed, int}>} */
    private function compile(Query $query): array
    {
        $query->parseOptions();
        $sql = $query->getConnection()->getBuilder()->select($query);

        return [$sql, $query->getBind(false)];
    }
}
