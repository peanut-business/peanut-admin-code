<?php
declare(strict_types=1);

use app\api\services\IndexApplicationService;
use app\api\services\PcApplicationService;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\dto\authorization\PermissionDecision;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\services\CrontabCommandService;
use app\common\services\decoration\DecorationReadService;
use app\modules\official\article\contracts\PublicArticleQueries;
use app\modules\official\import_export\contracts\dto\AsyncExportOperation;
use app\modules\official\import_export\contracts\ImportExportCommands;
use app\modules\official\import_export\contracts\ImportExportQueries;
use app\modules\official\import_export\engine\Application\ImportExportException;
use app\modules\official\import_export\engine\Application\ImportExportService;
use app\modules\official\import_export\infrastructure\file\AppFileMediaGateway;
use app\modules\official\import_export\services\ImportExportAdminApplicationService;
use app\modules\official\integration\application\IntegrationAdminApplicationService;
use app\modules\official\integration\application\MachineIdentity;
use app\modules\official\integration\application\MachineIdentityService;
use app\modules\official\integration\application\MachineScopeCatalog;
use app\modules\official\integration\application\MachineScopeGrantPolicy;
use app\modules\official\integration\application\SessionSecurityService;
use app\modules\official\integration\application\WebhookDeliveryLogService;
use app\modules\official\integration\application\WebhookService;
use app\modules\official\integration\contracts\IntegrationSecurityRepository;
use app\modules\official\integration\contracts\MachineScopeGrantResolver;
use app\modules\official\notification\contracts\NotificationCommands;
use app\modules\official\notification\contracts\NotificationQueries;
use app\modules\official\notification\delivery\Application\NotificationInboxService;
use app\modules\official\notification\delivery\Application\NotificationMessage;
use app\modules\official\notification\delivery\Persistence\NotificationRepository;
use app\modules\official\notification\services\NotificationAdminApplicationService;
use app\modules\official\task\contracts\TaskJobRuntime;
use app\modules\official\task\services\CrontabApplicationService;
use app\modules\official\task\services\TaskAdminApplicationService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PHPUnit\Framework\TestCase;

/** 管理 Controller 对应真实用例编排回归；数据库、文件及外部渠道边界使用受控替身。 */
final class ControllerUseCasesAuthorizationTest extends TestCase
{
    public function testImportExportUseCaseBuildsFixedContextAndReturnsLowLevelResult(): void
    {
        $tenant = $this->tenant();
        $actor = $this->actor();
        $authorization = $this->allowingAuthorization(
            $tenant,
            $actor,
            'official.import-export.operations.create',
        );
        $expected = $this->exportOperation();
        $commands = $this->createMock(ImportExportCommands::class);
        $commands->expects(self::once())->method('submitExport')->willReturnCallback(
            function (AuthorizedOperationContext $context, string $provider, string $idempotency) use ($tenant, $expected): AsyncExportOperation {
                self::assertSame($tenant, $context->tenantContext);
                self::assertSame(ImportExportService::RESOURCE_KEY, $context->resourceKey);
                self::assertSame('create', $context->operation);
                self::assertSame([], $context->targets);
                self::assertSame('operation.logs', $provider);
                self::assertSame('idem-12345678', $idempotency);
                self::assertNotSame('', $context->authorizationBasisDigest);
                return $expected;
            },
        );
        $service = new ImportExportAdminApplicationService(
            $authorization,
            $commands,
            $this->createStub(ImportExportQueries::class),
            $this->uninitialized(AppFileMediaGateway::class),
        );

        self::assertSame($expected, $service->submitExport(
            $tenant,
            $actor,
            'operation.logs',
            'idem-12345678',
        ));
    }

