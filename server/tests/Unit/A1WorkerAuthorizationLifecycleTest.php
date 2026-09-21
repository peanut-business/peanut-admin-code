<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\infrastructure\async\ModuleAwareTaskHandler;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\LeaseLostException;
use PeanutAdmin\Modules\Task\Contract\RetryableTaskException;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use PeanutAdmin\Modules\Task\Job\Execution\JobAuthorizationFence;
use PeanutAdmin\Modules\Task\Job\Execution\JobClaim;
use PeanutAdmin\Kernel\Async\AsyncAuthorizationRevalidator;
use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PHPUnit\Framework\TestCase;

final class A1MutableWorkerAuthorization implements AsyncAuthorizationRevalidator
{
    public int $calls = 0;
    public bool $revoked = false;
    public bool $narrowTargets = false;

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        ++$this->calls;
        if ($this->revoked) {
            throw new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
        }
        $targets = $envelope->requestedTargets;
        if ($this->narrowTargets && $targets !== []) {
            $first = $targets[0];
            $targets[0] = new RequestedTargetSet(
                $first->targetResourceKey,
                [$first->targetIds[0]],
                $first->targetRole,
            );
        }
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $envelope->memberId,
            'a1-worker-session',
            $envelope->tenantId,
            $envelope->accountId,
            $envelope->memberId,
            'admin-async-worker',
            new DateTimeImmutable('2026-09-21T00:00:00Z'),
            $this->calls,
        ), $envelope->traceId);

        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            $envelope->resourceKey,
            $envelope->operation,
            $targets,
            'a1-basis-' . $this->calls,
        ));
    }
}

final class A1MutableModuleRuntimeRepository implements ModuleRuntimeRepository
{
    /** @var array<string, string> */
    public array $statuses = [
        'official.task' => 'enabled',
        'official.import-export' => 'enabled',
    ];

    /** @var array<string, int> */
    public array $tenantReads = [];

    public function installation(string $moduleKey): ?ModuleInstallationRecord
    {
        return isset($this->statuses[$moduleKey])
            ? new ModuleInstallationRecord($moduleKey, '4.0.0-dev', 'active', 1, str_repeat('a', 64))
            : null;
    }

    public function tenantModule(int $tenantId, string $moduleKey): ?TenantModuleRecord
    {
        $this->tenantReads[$moduleKey] = ($this->tenantReads[$moduleKey] ?? 0) + 1;
        $status = $this->statuses[$moduleKey] ?? null;
        return $status === null ? null : new TenantModuleRecord(
            $tenantId,
            $moduleKey,
            $status,
            null,
            null,
            $this->tenantReads[$moduleKey],
        );
    }

    public function enabledDependents(int $tenantId, string $moduleKey): array
    {
        return [];
    }
}

final class A1WorkerAuthorizationLifecycleTest extends TestCase
{
    public function testAuthorizationRevocationStopsNextBatchWhileLeaseRemainsOwned(): void
    {
        [$authorization, $fence] = $this->authorizationFence();
        $counters = (object)['renew' => 0, 'lease' => 0];
        $execution = $this->execution($fence, $counters);

        $fence->handle(static fn(AuthorizedOperationContext $context): null => null);
        $execution->checkpoint();
        $authorization->revoked = true;

        try {
            $execution->checkpoint();
            self::fail('Revoked authorization must stop the next batch.');
        } catch (TaskJobException $exception) {
            self::assertSame('TASK_PERMISSION_DENIED', $exception->problemCode);
            self::assertNotInstanceOf(LeaseLostException::class, $exception);
            self::assertNotInstanceOf(RetryableTaskException::class, $exception);
        }

        self::assertSame(2, $counters->renew);
        self::assertSame(0, $counters->lease);
        self::assertSame(3, $authorization->calls);
    }

