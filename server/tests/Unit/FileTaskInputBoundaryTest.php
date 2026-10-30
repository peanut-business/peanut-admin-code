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
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testStableReferencesRejectUnavailableObjectsAsInvalidInput(): void
    {
        $app = new \think\App();
        $database = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($database);
        $database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT);
            INSERT INTO pa_tenant VALUES (1,'active'),(2,'active');
            CREATE TABLE pa_storage_account (id INTEGER PRIMARY KEY, account_key TEXT, driver TEXT, name TEXT, credential_ciphertext TEXT, credential_key_version INTEGER, status TEXT);
            INSERT INTO pa_storage_account VALUES (1,'local','local','Local',NULL,1,'active');
            CREATE TABLE pa_storage_space (id INTEGER PRIMARY KEY, account_id INTEGER, space_key TEXT, name TEXT, bucket TEXT, region TEXT, endpoint TEXT, access_domain TEXT, local_path TEXT, status TEXT);
            INSERT INTO pa_storage_space VALUES (1,1,'public','Public',NULL,NULL,NULL,NULL,'public/storage','active');
            CREATE TABLE pa_file_object (id INTEGER PRIMARY KEY, file_key TEXT, tenant_id INTEGER, access_type TEXT, storage_space_id INTEGER, object_key TEXT, status TEXT, purpose TEXT);
            INSERT INTO pa_file_object VALUES
                (1,'file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'public',1,'tenants/v1/1/public.png','ready','material.image'),
                (2,'file_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',1,'private',1,'tenants/v1/1/private.png','ready','document.resource'),
                (3,'file_cccccccccccccccccccccccccccccccc',1,'public',1,'tenants/v1/1/sensitive.png','ready','attachment.sensitive');
            SQL);
        $type = new ReflectionClass(\PeanutAdmin\Modules\File\Service\Storage\StorageService::class);
        $storage = $type->newInstanceWithoutConstructor();
        $type->getProperty('directory')->setValue($storage, new \PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery(
            new \app\common\execution\CurrentExecutionContext(new \app\common\execution\ExecutionContextStore()),
        ));
        $publicKey = 'file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        self::assertSame($publicKey, $storage->normalizePublicReference(1, $publicKey));
        self::assertSame('', $storage->normalizePublicReference(1, ''));
        foreach ([
            [2, $publicKey],
            [1, 'file_dddddddddddddddddddddddddddddddd'],
            [1, 'file_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
            [1, 'file_cccccccccccccccccccccccccccccccc'],
            [1, 'https://example.invalid/delivery'],
        ] as [$tenantId, $reference]) {
            try {
                $storage->normalizePublicReference($tenantId, $reference);
                self::fail('Invalid resource reference was accepted.');
            } catch (\app\common\exception\BusinessException $error) {
                self::assertSame('FILE_REFERENCE_INVALID', $error->errorCode);
                self::assertSame(400, $error->httpStatus);
            }
        }
    }

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
