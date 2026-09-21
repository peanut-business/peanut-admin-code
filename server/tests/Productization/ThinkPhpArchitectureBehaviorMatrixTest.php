<?php
declare(strict_types=1);

use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblemMapper;
use app\common\http\PageResult;
use app\common\validate\InputValidator;
use app\common\model\TenantOwnedModel;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\common\tenancy\DataScopePolicy;
use app\common\tenancy\MultiTenantDataScopePolicy;
use app\common\tenancy\PlatformTenantDataGateway;
use app\adminapi\services\generator\GeneratorRenderService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Module\Persistence\ThinkPhpModuleRuntimeRepository;
use think\Container;
use think\DbManager;
use think\Model;
use think\db\BaseQuery;
use think\db\connector\Mysql;
use think\paginator\driver\Bootstrap;
use think\App;
use think\exception\ValidateException;
use think\Validate;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

function expectTpq51(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class Tpq51RecordingMysql extends Mysql
{
    /** @var list<array{operation:string,sql:string}> */
    public array $statements = [];

    public function select(BaseQuery $query): array
    {
        $query->parseOptions();
        $this->record('select', $query, $this->builder->select($query));

        return match ($query->getTable()) {
            'pa_tpq51_child' => [
                ['id' => 1, 'tenant_id' => 101, 'parent_id' => 7, 'name' => 'first'],
                ['id' => 2, 'tenant_id' => 101, 'parent_id' => 8, 'name' => 'second'],
            ],
            'pa_tpq51_parent' => [
                ['id' => 7, 'tenant_id' => 101, 'name' => 'parent-a'],
                ['id' => 8, 'tenant_id' => 101, 'name' => 'parent-b'],
            ],
            default => [],
        };
    }

    public function insert(BaseQuery $query, bool $getLastInsID = false): int
    {
        $query->parseOptions();
        $this->record('insert', $query, $this->builder->insert($query));
        return 1;
    }

    public function update(BaseQuery $query): int
    {
        $query->parseOptions();
        $this->record('update', $query, $this->builder->update($query));
        return 1;
    }

    public function delete(BaseQuery $query): int
    {
        $query->parseOptions();
        $this->record('delete', $query, $this->builder->delete($query));
        return 1;
    }

    public function getAutoInc($tableName)
    {
        return null;
    }

    public function getLastInsID(BaseQuery $query, ?string $sequence = null)
    {
        return null;
    }

    public function resetStatements(): void
    {
        $this->statements = [];
    }

    private function record(string $operation, BaseQuery $query, string $sql): void
    {
        $this->statements[] = [
            'operation' => $operation,
            'sql' => $this->getRealSql($sql, $query->getBind()),
        ];
    }
}

abstract class Tpq51TenantModel extends TenantOwnedModel
{
    protected $autoWriteTimestamp = false;

    protected function getOptions(): array
    {
        return parent::getOptions() + [
            'pk' => 'id',
            'schema' => [
                'id' => 'int',
                'tenant_id' => 'int',
                'parent_id' => 'int',
                'name' => 'string',
            ],
        ];
    }
}

final class Tpq51Parent extends Tpq51TenantModel
{
    protected $name = 'tpq51_parent';
}

final class Tpq51Child extends Tpq51TenantModel
{
    protected $name = 'tpq51_child';

    public function owner()
    {
        return $this->belongsTo(Tpq51Parent::class, 'parent_id', 'id');
    }
}

final class Tpq51SceneValidate extends Validate
{
    protected $rule = [
        'narrow' => 'require',
        'default_only' => 'require',
    ];

    protected $scene = [
        'narrow' => ['narrow'],
    ];
}

function tpq51TenantContext(int $tenantId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        501,
        '01JTPQ51BEHAVIOR0000000001',
        $tenantId,
        1001,
        501,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), 'tpq51-request-' . $tenantId);
}

function tpq51SqlCount(string $sql, string $needle): int
{
    return substr_count(strtolower($sql), strtolower($needle));
}

function tpq51InstallDataScopePolicy(DataScopePolicy $policy): void
{
    Model::maker(static function (Model $model) use ($policy): void {
        if ($model instanceof TenantOwnedModel) {
            $model->setDataScopePolicy($policy);
        }
    });
}

$container = new Container();
Container::setInstance($container);

$store = new ExecutionContextStore();
$current = new CurrentExecutionContext($store);
$container->instance(ExecutionContextStore::class, $store);
$container->instance(CurrentExecutionContext::class, $current);

