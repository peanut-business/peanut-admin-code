<?php

declare(strict_types=1);

use PeanutAdmin\Fixtures\DeliveryRecord\Contract\DeliveryRecordCommands;
use PeanutAdmin\Fixtures\DeliveryRecord\Controller\DeliveryRecordHttpHandler;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Module\ModuleException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function fixtureHttpExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fixtureHttpRejects(Closure $operation, string $errorCode): void
{
    try {
        $operation();
    } catch (ModuleException $exception) {
        fixtureHttpExpect(
            $exception->errorCode === $errorCode,
            "unexpected Module refusal: {$exception->errorCode}",
        );
        return;
    }
    throw new RuntimeException("expected Module refusal: {$errorCode}");
}

$context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
    31,
    '01JFIXTUREHTTPBOUNDARY0000001',
    11,
    21,
    31,
    'admin-web',
    new DateTimeImmutable('now', new DateTimeZone('UTC')),
    1,
), 'fixture-http-boundary');
$disabledCommands = new class implements DeliveryRecordCommands {
    public int $calls = 0;

    public function record(string $reference): array
    {
        ++$this->calls;
        throw new ModuleException('MODULE_TENANT_DISABLED', 'disabled');
    }

    public function list(): array
    {
        ++$this->calls;
        throw new ModuleException('MODULE_TENANT_DISABLED', 'disabled');
    }
};
$contexts = new ExecutionContextStore();
$handler = new DeliveryRecordHttpHandler($disabledCommands, new CurrentExecutionContext($contexts));
fixtureHttpRejects(
    static fn() => $contexts->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.fixture.delivery-record.list'),
        static fn() => $handler->lists(),
    ),
    'MODULE_TENANT_DISABLED',
);
fixtureHttpExpect($disabledCommands->calls === 1, 'Tenant Admin request did not reach the guarded Module command exactly once');
fixtureHttpExpect($contexts->isEmpty(), 'Tenant Admin failure leaked execution context');

$systemContext = new TenantSystemContext(11, 'fixture.system', 'list', 'fixture-system-1');
fixtureHttpRejects(
    static fn() => $contexts->run(
        new \app\common\execution\SystemExecutionContext($systemContext),
        static fn() => $handler->lists(),
    ),
    'CONTEXT_TENANT_REQUIRED',
);
fixtureHttpExpect($disabledCommands->calls === 1, 'system actor reached the Module command');
fixtureHttpExpect($contexts->isEmpty(), 'system actor refusal leaked execution context');

$routeSource = (string) file_get_contents(dirname(__DIR__, 2)
    . '/app/modules/fixture/delivery_record/route/app.php');
$routeBootstrap = (string) file_get_contents(dirname(__DIR__, 2)
    . '/route/fixture_delivery_record.php');
fixtureHttpExpect(
    substr_count($routeSource, 'LoginMiddleware::class') === 2,
    'fixture HTTP routes lost the Tenant Admin session guard',
);
fixtureHttpExpect(
    !str_contains($routeSource, 'AuthMiddleware::class'),
    'fixture HTTP route reintroduced the generic root-bypass permission middleware',
);
fixtureHttpExpect(
    str_contains($routeBootstrap, 'Http/routes.php'),
    'ThinkPHP route bootstrap lost the Module-owned routes',
);

echo "FIXTURE-MODULE-HTTP-BOUNDARY-001 passed\n";