    public function testNarrowedTargetScopeIsRejectedByTheFinalFence(): void
    {
        [$authorization, $fence] = $this->authorizationFence([
            new RequestedTargetSet('official.order', ['order-a', 'order-b']),
        ]);
        $counters = (object)['renew' => 0, 'lease' => 0];
        $execution = $this->execution($fence, $counters);

        $fence->handle(static fn(AuthorizedOperationContext $context): null => null);
        $execution->checkpoint();
        $authorization->narrowTargets = true;

        try {
            $execution->assertLeaseOwned();
            self::fail('A final fence cannot continue with the broader original target scope.');
        } catch (TaskJobException $exception) {
            self::assertSame('TASK_PERMISSION_DENIED', $exception->problemCode);
        }

        self::assertSame(1, $counters->renew);
        self::assertSame(1, $counters->lease);
    }

    public function testUnchangedAuthorizationAllowsMultipleBatchesAndFinalFence(): void
    {
        [$authorization, $fence] = $this->authorizationFence();
        $counters = (object)['renew' => 0, 'lease' => 0];
        $execution = $this->execution($fence, $counters);

        $fence->handle(static fn(AuthorizedOperationContext $context): null => null);
        $execution->checkpoint();
        $execution->checkpoint();
        $execution->checkpoint();
        $execution->assertLeaseOwned();

        self::assertSame(3, $counters->renew);
        self::assertSame(1, $counters->lease);
        self::assertSame(5, $authorization->calls);
    }

    public function testOwningModuleDisabledBetweenBatchesStopsExecutionAndRestoresContext(): void
    {
        [$authorization, $fence] = $this->authorizationFence();
        $counters = (object)['renew' => 0, 'lease' => 0];
        $execution = $this->execution($fence, $counters);
        $contexts = new ExecutionContextStore();
        $modules = new A1MutableModuleRuntimeRepository();
        $handler = new class($contexts, $modules) implements TaskHandler {
            public int $batches = 0;

            public function __construct(
                private ExecutionContextStore $contexts,
                private A1MutableModuleRuntimeRepository $modules,
            ) {
            }

            public function key(): string
            {
                return 'a1.worker.module-disable';
            }

            public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
            {
                if (!$this->contexts->current() instanceof SystemExecutionContext) {
                    throw new RuntimeException('A1_SYSTEM_CONTEXT_REQUIRED');
                }
                $execution->checkpoint();
                ++$this->batches;
                $this->modules->statuses['official.import-export'] = 'disabled';
                $execution->checkpoint();
                ++$this->batches;
            }
        };
        $moduleAware = $this->moduleAware($contexts, $modules, $handler);

        try {
            $fence->handle(fn(AuthorizedOperationContext $context) => $moduleAware->handle($context, $execution));
            self::fail('A disabled owning Module must stop the next batch.');
        } catch (TaskJobException $exception) {
            self::assertSame('TASK_PERMISSION_DENIED', $exception->problemCode);
        }

        self::assertSame(1, $handler->batches);
        self::assertTrue($contexts->isEmpty());
        self::assertGreaterThanOrEqual(3, $modules->tenantReads['official.import-export']);
        self::assertSame(4, $authorization->calls);
    }

