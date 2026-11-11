<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use app\common\composition\ModuleComposition;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerContributor;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry;
use think\App;

function workerExpect(bool $condition, string $message): void
{
    $GLOBALS['taskWorkerCompositionChecks'] = ($GLOBALS['taskWorkerCompositionChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FixtureWorkerHandler implements TaskHandler
{
    public function key(): string
    {
        return 'fixture.worker.handle';
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void {}
}

final class FixtureWorkerDefinition implements TaskWorkerDefinition
{
    public function ownerModuleKey(): string
    {
        return 'fixture.worker';
    }

    public function resourceKey(): string
    {
        return 'fixture.worker.resource';
    }

    public function operation(): string
    {
        return 'execute';
    }

    public function handlers(): array
    {
        return [new FixtureWorkerHandler()];
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        throw new LogicException('not used by composition harness');
    }
}

final class FixtureWorkerProvider implements ModuleProvider, TaskWorkerContributor
{
    public function moduleKey(): string
    {
        return 'fixture.worker';
    }

    public function bindings(): array
    {
        return [];
    }

    public function taskWorkerDefinitions(): array
    {
        return [FixtureWorkerDefinition::class];
    }
}

// Task is now optional: a worker-only Module must not construct Task's
// registry until the Task Module itself is part of the compiled selection.
final class FixtureTaskProvider implements ModuleProvider
{
    public function moduleKey(): string
    {
        return 'peanut.task';
    }

    public function bindings(): array
    {
        return [];
    }
}

$fixture = ManifestDocument::fromObject('/fixture/worker', (object) [
    'key' => 'fixture.worker',
    'backend' => (object) ['provider' => FixtureWorkerProvider::class],
]);
$withoutTaskApp = new App(dirname(__DIR__, 4));
(new ModuleComposition($withoutTaskApp))->register(new CompiledModuleRegistry([
    $fixture,
], [], [], [], 'without-task'));
workerExpect(!$withoutTaskApp->bound(TaskWorkerDefinitionRegistry::class),
    'optional Task worker registry was instantiated without its Task Module');

$registry = new CompiledModuleRegistry([
    $fixture,
    ManifestDocument::fromObject('/peanut/task', (object) [
        'key' => 'peanut.task',
        'backend' => (object) ['provider' => FixtureTaskProvider::class],
    ]),
], [], [], [], 'with-task');
$app = new App(dirname(__DIR__, 4));
(new ModuleComposition($app))->register($registry);
$definitions = $app->make(TaskWorkerDefinitionRegistry::class)->resolve($app);
workerExpect(count($definitions) === 1, 'compiled worker contribution missing');
workerExpect($definitions[0] instanceof FixtureWorkerDefinition, 'compiled worker definition type changed');
workerExpect(count([...$definitions[0]->handlers()]) === 1, 'compiled worker handler missing');

try {
    new TaskWorkerDefinitionRegistry([
        ['module_key' => 'fixture.worker', 'definition' => FixtureWorkerDefinition::class],
        ['module_key' => 'fixture.worker', 'definition' => FixtureWorkerDefinition::class],
    ]);
    throw new RuntimeException('duplicate worker definition was accepted');
} catch (ModuleException $exception) {
    workerExpect($exception->errorCode === 'MODULE_TASK_WORKER_INVALID', 'duplicate worker error changed');
}

try {
    (new TaskWorkerDefinitionRegistry([
        ['module_key' => 'fixture.other', 'definition' => FixtureWorkerDefinition::class],
    ]))->resolve(new App(dirname(__DIR__, 4)));
    throw new RuntimeException('worker owner mismatch was accepted');
} catch (ModuleException $exception) {
    workerExpect($exception->errorCode === 'MODULE_TASK_WORKER_INVALID', 'worker owner mismatch error changed');
}

fwrite(STDOUT, 'task worker composition harness: PASS (' . ($GLOBALS['taskWorkerCompositionChecks'] ?? 0) . " checks)\n");
