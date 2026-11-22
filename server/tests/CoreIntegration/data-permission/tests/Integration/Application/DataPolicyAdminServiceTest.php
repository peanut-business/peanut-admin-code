<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Application;

use DateTimeImmutable;
use PeanutAdmin\Modules\Identity\DataPermission\Application\DataPolicyAdminService;
use PeanutAdmin\DataPermission\Target\ResolvedResourceTargets;
use PeanutAdmin\DataPermission\Target\ResourceTargetResolver;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\DataPermission\Tests\Integration\Schema\DataPermissionMigrationRunner;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\DataPermission\Catalog\ThinkPhpResourceOperationCatalog;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__, 4) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once dirname(__DIR__) . '/Schema/DataPermissionMigrationRunner.php';

final class DataPolicyAdminServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-17 07:00:00.000';

    private DataPolicyAdminService $service;
    private TenantContext $context;
    private int $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        (new DataPermissionMigrationRunner($this->database))->migrate();
        (new CorePermissionCatalogSynchronizer(
            new ThinkPhpAuthorizationCatalogRepository(),
        ))->synchronize();
        $tenantId = $this->tenant();
        $accountId = $this->account();
        $memberId = $this->member($tenantId, $accountId);
        $this->roleId = $this->role($tenantId, 'tenant.reviewer');
        $this->insert('pa_member_role', [
            'tenant_id' => $tenantId, 'tenant_member_id' => $memberId,
            'role_id' => $this->roleId, 'assigned_at' => self::NOW,
        ]);
        $permissionCatalog = new ThinkPhpAuthorizationCatalogRepository();
        foreach (['core.role.read', 'core.role.data-policy.manage'] as $permission) {
            $this->insert('pa_role_permission', [
                'tenant_id' => $tenantId, 'role_id' => $this->roleId,
                'permission_id' => $permissionCatalog->permissionId($permission), 'granted_at' => self::NOW,
            ]);
        }
        $catalog = $this->catalog($tenantId);
        $registry = new TargetResolverRegistry();
        $registry->register('test.project-resolver', new TestProjectResolver());
        $this->service = new DataPolicyAdminService(
            $catalog,
            $registry,
            new AuditService(),
            new TenantAuthorizationEvaluator($this->fixtureAuthorizationRepository(['example.work-item', 'example.target'])),
        );
        $this->context = TenantContext::fromValidatedSession(
            new ValidatedTenantSession(
                1,
                '01J00000000000000000000000',
                $tenantId,
                $accountId,
                $memberId,
                'admin-web',
                new DateTimeImmutable('2026-07-17T07:00:00Z'),
                1,
            1),
            'req_policy_test',
        );
    }

    public function testReplacesAndReadsTypedPolicyAtomicallyWithRevisionAndAudit(): void
    {
        $policy = $this->service->replace(
            $this->context,
            $this->roleId,
            'example.work-item',
            'list',
            [
                'status' => 'active',
                'reason' => 'Review own tenant and selected projects',
                'groups' => [
                    [
                        'name' => 'Tenant access',
                        'conditions' => [['condition_key' => 'core.tenant_all']],
                    ],
                    [
                        'name' => 'Selected projects',
                        'conditions' => [[
                            'condition_key' => 'core.specified_objects',
                            'target_set' => [
                                'name' => 'Priority projects',
                                'target_resource_key' => 'example.project',
                                'targets' => [['target_id' => '9001'], ['target_id' => '9002']],
                            ],
                        ]],
                    ],
                ],
            ],
            null,
        );

        self::assertSame('1', $policy['revision']);
        self::assertSame(['9001', '9002'], array_column(
            $policy['groups'][1]['conditions'][0]['target_set']['targets'],
            'target_id',
        ));
        self::assertSame($policy, $this->service->get(
            $this->context->tenantId,
            $this->roleId,
            'example.work-item',
            'list',
        ));
        $this->expectAdminError('PRECONDITION_REQUIRED', fn(): array => $this->service->replace(
            $this->context,
            $this->roleId,
            'example.work-item',
            'list',
            ['status' => 'disabled', 'groups' => []],
            null,
        ));
        $this->expectAdminError('REVISION_MISMATCH', fn(): array => $this->service->replace(
            $this->context,
            $this->roleId,
            'example.work-item',
            'list',
            ['status' => 'disabled', 'groups' => []],
            99,
        ));

        $policy = $this->service->replace(
            $this->context,
            $this->roleId,
            'example.work-item',
            'list',
            [
                'status' => 'active',
                'reason' => 'Narrow selected project',
                'groups' => [[
                    'name' => 'Selected projects',
                    'conditions' => [[
                        'condition_key' => 'core.specified_objects',
                        'target_set' => [
                            'name' => 'Priority project',
                            'target_resource_key' => 'example.project',
                            'targets' => [['target_id' => '9002']],
                        ],
                    ]],
                ]],
            ],
            1,
        );

        self::assertSame('2', $policy['revision']);
        self::assertSame(['9002'], array_column(
            $policy['groups'][0]['conditions'][0]['target_set']['targets'],
            'target_id',
        ));
        self::assertSame('9002', $this->query(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(condition_groups_json, '$[0].conditions[0].target_ids[0]')) FROM pa_data_permission",
        )->fetchColumn());
        self::assertSame(2, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        self::assertSame(3, (int) $this->query(
            'SELECT authorization_revision FROM pa_role WHERE id = ' . $this->roleId,
        )->fetchColumn());
    }

    public function testRejectsUnknownConditionsConfigAndTargetsWithoutPartialWrites(): void
    {
        foreach ([
            [[
                'status' => 'active',
                'groups' => [[
                    'name' => 'Unknown',
                    'conditions' => [['condition_key' => 'core.unknown']],
                ]],
            ], 'DATA_POLICY_INPUT_INVALID'],
            [[
                'status' => 'active',
                'groups' => [[
                    'name' => 'Config injection',
                    'conditions' => [[
                        'condition_key' => 'core.tenant_all',
                        'config' => ['sql' => '1=1'],
                    ]],
                ]],
            ], 'DATA_POLICY_INPUT_INVALID'],
            [[
                'status' => 'active',
                'groups' => [[
                    'name' => 'Missing target',
                    'conditions' => [[
                        'condition_key' => 'core.specified_objects',
                        'target_set' => [
                            'name' => 'Missing',
                            'target_resource_key' => 'example.project',
                            'targets' => [['target_id' => '9999']],
                        ],
                    ]],
                ]],
            ], 'AUTHZ_TARGET_NOT_FOUND'],
        ] as [$payload, $errorCode]) {
            $this->expectAdminError($errorCode, fn(): array => $this->service->replace(
                $this->context,
                $this->roleId,
                'example.work-item',
                'list',
                $payload,
                null,
            ));
        }

        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_data_permission')->fetchColumn());
        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
    }

    private function catalog(int $tenantId): ThinkPhpResourceOperationCatalog
    {
        $this->insert('pa_module_installation', [
            'module_key' => 'example.work-item',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', 'example.work-item'),
            'status' => 'active',
            'installed_at' => self::NOW,
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_module_installation', [
            'module_key' => 'example.target',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', 'example.target'),
            'status' => 'active',
            'installed_at' => self::NOW,
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach (['example.work-item', 'example.target'] as $moduleKey) {
            $this->licenseTenantModules($tenantId, [$moduleKey], 'active');
        }
        $resources = ManifestDocument::fromObject('/fixtures/work-item', (object) [
            'key' => 'example.work-item',
            'catalog' => (object) ['protected_resources' => [(object) [
                'key' => 'example.work-item',
                'provider' => 'test.work-item-provider',
                'ownership' => 'business_target_owned',
                'operations' => [(object) [
                    'key' => 'list', 'access_mode' => 'rule_filtered',
                    'target_cardinality' => 'many_readable', 'permission_match' => 'all',
                    'permissions' => ['core.role.read'],
                    'target_types' => [(object) [
                        'target_role' => 'primary', 'target_resource_key' => 'example.project',
                        'input_mode' => 'explicit', 'policy_selection_permission' => 'core.role.read',
                    ]],
                    'conditions' => [
                        (object) ['key' => 'core.tenant_all'],
                        (object) ['key' => 'core.specified_objects', 'selector_resource_key' => 'example.project'],
                    ],
                ]],
            ]]],
        ]);
        $targets = ManifestDocument::fromObject('/fixtures/project', (object) [
            'key' => 'example.target',
            'catalog' => (object) ['target_types' => [(object) [
                'key' => 'example.project', 'name' => 'Project', 'resolver' => 'test.project-resolver',
                'catalog_provider' => 'test.project-catalog', 'id_format' => 'decimal',
            ]]],
        ]);
        return new ThinkPhpResourceOperationCatalog(
            new CompiledModuleRegistry([$resources, $targets], [], [], [], 'policy-fixture'),
            $this->fixtureEntitlements(['example.work-item', 'example.target']),
        );
    }

    private function tenant(): int
    {
        return $this->insert('pa_tenant', [
            'code' => 'policy-tenant',
            'name' => 'Policy Tenant',
            'display_name' => 'Policy Tenant',
            'status' => 'active',
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function account(): int
    {
        return $this->insert('pa_account', [
            'display_name' => 'Policy Administrator',
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
            'display_name' => 'Policy Administrator',
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function role(int $tenantId, string $key): int
    {
        return $this->insert('pa_role', [
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => 'Reviewer',
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    /** @param callable(): mixed $operation */
    private function expectAdminError(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (AdminAccessException|ModuleException $exception) {
            self::assertSame($code, $exception->errorCode);

            return;
        }

        self::fail("Expected {$code}.");
    }
}

final class TestProjectResolver implements ResourceTargetResolver
{
    public function resolveAndValidate(TenantContext $context, TypedResourceTargetSet $targets): ResolvedResourceTargets
    {
        if ($targets->targetResourceKey !== 'example.project'
            || array_diff($targets->targetIds, ['9001', '9002']) !== []) {
            throw new DataAuthorizationException('AUTHZ_TARGET_NOT_FOUND', 'Project target was not found.');
        }

        return new ResolvedResourceTargets(new TypedResourceTargetCollection([$targets]));
    }
}
