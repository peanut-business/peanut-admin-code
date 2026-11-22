<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Application;

use DateTimeImmutable;
use PDO;
use ThinkPhpTestConnection;
use PeanutAdmin\Modules\Identity\DataPermission\Application\EffectiveAccessPreviewService;
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
use PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Tests\Integration\Schema\DataPermissionMigrationRunner;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use think\db\PDOConnection;
use think\db\Query;
use think\facade\Db;

require_once dirname(__DIR__, 4) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once dirname(__DIR__) . '/Schema/DataPermissionMigrationRunner.php';

final class EffectiveAccessPreviewServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-19 08:00:00.000';

    private int $tenantId;
    private int $actorAccountId;
    private int $actorMemberId;
    private int $subjectMemberId;
    private int $roleA;
    private int $roleB;
    private TenantContext $actor;
    private ThinkPhpTenantAuthorizationRepository $authorization;
    private ThinkPhpResourceOperationCatalog $catalog;
    private ThinkPhpPolicyRepository $policies;
    private ThinkPhpTargetSetMembershipProvider $targetMembership;
    /** @var list<ManifestDocument> */
    private array $catalogManifests = [];
    private PDOConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        (new DataPermissionMigrationRunner($this->database))->migrate();
        $this->database->exec(<<<'SQL'
CREATE TABLE pa_preview_record (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    created_by_member_id BIGINT UNSIGNED NULL,
    department_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        (new CorePermissionCatalogSynchronizer(
            new ThinkPhpAuthorizationCatalogRepository(),
        ))->synchronize();

        $this->tenantId = $this->tenant('preview-alpha');
        $this->actorAccountId = $this->account('Preview administrator');
        $this->actorMemberId = $this->member($this->tenantId, $this->actorAccountId, 'Administrator');
        $subjectAccountId = $this->account('Preview subject');
        $this->subjectMemberId = $this->member($this->tenantId, $subjectAccountId, 'Subject');
        $this->roleA = $this->role('tenant.preview-a', 'Preview A');
        $this->roleB = $this->role('tenant.preview-b', 'Preview B');
        $disabledRole = $this->role('tenant.preview-disabled', 'Disabled role', 'disabled');
        foreach ([$this->roleB, $disabledRole, $this->roleA] as $roleId) {
            $this->assignRole($roleId);
        }
        $this->grant($this->roleA, 'core.member.read');
        $this->grant($this->roleB, 'core.member.read');
        $this->grant($this->roleB, 'core.role.read');
        $this->grant($disabledRole, 'core.audit.read');

        $this->seedOperations();
        $this->seedConditionalPolicies();
        $this->authorization = $this->fixtureAuthorizationRepository(['preview.active', 'preview.unavailable']);
        $this->connection = ThinkPhpTestConnection::fromPdo($this->database);
        $this->targetMembership = new ThinkPhpTargetSetMembershipProvider();
        $this->loadCatalog('initial');
        $this->actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01J00000000000000000000000',
            $this->tenantId,
            $this->actorAccountId,
            $this->actorMemberId,
            'admin-web',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            1,
        1), 'req_effective_access');
    }

    public function testBuildsExactRedactedModesDigestAndOneAuditEvent(): void
    {
        $service = $this->service();

        $result = $service->preview($this->actor, $this->subjectMemberId, new PageRequest(1, 100));
        $data = $result['data'];

        self::assertSame('authorization_inputs', $data['preview_kind']);
        self::assertSame('2026-07-19T08:00:00+00:00', $data['evaluated_at']);
        self::assertSame([
            'tenant.preview-a',
            'tenant.preview-b',
            'tenant.preview-expired',
            'tenant.preview-future',
            'tenant.preview-group-disabled',
            'tenant.preview-policy-disabled',
        ], array_column($data['roles'], 'key'));
        self::assertSame(['core.member.read', 'core.role.read'], $data['permission_keys']);
        self::assertSame(10, $result['meta']['total']);
        self::assertSame(1, $result['meta']['total_pages']);

        $operations = [];
        foreach ($data['resource_operations'] as $operation) {
            $operations[$operation['resource_key']] = $operation;
        }
        self::assertSame('functional_denied', $operations['preview.a-functional-denied']['data_access']['mode']);
        self::assertFalse($operations['preview.a-functional-denied']['functional_allowed']);
        self::assertSame('no_effective_policy', $operations['preview.b-any']['data_access']['mode']);
        self::assertTrue($operations['preview.b-any']['functional_allowed']);
        self::assertSame('tenant_actor_denied', $operations['preview.c-system']['data_access']['mode']);
        self::assertSame('tenant_actor_denied', $operations['preview.d-platform']['data_access']['mode']);
        self::assertSame('global_reference_read', $operations['preview.e-global']['data_access']['mode']);
        self::assertFalse($operations['preview.e-global']['data_access']['runtime_decision_required']);
        self::assertSame('tenant_wide', $operations['preview.f-tenant-wide']['data_access']['mode']);
        self::assertTrue($operations['preview.f-tenant-wide']['data_access']['runtime_decision_required']);
        self::assertSame('conditional', $operations['preview.g-conditional']['data_access']['mode']);
        self::assertSame('no_effective_policy', $operations['preview.h-no-policy']['data_access']['mode']);
        self::assertTrue($operations['preview.h-no-policy']['data_access']['runtime_decision_required']);
        self::assertSame('global_reference_read', $operations['preview.i-global-targeted']['data_access']['mode']);
        self::assertTrue($operations['preview.i-global-targeted']['data_access']['runtime_decision_required']);
        self::assertSame('functional_denied', $operations['preview.j-unbound']['data_access']['mode']);
        self::assertArrayNotHasKey('preview.z-unavailable', $operations);

        $groups = $operations['preview.g-conditional']['data_access']['groups'];
        self::assertSame('any', $operations['preview.g-conditional']['data_access']['group_match']);
        self::assertSame(
            ['tenant.preview-a', 'tenant.preview-b'],
            array_column($groups, 'source_role_key'),
        );
        self::assertSame('tenant.preview-a', $groups[0]['source_role_key']);
        self::assertSame('all', $groups[0]['condition_match']);
        self::assertSame([
            'condition_key' => 'core.specified_objects',
            'target_resource_key' => 'preview.project',
            'target_count' => 501,
        ], $groups[0]['conditions'][0]);
        self::assertSame('tenant.preview-b', $groups[1]['source_role_key']);
        self::assertSame('core.self', $groups[1]['conditions'][0]['condition_key']);
        self::assertStringNotContainsString('target_id', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('provider', json_encode($result, JSON_THROW_ON_ERROR));

        $policyRevisions = [];
        foreach ($data['resource_operations'] as $operation) {
            $catalogOperation = $this->catalog->find($operation['resource_key'], $operation['operation']);
            self::assertNotNull($catalogOperation);
            $policyRevisions[] = $this->policies->revision(
                $this->tenantId,
                $this->subjectMemberId,
                $catalogOperation->id,
            )->value;
        }
        $digestParts = [
            'p1-b02-v1',
            (string) $this->tenantId,
            (string) $this->subjectMemberId,
            $this->authorization->revision($this->tenantId, $this->subjectMemberId),
            $this->catalog->registryRevision(),
            '1',
            '100',
        ];
        foreach ($data['resource_operations'] as $index => $operation) {
            $digestParts[] = $operation['resource_key'];
            $digestParts[] = $operation['operation'];
            $digestParts[] = $policyRevisions[$index];
        }
        self::assertSame(hash('sha256', implode('|', $digestParts)), $data['snapshot_revision']);

        $event = $this->query(<<<'SQL'
SELECT event_type, action, actor_tenant_member_id, actor_account_id,
       target_resource_type, target_resource_id, target_count, metadata_json
FROM pa_tenant_audit_event
SQL)->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($event);
        self::assertSame('tenant.member.effective-access.viewed', $event['event_type']);
        self::assertSame('core.member.effective-access.read', $event['action']);
        self::assertSame($this->actorMemberId, (int) $event['actor_tenant_member_id']);
        self::assertSame($this->actorAccountId, (int) $event['actor_account_id']);
        self::assertSame('member', $event['target_resource_type']);
        self::assertSame((string) $this->subjectMemberId, $event['target_resource_id']);
        self::assertSame(1, (int) $event['target_count']);
        $metadata = json_decode((string) $event['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($data['snapshot_revision'], $metadata['snapshot_revision']);
        self::assertSame(6, $metadata['role_count']);
        self::assertSame(2, $metadata['permission_count']);
        self::assertSame(10, $metadata['operation_count']);
        self::assertArrayNotHasKey('permission_keys', $metadata);
    }

    public function testPreviewModesStayInParityWithDataPermissionEngine(): void
    {
        $preview = $this->service()->preview(
            $this->actor,
            $this->subjectMemberId,
            new PageRequest(1, 100),
        );
        $summaries = [];
        foreach ($preview['data']['resource_operations'] as $summary) {
            $summaries[$summary['resource_key']] = $summary;
        }

        $accountId = (int) $this->query(<<<SQL
SELECT account_id FROM pa_tenant_member WHERE id = {$this->subjectMemberId}
SQL)->fetchColumn();
        $subject = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            2,
            '01J00000000000000000000001',
            $this->tenantId,
            $accountId,
            $this->subjectMemberId,
            'parity-test',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            1,
        1), 'req_effective_access_parity');
        $engine = $this->engine();

        foreach (['preview.a-functional-denied', 'preview.j-unbound'] as $resourceKey) {
            self::assertFalse($summaries[$resourceKey]['functional_allowed']);
            self::assertSame('functional_denied', $summaries[$resourceKey]['data_access']['mode']);
            self::assertSame(
                'AUTHZ_PERMISSION_DENIED',
                $this->engineError(fn() => $engine->queryConstraint($subject, $resourceKey, 'inspect')),
            );
        }

        self::assertTrue($summaries['preview.b-any']['functional_allowed']);
        self::assertSame('no_effective_policy', $summaries['preview.b-any']['data_access']['mode']);
        self::assertStringContainsString('1 = 0', $this->compiledSql($engine, $subject, 'preview.b-any'));

        foreach (['preview.c-system', 'preview.d-platform'] as $resourceKey) {
            self::assertSame('tenant_actor_denied', $summaries[$resourceKey]['data_access']['mode']);
            self::assertSame(
                'AUTHZ_SYSTEM_ACTOR_DENIED',
                $this->engineError(fn() => $engine->queryConstraint($subject, $resourceKey, 'inspect')),
            );
        }

        self::assertSame('global_reference_read', $summaries['preview.e-global']['data_access']['mode']);
        self::assertStringNotContainsString(
            ' WHERE ',
            $this->compiledSql($engine, $subject, 'preview.e-global'),
        );

        self::assertSame('tenant_wide', $summaries['preview.f-tenant-wide']['data_access']['mode']);
        $tenantWideSql = $this->compiledSql($engine, $subject, 'preview.f-tenant-wide');
        self::assertStringContainsString('`preview_record`.`tenant_id`', $tenantWideSql);

        self::assertSame('conditional', $summaries['preview.g-conditional']['data_access']['mode']);
        $conditionalSql = $this->compiledSql($engine, $subject, 'preview.g-conditional');
        self::assertStringContainsString('`preview_record`.`tenant_id`', $conditionalSql);
        self::assertStringContainsString(' OR ', $conditionalSql);
        self::assertStringContainsString('`preview_record`.`created_by_member_id`', $conditionalSql);
        self::assertStringContainsString('EXISTS (', $conditionalSql);

        foreach (['preview.h-no-policy', 'preview.i-global-targeted'] as $resourceKey) {
            self::assertTrue($summaries[$resourceKey]['data_access']['runtime_decision_required']);
            self::assertSame(
                'AUTHZ_TARGET_CARDINALITY_INVALID',
                $this->engineError(fn() => $engine->queryConstraint($subject, $resourceKey, 'inspect')),
            );
        }
    }

    public function testPaginatesOperationsAndChangesTheDigestByPage(): void
    {
        $service = $this->service();

        $first = $service->preview($this->actor, $this->subjectMemberId, new PageRequest(1, 2));
        $second = $service->preview($this->actor, $this->subjectMemberId, new PageRequest(2, 2));

        self::assertSame(['preview.a-functional-denied', 'preview.b-any'], array_column(
            $first['data']['resource_operations'],
            'resource_key',
        ));
        self::assertSame(['preview.c-system', 'preview.d-platform'], array_column(
            $second['data']['resource_operations'],
            'resource_key',
        ));
        self::assertNotSame($first['data']['snapshot_revision'], $second['data']['snapshot_revision']);
        self::assertSame(2, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());

        $catalogRevision = $this->catalog->registryRevision();
        $this->loadCatalog('replacement');
        self::assertNotSame($catalogRevision, $this->catalog->registryRevision());
        $afterInstallationChange = $this->service()->preview(
            $this->actor,
            $this->subjectMemberId,
            new PageRequest(1, 2),
        );
        self::assertNotSame(
            $first['data']['snapshot_revision'],
            $afterInstallationChange['data']['snapshot_revision'],
        );
    }

    public function testInactiveMemberHasNoEffectiveRolesPermissionsOrPolicyGroups(): void
    {
        $this->database->exec(
            "UPDATE pa_tenant_member SET status = 'suspended' WHERE id = {$this->subjectMemberId}",
        );

        $result = $this->service()->preview(
            $this->actor,
            $this->subjectMemberId,
            new PageRequest(1, 100),
        );

        self::assertFalse($result['data']['member']['effective']);
        self::assertSame([], $result['data']['roles']);
        self::assertSame([], $result['data']['permission_keys']);
        foreach ($result['data']['resource_operations'] as $operation) {
            self::assertFalse($operation['functional_allowed']);
            self::assertSame('functional_denied', $operation['data_access']['mode']);
            self::assertSame([], $operation['data_access']['groups']);
        }
    }

    public function testUnknownOrCrossTenantMemberFailsWithoutAudit(): void
    {
        $otherTenant = $this->tenant('preview-beta');
        $otherMember = $this->member($otherTenant, $this->account('Other tenant member'), 'Other');
        $service = $this->service();

        foreach ([$otherMember, PHP_INT_MAX] as $memberId) {
            try {
                $service->preview($this->actor, $memberId, new PageRequest());
                self::fail('A member outside the current Tenant must not be previewed.');
            } catch (AdminAccessException $exception) {
                self::assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
            }
        }

        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
    }

    public function testAuditFailureRollsBackAndFailsTheRequest(): void
    {
        $this->database->exec('DROP TABLE pa_tenant_audit_event');

        $this->expectException(\think\db\exception\PDOException::class);
        $this->service()->preview($this->actor, $this->subjectMemberId, new PageRequest());
    }

    private function service(): EffectiveAccessPreviewService
    {
        return new EffectiveAccessPreviewService(
            $this->authorization,
            $this->catalog,
            $this->policies,
            new AuditService(),
            new PreviewClock(),
        );
    }

    private function engine(): DataPermissionEngine
    {
        $provider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('preview_record.tenant_id'),
                new ColumnReference('preview_record.created_by_member_id'),
                new ColumnReference('preview_record.department_id'),
                ['preview.project' => new ColumnReference('preview_record.project_id')],
            ),
            new ThinkPhpDepartmentHierarchyProvider(),
            $this->targetMembership,
            new ConditionProviderRegistry(),
        );
        $providers = new ResourceProviderRegistry();
        $providers->registerQuery('hidden.provider', $provider);

        return new DataPermissionEngine(
            $this->catalog,
            $this->policies,
            new TenantAuthorizationEvaluator($this->authorization),
            $providers,
            new TargetResolverRegistry(),
            new TargetCatalogProviderRegistry(),
            new SharedMasterScopeProviderRegistry(),
        );
    }

    private function compiledSql(
        DataPermissionEngine $engine,
        TenantContext $subject,
        string $resourceKey,
    ): string {
        $query = new Query($this->connection);
        $query->name('preview_record');
        $query->alias('preview_record');
        (new ThinkPhpQueryConstraintApplier(new \PeanutAdmin\Modules\Identity\access\Infrastructure\ThinkPhpTargetSetConstraintApplier($this->targetMembership)))->apply(
            $query,
            $engine->queryConstraint($subject, $resourceKey, 'inspect'),
        );

        return (string) (clone $query)->fetchSql()->select();
    }

    private function engineError(callable $operation): string
    {
        try {
            $operation();
            self::fail('The real data-permission engine was expected to deny this operation.');
        } catch (DataAuthorizationException $exception) {
            return $exception->errorCode;
        }
    }

    private function seedOperations(): void
    {
        $definitions = [
            ['preview.a-functional-denied', 'tenant_owned', 'rule_filtered', 'none', 'all', ['core.member.read', 'core.member.update']],
            ['preview.b-any', 'tenant_owned', 'rule_filtered', 'none', 'any', ['core.member.read', 'core.member.update']],
            ['preview.c-system', 'tenant_owned', 'system_internal', 'none', 'all', ['core.member.read']],
            ['preview.d-platform', 'platform_internal', 'tenant_wide', 'none', 'all', ['core.member.read']],
            ['preview.e-global', 'global_reference', 'global_reference_read', 'none', 'all', ['core.member.read']],
            ['preview.f-tenant-wide', 'tenant_owned', 'tenant_wide', 'none', 'all', ['core.member.read']],
            ['preview.g-conditional', 'tenant_owned', 'rule_filtered', 'none', 'all', ['core.member.read']],
            ['preview.h-no-policy', 'business_target_owned', 'explicit_targets', 'one_required', 'all', ['core.member.read']],
            ['preview.i-global-targeted', 'global_reference', 'global_reference_read', 'one_required', 'all', ['core.member.read']],
            ['preview.j-unbound', 'tenant_owned', 'rule_filtered', 'none', 'all', []],
        ];
        foreach (['preview.active', 'preview.unavailable'] as $moduleKey) {
            $this->insert('pa_module_installation', [
                'module_key' => $moduleKey,
                'installed_version' => '1.0.0',
                'manifest_schema_version' => 1,
                'manifest_digest' => hash('sha256', $moduleKey),
                'status' => 'active',
                'installed_at' => self::NOW,
                'activated_at' => self::NOW,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
        $this->licenseTenantModules($this->tenantId, ['preview.active'], 'active');
        $unavailable = [['preview.z-unavailable', 'tenant_owned', 'rule_filtered', 'none', 'all', ['core.member.read']]];
        foreach (['preview.active' => $definitions, 'preview.unavailable' => $unavailable] as $moduleKey => $resources) {
            $catalog = [];
            foreach ($resources as [$resourceKey, $ownership, $accessMode, $cardinality, $match, $permissions]) {
                $conditions = $resourceKey === 'preview.g-conditional' ? [
                    (object) ['key' => 'core.specified_objects', 'selector_resource_key' => 'preview.project'],
                    (object) ['key' => 'core.self'],
                ] : [];
                $catalog[] = (object) [
                    'key' => $resourceKey,
                    'ownership' => $ownership,
                    'provider' => 'hidden.provider',
                    'operations' => [(object) [
                        'key' => 'inspect',
                        'access_mode' => $accessMode,
                        'target_cardinality' => $cardinality,
                        'permission_match' => $match,
                        'permissions' => $permissions,
                        'target_types' => [],
                        'conditions' => $conditions,
                    ]],
                ];
            }
            $this->catalogManifests[] = ManifestDocument::fromObject('/fixtures/' . $moduleKey, (object) [
                'key' => $moduleKey,
                'tenant' => (object) ['enableable' => true],
                'catalog' => (object) ['protected_resources' => $catalog],
            ]);
        }
    }

    private function loadCatalog(string $revision): void
    {
        $registry = new CompiledModuleRegistry($this->catalogManifests, [], [], [], $revision);
        $this->catalog = new ThinkPhpResourceOperationCatalog(
            $registry,
            $this->fixtureEntitlements(['preview.active', 'preview.unavailable']),
        );
        $this->policies = new ThinkPhpPolicyRepository($this->catalog, $this->targetMembership);
    }

    private function seedConditionalPolicies(): void
    {
        $specified = [
            'id' => 1, 'key' => 'core.specified_objects', 'target_set_id' => 1,
            'target_resource_key' => 'preview.project', 'target_ids' => array_map('strval', range(1, 501)),
        ];
        $self = ['id' => 2, 'key' => 'core.self', 'target_set_id' => null, 'target_resource_key' => null, 'target_ids' => []];
        $this->policy($this->roleA, [['id' => 1, 'conditions' => [$specified]]]);
        $this->policy($this->roleB, [['id' => 2, 'conditions' => [$self]]]);

        foreach ([
            ['tenant.preview-future', 'active', '2099-01-01 00:00:00.000', null, true],
            ['tenant.preview-expired', 'active', null, '2020-01-01 00:00:00.000', true],
            ['tenant.preview-policy-disabled', 'disabled', null, null, true],
            ['tenant.preview-group-disabled', 'active', null, null, false],
        ] as [$key, $status, $from, $until, $groupActive]) {
            $roleId = $this->role($key, $key);
            $this->assignRole($roleId);
            $this->policy($roleId, $groupActive ? [['id' => 3, 'conditions' => [$self]]] : [], $status, $from, $until);
        }
    }

    /** @param list<array{id:int,conditions:list<array<string,mixed>>}> $groups */
    private function policy(
        int $roleId,
        array $groups,
        string $status = 'active',
        ?string $validFrom = null,
        ?string $validUntil = null,
    ): int {
        return $this->insert('pa_data_permission', [
            'tenant_id' => $this->tenantId,
            'role_id' => $roleId,
            'resource_key' => 'preview.g-conditional',
            'scope_type' => 'rules',
            'condition_groups_json' => json_encode($groups, JSON_THROW_ON_ERROR),
            'status' => $status,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'reason' => null,
            'created_by_member_id' => $this->actorMemberId,
            'updated_by_member_id' => $this->actorMemberId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
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
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function member(int $tenantId, int $accountId, string $name): int
    {
        return $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'display_name' => $name,
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function role(string $key, string $name, string $status = 'active'): int
    {
        return $this->insert('pa_role', [
            'tenant_id' => $this->tenantId,
            'key' => $key,
            'name' => $name,
            'status' => $status,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function assignRole(int $roleId): void
    {
        $this->insert('pa_member_role', [
            'tenant_id' => $this->tenantId,
            'tenant_member_id' => $this->subjectMemberId,
            'role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);
    }

    private function grant(int $roleId, string $permissionKey): void
    {
        $this->insert('pa_role_permission', [
            'tenant_id' => $this->tenantId,
            'role_id' => $roleId,
            'permission_id' => $this->permissionId($permissionKey),
            'granted_at' => self::NOW,
        ]);
    }

    private function permissionId(string $key): int
    {
        $statement = $this->database->prepare('SELECT id FROM pa_permission WHERE `key` = :key');
        $statement->execute(['key' => $key]);

        return (int) $statement->fetchColumn();
    }


}

final class PreviewClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-19T08:00:00Z');
    }
}