    public function testHandlerExceptionRestoresNestedSystemExecutionContext(): void
    {
        [, $fence] = $this->authorizationFence();
        $counters = (object)['renew' => 0, 'lease' => 0];
        $execution = $this->execution($fence, $counters);
        $contexts = new ExecutionContextStore();
        $modules = new A1MutableModuleRuntimeRepository();
        $handler = new class($contexts) implements TaskHandler {
            public function __construct(private ExecutionContextStore $contexts)
            {
            }

            public function key(): string
            {
                return 'a1.worker.context-exception';
            }

            public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
            {
                if (!$this->contexts->current() instanceof SystemExecutionContext) {
                    throw new RuntimeException('A1_SYSTEM_CONTEXT_REQUIRED');
                }
                $execution->checkpoint();
                if (!$this->contexts->current() instanceof SystemExecutionContext) {
                    throw new RuntimeException('A1_NESTED_CONTEXT_NOT_RESTORED');
                }
                throw new RuntimeException('A1_HANDLER_FAILURE');
            }
        };
        $moduleAware = $this->moduleAware($contexts, $modules, $handler);

        try {
            $fence->handle(fn(AuthorizedOperationContext $context) => $moduleAware->handle($context, $execution));
            self::fail('The handler fixture must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('A1_HANDLER_FAILURE', $exception->getMessage());
        }

        self::assertTrue($contexts->isEmpty());
    }

    public function testModuleWrapperFinalFenceRejectsAuthorizationRevokedAfterHandlerReturns(): void
    {
        [$authorization, $fence] = $this->authorizationFence();
        $counters = (object)['renew' => 0, 'lease' => 0];
        $execution = $this->execution($fence, $counters);
        $contexts = new ExecutionContextStore();
        $modules = new A1MutableModuleRuntimeRepository();
        $handler = new class($authorization) implements TaskHandler {
            public bool $completed = false;

            public function __construct(private A1MutableWorkerAuthorization $authorization)
            {
            }

            public function key(): string
            {
                return 'a1.worker.final-fence';
            }

            public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
            {
                $execution->checkpoint();
                $this->completed = true;
                $this->authorization->revoked = true;
            }
        };
        $moduleAware = $this->moduleAware($contexts, $modules, $handler);

        try {
            $fence->handle(fn(AuthorizedOperationContext $context) => $moduleAware->handle($context, $execution));
            self::fail('Result delivery must reauthorize after the handler returns.');
        } catch (TaskJobException $exception) {
            self::assertSame('TASK_PERMISSION_DENIED', $exception->problemCode);
        }

        self::assertTrue($handler->completed);
        self::assertSame(1, $counters->lease);
        self::assertTrue($contexts->isEmpty());
    }

    /**
     * @param list<RequestedTargetSet> $targets
     * @return array{A1MutableWorkerAuthorization, JobAuthorizationFence}
     */
    private function authorizationFence(array $targets = []): array
    {
        $authorization = new A1MutableWorkerAuthorization();
        $codec = new TrustedEnvelopeCodec('a1-worker-signing-key-that-is-at-least-32-bytes');
        $submitted = $this->submittedContext($targets);
        $jobKey = 'job_a1_worker_authorization';
        $claim = new JobClaim(
            1,
            $jobKey,
            101,
            'a1.worker.handler',
            [],
            $codec->issue($submitted, $jobKey, 'trace-a1-worker'),
            1,
            3,
            str_repeat('b', 64),
        );
        $fence = new JobAuthorizationFence(new JobHandlerAdapter($codec, $authorization), $claim);

        return [$authorization, $fence];
    }

    /** @param list<RequestedTargetSet> $targets */
    private function submittedContext(array $targets): AuthorizedOperationContext
    {
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            501,
            'a1-submission-session',
            101,
            111,
            501,
            'admin-web',
            new DateTimeImmutable('2026-09-21T00:00:00Z'),
            1,
        ), 'trace-a1-worker');

        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            'official.import-export.operation',
            'create',
            $targets,
            'a1-submission-basis',
        ));
    }

    private function execution(JobAuthorizationFence $fence, object $counters): JobExecution
    {
        return new JobExecution(
            'job_a1_worker_authorization',
            101,
            1,
            [],
            static function () use ($counters): void {
                ++$counters->renew;
            },
            static function () use ($counters): void {
                ++$counters->lease;
            },
            static fn() => $fence->assertAuthorized(),
        );
    }

    private function moduleAware(
        ExecutionContextStore $contexts,
        A1MutableModuleRuntimeRepository $modules,
        TaskHandler $handler,
    ): ModuleAwareTaskHandler {
        return new ModuleAwareTaskHandler(
            new ModuleExecutionBoundary(new CurrentExecutionContext($contexts), $modules),
            $contexts,
            'official.import-export',
            $handler,
        );
    }
}
