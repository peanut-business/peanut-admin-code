<?php

declare(strict_types=1);

use app\adminapi\controller\log\OperationLogController;
use app\adminapi\services\log\OperationLogApplicationService;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\PermissionDecision;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\PageResult;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\Request;

final class OperationLogExportPermissionTest extends TestCase
{
    private const PERMISSION = 'peanut.import-export.operation-log.export';

    public static function listRequests(): iterable
    {
        yield 'ordinary list without export authority' => [[], null, new PageResult([], 0, 1, 15), ['count' => 0]];
        yield 'export count denied' => [['export' => '1'], false, null, null];
        yield 'file generation denied' => [['export' => '2'], false, null, null];
        yield 'export count allowed' => [['export' => '1'], true, ['count' => 1], ['count' => 1]];
        yield 'file generation allowed' => [
            ['export' => '2', 'page_type' => '1', 'page_start' => '1', 'page_end' => '1'],
            true,
            ['url' => '/fixture/audit.xlsx', 'file_name' => 'audit.xlsx'],
            ['file_name' => 'audit.xlsx'],
        ];
    }

    #[DataProvider('listRequests')]
    public function testExportRequiresItsOwnPermissionBeforeTheLogService(
        array $query,
        ?bool $exportAllowed,
        PageResult|array|null $serviceResult,
        ?array $expectedData,
    ): void {
        if (!class_exists(AdminDirectoryQuery::class)) {
            self::markTestSkipped('Requires official module Identity.');
        }
        $serverRoot = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server';
        require_once $serverRoot . '/vendor/topthink/framework/src/helper.php';

        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1, 'operation-log-test-session', 101, 501, 601, 'admin-web',
            new DateTimeImmutable('2035-01-01'), 7, 1,
        ), 'operation-log-export-test');
        $contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($contexts);
        $modules = $this->createMock(ModuleRuntimeRepository::class);
        $modules->expects($exportAllowed === null ? self::never() : self::once())
            ->method('installation')->with('peanut.import-export')
            ->willReturn(new ModuleInstallationRecord('peanut.import-export', '1.0.0', 'active', 1, str_repeat('a', 64)));
        $modules->expects($exportAllowed === null ? self::never() : self::once())->method('tenantModule')
            ->with($tenant->tenantId, 'peanut.import-export', $tenant)
            ->willReturn(new TenantModuleRecord($tenant->tenantId, 'peanut.import-export', 'enabled', null, null, 1));
        $boundary = new ModuleExecutionBoundary($current, $modules, new AdminDirectoryQuery($current));

        $execution = new AdminExecutionContext($tenant, 'http.admin.get.log/lists', [
            'id' => $tenant->memberId,
            'tenant_id' => $tenant->tenantId,
            'account_id' => $tenant->accountId,
            'authorization_revision' => $tenant->authorizationRevision,
        ]);
        $authorization = $this->createMock(AdminAuthorizationQuery::class);
        if ($exportAllowed === null) {
            $authorization->expects(self::never())->method('decide');
        } else {
            $authorization->expects(self::once())->method('decide')
                ->with(
                    $tenant,
                    self::callback(static fn ($actor): bool => $actor->id === $tenant->memberId
                        && $actor->tenantId === $tenant->tenantId
                        && $actor->accountId === $tenant->accountId),
                    self::PERMISSION,
                )
                ->willReturn($exportAllowed
                    ? PermissionDecision::allow(self::PERMISSION)
                    : PermissionDecision::deny(self::PERMISSION, 'PERMISSION_NOT_GRANTED'));
        }

        $operationLogs = $this->createMock(OperationLogApplicationService::class);
        if ($exportAllowed === false) {
            // The application service owns both the log query and the XLSX writer.
            $operationLogs->expects(self::never())->method('lists');
        } else {
            $operationLogs->expects(self::once())->method('lists')->with($tenant, $query)->willReturn($serviceResult);
        }

        $previousContainer = Container::getInstance();
        try {
            $app = new App(sys_get_temp_dir() . '/peanut-operation-log-export-test');
            $app->instance(Request::class, (new Request())->withGet($query));
            $app->instance(CurrentExecutionContext::class, $current);
            $run = fn () => $contexts->run($execution, fn () =>
                (new OperationLogController($app, $operationLogs, $boundary, $authorization))->lists());

            if ($exportAllowed === false) {
                try {
                    $run();
                    self::fail('The synchronous export reached the log service without its action permission.');
                } catch (BusinessException $error) {
                    self::assertSame(403, $error->httpStatus);
                    self::assertSame('OPERATION_LOG_EXPORT_DENIED', $error->errorCode);
                }
                return;
            }

            $response = $run()->getData();
            self::assertSame(20000, $response['code']);
            foreach ($expectedData as $key => $value) {
                self::assertSame($value, $response['data'][$key]);
            }
        } finally {
            Container::setInstance($previousContainer);
        }
    }
}
