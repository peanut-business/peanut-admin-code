<?php
declare(strict_types=1);

use app\command\OpsModuleTask;
use app\AppService;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\service\audit\AuditContractHost;
use app\platform\service\ops\DeploymentModuleRequestService;
use app\platform\service\ops\ThinkPhpModuleOperationTaskExecutionService;
use app\platform\service\plugin\ModuleCatalogApplier;
use think\App;
use think\console\Input;
use think\console\Output;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectOpsModuleWiring(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$app = new App(dirname(__DIR__, 2));
$contexts = new ExecutionContextStore();
$current = new CurrentExecutionContext($contexts);
$audit = (new ReflectionClass(AuditContractHost::class))->newInstanceWithoutConstructor();
$trustedKey = str_repeat('k', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
$app->config->set(['official.article' => ['root' => 'app/modules/official/article']], 'modules');
$app->config->set(['trusted_ed25519_keys' => ['c01-test' => base64_encode($trustedKey)]], 'module_packages');
$app->instance(ExecutionContextStore::class, $contexts);
$app->instance(CurrentExecutionContext::class, $current);
$app->instance(AuditContractHost::class, $audit);
$app->instance(
    ModuleCatalogApplier::class,
    (new ReflectionClass(ModuleCatalogApplier::class))->newInstanceWithoutConstructor(),
);
$appService = new AppService($app);
$registerPlatform = new ReflectionMethod(AppService::class, 'registerPlatform');
$registerPlatform->invoke($appService);

$registeredService = $app->make(ThinkPhpModuleOperationTaskExecutionService::class);
$command = $app->make(OpsModuleTask::class);
$serviceProperty = new ReflectionProperty(OpsModuleTask::class, 'service');
expectOpsModuleWiring(
    $serviceProperty->getValue($command) === $registeredService
        && $registeredService === $app->make(ThinkPhpModuleOperationTaskExecutionService::class),
    'OpsModuleTask did not receive the AppService-owned execution service',
);
$requests = $app->make(DeploymentModuleRequestService::class);
$moduleConfigProperty = new ReflectionProperty(DeploymentModuleRequestService::class, 'moduleConfig');
expectOpsModuleWiring(
    $moduleConfigProperty->getValue($requests) === ['official.article' => ['root' => 'app/modules/official/article']],
    'AppService did not provide Module configuration to the request service',
);
$trustedKeysProperty = new ReflectionProperty(DeploymentModuleRequestService::class, 'trustedKeys');
expectOpsModuleWiring(
    $trustedKeysProperty->getValue($requests) === ['c01-test' => $trustedKey],
    'AppService did not decode trusted Module keys for the request service',
);
$source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/command/OpsModuleTask.php');
expectOpsModuleWiring(!str_contains($source, 'RuntimeFactory'), 'OpsModuleTask retained Runtime factory construction');
expectOpsModuleWiring(!str_contains($source, 'trustedKeys('), 'OpsModuleTask retained duplicate trusted-key decoding');

$command->setApp($app);
$invalidKeyOutput = new Output('buffer');
expectOpsModuleWiring(
    $command->run(new Input(['advance']), $invalidKeyOutput) === 1
        && str_contains($invalidKeyOutput->fetch(), 'OPS_MODULE_TASK_KEY_INVALID'),
    'OpsModuleTask no longer rejects a missing task key before execution',
);
$invalidRevisionOutput = new Output('buffer');
expectOpsModuleWiring(
    $command->run(new Input(['advance', '--task-key=job_' . str_repeat('a', 32), '--revision=0']), $invalidRevisionOutput) === 1
        && str_contains($invalidRevisionOutput->fetch(), 'OPS_MODULE_EXECUTION_REVISION_INVALID'),
    'OpsModuleTask no longer rejects an invalid revision before execution',
);

echo "C01-C-OPS-MODULE-WIRING passed\n";
