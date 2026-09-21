<?php
declare(strict_types=1);

/** Static contract for flat admin CRUD controllers composed with CrudTrait. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectAdminTenantCrud(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reflectAdminTenantCrud(string $class): ReflectionClass
{
    expectAdminTenantCrud(class_exists($class), 'controller class is not autoloadable: ' . $class);
    return new ReflectionClass($class);
}

function expectCrudConstant(ReflectionClass $class, string $name, mixed $expected): void
{
    $constant = $class->getReflectionConstant($name);
    expectAdminTenantCrud($constant !== null, $class->getName() . ' is missing ' . $name);
    expectAdminTenantCrud(
        $constant->getValue() === $expected,
        sprintf('%s::%s changed', $class->getName(), $name),
    );
}

$base = 'app\\adminapi\\controller\\BaseAdminController';
$trait = 'app\\common\\traits\\CrudTrait';
$actions = ['lists', 'detail', 'add', 'edit', 'delete', 'updateStatus'];

expectAdminTenantCrud(trait_exists($trait), $trait . ' is not autoloadable');
foreach ([
    'app\\modules\\official\\reference_codes\\controllers\\DictTypeController' => [
        'service' => 'app\\modules\\official\\reference_codes\\services\\DictTypeApplicationService',
        'validate' => 'app\\modules\\official\\reference_codes\\validation\\DictTypeValidate',
        'extra' => ['all'],
    ],
    'app\\modules\\official\\reference_codes\\controllers\\DictDataController' => [
        'service' => 'app\\modules\\official\\reference_codes\\services\\DictDataApplicationService',
        'validate' => 'app\\modules\\official\\reference_codes\\validation\\DictDataValidate',
        'extra' => ['byType'],
    ],
    'app\\modules\\official\\oauth\\controller\\OfficialAccountReplyController' => [
        'service' => 'app\\modules\\official\\oauth\\services\\OfficialAccountReplyApplicationService',
        'validate' => 'app\\modules\\official\\oauth\\validate\\OfficialAccountReplyValidate',
        'extra' => [],
    ],
    'app\\modules\\official\\article\\controller\\ArticleController' => [
        'service' => 'app\\modules\\official\\article\\contracts\\ArticleAdministration',
        'validate' => 'app\\modules\\official\\article\\validate\\ArticleValidate',
        'extra' => [],
    ],
    'app\\modules\\official\\article\\controller\\ArticleCateController' => [
        'service' => 'app\\modules\\official\\article\\contracts\\ArticleAdministration',
        'validate' => 'app\\modules\\official\\article\\validate\\ArticleCateValidate',
        'extra' => ['all'],
    ],
    'app\\adminapi\\controller\\dept\\DeptController' => [
        'service' => 'app\\adminapi\\services\\dept\\DeptApplicationService',
        'validate' => null,
        'extra' => ['all', 'leaderDept'],
    ],
    'app\\adminapi\\controller\\dept\\JobsController' => [
        'service' => 'app\\adminapi\\services\\dept\\JobsApplicationService',
        'validate' => null,
        'extra' => ['all'],
    ],
] as $className => $contract) {
    $class = reflectAdminTenantCrud($className);
    expectAdminTenantCrud($class->getParentClass()?->getName() === $base, $className . ' must extend BaseAdminController directly');
    expectAdminTenantCrud(in_array($trait, class_uses($className), true), $className . ' must compose CrudTrait directly');

    $constructor = $class->getConstructor();
    expectAdminTenantCrud(
        $constructor?->getDeclaringClass()->getName() === app\BaseController::class
            && count($constructor->getParameters()) === 1
            && $constructor->getParameters()[0]->getType()?->getName() === think\App::class,
        $className . ' must inherit the native App constructor',
    );
    $getters = array_filter(
        $class->getMethods(ReflectionMethod::IS_PROTECTED),
        static fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName()
            && $method->getNumberOfParameters() === 0
            && $method->getReturnType()?->getName() === $contract['service'],
    );
    expectAdminTenantCrud(count($getters) === 1, $className . ' must expose one fixed typed service getter');
    if ($contract['validate'] !== null) {
        expectCrudConstant($class, 'CRUD_VALIDATE', $contract['validate']);
    }
    foreach ($actions as $action) {
        expectAdminTenantCrud($class->hasMethod($action), $className . ' is missing ' . $action . '()');
        expectAdminTenantCrud($class->getMethod($action)->isPublic(), $className . '::' . $action . '() is not public');
        expectAdminTenantCrud($class->getMethod($action)->isFinal(), $className . '::' . $action . '() is not final');
        expectAdminTenantCrud(
            $class->getMethod($action)->getReturnType()?->getName() === 'think\\response\\Json',
            $className . '::' . $action . '() must return think\\response\\Json',
        );
    }
    foreach ($contract['extra'] as $method) {
        expectAdminTenantCrud($class->hasMethod($method), $className . ' is missing ' . $method . '()');
    }
}

foreach ([
    dirname(__DIR__, 2) . '/app/adminapi/controller/AbstractTenantCrudController.php',
    dirname(__DIR__, 2) . '/app/adminapi/controller/dept/AbstractOrgCrudController.php',
    dirname(__DIR__, 2) . '/app/Modules/Official/Article/Http/Controller/AbstractArticleCrudController.php',
] as $removedBase) {
    expectAdminTenantCrud(!is_file($removedBase), 'obsolete CRUD base remains: ' . $removedBase);
}

echo "ADMIN-TENANT-CRUD-CONTROLLER-CONTRACT-001 passed\n";
