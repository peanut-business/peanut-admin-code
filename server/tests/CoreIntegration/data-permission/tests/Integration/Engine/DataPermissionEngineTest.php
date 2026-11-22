<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Engine;

use DateTimeImmutable;
use PDO;
use ThinkPhpTestConnection;
use PeanutAdmin\Modules\Identity\DataPermission\Catalog\ThinkPhpResourceOperationCatalog;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\Modules\Identity\DataPermission\Policy\ThinkPhpPolicyRepository;
use PeanutAdmin\DataPermission\Provider\ConditionProviderRegistry;
use PeanutAdmin\Modules\Identity\DataPermission\Provider\ThinkPhpDepartmentHierarchyProvider;
use PeanutAdmin\Modules\Identity\DataPermission\Provider\ThinkPhpTargetSetMembershipProvider;
use PeanutAdmin\DataPermission\Provider\ProviderColumnMap;
use PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProviderRegistry;
use PeanutAdmin\DataPermission\Provider\StandardResourcePolicyProvider;
use PeanutAdmin\DataPermission\Target\ResolvedResourceTargets;
use PeanutAdmin\DataPermission\Target\ResourceTargetResolver;
use PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\DataPermission\Tests\Integration\Schema\DataPermissionMigrationRunner;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use think\db\PDOConnection;
use think\db\BaseQuery;
use think\db\Query;
use think\facade\Db;

require_once dirname(__DIR__, 4) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once dirname(__DIR__) . '/Schema/DataPermissionMigrationRunner.php';

final class DataPermissionEngineTest extends DatabaseTestCase
{
    private const NOW = '2026-07-16 10:00:00.000';

    private int $alphaTenant;
    private int $betaTenant;
    private int $accountId;
    private int $memberId;
    private int $otherMemberId;
    private int $roleA;
    private int $roleB;
    private int $readPermissionId;
    private int $updatePermissionId;

    private const CONDITIONS = [
        'core.tenant_all' => null,
        'core.self' => null,
        'core.own_department' => null,
        'core.department_tree' => null,
        'core.specified_departments' => 'core.department',
        'core.specified_objects' => 'example.project',
    ];

    /** @var list<object> */
    private array $operations = [];
    /** @var array<int,list<string>> */
    private array $targetSets = [];
    private ThinkPhpTargetSetMembershipProvider $targetMembership;