$database = new DbManager();
$database->setConfig([
    'default' => 'recording',
    'auto_timestamp' => false,
    'connections' => [
        'recording' => [
            'type' => '\\' . Tpq51RecordingMysql::class,
            'builder' => think\db\builder\Mysql::class,
            'prefix' => 'pa_',
            'fields_strict' => true,
        ],
    ],
]);
$connection = $database->connect();
expectTpq51($connection instanceof Tpq51RecordingMysql, 'TPQ51 recording connector was not selected');

$missingPolicyRejected = false;
try {
    Tpq51Child::where('id', '>', 0)->select();
} catch (LogicException $exception) {
    $missingPolicyRejected = $exception->getMessage() === 'DATA_SCOPE_POLICY_UNAVAILABLE';
}
expectTpq51($missingPolicyRejected, 'Tenant Model did not fail closed without an injected data-scope policy');

$tenantContext = tpq51TenantContext(101);
$execution = new \app\common\execution\AdminExecutionContext($tenantContext, 'tpq51.behavior');
tpq51InstallDataScopePolicy(new MultiTenantDataScopePolicy($current));

$connection->resetStatements();
$children = $store->run(
    $execution,
    static fn() => Tpq51Child::with('owner')->select(),
);
expectTpq51(count($children) === 2, 'ThinkORM eager result shape changed');
expectTpq51(count($connection->statements) === 2, 'eager relation query count is not constant');
foreach ($connection->statements as $statement) {
    expectTpq51(
        tpq51SqlCount($statement['sql'], 'tenant_id') === 1,
        'root or relation SQL lost the single Tenant global scope: ' . $statement['sql'],
    );
}

$connection->resetStatements();
$store->run(
    $execution,
    static fn() => Tpq51Child::alias('child')->where('child.id', '>', 0)->select(),
);
$aliasSql = $connection->statements[0]['sql'] ?? '';
expectTpq51(
    tpq51SqlCount($aliasSql, 'tenant_id') === 1 && str_contains($aliasSql, '`child`.`tenant_id`'),
    'alias-aware Tenant scope SQL changed: ' . $aliasSql,
);

$connection->resetStatements();
$created = new Tpq51Child([
    'id' => 3,
    'parent_id' => 7,
    'name' => 'created',
]);
expectTpq51(
    $store->run($execution, static fn() => $created->save()),
    'Tenant Model save did not reach the ThinkORM insert path',
);
$insertSql = $connection->statements[0]['sql'] ?? '';
expectTpq51(
    (int)$created->getData('tenant_id') === 101 && tpq51SqlCount($insertSql, 'tenant_id') === 1,
    'before-insert Tenant ownership hook did not populate the trusted Tenant',
);

$rejected = false;
try {
    $store->run($execution, static fn() => (new Tpq51Child([
        'id' => 4,
        'tenant_id' => 202,
        'parent_id' => 7,
        'name' => 'rejected',
    ]))->save());
} catch (DomainException $exception) {
    $rejected = $exception->getMessage() === 'TENANT_WRITE_OWNERSHIP_MISMATCH';
}
expectTpq51($rejected, 'request payload overrode trusted Tenant ownership');

$connection->resetStatements();
$store->run(
    $execution,
    static fn() => Tpq51Child::where('id', '>', 0)->update(['name' => 'bulk-updated']),
);
$store->run(
    $execution,
    static fn() => Tpq51Child::where('id', '>', 0)->delete(),
);
expectTpq51(
    count($connection->statements) === 2
        && array_column($connection->statements, 'operation') === ['update', 'delete'],
    'bulk update/delete did not reach the ThinkORM write path',
);
foreach ($connection->statements as $statement) {
    expectTpq51(
        tpq51SqlCount($statement['sql'], 'tenant_id') === 1,
        'bulk write lost Tenant global scope: ' . $statement['sql'],
    );
}

$differentTenantRejected = false;
try {
    $store->run($execution, static fn() => $store->run(
        new \app\common\execution\AdminExecutionContext(tpq51TenantContext(202), 'tpq51.different-tenant'),
        static fn() => null,
    ));
} catch (DomainException $exception) {
    $differentTenantRejected = $exception->getMessage() === 'EXECUTION_TENANT_CONTEXT_MISMATCH';
}
expectTpq51($differentTenantRejected, 'nested execution context changed the authoritative Tenant');
expectTpq51($store->isEmpty(), 'rejected Tenant context leaked onto the execution stack');

$connection->resetStatements();
(new PlatformTenantDataGateway($current))
    ->query(Tpq51Child::class, 'platform-test', 'tpq51.cross-tenant-read')
    ->select();
