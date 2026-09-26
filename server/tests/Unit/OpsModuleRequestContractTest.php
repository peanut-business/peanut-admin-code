<?php

declare(strict_types=1);

use app\command\OpsModuleRequest;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Ops\Contract\DeploymentModuleRequests;
use PeanutAdmin\Modules\Ops\Service\DeploymentModuleRequestService;
use PHPUnit\Framework\TestCase;
use think\console\Input;
use think\console\Output;

/** Native CLI plus synthetic port only; no preparation writes, package execution, resources or database. */
final class OpsModuleRequestContractTest extends TestCase
{
    public function testPortIsBoundWithoutPublishingInternalExecutionOrStorage(): void
    {
        self::assertSame(DeploymentModuleRequestService::class, (new \PeanutAdmin\Modules\Ops\ModuleProvider())->bindings()[DeploymentModuleRequests::class] ?? null);
        self::assertTrue(is_subclass_of(DeploymentModuleRequestService::class, DeploymentModuleRequests::class));
        self::assertSame(['preview','prepare'], array_map(static fn(ReflectionMethod $method): string => $method->name, (new ReflectionClass(DeploymentModuleRequests::class))->getMethods()));
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/ops/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(DeploymentModuleRequests::class, $manifest['contracts']['exports']);
        self::assertNotContains(DeploymentModuleRequestService::class, $manifest['contracts']['exports']);
    }

    public function testPreviewTrimsArgumentsAndUsesNullForOmittedOptionals(): void
    {
        $port = $this->createMock(DeploymentModuleRequests::class);
        $port->expects(self::once())->method('preview')->with('worker.id','target.id','retire','fixture.alpha',null,null)->willReturn(['operation' => 'retire', 'plan_digest' => str_repeat('a', 64)]);
        $port->expects(self::never())->method('prepare');
        [$exit, $output] = $this->invoke($port, ['preview','--delivery-resource-id= worker.id ','--target-resource-id= target.id ','--operation= retire ','--package-key= fixture.alpha ']);
        self::assertSame(0, $exit);
        self::assertSame(['ok' => true, 'result' => ['operation' => 'retire', 'plan_digest' => str_repeat('a', 64)]], $output);
    }

    public function testPreparationPassesExactDigestAndOptionalIdentitiesWithoutAuthorizingThem(): void
    {
        $digest = str_repeat('b', 64);
        $port = $this->createMock(DeploymentModuleRequests::class);
        $port->expects(self::once())->method('prepare')->with('worker.id','target.id','purge','fixture.alpha',str_repeat('a',64),'fixture-key',$digest)->willReturn(['request_key' => 'modreq_' . str_repeat('c',32)]);
        $port->expects(self::never())->method('preview');
        [$exit, $output] = $this->invoke($port, ['prepare','--delivery-resource-id=worker.id','--target-resource-id=target.id','--operation=purge','--package-key=fixture.alpha','--archive-sha256= ' . str_repeat('a',64) . ' ','--signature-key-id= fixture-key ','--confirm-plan-digest= ' . $digest . ' ']);
        self::assertSame(0, $exit);
        self::assertSame(['ok' => true,'result' => ['request_key' => 'modreq_' . str_repeat('c',32)]], $output);
    }

    public function testBlankPreparationDigestIsNotInvented(): void
    {
        $port = $this->createMock(DeploymentModuleRequests::class);
        $port->expects(self::once())->method('prepare')->with('','','','',null,null,null)->willThrowException(new RuntimeException('OPS_MODULE_CONFIRM_PLAN_REQUIRED'));
        [$exit,$output] = $this->invoke($port, ['prepare']);
        self::assertSame(1,$exit);
        self::assertSame(['ok'=>false,'error_code'=>'OPS_MODULE_CONFIRM_PLAN_REQUIRED'],$output);
    }

    public function testUnknownActionAndMissingDependencyNeverFallBackToConstructingTheService(): void
    {
        $port = $this->createMock(DeploymentModuleRequests::class);
        $port->expects(self::never())->method('preview');
        $port->expects(self::never())->method('prepare');
        [$exit,$output] = $this->invoke($port,['unknown']);
        self::assertSame(1,$exit);
        self::assertSame(['ok'=>false,'error_code'=>'OPS_MODULE_REQUEST_ACTION_INVALID'],$output);
        [$exit,$output] = $this->invoke(null,['preview']);
        self::assertSame(1,$exit);
        self::assertSame(['ok'=>false,'error_code'=>'OPS_MODULE_REQUEST_FAILED'],$output);
        $source = file_get_contents((new ReflectionClass(OpsModuleRequest::class))->getFileName());
        foreach (['new DeploymentModuleRequestService','new PluginRuntimeGovernanceService','Config::get','app('] as $locator) {
            self::assertStringNotContainsString($locator,$source);
        }
    }

    public function testStableFailureCodesSurviveAndOtherFailureDetailsAreRedacted(): void
    {
        foreach (['MODULE_CATALOG_EXTERNAL_PERMISSION_REFERENCE','PLUGIN_NOT_INSTALLED','PACKAGE_SIGNATURE_INVALID','OPS_MODULE_PREFLIGHT_BLOCKED','fixture://private/detail'] as $message) {
            $port = $this->createMock(DeploymentModuleRequests::class);
            $port->expects(self::once())->method('preview')->willThrowException(new RuntimeException($message));
            [$exit,$output] = $this->invoke($port,['preview']);
            self::assertSame(1,$exit);
            self::assertSame(['ok'=>false,'error_code'=>str_starts_with($message,'fixture:') ? 'OPS_MODULE_REQUEST_FAILED' : $message],$output);
        }
    }

    private function invoke(?DeploymentModuleRequests $port,array $arguments): array
    {
        $constructor = (new ReflectionClass(OpsModuleRequest::class))->getConstructor();
        self::assertCount(3,$constructor->getParameters()); // Guard legacy code before it could construct real services.
        self::assertSame(DeploymentModuleRequests::class,$constructor->getParameters()[2]->getType()->getName());
        $contexts = new ExecutionContextStore();
        $command = new OpsModuleRequest($contexts,new CurrentExecutionContext($contexts),$port);
        self::assertSame('ops-module:request',$command->getName());
        $output = new Output('buffer');
        $exit = $command->run(new Input($arguments),$output);
        self::assertTrue($contexts->isEmpty());
        return [$exit,json_decode($output->fetch(),true,512,JSON_THROW_ON_ERROR)];
    }
}
