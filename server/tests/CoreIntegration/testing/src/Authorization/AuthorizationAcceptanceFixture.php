<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use DateTimeImmutable;
use PeanutAdmin\Modules\Identity\DataPermission\Catalog\ThinkPhpResourceOperationCatalog;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\Modules\Identity\DataPermission\Policy\ThinkPhpPolicyRepository;
use PeanutAdmin\DataPermission\Provider\ConditionProviderRegistry;
use PeanutAdmin\Modules\Identity\DataPermission\Provider\ThinkPhpDepartmentHierarchyProvider;
use PeanutAdmin\Modules\Identity\DataPermission\Provider\ThinkPhpTargetSetMembershipProvider;
use PeanutAdmin\DataPermission\Provider\ProviderColumnMap;
use PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProviderRegistry;
use PeanutAdmin\DataPermission\Provider\StandardResourcePolicyProvider;
use PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use RuntimeException;
use think\db\PDOConnection;
use think\facade\Db;

final class AuthorizationAcceptanceFixture
{
    private const NOW = '2026-07-16 12:00:00.000';

    private ThinkPhpAuthorizationCatalogRepository $catalog;
    private ThinkPhpTargetSetMembershipProvider $targetMembership;
    private int $alphaTenant;
    private int $betaTenant;
    private int $accountId;
    private int $alphaMember;
    private int $betaMember;
    private int $readerRole;
    private int $writerRole;

    /** @var array<string, int> */
    private array $permissions = [];

    /** @var array<string,list<object>> */
    private array $operations = [];

    /** @var array<string, int> */
    private array $policyIds = [];

    /** @var array<string, int> */
    private array $recordIds = [];

    public static function install(): AuthorizationAcceptanceEnvironment
    {
        $fixture = new self();

        return $fixture->build();
    }

    private function build(): AuthorizationAcceptanceEnvironment
    {
        $this->targetMembership = new ThinkPhpTargetSetMembershipProvider();
        $this->createTables();
        $this->seedCatalog();
        $this->seedPrincipals();
        $this->seedTargetsAndRecords();
        $this->seedPolicies();
        $engine = $this->engine();
        $alphaContext = $this->context($this->alphaTenant, $this->alphaMember, $this->accountId, 'alpha');
        $betaContext = $this->context($this->betaTenant, $this->betaMember, $this->accountId, 'beta');
        $trace = new AuthorizationSqlTrace();

        return new AuthorizationAcceptanceEnvironment(
            $engine,
            $alphaContext,
            $betaContext,
            new ResourceProviderContractHarness($engine, $alphaContext, $trace, constraints: new \PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier(new \PeanutAdmin\Modules\Identity\access\Infrastructure\ThinkPhpTargetSetConstraintApplier($this->targetMembership))),
            $trace,
            $this->accountId,
            $this->alphaTenant,
            $this->betaTenant,
            $this->alphaMember,
            $this->betaMember,
            $this->recordIds,
            $this->policyIds,
        );
    }