expectTpq51(
    tpq51SqlCount($connection->statements[0]['sql'] ?? '', 'tenant_id') === 0,
    'explicit Platform Tenant gateway did not preserve its audited scope bypass',
);

tpq51InstallDataScopePolicy(new MultiTenantDataScopePolicy($current));
$connection->resetStatements();
$standalone = new Tpq51Child([
    'id' => 5,
    'parent_id' => 7,
    'name' => 'standalone',
]);
expectTpq51(
    $store->run($execution, static fn() => $standalone->save()),
    'Standalone Model save did not reach ThinkORM',
);
$store->run(
    $execution,
    static fn() => Tpq51Child::alias('child')->where('child.id', '>', 0)->select(),
);
expectTpq51(
    (int)$standalone->getData('tenant_id') === 101
        && tpq51SqlCount($connection->statements[0]['sql'] ?? '', 'tenant_id') === 1,
    'Standalone write lost the shared Tenant ownership column',
);
expectTpq51(
    tpq51SqlCount($connection->statements[1]['sql'] ?? '', 'tenant_id') === 1,
    'Standalone read lost the shared Tenant predicate',
);

$paginator = new Bootstrap([
    ['id' => 41],
    ['id' => 42],
], 20, 3, 52);
$page = PageResult::fromPaginator($paginator, 3)->withMetadata(['aggregate' => ['active' => 2]]);
expectTpq51($page->responseData() === [
    'lists' => [['id' => 41], ['id' => 42]],
    'count' => 52,
    'pageNo' => 3,
    'pageSize' => 20,
    'aggregate' => ['active' => 2],
], 'PageResult no longer preserves the public pagination envelope');

$mapper = new ApiProblemMapper();
$businessProblem = $mapper->map(BusinessException::conflict('TPQ51_CONFLICT', 'conflict'));
expectTpq51(
    $businessProblem?->httpStatus === 409
        && $businessProblem->apiCode() === 40900
        && $businessProblem->data() === ['error_code' => 'TPQ51_CONFLICT'],
    'Business exception mapping changed',
);
$moduleProblem = $mapper->map(new ModuleException('MODULE_TENANT_DISABLED', 'internal detail'));
expectTpq51(
    $moduleProblem?->httpStatus === 403
        && $moduleProblem->apiCode() === 40300
        && $moduleProblem->getMessage() === 'Module request was rejected.',
    'Module exception mapping changed or leaked internal detail',
);
expectTpq51($mapper->map(new RuntimeException('unknown')) === null, 'unknown exception was exposed as a public problem');

$applicationRoot = dirname(__DIR__, 2) . '/app/adminapi/services';
foreach ([
    dirname(__DIR__, 2) . '/app/adminapi/services/generator/GeneratorService.php',
    $applicationRoot . '/dept/JobsApplicationService.php',
] as $applicationFile) {
    $applicationSource = (string)file_get_contents($applicationFile);
    expectTpq51(
        preg_match('/^\s*use\s+app\\\\[^;]+\\\\model\\\\/mi', $applicationSource) !== 1,
        basename($applicationFile) . ' imports a persistence Model',
    );
}
$jobsApplicationSource = (string)file_get_contents($applicationRoot . '/dept/JobsApplicationService.php');
expectTpq51(
    preg_match('/catch\s*\(\\\\Throwable[^)]*\)\s*\{\s*throw\s+\$[A-Za-z_][A-Za-z0-9_]*\s*;\s*\}/s', $jobsApplicationSource) !== 1,
    'JobsApplicationService retained a no-op catch/rethrow block',
);

