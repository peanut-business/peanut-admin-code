<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\services\CrontabCommandService;
use PeanutAdmin\Modules\File\Service\FileAdministrationService;
use PeanutAdmin\Modules\Task\Service\CrontabApplicationService;
use PeanutAdmin\Modules\Task\Validation\CrontabValidate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FileTaskInputBoundaryTest extends TestCase
{
    public function testMaterialIdsPreserveValidIdentifiersAndRejectCoercion(): void
    {
        $type = new ReflectionClass(FileAdministrationService::class);
        $service = $type->newInstanceWithoutConstructor();
        $normalize = $type->getMethod('normalizeIds');
        self::assertSame([1, 2], $normalize->invoke($service, [1, '2', 1]));
        foreach ([['1junk'], [true], [1.5], [[1]], ['9223372036854775808'], [0], []] as $ids) {
            try {
                $normalize->invoke($service, $ids);
                self::fail('Invalid material identifiers were accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSchedulesOnlyAcceptCommandsThatTheWorkerCanExecute(): void
    {
        $commands = new CrontabCommandService(
            ['crontab:demo' => \stdClass::class, 'module:compile' => \stdClass::class],
            ['crontab:demo' => 'peanut.task'],
        );
        $input = ['name' => 'Boundary check', 'type' => 1, 'command' => 'crontab:demo',
            'status' => 2, 'expression' => '* * * * *'];
        self::assertTrue((new CrontabValidate($commands))->scene('add')->check($input));
        foreach (['module:compile', 'crontab', 'unknown'] as $command) {
            $input['command'] = $command;
            self::assertFalse((new CrontabValidate($commands))->scene('add')->check($input));
            foreach (['add', 'edit'] as $action) {
                try {
                    (new CrontabApplicationService($commands))->{$action}($input + ['id' => 1]);
                    self::fail('A non-tenant command reached schedule persistence.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('定时任务命令', $error->getMessage());
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testScheduledCommandExitCodeIsNotDiscarded(): void
    {
        $app = new \think\App();
        $config = new \think\Config();
        $config->set(['signing_key' => str_repeat('x', 32)], 'async');
        $app->instance('config', $config);
        foreach ([
            \app\common\execution\ExecutionContextStore::class,
            \app\common\execution\CurrentExecutionContext::class,
            \PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery::class,
            \app\common\infrastructure\module\ModuleExecutionBoundary::class,
            CrontabCommandService::class,
            \PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog::class,
            \PeanutAdmin\Modules\Identity\Module\TenantEntitlementService::class,
        ] as $type) {
            $reflection = new ReflectionClass($type);
            $app->instance($type, $reflection->isInterface() ? $this->createStub($type) : $reflection->newInstanceWithoutConstructor());
        }
        $app->instance(\PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry::class,
            new \PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry([]));
        $console = new class {
            public int $exitCode = 0;
            public function find(string $name): \think\console\Command
            {
                return new class ($this->exitCode) extends \think\console\Command {
                    public function __construct(private readonly int $exitCode)
                    {
                        parent::__construct();
                    }
                    protected function configure()
                    {
                        $this->setName('crontab:demo');
                        $this->addArgument('command', \think\console\input\Argument::REQUIRED);
                    }
                    protected function execute(\think\console\Input $input, \think\console\Output $output)
                    {
                        return $this->exitCode;
                    }
                };
            }
        };
        $app->instance('console', $console);
        $bindings = (new \PeanutAdmin\Modules\Task\ModuleProvider())->bindings();
        $runtime = $bindings[\PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class]($app);
        $dispatch = (new \ReflectionProperty($runtime, 'dispatch'))->getValue($runtime);
        self::assertInstanceOf(\think\console\Output::class, $dispatch('crontab:demo'));
        $console->exitCode = 1;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CRONTAB_COMMAND_FAILED');
        $dispatch('crontab:demo');
    }
}