    private function createTables(): void
    {
        foreach ([
            <<<'SQL'
CREATE TABLE fixture_project (
  tenant_id BIGINT UNSIGNED NOT NULL,
  id VARCHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_queue (
  tenant_id BIGINT UNSIGNED NOT NULL,
  id VARCHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_record (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  project_id VARCHAR(32) NOT NULL,
  created_by_member_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(64) NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_fixture_record_code (tenant_id, code),
  KEY idx_fixture_record_tenant_project (tenant_id, project_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_target_visibility (
  tenant_id BIGINT UNSIGNED NOT NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  target_id VARCHAR(32) NOT NULL,
  PRIMARY KEY (tenant_id, member_id, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_reference (
  id VARCHAR(32) NOT NULL,
  owner_tenant_id BIGINT UNSIGNED NULL,
  owner_project_id VARCHAR(32) NULL,
  visibility VARCHAR(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_reference_visibility (
  reference_id VARCHAR(32) NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  project_id VARCHAR(32) NOT NULL,
  PRIMARY KEY (reference_id, tenant_id, project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        ] as $sql) {
            $this->connection()->execute($sql);
        }
    }

    private function seedCatalog(): void
    {
        $this->catalog = new ThinkPhpAuthorizationCatalogRepository();
        (new CorePermissionCatalogSynchronizer($this->catalog))->synchronize();
        $this->insert('pa_module_installation', [
            'module_key' => 'fixture',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => $this->registry()->requireManifest('fixture')->digest,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach ([
            'menu', 'read', 'create', 'update', 'delete', 'batch', 'import',
            'export', 'job', 'aggregate', 'reference.read', 'reference.use', 'project.select',
        ] as $action) {
            $key = 'fixture.record.' . $action;
            if (str_starts_with($action, 'reference.')) {
                $key = 'fixture.' . $action;
            } elseif ($action === 'project.select') {
                $key = 'fixture.project.select';
            }
            $this->permissions[$key] = $this->catalog->syncPermission(new PermissionDefinition(
                $key,
                'fixture',
                $action === 'menu' ? 'menu' : 'api',
                $key,
                in_array($action, ['delete', 'batch', 'import'], true) ? 'sensitive' : 'normal',
                '1.0.0',
            ));
        }
        foreach ([
            'list' => ['rule_filtered', 'many_readable', 'fixture.record.read'],
            'detail' => ['explicit_targets', 'one_required', 'fixture.record.read'],
            'create' => ['explicit_targets', 'one_required', 'fixture.record.create'],
            'update' => ['explicit_targets', 'one_required', 'fixture.record.update'],
            'delete' => ['explicit_targets', 'one_required', 'fixture.record.delete'],
            'batch' => ['explicit_targets', 'one_required', 'fixture.record.batch'],
            'import' => ['explicit_targets', 'one_required', 'fixture.record.import'],
            'export' => ['rule_filtered', 'many_readable', 'fixture.record.export'],
            'job' => ['rule_filtered', 'many_readable', 'fixture.record.job'],
            'aggregate' => ['rule_filtered', 'aggregate_read', 'fixture.record.aggregate'],
            'bulk-write' => ['explicit_targets', 'bulk_write', 'fixture.record.batch'],
            'policy-publish' => ['explicit_targets', 'policy_publish', 'fixture.record.update'],
        ] as $operation => [$mode, $cardinality, $permission]) {
            $this->operation(
                'fixture.record',
                $operation,
                $mode,
                $cardinality,
                $permission,
            );
        }
        $this->operation(
            'fixture.reference',
            'list',
            'rule_filtered',
            'many_readable',
            'fixture.reference.read',
        );
        $this->operation(
            'fixture.reference',
            'use',
            'explicit_targets',
            'one_required',
            'fixture.reference.use',
        );
        $this->operation(
            'fixture.reference-unregistered',
            'list',
            'rule_filtered',
            'many_readable',
            'fixture.reference.read',
        );
    }

    private function seedPrincipals(): void
    {
        $this->alphaTenant = $this->tenant('alpha');
        $this->betaTenant = $this->tenant('beta');
        $this->accountId = $this->insert('pa_account', [
            'display_name' => 'Multi-tenant account',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->alphaMember = $this->member($this->alphaTenant, $this->accountId);
        $this->betaMember = $this->member($this->betaTenant, $this->accountId);
        $this->readerRole = $this->role($this->alphaTenant, 'fixture-reader');
        $this->writerRole = $this->role($this->alphaTenant, 'fixture-writer');
        $betaMenuRole = $this->role($this->betaTenant, 'fixture-menu-only');
        $this->assignRole($this->alphaTenant, $this->alphaMember, $this->readerRole);
        $this->assignRole($this->alphaTenant, $this->alphaMember, $this->writerRole);
        $this->assignRole($this->betaTenant, $this->betaMember, $betaMenuRole);

        foreach ([
            'fixture.record.read', 'fixture.record.export', 'fixture.record.job',
            'fixture.record.aggregate', 'fixture.reference.read', 'fixture.reference.use',
            'core.role.data-policy.manage',
        ] as $permission) {
            $permissionId = str_starts_with($permission, 'core.')
                ? $this->permissionId($permission)
                : $this->permissions[$permission];
            $this->grant($this->alphaTenant, $this->readerRole, $permissionId);
        }
        foreach ([
            'fixture.record.create', 'fixture.record.update', 'fixture.record.delete',
            'fixture.record.batch', 'fixture.record.import',
        ] as $permission) {
            $this->grant($this->alphaTenant, $this->writerRole, $this->permissions[$permission]);
        }
        $this->grant($this->betaTenant, $betaMenuRole, $this->permissions['fixture.record.menu']);
        foreach ([$this->alphaTenant, $this->betaTenant] as $tenantId) {
            $planId = $this->insert('pa_plan', [
                'code' => 'acceptance-' . $tenantId, 'name' => 'Acceptance fixture',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            Db::name('plan_module')->insert(['plan_id' => $planId, 'module_key' => 'fixture']);
            foreach (array_keys($this->permissions) as $permissionKey) {
                Db::name('plan_permission')->insert(['plan_id' => $planId, 'permission_key' => $permissionKey]);
            }
            $this->insert('pa_tenant_license', [
                'tenant_id' => $tenantId, 'plan_id' => $planId, 'status' => 'active',
                'effective_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW,
            ]);
        }
    }

    private function seedTargetsAndRecords(): void
    {
        foreach ([
            [$this->alphaTenant, 'A', 'Project A'],
            [$this->alphaTenant, 'B', 'Project B'],
            [$this->alphaTenant, 'C', 'Project C'],
            [$this->betaTenant, 'A', 'Beta Project A'],
        ] as $row) {
            $this->insert('fixture_project', [
                'tenant_id' => $row[0],
                'id' => $row[1],
                'name' => $row[2],
            ]);
        }
        $this->insert('fixture_queue', [
            'tenant_id' => $this->alphaTenant,
            'id' => 'A',
            'name' => 'Queue A',
        ]);
        foreach (['A', 'B'] as $projectId) {
            $this->insert('fixture_target_visibility', [
                'tenant_id' => $this->alphaTenant,
                'member_id' => $this->alphaMember,
                'target_id' => $projectId,
            ]);
        }
        foreach ([
            'alpha_a_1' => [$this->alphaTenant, 'A', $this->alphaMember, 'Alpha A 1'],
            'alpha_a_2' => [$this->alphaTenant, 'A', $this->alphaMember, 'Alpha A 2'],
            'alpha_b' => [$this->alphaTenant, 'B', $this->alphaMember, 'Alpha B'],
            'alpha_c' => [$this->alphaTenant, 'C', $this->alphaMember, 'Alpha C'],
            'beta_a' => [$this->betaTenant, 'A', $this->betaMember, 'Beta A'],
        ] as $key => [$tenantId, $projectId, $memberId, $name]) {
            $this->recordIds[$key] = $this->insert('fixture_record', [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'created_by_member_id' => $memberId,
                'name' => $name,
            ]);
        }
        foreach ([
            ['PUBLIC', null, null, 'public', 'Public reference'],
            ['PRIVATE_A', $this->alphaTenant, 'A', 'private', 'Private A'],
            ['PRIVATE_C', $this->alphaTenant, 'C', 'private', 'Private C'],
        ] as $row) {
            $this->insert('fixture_reference', [
                'id' => $row[0],
                'owner_tenant_id' => $row[1],
                'owner_project_id' => $row[2],
                'visibility' => $row[3],
                'name' => $row[4],
            ]);
        }
        $this->insert('fixture_reference_visibility', [
            'reference_id' => 'PRIVATE_A',
            'tenant_id' => $this->alphaTenant,
            'project_id' => 'A',
        ]);
        $this->insert('fixture_reference_visibility', [
            'reference_id' => 'PRIVATE_C',
            'tenant_id' => $this->alphaTenant,
            'project_id' => 'C',
        ]);
    }

    private function seedPolicies(): void
    {
        $readId = $this->policy($this->readerRole, 'fixture.record', 'core.specified_objects', ['A', 'B']);
        $writeId = $this->policy($this->writerRole, 'fixture.record', 'core.specified_objects', ['A']);
        foreach (['list', 'detail', 'export', 'job', 'aggregate'] as $operation) {
            $this->policyIds[$operation] = $readId;
        }
        foreach (['create', 'update', 'delete', 'batch', 'import', 'bulk-write', 'policy-publish'] as $operation) {
            $this->policyIds[$operation] = $writeId;
        }
        $referenceId = $this->policy($this->readerRole, 'fixture.reference', 'core.tenant_all', []);
        $this->policyIds['reference-list'] = $referenceId;
        $this->policyIds['reference-use'] = $referenceId;
        $this->policyIds['unregistered-reference-list'] = $this->policy(
            $this->readerRole, 'fixture.reference-unregistered', 'core.tenant_all', [],
        );
    }

    private function engine(): DataPermissionEngine
    {
        $providers = new ResourceProviderRegistry();
        $recordProvider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('record.tenant_id'),
                new ColumnReference('record.created_by_member_id'),
                null,
                ['fixture.project' => new ColumnReference('record.project_id')],
            ),
            new ThinkPhpDepartmentHierarchyProvider(),
            $this->targetMembership,
            new ConditionProviderRegistry(),
        );
        $referenceProvider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('reference.owner_tenant_id'),
                null,
                null,
                ['fixture.project' => new ColumnReference('reference.owner_project_id')],
            ),
            new ThinkPhpDepartmentHierarchyProvider(),
            $this->targetMembership,
            new ConditionProviderRegistry(),
        );
        foreach (['fixture.record.standard' => $recordProvider, 'fixture.reference.standard' => $referenceProvider] as $key => $provider) {
            $providers->registerQuery($key, $provider);
            $providers->registerTarget($key, $provider);
            $providers->registerCreate($key, $provider);
        }
        $resolvers = new TargetResolverRegistry();
        $resolvers->register('fixture.project.resolver', new FixtureTargetResolver('fixture.project'));
        $resolvers->register('fixture.queue.resolver', new FixtureTargetResolver('fixture.queue'));
        $catalogProviders = new TargetCatalogProviderRegistry();
        $catalogProviders->register('fixture.project.catalog', new FixtureTargetCatalogProvider());
        $shared = new SharedMasterScopeProviderRegistry();
        $shared->register('fixture.reference', new FixtureSharedMasterScopeProvider());

        $registry = $this->registry();
        $entitlements = new \PeanutAdmin\Modules\Identity\Module\TenantEntitlementService($registry);
        $queries = new \PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery(
            new \PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory(), $registry,
        );
        $catalog = new ThinkPhpResourceOperationCatalog($registry, $entitlements);
        return new DataPermissionEngine(
            $catalog,
            new ThinkPhpPolicyRepository($catalog, $this->targetMembership),
            new TenantAuthorizationEvaluator(
                new ThinkPhpTenantAuthorizationRepository($entitlements, $queries),
            ),
            $providers,
            $resolvers,
            $catalogProviders,
            $shared,
        );
    }

    private function operation(
        string $resourceKey,
        string $operation,
        string $accessMode,
        string $cardinality,
        string $permission,
    ): void {
        $this->operations[$resourceKey][] = (object) [
            'key' => $operation,
            'access_mode' => $accessMode,
            'target_cardinality' => $cardinality,
            'permission_match' => 'all',
            'permissions' => [$permission],
            'target_types' => [(object) [
                'target_role' => 'primary',
                'target_resource_key' => 'fixture.project',
                'input_mode' => 'explicit',
                'policy_selection_permission' => 'fixture.project.select',
            ]],
            'conditions' => [
                (object) ['key' => 'core.tenant_all'],
                (object) ['key' => 'core.specified_objects', 'selector_resource_key' => 'fixture.project'],
            ],
        ];
    }

    /** @param list<string> $targetIds */
    private function policy(int $roleId, string $resourceKey, string $conditionKey, array $targetIds): int
    {
        $condition = [
            'id' => 1, 'key' => $conditionKey,
            'target_set_id' => $targetIds === [] ? null : 1,
            'target_resource_key' => $targetIds === [] ? null : 'fixture.project',
            'target_ids' => $targetIds,
        ];
        return $this->insert('pa_data_permission', [
            'tenant_id' => $this->alphaTenant,
            'role_id' => $roleId,
            'resource_key' => $resourceKey,
            'scope_type' => 'rules',
            'condition_groups_json' => json_encode([['id' => 1, 'conditions' => [$condition]]], JSON_THROW_ON_ERROR),
            'created_by_member_id' => $this->alphaMember,
            'updated_by_member_id' => $this->alphaMember,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function tenant(string $code): int
    {
        return $this->insert('pa_tenant', [
            'code' => $code,
            'name' => ucfirst($code),
            'display_name' => ucfirst($code),
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function member(int $tenantId, int $accountId): int
    {
        return $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
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

    private function permissionId(string $key): int
    {
        $id = Db::name('permission')->where('key', $key)->value('id');
        if ($id === null) {
            throw new RuntimeException("Fixture permission does not exist: {$key}");
        }

        return (int) $id;
    }

    private function context(int $tenantId, int $memberId, int $accountId, string $suffix): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $memberId,
            'session-' . $suffix,
            $tenantId,
            $accountId,
            $memberId,
            'web',
            new DateTimeImmutable(self::NOW),
            1,
            (int) Db::name('tenant')->where('id', $tenantId)->value('authorization_revision'),
        ), 'request-' . $suffix);
    }

    private function registry(): \PeanutAdmin\Kernel\Module\CompiledModuleRegistry
    {
        $manifest = \PeanutAdmin\Kernel\Module\ManifestDocument::fromObject('fixtures/fixture', (object) [
            'key' => 'fixture', 'version' => '1.0.0', 'schema_version' => 1,
            'tenant' => (object) ['enableable' => true],
            'catalog' => (object) [
                'target_types' => array_map(static fn(string $key): object => (object) [
                    'key' => $key, 'resolver' => $key . '.resolver', 'catalog_provider' => $key . '.catalog',
                ], ['fixture.project', 'fixture.queue']),
                'protected_resources' => array_map(fn(string $key): object => (object) [
                    'key' => $key,
                    'provider' => $key === 'fixture.record' ? 'fixture.record.standard' : 'fixture.reference.standard',
                    'ownership' => $key === 'fixture.record' ? 'business_target_owned' : 'shared_master',
                    'operations' => $this->operations[$key],
                ], array_keys($this->operations)),
            ],
        ]);
        return new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([$manifest], [], [], [], 'acceptance-fixture');
    }

    /** @param array<string, int|string|null> $values */
    private function insert(string $table, array $values): int
    {
        return (int) Db::table($table)->insertGetId($values);
    }

    private function connection(): PDOConnection
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new RuntimeException('Authorization fixture requires ThinkPHP PDO query support.');
        }

        return $connection;
    }
}
