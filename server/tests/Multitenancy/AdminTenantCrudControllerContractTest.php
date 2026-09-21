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
    'app\\adminapi\\controller\\dict\\DictTypeController' => [
        'service' => 'app\\adminapi\\application\\dict\\DictTypeApplicationService',
        'validate' => 'app\\adminapi\\validate\\dict\\DictTypeValidate',
        'extra' => ['all'],
    ],
    'app\\adminapi\\controller\\dict\\DictDataController' => [
        'service' => 'app\\adminapi\\application\\dict\\DictDataApplicationService',
        'validate' => 'app\\adminapi\\validate\\dict\\DictDataValidate',
        'extra' => ['byType'],
    ],
    'PeanutAdmin\\Modules\\OAuth\\Controller\\OfficialAccountReplyController' => [
        'service' => 'PeanutAdmin\\Modules\\OAuth\\Service\\OfficialAccountReplyApplicationService',
        'validate' => 'PeanutAdmin\\Modules\\OAuth\\Validation\\OfficialAccountReplyValidate',
        'extra' => [],
    ],
    'PeanutAdmin\\Modules\\Article\\Controller\\ArticleController' => [
        'service' => 'PeanutAdmin\\Modules\\Article\\Contract\\ArticleAdministration',
        'validate' => 'PeanutAdmin\\Modules\\Article\\Validation\\ArticleValidate',
        'extra' => [],
    ],
    'PeanutAdmin\\Modules\\Article\\Controller\\ArticleCateController' => [
        'service' => 'PeanutAdmin\\Modules\\Article\\Contract\\ArticleAdministration',
        'validate' => 'PeanutAdmin\\Modules\\Article\\Validation\\ArticleCateValidate',
        'extra' => ['all'],
    ],
    'app\\adminapi\\controller\\dept\\DeptController' => [
        'service' => 'app\\adminapi\\application\\dept\\DeptApplicationService',
        'validate' => null,
        'extra' => ['all', 'leaderDept'],
    ],
    'app\\adminapi\\controller\\dept\\JobsController' => [
        'service' => 'app\\adminapi\\application\\dept\\JobsApplicationService',
        'validate' => null,
        'extra' => ['all'],
    ],
] as $className => $contract) {
    $class = reflectAdminTenantCrud($className);
    expectAdminTenantCrud($class->getParentClass()?->getName() === $base, $className . ' must extend BaseAdminController directly');
    expectAdminTenantCrud(in_array($trait, class_uses($className), true), $className . ' must compose CrudTrait directly');

    $parameters = $class->getConstructor()?->getParameters() ?? [];
    expectAdminTenantCrud(
        count($parameters) === 3 && $parameters[2]->getType()?->getName() === $contract['service'],
        $className . ' must inject ' . $contract['service'],
    );
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
    dirname(__DIR__, 2) . '/app/modules/official/article/Http/Controller/AbstractArticleCrudController.php',
] as $removedBase) {
    expectAdminTenantCrud(!is_file($removedBase), 'obsolete CRUD base remains: ' . $removedBase);
}

echo "ADMIN-TENANT-CRUD-CONTROLLER-CONTRACT-001 passed\n";
