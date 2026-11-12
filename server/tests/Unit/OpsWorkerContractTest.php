<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Ops\Contract\BackupTaskExecution;
use PeanutAdmin\Modules\Ops\Contract\RestoreTaskExecution;
use PeanutAdmin\Modules\Ops\Contract\ModuleTaskExecution;
use PeanutAdmin\Modules\Ops\Contract\UpgradeTaskExecution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\console\Input;
use think\console\Output;

/** Native CLI/context/container with synthetic worker ports; never runs a real task, backup or database. */
final class OpsWorkerContractTest extends TestCase
{
    public static function workers(): array
    {
        return [
            'backup' => [\app\command\OpsBackupTask::class, BackupTaskExecution::class, \PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpBackupTaskExecutionService::class, 'ops-backup:task', 'OPS_BACKUP'],
            'restore' => [\app\command\OpsRestoreTask::class, RestoreTaskExecution::class, \PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpRestoreTaskExecutionService::class, 'ops-restore:task', 'OPS_RESTORE'],
            'module' => [\app\command\OpsModuleTask::class, ModuleTaskExecution::class, \PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpModuleOperationTaskExecutionService::class, 'ops-module:task', 'OPS_MODULE'],
            'upgrade' => [\app\command\OpsUpgradeTask::class, UpgradeTaskExecution::class, \PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpUpgradeTaskExecutionService::class, 'ops-upgrade:task', 'OPS_UPGRADE'],
        ];
    }

