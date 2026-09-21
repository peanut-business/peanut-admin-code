<?php
declare(strict_types=1);

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\dto\authorization\PermissionDecision;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Application\SettingAdminService;
use PeanutAdmin\Modules\Settings\Application\SettingResolver;
use PeanutAdmin\Modules\Settings\Service\SettingsHttpApplicationService;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeQuery;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeAdminService;
use PeanutAdmin\Modules\ReferenceCodes\Service\ReferenceCodesHttpApplicationService;
use PHPUnit\Framework\TestCase;

/** 直接调用真实公开用例，确认认证/权限在读取定义及事务前执行；不冒充数据库测试。 */
final class PublicSettingsAuthorizationTest extends TestCase
{
    public function testEveryPublicOperationRejectsMissingExecutionBeforePersistence(): void
    {
        $contexts = new ExecutionContextStore();
        $auth = $this->createMock(AdminAuthorizationQuery::class);
        $auth->expects(self::never())->method('decide');
        foreach ($this->operations($contexts, $auth, $this->tenant()) as $operation) {
            $this->denied($operation);
        }
        self::assertTrue($contexts->isEmpty());
    }

    public function testReadAndWriteUseFixedPermissionsAndRejectEvenActiveMembers(): void
    {
        $contexts = new ExecutionContextStore();
        $tenant = $this->tenant();
        $seen = [];
        $auth = $this->createMock(AdminAuthorizationQuery::class);
        $auth->expects(self::exactly(9))->method('decide')->willReturnCallback(
            function ($actual, $actor, string $permission) use ($tenant, &$seen): PermissionDecision {
                self::assertSame($tenant, $actual);
                self::assertSame(501, $actor->id);
                $seen[] = $permission;
                return PermissionDecision::deny($permission, 'PERMISSION_NOT_GRANTED');
            },
        );
        $contexts->run(new AdminExecutionContext($tenant, 'test.settings', $this->principal()), function () use ($contexts, $auth, $tenant): void {
            foreach ($this->operations($contexts, $auth, $tenant) as $operation) $this->denied($operation);
        });
        self::assertSame([
            'official.settings.read', 'official.settings.manage', 'official.settings.manage',
            'official.reference-codes.read', 'official.reference-codes.read', 'official.reference-codes.read',
            'official.reference-codes.manage', 'official.reference-codes.manage', 'official.reference-codes.manage',
        ], $seen);
        self::assertTrue($contexts->isEmpty());
    }

    public function testForeignTenantOrStaleContextCannotReachPermissionOrDataCalls(): void
    {
        $contexts = new ExecutionContextStore();
        $auth = $this->createMock(AdminAuthorizationQuery::class);
        $auth->expects(self::never())->method('decide');
        $contexts->run(new AdminExecutionContext($this->tenant(), 'test.settings', $this->principal()), function () use ($contexts, $auth): void {
            foreach ([$this->tenant(102), $this->tenant(101, 2)] as $supplied) {
                foreach ($this->operations($contexts, $auth, $supplied) as $operation) $this->denied($operation);
            }
        });
        self::assertTrue($contexts->isEmpty());
    }

    public function testAuthorizedSettingsReadPreservesThePublicResult(): void
    {
        $contexts = new ExecutionContextStore();
        $tenant = $this->tenant();
        $auth = $this->createMock(AdminAuthorizationQuery::class);
        $auth->expects(self::once())->method('decide')->willReturnCallback(static function ($context, $actor, $permission): PermissionDecision {
            self::assertSame('official.settings.read', $permission);
            return PermissionDecision::allow($permission);
        });
        [$settings] = $this->services($contexts, $auth);
        $result = $contexts->run(new AdminExecutionContext($tenant, 'test.settings', $this->principal()), fn() => $settings->list($tenant));
        self::assertSame(['items' => []], $result);
    }

    private function operations(ExecutionContextStore $contexts, AdminAuthorizationQuery $auth, TenantContext $tenant): array
    {
        [$settings, $reference] = $this->services($contexts, $auth);
        return [
            fn() => $settings->list($tenant),
            fn() => $settings->replace($tenant, 'official.settings', 'example', 'value', null, '*', 'test-key'),
            fn() => $settings->unset($tenant, 'official.settings', 'example', 'etag', 'test-key'),
            fn() => $reference->sets($tenant),
            fn() => $reference->list($tenant, 'official.reference-codes', 'example', []),
            fn() => $reference->get($tenant, 'official.reference-codes', 'example', 'a', null),
            fn() => $reference->create($tenant, 'official.reference-codes', 'example', [], 'test-key', '*'),
            fn() => $reference->replace($tenant, 'official.reference-codes', 'example', 'a', [], 'test-key', 'etag'),
            fn() => $reference->retire($tenant, 'official.reference-codes', 'example', 'a', 'test-key', 'etag'),
        ];
    }

    private function services(ExecutionContextStore $contexts, AdminAuthorizationQuery $auth): array
    {
        $empty = static fn(string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $idempotency = $this->createMock(IdempotentCommandExecutor::class);
        $idempotency->expects(self::never())->method('begin');
        $modules = $this->createMock(ModuleRuntimeRepository::class);
        $modules->expects(self::never())->method('tenantModule');
        $current = new CurrentExecutionContext($contexts);
        return [
            new SettingsHttpApplicationService(new SettingDefinitionRegistry(), $empty(SettingAdminService::class), $empty(SettingResolver::class), $idempotency, $modules, $current, $auth),
            new ReferenceCodesHttpApplicationService(new ReferenceCodeSetRegistry(), $empty(ReferenceCodeQuery::class), $empty(ReferenceCodeAdminService::class), $idempotency, $modules, $current, $auth),
        ];
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Public operation accepted an unauthorised actor.');
        } catch (BusinessException $error) {
            self::assertSame(403, $error->httpStatus);
            self::assertContains($error->errorCode, ['SETTING_PERMISSION_DENIED', 'REFERENCE_CODE_PERMISSION_DENIED']);
        }
    }

    private function tenant(int $tenantId = 101, int $revision = 1): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(1, '01J00000000000000000000000', $tenantId, 301, 501, 'admin-web', new DateTimeImmutable('2031-01-01T00:00:00Z'), $revision), 'settings-test');
    }

    private function principal(): array
    {
        return ['id' => 501, 'tenant_id' => 101, 'account_id' => 301, 'authorization_revision' => 1, 'root' => 0];
    }
}