$generatedFiles = GeneratorRenderService::render([
    'table_name' => 'pa_demo_article',
    'module_name' => 'demo',
    'entity_name' => 'Article',
    'data_owner' => 'tenant',
    'target_edition' => 'multi-tenant',
    'columns' => [
        ['column_name' => 'id', 'php_type' => 'int', 'is_pk' => true, 'is_required' => true],
        ['column_name' => 'tenant_id', 'php_type' => 'int', 'is_required' => true],
        ['column_name' => 'title', 'php_type' => 'string', 'is_required' => true, 'is_insert' => true, 'is_update' => true],
    ],
]);
$assertGeneratedServiceOutput = static function (array $files): void {
    $byPath = array_column($files, 'content', 'path');
    $servicePath = 'server/app/adminapi/services/demo/ArticleService.php';
    if (count($files) !== 6
        || !isset($byPath[$servicePath])
        || !str_contains($byPath[$servicePath], 'namespace app\\adminapi\\services\\demo;')
        || !str_contains($byPath[$servicePath], 'class ArticleService')
        || !str_contains($byPath['server/app/adminapi/controller/demo/ArticleController.php'] ?? '', 'use app\\adminapi\\services\\demo\\ArticleService;')
        || !str_contains($byPath['server/app/adminapi/controller/demo/ArticleController.php'] ?? '', 'protected string $crudClass = ArticleService::class;')
        || !str_contains($byPath['server/app/adminapi/controller/demo/ArticleController.php'] ?? '', '@property-read ArticleService $crud')) {
        throw new RuntimeException('generator services output contract violated');
    }
    foreach (array_keys($byPath) as $path) {
        if (str_contains($path, 'adminapi/' . 'application') || str_contains($path, 'Application' . 'Service.php')) {
            throw new RuntimeException('generator services output contract violated');
        }
    }
};
$assertGeneratedServiceOutput($generatedFiles);
$generatedByPath = array_column($generatedFiles, 'content', 'path');
$servicePath = 'server/app/adminapi/services/demo/ArticleService.php';
expectTpq51(count($generatedFiles) === 6 && isset($generatedByPath[$servicePath]), 'generator did not render the services output path');
expectTpq51(
    str_contains($generatedByPath[$servicePath], 'namespace app\\adminapi\\services\\demo;')
        && str_contains($generatedByPath[$servicePath], 'class ArticleService'),
    'generator service output retained the application namespace or class',
);
$controllerContent = $generatedByPath['server/app/adminapi/controller/demo/ArticleController.php'] ?? '';
expectTpq51(
    str_contains($controllerContent, 'use app\\adminapi\\services\\demo\\ArticleService;')
        && str_contains($controllerContent, 'protected string $crudClass = ArticleService::class;')
        && str_contains($controllerContent, '@property-read ArticleService $crud')
        && !str_contains($controllerContent, 'function service()'),
    'generator controller did not import the services class',
);
foreach (array_keys($generatedByPath) as $path) {
    expectTpq51(!str_contains($path, 'adminapi/application') && !str_contains($path, 'ApplicationService.php'), 'generator reintroduced the retired application output path');
}
$legacyFiles = array_map(static function (array $file): array {
    $file['path'] = str_replace('services/demo/ArticleService.php', 'application/demo/ArticleApplicationService.php', $file['path']);
    $file['content'] = str_replace('services\\demo\\ArticleService', 'application\\demo\\ArticleApplicationService', $file['content']);
    return $file;
}, $generatedFiles);
try {
    $assertGeneratedServiceOutput($legacyFiles);
    throw new RuntimeException('legacy generated output was accepted');
} catch (RuntimeException $exception) {
    expectTpq51($exception->getMessage() === 'generator services output contract violated', 'legacy generated output did not fail the services output contract');
}

$scannerProbe = <<<'PY'
import importlib.machinery
import importlib.util
import json
import pathlib
import sys

