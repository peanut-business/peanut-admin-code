<?php

declare(strict_types=1);

use app\common\http\ApiProblemMapper;
use app\platform\validation\module\ModulePublicSurfacePolicy;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Logs\TenantDiagnosticAttributes;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceWindow;
use PeanutAdmin\Modules\Ops\Domain\Package;
use PeanutAdmin\Modules\Ops\Domain\Status\OpsStatusService;
use PeanutAdmin\Modules\Ops\Domain\Status\OpsStatusSnapshot;
use PeanutAdmin\Modules\Ops\Domain\Status\RuntimeStatusProvider;
use PeanutAdmin\Modules\Ops\Domain\Task\BackupRestoreProvider;
use PeanutAdmin\Modules\Ops\Domain\Task\BackupRestoreProviderRegistry;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTask;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskDispatcher;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskService;
use PeanutAdmin\Modules\Ops\Infrastructure\PairedBackupProvider;
use PeanutAdmin\Modules\Ops\Service\PlatformOpsApplicationService;
use PHPUnit\Framework\TestCase;

/** No task execution or runtime resources: public values and denied service paths only. */
final class OpsPublicBoundaryTest extends TestCase
{
    public function testExistingHostApiAndReturnedValuesAreDeclaredButStorageIsNot(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/ops/module.json'), true, 512, JSON_THROW_ON_ERROR);
        $exports = $manifest['contracts']['exports'];
        foreach ([OpsConsoleException::class, TenantDiagnosticAttributes::class,
            PlatformOpsApplicationService::class, MaintenanceWindow::class,
            OpsStatusSnapshot::class, OpsTask::class, BackupRestoreProvider::class,
            PairedBackupProvider::class] as $type) {
            self::assertContains($type, $exports);
        }
        foreach ($exports as $type) {
            self::assertFalse(ModulePublicSurfacePolicy::isInternalPersistence($type));
        }
        self::assertNotContains(\PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpBackupTaskExecutionService::class, $exports);
        self::assertNotContains(\PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpRestoreTaskExecutionService::class, $exports);
    }

    public function testStatusPermissionIsCheckedBeforeTheProviderRuns(): void
    {
        $context = $this->context();
        $permissions = $this->createMock(PlatformPermissionChecker::class);
        $permissions->expects(self::once())->method('allows')->with($context, Package::READ_PERMISSION)->willReturn(false);
        $provider = $this->createMock(RuntimeStatusProvider::class);
        $provider->expects(self::never())->method('snapshot');
        $this->expectException(OpsConsoleException::class);
        $this->expectExceptionMessage('OPS_PERMISSION_DENIED');
        (new OpsStatusService($permissions, $provider))->read($context);
    }

    public function testTaskPermissionIsCheckedBeforeDispatch(): void
    {
        $context = $this->context();
        $permissions = $this->createMock(PlatformPermissionChecker::class);
        $permissions->expects(self::once())->method('allows')->with($context, Package::BACKUP_PERMISSION)->willReturn(false);
        $dispatcher = $this->createMock(OpsTaskDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');
        $dispatcher->expects(self::never())->method('find');
        $this->expectException(OpsConsoleException::class);
        $this->expectExceptionMessage('OPS_PERMISSION_DENIED');
        (new OpsTaskService($permissions, new BackupRestoreProviderRegistry([]), $dispatcher))
            ->submitBackup($context, 'not-resolved', 'fixture-key');
    }

    public function testPublishedDescriptorContainsOnlyLogicalRegistrationValues(): void
    {
        $provider = new PairedBackupProvider();
        self::assertInstanceOf(BackupRestoreProvider::class, $provider);
        self::assertSame('peanut.paired-db-files', $provider->key());
        self::assertSame('peanut.backup.create', $provider->backupHandlerKey());
        self::assertSame('peanut.restore.verify', $provider->restoreHandlerKey());
        self::assertSame(['isolated-new-target'], $provider->restoreTargetKeys());
        self::assertSame(1, $provider->maximumAttempts());
        $methods = array_map(static fn(ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($provider))->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($methods);
        self::assertSame(['backupHandlerKey', 'key', 'maximumAttempts', 'restoreHandlerKey', 'restoreTargetKeys'], $methods);
    }

    public function testUnknownTenantDiagnosticsDoNotInventAnIdentity(): void
    {
        self::assertSame(['scope' => 'unavailable', 'tenant_id' => null, 'request_id' => ''], TenantDiagnosticAttributes::fromTenantContext(null));
    }

    public function testPublicExceptionKeepsStableStatusAndSafeHttpMapping(): void
    {
        $problem = (new ApiProblemMapper())->map(OpsConsoleException::denied());
        self::assertNotNull($problem);
        self::assertSame(403, $problem->httpStatus);
        self::assertSame(['error_code' => 'OPS_PERMISSION_DENIED'], $problem->data());
        self::assertSame('Operations request was rejected.', $problem->getMessage());
    }

    public function testMaintenanceValueRetainsItsPublicShapeAndRejectsEmptyDuration(): void
    {
        $value = new MaintenanceWindow('maintenance_' . str_repeat('a', 32), 'scheduled', 'fixture.maintenance', '2031-01-01T00:00:00.000Z', '2031-01-01T00:01:00.000Z', 1);
        self::assertSame(['maintenance_key', 'state', 'reason_key', 'starts_at', 'ends_at', 'revision'], array_keys($value->toPublicArray()));
        $this->expectException(InvalidArgumentException::class);
        new MaintenanceWindow('maintenance_' . str_repeat('a', 32), 'scheduled', 'fixture.maintenance', '2031-01-01T00:00:00.000Z', '2031-01-01T00:00:00.000Z', 1);
    }

    public function testTaskValueExcludesExecutionPayloadAndRejectsInvalidAttempts(): void
    {
        $value = new OpsTask('job_' . str_repeat('b', 32), Package::BACKUP_TASK_TYPE, 'queued', 0, 1, 1, null, '2031-01-01T00:00:00.000Z', '2031-01-01T00:00:00.000Z', '2031-01-01T00:00:00.000Z', null);
        self::assertArrayNotHasKey('payload', $value->toPublicArray());
        self::assertArrayNotHasKey('credentials', $value->toPublicArray());
        self::assertSame('queued', $value->toPublicArray()['status']);
        $this->expectException(InvalidArgumentException::class);
        new OpsTask('job_' . str_repeat('b', 32), Package::BACKUP_TASK_TYPE, 'queued', 2, 1, 1, null, '2031-01-01T00:00:00.000Z', '2031-01-01T00:00:00.000Z', '2031-01-01T00:00:00.000Z', null);
    }

    private function context(): PlatformContext
    {
        return PlatformContext::fromValidatedSession(new ValidatedPlatformSession(
            11,
            'fixture-platform-session',
            21,
            31,
            'platform-web',
            new DateTimeImmutable('2031-01-01T00:00:00Z'),
        ), 'fixture-ops-boundary');
    }
}