    #[DataProvider('workers')]
    public function testOnlyWorkerPortIsPublicAndNativeBindingKeepsTheImplementation(string $command, string $port, string $implementation, string $name, string $prefix): void
    {
        $module = new \PeanutAdmin\Modules\Ops\ModuleProvider();
        self::assertSame($implementation, $module->bindings()[$port] ?? null);
        self::assertTrue(is_subclass_of($implementation, $port));
        $manifest = json_decode(file_get_contents(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/app/modules/peanut/ops/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains($port, $manifest['contracts']['exports']);
        self::assertNotContains($implementation, $manifest['contracts']['exports']);
        $app = new App((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/.local/tmp/ops-worker-contracts');
        $app->bind($module->bindings());
        $fake = $this->createMock($port);
        foreach ((new ReflectionClass($port))->getMethods() as $method) {
            $fake->expects(self::never())->method($method->name);
        }
        // A container fixture replaces only this exact private implementation. Nothing resolves its dependencies.
        $app->instance($implementation, $fake);
        self::assertSame($fake, $app->make($port));
    }

    #[DataProvider('workers')]
    public function testClaimUsesInjectedPortAndRestoresActualExecutionContext(string $type, string $port, string $implementation, string $name, string $prefix): void
    {
        $contexts = new ExecutionContextStore();
        $worker = $this->createMock($port);
        $worker->expects(self::once())->method('claim')->willReturnCallback(function () use ($contexts): ?array {
            self::assertNotNull($contexts->current());
            return null;
        });
        [$exit, $payload] = $this->withDatabaseIdentity(fn(): array =>
            $this->runCommand($type, $port, $worker, ['claim'], $contexts, $name));
        self::assertSame(0, $exit);
        self::assertSame(['ok' => true, 'result' => null], $payload);
        self::assertTrue($contexts->isEmpty());
    }

    public static function guardedWorkers(): array
    {
        return array_intersect_key(self::workers(), array_flip(['backup', 'restore']));
    }

    #[DataProvider('guardedWorkers')]
    public function testGuardedClaimRejectsAnotherDatabaseBeforeInvokingWorker(string $type, string $port, string $implementation, string $name, string $prefix): void
    {
        $worker = $this->createMock($port);
        $worker->expects(self::never())->method('claim');
        [$exit, $payload] = $this->withDatabaseIdentity(fn(): array =>
            $this->runCommand($type, $port, $worker, ['claim'], new ExecutionContextStore(), $name), false);
        self::assertSame(1, $exit);
        self::assertSame(['ok' => false, 'error_code' => $prefix . '_APPLICATION_CONNECTION_MISMATCH'], $payload);
    }

    #[DataProvider('workers')]
    public function testHeartbeatKeepsTaskKeyRevisionAndResult(string $type, string $port, string $implementation, string $name, string $prefix): void
    {
        $key = 'job_' . str_repeat('a', 32);
        $worker = $this->createMock($port);
        $worker->expects(self::once())->method('heartbeat')->with($key, 7)->willReturn(['task_key' => $key, 'status' => 'running', 'revision' => 7]);
        [$exit, $payload] = $this->runCommand($type, $port, $worker, ['heartbeat', '--task-key=' . $key, '--revision=7'], new ExecutionContextStore(), $name);
        self::assertSame(0, $exit);
        self::assertSame(['ok' => true, 'result' => ['task_key' => $key, 'status' => 'running', 'revision' => 7]], $payload);
    }

    #[DataProvider('workers')]
    public function testInvalidRevisionDoesNotReachWorker(string $type, string $port, string $implementation, string $name, string $prefix): void
    {
        $worker = $this->createMock($port);
        $worker->expects(self::never())->method('heartbeat');
        [$exit, $payload] = $this->runCommand($type, $port, $worker, ['heartbeat', '--task-key=job_' . str_repeat('a', 32), '--revision=0'], new ExecutionContextStore(), $name);
        self::assertSame(1, $exit);
        self::assertSame(['ok' => false, 'error_code' => $prefix . '_EXECUTION_REVISION_INVALID']
            + ($prefix === 'OPS_MODULE' ? ['exit_code' => 1] : []), $payload);
    }

    #[DataProvider('workers')]
    public function testFailureCodeAndRedactionRemainStable(string $type, string $port, string $implementation, string $name, string $prefix): void
    {
        $key = 'job_' . str_repeat('a', 32);
        $worker = $this->createMock($port);
        $worker->expects(self::once())->method('fail')->with($key, 3, $prefix . '_WORKER_FAILED')->willReturn(['status' => 'dead']);
        [$exit, $payload] = $this->runCommand($type, $port, $worker, ['fail', '--task-key=' . $key, '--revision=3', '--error-code=' . $prefix . '_WORKER_FAILED'], new ExecutionContextStore(), $name);
        self::assertSame(0, $exit);
        self::assertSame(['ok' => true, 'result' => ['status' => 'dead']], $payload);
        foreach (['mysql://fixture:private@host/db', $prefix . '_EXECUTION_FENCED'] as $message) {
            $contexts = new ExecutionContextStore();
            $failure = $this->createMock($port);
            $failure->expects(self::once())->method('heartbeat')->with($key, 3)->willThrowException(new RuntimeException($message));
            [$exit, $payload] = $this->runCommand($type, $port, $failure, ['heartbeat', '--task-key=' . $key, '--revision=3'], $contexts, $name);
            self::assertSame(1, $exit);
            self::assertSame(['ok' => false, 'error_code' => str_starts_with($message, $prefix) ? $message : $prefix . '_WORKER_FAILED']
                + ($prefix === 'OPS_MODULE' ? ['exit_code' => 1] : []), $payload);
            self::assertTrue($contexts->isEmpty());
        }
    }

    #[DataProvider('workers')]
    public function testMissingPortNeverFallsBackToAServiceLocator(string $type, string $port, string $implementation, string $name, string $prefix): void
    {
        [$exit, $payload] = $this->runCommand($type, $port, null, ['claim'], new ExecutionContextStore(), $name);
        self::assertSame(1, $exit);
        self::assertSame(['ok' => false, 'error_code' => $prefix . '_WORKER_FAILED']
            + ($prefix === 'OPS_MODULE' ? ['exit_code' => 1] : []), $payload);
        $source = file_get_contents((new ReflectionClass($type))->getFileName());
        self::assertStringNotContainsString('app(', $source);
        self::assertStringNotContainsString('Ops\\Infrastructure\\', $source);
    }

    #[DataProvider('workers')]
    public function testUnknownActionDoesNotInvokeAnyWorkerMethod(string $type, string $port, string $implementation, string $name, string $prefix): void
    {
        $worker = $this->createMock($port);
        foreach ((new ReflectionClass($port))->getMethods() as $method) {
            $worker->expects(self::never())->method($method->name);
        }
        [$exit, $payload] = $this->runCommand($type, $port, $worker, ['unknown'], new ExecutionContextStore(), $name);
        self::assertSame(1, $exit);
        self::assertSame(['ok' => false, 'error_code' => $prefix . ($prefix === 'OPS_MODULE' ? '_TASK_ACTION_INVALID' : '_ACTION_INVALID')]
            + ($prefix === 'OPS_MODULE' ? ['exit_code' => 1] : []), $payload);
    }

    public function testModuleAndUpgradeSpecificTransitionsKeepTheirExactInputs(): void
    {
        foreach (['module', 'upgrade'] as $kind) {
            [$type, $port, $implementation, $name] = self::workers()[$kind];
            foreach ($kind === 'module' ? ['advance','execute','succeed'] : ['advance','succeed'] as $action) {
                $key = 'job_' . str_repeat('b', 32);
                $worker = $this->createMock($port);
                $worker->expects(self::once())->method($action)->with($key, 9)->willReturn(['action' => $action]);
                [$exit, $payload] = $this->runCommand($type, $port, $worker, [$action, '--task-key=' . $key, '--revision=9'], new ExecutionContextStore(), $name);
                self::assertSame(0, $exit);
                self::assertSame(['ok' => true, 'result' => ['action' => $action]], $payload);
            }
        }
    }

    public function testNativeContainerInjectsThePublicWorkerAndSharedContext(): void
    {
        [$type, $port, $implementation, $name] = self::workers()['backup'];
        $app = new App((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/.local/tmp/ops-worker-injection');
        $contexts = new ExecutionContextStore();
        $worker = $this->createMock($port);
        $worker->expects(self::once())->method('heartbeat')->with('job_' . str_repeat('a', 32), 7)->willReturn([]);
        $app->instance(ExecutionContextStore::class, $contexts);
        $app->instance(CurrentExecutionContext::class, new CurrentExecutionContext($contexts));
        $app->instance($port, $worker);
        $parameters = (new ReflectionClass($type))->getConstructor()->getParameters();
        self::assertCount(3, $parameters); // Fail before executing legacy global-locator code.
        $command = $app->invokeClass($type);
        self::assertSame($worker, (new ReflectionProperty($command, 'service'))->getValue($command));
        $output = new Output('buffer');
        self::assertSame(0, $command->run(new Input(['heartbeat', '--task-key=job_' . str_repeat('a', 32), '--revision=7']), $output));
        self::assertTrue($contexts->isEmpty());
    }

    public function testLifecycleFixtureConsumesInitializedContainerWithoutDuplicatingTheConstructor(): void
    {
        $source = file_get_contents(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/fixtures/module-lifecycle/run.php');
        self::assertStringNotContainsString('SourceApplicationBackup', $source);
        self::assertStringContainsString('$app = (new think\\App($root . \'/server\'))->initialize();', $source);
        self::assertStringContainsString('memberUploadInstalledAssertions($app, $pdo,', $source);
        self::assertStringContainsString('taskImportExportInstalledAssertions($app, $pdo,', $source);
        self::assertStringContainsString('$guard();', $source);
        self::assertStringContainsString("'native_identity' => 'verified-current-session'", $source);
    }

    /** Exercise the real identity guard with a synthetic registry and no database connection. */
    private function withDatabaseIdentity(Closure $run, bool $matches = true): array
    {
        $previousApp = \think\Container::getInstance();
        $app = new App((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/.local/tmp/ops-worker-identity');
        $app->env->set([
            'APP_ENV' => 'development', 'DEPLOYMENT_MODE' => 'multi-tenant',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_NAME' => 'ops_worker_fixture',
            'DB_USER' => 'synthetic', 'DB_PASS' => 'synthetic',
        ]);
        $app->config->set(['default' => 'mysql', 'connections' => ['mysql' => [
            'type' => 'mysql', 'hostname' => '127.0.0.1', 'hostport' => '1',
            'database' => $matches ? 'ops_worker_fixture' : 'another_fixture',
        ]]], 'database');
        $directory = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/.local/tmp';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $registry = tempnam($directory, 'ops-worker-registry-');
        file_put_contents($registry, json_encode([
            'schema_version' => 1, 'project_id' => 'peanut-admin', 'authority' => ['role' => 'maintainer'],
            'resources' => ['databases' => [[
                'stable_resource_id' => 'ops-worker-fixture', 'environment' => 'development',
                'service_type' => 'mysql', 'fallback' => 'none', 'database' => 'ops_worker_fixture',
                'deployment_modes' => ['multi-tenant'],
                'upstream_endpoint' => ['endpoint_id' => 'ops-worker-fixture', 'host' => '127.0.0.1', 'port' => 1, 'consumers' => ['host']],
            ]]],
        ], JSON_THROW_ON_ERROR));
        $environment = [
            'PEANUT_RESOURCE_REGISTRY' => $registry, 'PEANUT_DATABASE_RESOURCE_ID' => 'ops-worker-fixture',
            'PEANUT_DEPLOYMENT_TARGET' => 'local-development', 'PEANUT_DATABASE_CONSUMER' => 'host',
            'PEANUT_DATABASE_ENDPOINT_ID' => 'ops-worker-fixture',
        ];
        $previous = [];
        foreach ($environment as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            return $run();
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
            unlink($registry);
            \think\Container::setInstance($previousApp);
        }
    }

    private function runCommand(string $type, string $port, ?object $worker, array $arguments, ExecutionContextStore $contexts, string $name): array
    {
        $constructor = (new ReflectionClass($type))->getConstructor();
        self::assertCount(3, $constructor->getParameters());
        self::assertSame($port, $constructor->getParameters()[2]->getType()->getName());
        $command = new $type($contexts, new CurrentExecutionContext($contexts), $worker);
        self::assertSame($name, $command->getName());
        $output = new Output('buffer');
        $exit = $command->run(new Input($arguments), $output);
        self::assertTrue($contexts->isEmpty());
        return [$exit, json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR)];
    }
}
