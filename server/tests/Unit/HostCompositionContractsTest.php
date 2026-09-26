<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Status\OpsStatusService;
use PHPUnit\Framework\TestCase;
use think\App;

/** Synthetic trusted worker definitions and denied provider query; never executes handlers, IO or probes. */
final class HostCompositionContractsTest extends TestCase
{
    public function testExactReviewedCompositionTypesArePublishedNotTheirStores(): void
    {
        foreach (['task' => TaskWorkerDefinitionRegistry::class, 'ops' => PlatformPermissionChecker::class] as $module => $type) {
            $manifest = json_decode(file_get_contents(dirname(__DIR__,2) . '/app/modules/official/' . $module . '/module.json'),true,512,JSON_THROW_ON_ERROR);
            self::assertContains($type,$manifest['contracts']['exports']);
            self::assertNotContains(\PeanutAdmin\Modules\Ops\Domain\Package::class,$manifest['contracts']['exports']);
        }
    }

    public function testRegistryPreservesExactRegisteredInstanceWithoutExecutingOrAuthorizingIt(): void
    {
        $app = $this->app();
        $worker = $this->worker('fixture.alpha','fixture.alpha.records','handler.alpha');
        $app->instance(CompositionFixtureWorker::class,$worker);
        $registry = new TaskWorkerDefinitionRegistry([['module_key'=>'fixture.alpha','definition'=>CompositionFixtureWorker::class]]);
        self::assertSame([$worker],$registry->resolve($app));
    }

    public function testDuplicateDefinitionAndOwnerMismatchStillReject(): void
    {
        $row=['module_key'=>'fixture.alpha','definition'=>CompositionFixtureWorker::class];
        try {
            new TaskWorkerDefinitionRegistry([$row,$row]);
            self::fail('Duplicate definition was accepted.');
        } catch (ModuleException $exception) {
            self::assertSame('MODULE_TASK_WORKER_INVALID',$exception->errorCode);
        }
        $app=$this->app();
        $app->instance(CompositionFixtureWorker::class,$this->worker('fixture.beta','fixture.beta.records','handler.beta'));
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module Task worker owner is invalid.');
        (new TaskWorkerDefinitionRegistry([$row]))->resolve($app);
    }

    public function testAuthorizationAndHandlerKeysCannotCollide(): void
    {
        foreach ([['fixture.shared','fixture.shared','handler.alpha','handler.beta'],['fixture.alpha','fixture.beta','handler.shared','handler.shared']] as [$firstResource,$secondResource,$firstHandler,$secondHandler]) {
            $app=$this->app();
            $first=$this->worker('fixture.alpha',$firstResource,$firstHandler);
            $handler=$this->createMock(TaskHandler::class);
            $handler->method('key')->willReturn($secondHandler);
            $handler->expects(self::never())->method('handle');
            $second=new CompositionFixtureSecondWorker('fixture.beta',$secondResource,[$handler]);
            $app->instance(CompositionFixtureWorker::class,$first);
            $app->instance(CompositionFixtureSecondWorker::class,$second);
            try {
                (new TaskWorkerDefinitionRegistry([
                    ['module_key'=>'fixture.alpha','definition'=>CompositionFixtureWorker::class],
                    ['module_key'=>'fixture.beta','definition'=>CompositionFixtureSecondWorker::class],
                ]))->resolve($app);
                self::fail('Conflicting definitions were accepted.');
            } catch (ModuleException $exception) {
                self::assertSame('MODULE_TASK_WORKER_CONFLICT',$exception->errorCode);
            }
        }
    }

    public function testEmptyHandlerSetCannotLookLikeWorkingRegistration(): void
    {
        $app=$this->app();
        $app->instance(CompositionFixtureWorker::class,new CompositionFixtureWorker('fixture.alpha','fixture.alpha',[]));
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module Task worker has no handlers.');
        (new TaskWorkerDefinitionRegistry([['module_key'=>'fixture.alpha','definition'=>CompositionFixtureWorker::class]]))->resolve($app);
    }

    public function testProviderReadPermissionComesFromPublicCapabilityAndDenyPrecedesContributors(): void
    {
        self::assertSame('platform.ops.read',OpsStatusService::READ_PERMISSION);
        $context=\PeanutAdmin\Kernel\Context\PlatformContext::fromValidatedSession(new \PeanutAdmin\Kernel\Auth\ValidatedPlatformSession(11,'fixture-session',21,31,'platform-web',new DateTimeImmutable('2031-01-01T00:00:00Z')),'fixture-request');
        $permissions=$this->createMock(PlatformPermissionChecker::class);
        $permissions->expects(self::once())->method('allows')->with($context,'platform.ops.read')->willReturn(false);
        $contributor=$this->createMock(\app\platform\contract\provider\ProviderQualificationContributor::class);
        $contributor->expects(self::never())->method('subjects');
        $service=new \app\platform\services\provider\PlatformProviderQualificationService($permissions,[$contributor],str_repeat('x',32));
        $this->expectException(OpsConsoleException::class);
        $this->expectExceptionMessage('OPS_PERMISSION_DENIED');
        $service->snapshot($context);
    }

    private function worker(string $owner,string $resource,string $handlerKey): CompositionFixtureWorker
    {
        $handler=$this->createMock(TaskHandler::class);
        $handler->method('key')->willReturn($handlerKey);
        $handler->expects(self::never())->method('handle');
        return new CompositionFixtureWorker($owner,$resource,[$handler]);
    }

    private function app(): App
    {
        return new App(dirname(__DIR__,3) . '/.local/tmp/host-composition-contracts');
    }
}

class CompositionFixtureWorker implements TaskWorkerDefinition
{
    public function __construct(private string $owner,private string $resource,private array $handlers) {}
    public function ownerModuleKey(): string { return $this->owner; }
    public function resourceKey(): string { return $this->resource; }
    public function operation(): string { return 'read'; }
    public function handlers(): array { return $this->handlers; }
    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext { throw new LogicException('Fixture reauthorization must not run.'); }
}

final class CompositionFixtureSecondWorker extends CompositionFixtureWorker {}