scanner_path = pathlib.Path(sys.argv[1])
loader = importlib.machinery.SourceFileLoader("tpq_architecture_scanner", str(scanner_path))
spec = importlib.util.spec_from_loader(loader.name, loader)
scanner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scanner)
cases = {
    "host_application": (
        "server/app/adminapi/services/ProbeService.php",
        "<?php\nuse PeanutAdmin\\Modules\\Task\\Service\\CrontabApplicationService;\n",
    ),
    "platform_adapter_model": (
        "server/app/platform/infrastructure/Probe.php",
        "<?php\nuse PeanutAdmin\\Modules\\Task\\Model\\Crontab;\n",
    ),
    "host_contract": (
        "server/app/api/services/ProbeService.php",
        "<?php\nuse PeanutAdmin\\Modules\\Task\\Contract\\TaskJobRuntime;\n",
    ),
    "module_internal": (
        "server/app/modules/official/task/src/Service/Probe.php",
        "<?php\nuse PeanutAdmin\\Modules\\Task\\Infrastructure\\Runtime\\ThinkPhpTaskJobRuntime;\n",
    ),
    "application_console": (
        "server/app/adminapi/services/ConsoleProbeService.php",
        "<?php\nuse think\\Console;\n",
    ),
    "services_console": (
        "server/app/adminapi/services/ConsoleProbe.php",
        "<?php\nuse think\\Console;\n",
    ),
    "application_transport": (
        "server/app/modules/official/oauth/src/Service/TransportProbe.php",
        "<?php\nfinal class TransportProbe { public function run(): void { new WechatOAuthTransport(); } }\n",
    ),
    "tenant_join_missing": (
        "server/app/modules/official/article/src/Service/JoinProbe.php",
        "<?php\n$query->join('article a', 'a.id = c.article_id');\n",
    ),
    "tenant_join_scoped": (
        "server/app/modules/official/article/src/Service/ScopedJoinProbe.php",
        "<?php\n$query->leftJoin('article a', 'a.tenant_id = c.tenant_id AND a.id = c.article_id');\n",
    ),
    "tenant_join_global": (
        "server/app/common/service/authorization/GlobalJoinProbe.php",
        "<?php\n$query->join('permission p', 'p.key = m.perms');\n",
    ),
}
print(json.dumps({
    name: [hit[0] for hit in scanner.hits_for(scanner.ROOT / path, source)]
    for name, (path, source) in cases.items()
}))
PY;
$scannerPath = dirname(__DIR__, 3) . '/scripts/check-thinkphp-architecture';
$probeOutput = [];
$probeStatus = 0;
exec(
    'python3 -c ' . escapeshellarg($scannerProbe) . ' ' . escapeshellarg($scannerPath),
    $probeOutput,
    $probeStatus,
);
$probeHits = json_decode(implode("\n", $probeOutput), true, 32, JSON_THROW_ON_ERROR);
expectTpq51($probeStatus === 0, 'architecture scanner probe did not execute');
expectTpq51(
    in_array('host_module_internal_dependency', $probeHits['host_application'] ?? [], true),
    'Host Application import of a Module Application implementation was not rejected',
);
expectTpq51(
    in_array('host_module_internal_dependency', $probeHits['platform_adapter_model'] ?? [], true),
    'Platform infrastructure adapter import of a Module Model was incorrectly exempted',
);
expectTpq51(
    !in_array('host_module_internal_dependency', $probeHits['host_contract'] ?? [], true),
    'Host import of a Module Contract was rejected',
);
expectTpq51(
    !in_array('host_module_internal_dependency', $probeHits['module_internal'] ?? [], true),
    'Module-internal implementation import was treated as a Host dependency',
);
expectTpq51(
    in_array('application_framework_model', $probeHits['application_console'] ?? [], true),
    'Application import of ThinkPHP Console was not rejected',
);
expectTpq51(
    in_array('application_framework_model', $probeHits['services_console'] ?? [], true),
    'Services import of ThinkPHP Console was not rejected',
);
expectTpq51(
    in_array('application_composition_root', $probeHits['application_transport'] ?? [], true),
    'Application construction of a transport dependency was not rejected',
);
expectTpq51(
    in_array('tenant_join_missing', $probeHits['tenant_join_missing'] ?? [], true),
    'Tenant JOIN without a tenant_id equality was not rejected',
);
expectTpq51(
    !in_array('tenant_join_missing', $probeHits['tenant_join_scoped'] ?? [], true),
    'Tenant-scoped JOIN was incorrectly rejected',
);
expectTpq51(
    !in_array('tenant_join_missing', $probeHits['tenant_join_global'] ?? [], true),
    'Global system-table JOIN was incorrectly rejected',
);

$contextFailure = false;
try {
    (new ModuleExecutionBoundary($current, new ThinkPhpModuleRuntimeRepository()))->assertWorker('official.article');
} catch (DomainException $exception) {
    $contextFailure = $exception->getMessage() === 'EXECUTION_CONTEXT_REQUIRED';
}
expectTpq51($contextFailure, 'Module execution boundary did not fail closed without trusted context');

$sceneValidator = 'app\\test\\Tpq51SceneValidate';
class_alias(Tpq51SceneValidate::class, $sceneValidator);
$inputValidator = new InputValidator(new App(dirname(__DIR__, 2)), $current);
$inputValidator->validate(['narrow' => 'accepted'], $sceneValidator . '.narrow');
$unknownSceneRejected = false;
try {
    $inputValidator->validate(['narrow' => 'accepted'], $sceneValidator . '.unknown');
} catch (ValidateException $exception) {
    $unknownSceneRejected = $exception->getMessage() === '验证场景不存在'
        && $mapper->map($exception)?->apiCode() === 40000;
}
expectTpq51($unknownSceneRejected, 'unknown validation scene fell back to default rules or escaped the input error envelope');

try {
    $store->run($execution, static function (): void {
        throw new RuntimeException('worker failure');
    });
} catch (RuntimeException $exception) {
    expectTpq51($exception->getMessage() === 'worker failure', 'worker failure identity changed');
}
expectTpq51($store->isEmpty(), 'long-running execution context leaked after failure');

echo "TPQ51-THINKPHP-ARCHITECTURE-BEHAVIOR-MATRIX passed\n";
