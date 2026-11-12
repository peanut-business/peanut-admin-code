<?php

declare(strict_types=1);

use app\common\composition\ModuleComposition;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry;

require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';

final class IndependentCompositionProvider implements ModuleProvider
{
    public function moduleKey(): string { return 'acme.independent'; }
    public function bindings(): array { return []; }
}

final class SelectedTaskCompositionProvider implements ModuleProvider
{
    public function moduleKey(): string { return 'peanut.task'; }
    public function bindings(): array { return []; }
}

$denyTask = static function (string $class): void {
    if (str_starts_with($class, 'PeanutAdmin\\Modules\\Task\\')) {
        throw new RuntimeException('Unselected Task source was loaded: ' . $class);
    }
};
$compose = static function (string $key, string $provider): think\App {
    $app = new think\App(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server'));
    $manifest = ManifestDocument::fromObject('/selected', (object) ['key' => $key, 'backend' => ['provider' => $provider]]);
    (new ModuleComposition($app))->register(new CompiledModuleRegistry([$manifest], [], [], [], 'selected'));
    return $app;
};
spl_autoload_register($denyTask, true, true);
try {
    $app = $compose('acme.independent', IndependentCompositionProvider::class);
    if ($app->bound(TaskWorkerDefinitionRegistry::class)) {
        throw new RuntimeException('Unselected Task acquired a worker registry');
    }
} finally {
    spl_autoload_unregister($denyTask);
}
$app = $compose('peanut.task', SelectedTaskCompositionProvider::class);
if (!$app->bound(TaskWorkerDefinitionRegistry::class)
    || !$app->make(TaskWorkerDefinitionRegistry::class) instanceof TaskWorkerDefinitionRegistry) {
    throw new RuntimeException('Selected Task lost its native worker registry');
}
echo "OPTIONAL-TASK-COMPOSITION PASS (absent source and selected registry)\n";