    private ThinkPhpAuthorizationCatalogRepository $authorizationCatalog;
    private DataPermissionEngine $engine;
    private TenantContext $context;
    private PDOConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        (new DataPermissionMigrationRunner($this->database))->migrate();
        $this->createFixtureTables();
        $this->seedKernel();
        $this->seedBusinessFixtures();
        $this->connection = ThinkPhpTestConnection::fromPdo($this->database);
        $this->targetMembership = new ThinkPhpTargetSetMembershipProvider();
        $this->engine = $this->engine();
    }

    public function testAllSixCoreScopesCompileIntoTheDatabaseQuery(): void
    {
        $cases = [
            'core.tenant_all' => [null, [1, 2, 3]],
            'core.self' => [null, [1]],
            'core.own_department' => [null, [1]],
            'core.department_tree' => [null, [1, 2]],
            'core.specified_departments' => [$this->targetSet(['3']), [3]],
            'core.specified_objects' => [$this->targetSet(['102']), [2]],
        ];

        foreach ($cases as $conditionKey => [$targetSetId, $expectedIds]) {
            $operation = 'scope-' . str_replace(['core.', '_'], ['', '-'], $conditionKey);
            $this->operation($operation, 'rule_filtered', 'many_readable', $this->readPermissionId);
            $policyId = $this->policy($this->roleA, [[[$conditionKey, $targetSetId]]]);

            self::assertSame($expectedIds, $this->authorizedIds($operation), $conditionKey);
            $this->database->exec("DELETE FROM pa_data_permission WHERE id = {$policyId}");
        }
    }

    public function testConditionsUseAndWhileGroupsAndRolesUseOr(): void
    {
        $this->operation('combined', 'rule_filtered', 'many_readable', $this->readPermissionId);
        $projectA = $this->targetSet(['101']);
        $projectB = $this->targetSet(['102']);
        $siblingDepartment = $this->targetSet(['3']);
        $this->policy($this->roleA, [
            [['core.self', null], ['core.specified_objects', $projectA]],
            [['core.specified_departments', $siblingDepartment]],
        ]);
        $this->policy($this->roleB, [
            [['core.specified_objects', $projectB]],
        ]);

        self::assertSame([1, 2, 3], $this->authorizedIds('combined'));
    }

    public function testNoPolicyUnknownOperationAndMissingFunctionalPermissionFailClosed(): void
    {
        $this->operation('no-policy', 'rule_filtered', 'many_readable', $this->readPermissionId);
        self::assertSame([], $this->authorizedIds('no-policy'));

        $expiredPolicy = $this->policy($this->roleA, [[['core.tenant_all', null]]]);
        $this->database->exec(<<<SQL
UPDATE pa_data_permission
SET valid_until = '2020-01-01 00:00:00.000', revision = revision + 1
WHERE id = {$expiredPolicy}
SQL);
        self::assertSame([], $this->authorizedIds('no-policy'));

        $this->expectDataError('AUTHZ_OPERATION_UNDECLARED', fn() => $this->engine->queryConstraint(
            $this->context,
            'example.work-item',
            'not-declared',
        ));

        $otherContext = $this->context($this->otherMemberId, 2);
        $this->expectDataError('AUTHZ_PERMISSION_DENIED', fn() => $this->engine->queryConstraint(
            $otherContext,
            'example.work-item',
            'no-policy',
        ));
    }

    public function testPolicyRevisionMakesCachedCompiledScopeUnreachable(): void
    {
        $this->operation('cache-revision', 'rule_filtered', 'many_readable', $this->readPermissionId);
        $projectA = $this->targetSet(['101']);
        $policyId = $this->policy($this->roleA, [[['core.specified_objects', $projectA]]]);
        self::assertSame([1], $this->authorizedIds('cache-revision'));

        $projectB = $this->targetSet(['102']);
        $changed = $this->database->prepare(
            'UPDATE pa_data_permission SET condition_groups_json = ?, revision = revision + 1 WHERE id = ?',
        );
        $changed->execute([$this->conditionGroups([
            [['core.specified_objects', $projectA]],
            [['core.specified_objects', $projectB]],
        ]), $policyId]);

        self::assertSame([1, 2], $this->authorizedIds('cache-revision'));
    }

    public function testExplicitTargetsValidateCardinalityTypeTenantAndPolicy(): void
    {
        $this->operation('update', 'explicit_targets', 'one_required', $this->updatePermissionId);
        $projectA = $this->targetSet(['101']);
        $this->policy($this->roleA, [[['core.specified_objects', $projectA]]]);

        $allowed = $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.project', ['101']),
        );
        self::assertTrue($allowed->allowed);
        self::assertFalse($this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.project', ['102']),
        )->allowed);

        $this->expectDataError('AUTHZ_TARGET_CARDINALITY_INVALID', fn() => $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            new TypedResourceTargetCollection(),
        ));
        $this->expectDataError('AUTHZ_TARGET_CARDINALITY_INVALID', fn() => $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.project', ['101', '102']),
        ));
        $this->expectDataError('AUTHZ_TARGET_TYPE_MISMATCH', fn() => $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.queue', ['101']),
        ));
        $this->expectDataError('AUTHZ_TARGET_TENANT_MISMATCH', fn() => $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.project', ['201']),
        ));
    }

    public function testTenFiveHundredAndFiveThousandTargetsRemainSqlBounded(): void
    {
        $this->database->exec('DELETE FROM test_work_item');
        $insert = $this->database->prepare(<<<'SQL'
INSERT INTO test_work_item (id, tenant_id, created_by_member_id, department_id, project_id, name)
VALUES (:id, :tenant_id, :member_id, 1, :project_id, :name)
SQL);
        for ($id = 1; $id <= 5000; ++$id) {
            $insert->execute([
                'id' => $id,
                'tenant_id' => $this->alphaTenant,
                'member_id' => $this->memberId,
                'project_id' => $id,
                'name' => 'Item ' . $id,
            ]);
        }

        foreach ([10, 500, 5000] as $size) {
            $operation = 'target-size-' . $size;
            $this->operation($operation, 'rule_filtered', 'many_readable', $this->readPermissionId);
            $targetIds = array_map('strval', range(1, $size));
            $targetSetId = $this->targetSet($targetIds);
            $policyId = $this->policy($this->roleA, [[[
                'core.specified_objects',
                $targetSetId,
            ]]]);
            $query = $this->authorizedQuery($operation);
            self::assertSame($size, (int) (clone $query)->count());
            $compiledQuery = (clone $query)->field('id');
            $compiledQuery->parseOptions();
            $compiledSql = $compiledQuery->getConnection()->getBuilder()->select($compiledQuery);
            $bindCount = count($compiledQuery->getBind(false));
            $sql = (string) (clone $query)->field('id')->fetchSql()->select();
            self::assertNotFalse($this->query('EXPLAIN ' . $sql)->fetch(PDO::FETCH_ASSOC));
            if ($size <= 500) {
                self::assertStringContainsString(' IN (', $compiledSql);
                self::assertSame($size + 1, $bindCount);
            } else {
                self::assertStringContainsString('EXISTS (', $compiledSql);
                self::assertStringContainsString('pa_data_permission_target', $compiledSql);
                self::assertStringNotContainsString('JSON_TABLE(', $compiledSql);
                self::assertLessThan(10, $bindCount);
                self::assertTrue($this->targetMembership->containsAll(
                    $this->alphaTenant,
                    $targetSetId,
                    $targetIds,
                ));
            }
            $this->database->exec("DELETE FROM pa_data_permission WHERE id = {$policyId}");
        }
    }

    private function createFixtureTables(): void
    {
        $this->database->exec(<<<'SQL'
CREATE TABLE test_project (
    id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_project_tenant (tenant_id, id)
) ENGINE=InnoDB
SQL);
        $this->database->exec(<<<'SQL'
CREATE TABLE test_work_item (
    id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    created_by_member_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_work_item_tenant_project (tenant_id, project_id, id),
    KEY idx_work_item_tenant_department (tenant_id, department_id, id)
) ENGINE=InnoDB
SQL);
    }

    private function seedKernel(): void
    {
        $this->authorizationCatalog = new ThinkPhpAuthorizationCatalogRepository();
        (new CorePermissionCatalogSynchronizer($this->authorizationCatalog))->synchronize();
        $this->insert('pa_module_installation', [
            'module_key' => 'example',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', 'example'),
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->readPermissionId = $this->authorizationCatalog->syncPermission(new PermissionDefinition(
            'example.work-item.read',
            'example',
            'api',
            'Read work items',
            'normal',
            '1.0.0',
        ));
        $this->updatePermissionId = $this->authorizationCatalog->syncPermission(new PermissionDefinition(
            'example.work-item.update',
            'example',
            'api',
            'Update work items',
            'sensitive',
            '1.0.0',
        ));
        $this->alphaTenant = $this->tenant('alpha');
        $this->betaTenant = $this->tenant('beta');
        $this->department($this->alphaTenant, 1, null, 'root');
        $this->department($this->alphaTenant, 2, 1, 'child');
        $this->department($this->alphaTenant, 3, null, 'sibling');
        $this->department($this->betaTenant, 4, null, 'beta-root');

        $this->accountId = $this->account('Alpha member');
        $this->memberId = $this->member($this->alphaTenant, $this->accountId, 1);
        $otherAccount = $this->account('No permission');
        $this->otherMemberId = $this->member($this->alphaTenant, $otherAccount, null);
        $this->roleA = $this->role($this->alphaTenant, 'role-a');
        $this->roleB = $this->role($this->alphaTenant, 'role-b');
        $this->assignRole($this->alphaTenant, $this->memberId, $this->roleA);
        $this->assignRole($this->alphaTenant, $this->memberId, $this->roleB);
        $this->grant($this->alphaTenant, $this->roleA, $this->readPermissionId);
        $this->grant($this->alphaTenant, $this->roleA, $this->updatePermissionId);
        $this->grant($this->alphaTenant, $this->roleB, $this->readPermissionId);
        $this->licenseTenantModules($this->alphaTenant, ['example'], 'active');
        $this->context = $this->context($this->memberId, $this->accountId);
    }

    private function seedBusinessFixtures(): void
    {
        foreach ([
            [101, $this->alphaTenant, 'Project A'],
            [102, $this->alphaTenant, 'Project B'],
            [103, $this->alphaTenant, 'Project C'],
            [201, $this->betaTenant, 'Beta Project'],
        ] as [$id, $tenantId, $name]) {
            $statement = $this->database->prepare('INSERT INTO test_project (id, tenant_id, name) VALUES (?, ?, ?)');
            $statement->execute([$id, $tenantId, $name]);
        }
        foreach ([
            [1, $this->alphaTenant, $this->memberId, 1, 101, 'Alpha A'],
            [2, $this->alphaTenant, $this->otherMemberId, 2, 102, 'Alpha B'],
            [3, $this->alphaTenant, $this->otherMemberId, 3, 103, 'Alpha C'],
            [4, $this->betaTenant, 999, 4, 201, 'Beta'],
        ] as $row) {
            $statement = $this->database->prepare(<<<'SQL'
INSERT INTO test_work_item (id, tenant_id, created_by_member_id, department_id, project_id, name)
VALUES (?, ?, ?, ?, ?, ?)
SQL);
            $statement->execute($row);
        }
    }

    private function engine(): DataPermissionEngine
    {
        $provider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('work_item.tenant_id'),
                new ColumnReference('work_item.created_by_member_id'),
                new ColumnReference('work_item.department_id'),
                ['example.project' => new ColumnReference('work_item.project_id')],
            ),
            new ThinkPhpDepartmentHierarchyProvider(),
            $this->targetMembership,
            new ConditionProviderRegistry(),
        );
        $providers = new ResourceProviderRegistry();
        $providers->registerQuery('example.work-item.standard', $provider);
        $providers->registerTarget('example.work-item.standard', $provider);
        $providers->registerCreate('example.work-item.standard', $provider);
        $resolvers = new TargetResolverRegistry();
        $resolvers->register('example.project.resolver', new ProjectTargetResolver($this->database));

        $manifest = \PeanutAdmin\Kernel\Module\ManifestDocument::fromObject('/fixtures/example', (object) [
            'key' => 'example',
            'catalog' => (object) [
                'target_types' => [(object) [
                    'key' => 'example.project',
                    'resolver' => 'example.project.resolver',
                    'catalog_provider' => 'example.project.catalog',
                ]],
                'protected_resources' => [(object) [
                    'key' => 'example.work-item',
                    'provider' => 'example.work-item.standard',
                    'ownership' => 'business_target_owned',
                    'operations' => $this->operations,
                ]],
            ],
        ]);
        $registry = new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([$manifest], [], [], [], 'engine-fixture');
        $catalog = new ThinkPhpResourceOperationCatalog($registry, $this->fixtureEntitlements(['example']));
        return new DataPermissionEngine(
            $catalog,
            new ThinkPhpPolicyRepository($catalog, $this->targetMembership),
            new TenantAuthorizationEvaluator(
                $this->fixtureAuthorizationRepository(['example']),
            ),
            $providers,
            $resolvers,
            new TargetCatalogProviderRegistry(),
            new SharedMasterScopeProviderRegistry(),
        );
    }

    private function operation(
        string $operation,
        string $accessMode,
        string $cardinality,
        int $permissionId,
    ): void {
        $permissionKey = $permissionId === $this->updatePermissionId
            ? 'example.work-item.update' : 'example.work-item.read';
        $conditions = [];
        foreach (self::CONDITIONS as $key => $targetResourceKey) {
            $conditions[] = (object) ['key' => $key, 'selector_resource_key' => $targetResourceKey];
        }
        $this->operations[] = (object) [
            'key' => $operation,
            'access_mode' => $accessMode,
            'target_cardinality' => $cardinality,
            'permission_match' => 'all',
            'permissions' => [$permissionKey],
            'target_types' => [(object) [
                'target_role' => 'primary',
                'target_resource_key' => 'example.project',
                'input_mode' => 'explicit',
                'policy_selection_permission' => $permissionKey,
            ]],
            'conditions' => $conditions,
        ];
        $this->engine = $this->engine();
    }

    /** @param list<list<array{string,int|null}>> $groups */
    private function policy(int $roleId, array $groups): int
    {
        return $this->insert('pa_data_permission', [
            'tenant_id' => $this->alphaTenant,
            'role_id' => $roleId,
            'resource_key' => 'example.work-item',
            'scope_type' => 'rules',
            'condition_groups_json' => $this->conditionGroups($groups),
            'status' => 'active',
            'created_by_member_id' => $this->memberId,
            'updated_by_member_id' => $this->memberId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    /** @param list<list<array{string,int|null}>> $groups */
    private function conditionGroups(array $groups): string
    {
        $normalized = [];
        $conditionId = 0;
        foreach ($groups as $index => $group) {
            $conditions = [];
            foreach ($group as [$key, $targetSetId]) {
                $conditions[] = [
                    'id' => ++$conditionId,
                    'key' => $key,
                    'target_set_id' => $targetSetId,
                    'target_resource_key' => self::CONDITIONS[$key],
                    'target_ids' => $this->targetSets[$targetSetId] ?? [],
                ];
            }
            $normalized[] = ['id' => $index + 1, 'conditions' => $conditions];
        }
        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $targetIds */
    private function targetSet(array $targetIds): int
    {
        $id = count($this->targetSets) + 1;
        $this->targetSets[$id] = $targetIds;

        return $id;
    }

    /** @return list<int> */
    private function authorizedIds(string $operation): array
    {
        return array_values(array_map('intval', $this->authorizedQuery($operation)
            ->order('id')
            ->column('id')));
    }

    private function authorizedQuery(string $operation): Query
    {
        $query = new Query($this->connection);
        $query->table('test_work_item');
        $query->alias('work_item');
        (new ThinkPhpQueryConstraintApplier(new \PeanutAdmin\Modules\Identity\access\Infrastructure\ThinkPhpTargetSetConstraintApplier($this->targetMembership)))->apply($query, $this->engine->queryConstraint(
            $this->context,
            'example.work-item',
            $operation,
        ));

        return $query;
    }

    private function tenant(string $code): int
    {
        return $this->insert('pa_tenant', [
            'code' => $code,
            'name' => $code,
            'display_name' => $code,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function account(string $name): int
    {
        return $this->insert('pa_account', [
            'display_name' => $name,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function department(int $tenantId, int $id, ?int $parentId, string $code): void
    {
        $statement = $this->database->prepare(<<<'SQL'
INSERT INTO pa_department (id, tenant_id, parent_id, code, name, created_at, updated_at)
VALUES (:id, :tenant_id, :parent_id, :code, :name, :created_at, :updated_at)
SQL);
        $statement->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $code,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function member(int $tenantId, int $accountId, ?int $departmentId): int
    {
        return $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'primary_department_id' => $departmentId,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function role(int $tenantId, string $key): int
    {
        return $this->insert('pa_role', [
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => $key,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function assignRole(int $tenantId, int $memberId, int $roleId): void
    {
        $this->insert('pa_member_role', [
            'tenant_id' => $tenantId,
            'tenant_member_id' => $memberId,
            'role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);
    }

    private function grant(int $tenantId, int $roleId, int $permissionId): void
    {
        $this->insert('pa_role_permission', [
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'granted_at' => self::NOW,
        ]);
    }

    private function context(int $memberId, int $accountId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            'session-' . $memberId,
            $this->alphaTenant,
            $accountId,
            $memberId,
            'web',
            new DateTimeImmutable(self::NOW),
            1,
        1), 'request-' . $memberId);
    }

    /** @param list<string> $ids */
    private function targets(string $resourceKey, array $ids): TypedResourceTargetCollection
    {
        return new TypedResourceTargetCollection([
            new TypedResourceTargetSet($resourceKey, $ids),
        ]);
    }

    private function expectDataError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected data authorization error {$code}.");
        } catch (DataAuthorizationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}

final readonly class ProjectTargetResolver implements ResourceTargetResolver
{
    public function __construct(private PDO $pdo) {}

    public function resolveAndValidate(
        TenantContext $context,
        TypedResourceTargetSet $targets,
    ): ResolvedResourceTargets {
        $placeholders = implode(', ', array_fill(0, count($targets->targetIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM test_project WHERE tenant_id = ? AND id IN ({$placeholders})",
        );
        $statement->execute([$context->tenantId, ...$targets->targetIds]);
        if (count($statement->fetchAll(PDO::FETCH_COLUMN)) !== count($targets->targetIds)) {
            throw new DataAuthorizationException(
                'AUTHZ_TARGET_TENANT_MISMATCH',
                'The target does not belong to the current tenant.',
            );
        }

        return new ResolvedResourceTargets(new TypedResourceTargetCollection([$targets]));
    }
}
