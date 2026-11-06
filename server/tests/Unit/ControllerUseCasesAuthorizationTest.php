<?php

declare(strict_types=1);

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\dto\authorization\PermissionDecision;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\services\CrontabCommandService;
use PeanutAdmin\Modules\Integration\Application\IntegrationAdminApplicationService;
use PeanutAdmin\Modules\Integration\Application\MachineIdentity;
use PeanutAdmin\Modules\Integration\Application\MachineIdentityService;
use PeanutAdmin\Modules\Integration\Application\MachineScopeCatalog;
use PeanutAdmin\Modules\Integration\Application\MachineScopeGrantPolicy;
use PeanutAdmin\Modules\Integration\Application\SessionSecurityService;
use PeanutAdmin\Modules\Integration\Application\WebhookDeliveryLogService;
use PeanutAdmin\Modules\Integration\Application\WebhookService;
use PeanutAdmin\Modules\Integration\Contract\IntegrationSecurityRepository;
use PeanutAdmin\Modules\Integration\Contract\MachineScopeGrantResolver;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Service\CrontabApplicationService;
use PeanutAdmin\Modules\Task\Service\TaskAdminApplicationService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\TestCase;

/** 管理 Controller 对应真实用例编排回归；数据库、文件及外部渠道边界使用受控替身。 */
final class ControllerUseCasesAuthorizationTest extends TestCase
{
    public function testIntegrationAdminUseCaseAuthorizesThenUsesTenantRepositoryBoundary(): void
    {
        $tenant = $this->tenant();
        $actor = $this->actor();
        $machine = new MachineIdentity(
            'machine_' . str_repeat('a', 32),
            'Worker',
            ['data.export.read'],
            'active',
            'pa_mi_prefix',
            '1234',
            null,
            null,
            1,
            '2026-09-21T00:00:00.000Z',
        );
        $repository = $this->createMock(IntegrationSecurityRepository::class);
        $repository->expects(self::once())->method('machines')->with(11)->willReturn([$machine]);
        $scopeResolver = $this->createStub(MachineScopeGrantResolver::class);
        $scopeResolver->method('grantableScopes')->willReturn(['data.export.read']);
        $machines = new MachineIdentityService(
            $repository,
            new MachineScopeGrantPolicy(new MachineScopeCatalog(['data.export.read']), $scopeResolver),
        );
        $service = new IntegrationAdminApplicationService(
            $this->allowingAuthorization($tenant, $actor, 'peanut.integration.machine.read'),
            $machines,
            $this->uninitialized(WebhookService::class),
            $this->uninitialized(WebhookDeliveryLogService::class),
            $this->uninitialized(SessionSecurityService::class),
        );

        self::assertSame([$machine], $service->machines($tenant, $actor));
    }

    public function testTaskExpressionUseCaseKeepsPermissionInsideService(): void
    {
        $tenant = $this->tenant();
        $actor = $this->actor();
        $store = new ExecutionContextStore();
        $service = new TaskAdminApplicationService(
            $this->allowingAuthorization($tenant, $actor, 'peanut.task.expression'),
            new CrontabApplicationService(new CrontabCommandService([], []),
                $this->uninitialized(\app\common\infrastructure\module\ModuleExecutionBoundary::class)),
            $this->createStub(TaskJobRuntime::class),
            new CurrentExecutionContext($store),
        );

        $dates = $store->run(
            new AdminExecutionContext($tenant, 'test.task.expression', $actor->toArray()),
            fn(): array => $service->previewExpression($tenant, $actor, '0 * * * *'),
        );
        self::assertCount(5, $dates);
        self::assertSame(1, $dates[0]['time']);
    }

    public function testIntegrationRejectsCrossTenantActorBeforeRepositoryCall(): void
    {
        $tenant = $this->tenant();
        $actor = $this->actor(12);
        $authorization = $this->createMock(AdminAuthorizationQuery::class);
        $authorization->expects(self::once())->method('decide')
            ->with($tenant, $actor, 'peanut.integration.machine.read')
            ->willReturn(PermissionDecision::deny('peanut.integration.machine.read', 'INVALID_TENANT_ADMIN_CONTEXT'));
        $repository = $this->createMock(IntegrationSecurityRepository::class);
        $repository->expects(self::never())->method('machines');
        $machines = new MachineIdentityService(
            $repository,
            new MachineScopeGrantPolicy(new MachineScopeCatalog(['data.export.read']), $this->createStub(MachineScopeGrantResolver::class)),
        );
        $service = new IntegrationAdminApplicationService(
            $authorization,
            $machines,
            $this->uninitialized(WebhookService::class),
            $this->uninitialized(WebhookDeliveryLogService::class),
            $this->uninitialized(SessionSecurityService::class),
        );
        $this->expectException(\PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException::class);
        $service->machines($tenant, $actor);
    }

    private function allowingAuthorization(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $permission,
    ): AdminAuthorizationQuery {
        $authorization = $this->createMock(AdminAuthorizationQuery::class);
        $authorization->expects(self::once())->method('decide')
            ->with($tenant, $actor, $permission)
            ->willReturn(PermissionDecision::allow($permission));
        return $authorization;
    }

    private function tenant(): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01J00000000000000000000000',
            11,
            21,
            31,
            'admin-web',
            new \DateTimeImmutable('2026-09-21T00:00:00Z'),
            7,
            1,
        ), 'request-controller-use-case');
    }

    private function actor(int $tenantId = 11): AdminPrincipal
    {
        return new AdminPrincipal(
            id: 31,
            tenantId: $tenantId,
            accountId: 21,
            tenantName: '测试租户',
            username: 'admin@example.test',
            nickname: '管理员',
            name: '管理员',
            avatar: '',
            root: false,
            switchableTenantCount: 1,
            roles: [],
            roleName: '管理员',
            authorizationRevision: 7,
            primaryDepartmentId: null,
            lastLoginAt: null,
        );
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function uninitialized(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