    public function testImportExportRejectsCrossTenantActorBeforeLowLevelCall(): void
    {
        $tenant = $this->tenant();
        $foreignActor = $this->actor(12);
        $authorization = $this->createMock(AdminAuthorizationQuery::class);
        $authorization->expects(self::once())->method('decide')
            ->with($tenant, $foreignActor, 'official.import-export.operations.create')
            ->willReturn(PermissionDecision::deny('official.import-export.operations.create', 'INVALID_TENANT_ADMIN_CONTEXT'));
        $commands = $this->createMock(ImportExportCommands::class);
        $commands->expects(self::never())->method('submitExport');
        $service = new ImportExportAdminApplicationService(
            $authorization,
            $commands,
            $this->createStub(ImportExportQueries::class),
            $this->uninitialized(AppFileMediaGateway::class),
        );

        try {
            $service->submitExport($tenant, $foreignActor, 'operation.logs', 'idem-12345678');
            self::fail('跨租户人员不得获得导出操作上下文');
        } catch (ImportExportException $exception) {
            self::assertSame('IMPORT_EXPORT_PERMISSION_DENIED', $exception->problemCode);
        }
    }

    public function testImportExportPropagatesOriginalBusinessException(): void
    {
        $tenant = $this->tenant();
        $actor = $this->actor();
        $expected = ImportExportException::providerUnavailable();
        $commands = $this->createMock(ImportExportCommands::class);
        $commands->expects(self::once())->method('submitExport')->willThrowException($expected);
        $service = new ImportExportAdminApplicationService(
            $this->allowingAuthorization($tenant, $actor, 'official.import-export.operations.create'),
            $commands,
            $this->createStub(ImportExportQueries::class),
            $this->uninitialized(AppFileMediaGateway::class),
        );

        try {
            $service->submitExport($tenant, $actor, 'missing.provider', 'idem-12345678');
            self::fail('底层业务异常必须原样传播到 HTTP 映射边界');
        } catch (ImportExportException $exception) {
            self::assertSame($expected, $exception);
        }
    }

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
            $this->allowingAuthorization($tenant, $actor, 'official.integration.machine.read'),
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
            $this->allowingAuthorization($tenant, $actor, 'official.task.expression'),
            new CrontabApplicationService(new CrontabCommandService([], [])),
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

    public function testNotificationInboxUseCaseBuildsRecipientScopedContext(): void
    {
        $tenant = $this->tenant();
        $actor = $this->actor();
        $store = new ExecutionContextStore();
        $message = new NotificationMessage(
            'notice_' . str_repeat('b', 32),
            'template.test',
            1,
            'Subject',
            'Body',
            'unread',
            1,
            '2026-09-21T00:00:00.000Z',
            null,
            null,
            [],
        );
        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects(self::once())->method('inbox')
            ->with(11, 31, 'unread', 1, 20)
            ->willReturn(['items' => [$message], 'page' => 1, 'page_size' => 20, 'total' => 1]);
        $service = new NotificationAdminApplicationService(
            $this->allowingAuthorization($tenant, $actor, 'official.notification.inbox.read'),
            $this->createStub(NotificationQueries::class),
            $this->createStub(NotificationCommands::class),
            new NotificationInboxService($repository),
            new CurrentExecutionContext($store),
        );

        $result = $store->run(
            new AdminExecutionContext($tenant, 'test.notification.inbox', $actor->toArray()),
            fn(): array => $service->messages($tenant, $actor, 'unread', 1, 20),
        );
        self::assertSame([$message], $result['items']);
        self::assertSame(1, $result['total']);
    }

    public function testPcUseCaseRejectsMismatchedPublicOperationBeforeArticleQuery(): void
    {
        $articles = $this->createMock(PublicArticleQueries::class);
        $articles->expects(self::never())->method('infoCenter');
        $service = new PcApplicationService(
            $articles,
            $this->uninitialized(DecorationReadService::class),
            $this->uninitialized(IndexApplicationService::class),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('EXECUTION_PUBLIC_TENANT_CONTEXT_REQUIRED');
        $service->infoCenter(new TenantSystemContext(11, 'public', 'article.other', 'request-pc'));
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

    private function exportOperation(): AsyncExportOperation
    {
        return AsyncExportOperation::fromPublicArray([
            'operation_key' => 'iox_' . str_repeat('c', 32),
            'provider_key' => 'operation.logs',
            'direction' => 'export',
            'status' => 'queued',
            'revision' => 1,
        ]);
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function uninitialized(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
