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
    'PeanutAdmin\\Modules\\ReferenceCodes\\Controller\\DictTypeController' => [
        'service' => 'PeanutAdmin\\Modules\\ReferenceCodes\\Service\\DictTypeApplicationService',
        'property' => 'dictionaryTypes',
        'validate' => 'PeanutAdmin\\Modules\\ReferenceCodes\\Validation\\DictTypeValidate',
        'extra' => ['all'],
    ],
    'PeanutAdmin\\Modules\\ReferenceCodes\\Controller\\DictDataController' => [
        'service' => 'PeanutAdmin\\Modules\\ReferenceCodes\\Service\\DictDataApplicationService',
        'property' => 'dictionaryData',
        'validate' => 'PeanutAdmin\\Modules\\ReferenceCodes\\Validation\\DictDataValidate',
        'extra' => ['byType'],
    ],
    'PeanutAdmin\\Modules\\OAuth\\Controller\\OfficialAccountReplyController' => [
        'service' => 'PeanutAdmin\\Modules\\OAuth\\Service\\OfficialAccountReplyApplicationService',
        'property' => 'replies',
        'validate' => 'PeanutAdmin\\Modules\\OAuth\\Validation\\OfficialAccountReplyValidate',
        'extra' => [],
    ],
    'PeanutAdmin\\Modules\\Article\\Controller\\ArticleController' => [
        'service' => 'PeanutAdmin\\Modules\\Article\\Contract\\ArticleAdministration',
        'property' => 'crud',
        'validate' => 'PeanutAdmin\\Modules\\Article\\Validation\\ArticleValidate',
        'extra' => [],
        'soft_delete' => true,
    ],
    'PeanutAdmin\\Modules\\Article\\Controller\\ArticleCateController' => [
        'service' => 'PeanutAdmin\\Modules\\Article\\Contract\\ArticleCategoryAdministration',
        'property' => 'crud',
        'validate' => 'PeanutAdmin\\Modules\\Article\\Validation\\ArticleCateValidate',
        'extra' => ['all'],
        'soft_delete' => true,
    ],
    'app\\adminapi\\controller\\dept\\DeptController' => [
        'service' => 'app\\adminapi\\services\\dept\\DeptApplicationService',
        'property' => 'departments',
        'validate' => null,
        'extra' => ['all', 'leaderDept'],
    ],
    'app\\adminapi\\controller\\dept\\JobsController' => [
        'service' => 'app\\adminapi\\services\\dept\\JobsApplicationService',
        'property' => 'jobs',
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
    $propertyName = $contract['property'];
    $property = $class->getProperty($propertyName . 'Class');
    expectAdminTenantCrud(
        $property->isProtected()
            && !$property->isStatic()
            && $property->getType()?->getName() === 'string'
            && $property->getDefaultValue() === $contract['service'],
        $className . ' must expose protected string $' . $propertyName . 'Class',
    );
    expectAdminTenantCrud(
        str_contains(
            (string)$class->getDocComment(),
            '@property-read ' . basename(str_replace('\\', '/', $contract['service'])) . ' $' . $propertyName,
        ),
        $className . ' must expose the accurate readonly property type',
    );
    if (($contract['soft_delete'] ?? false) === true) {
        foreach (['recycleLists', 'recycleDetail', 'restore', 'forceDelete'] as $softDeleteAction) {
            expectAdminTenantCrud($class->hasMethod($softDeleteAction), $className . ' is missing ' . $softDeleteAction . '()');
        }
    }
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

$articleBindings = (new \PeanutAdmin\Modules\Article\ModuleProvider())->bindings();
expectAdminTenantCrud(
    ($articleBindings[\PeanutAdmin\Modules\Article\Contract\ArticleAdministration::class] ?? null)
        === \PeanutAdmin\Modules\Article\Service\ArticleAdministrationService::class,
    'ArticleAdministration binding changed',
);
expectAdminTenantCrud(
    ($articleBindings[\PeanutAdmin\Modules\Article\Contract\ArticleCategoryAdministration::class] ?? null)
        === \PeanutAdmin\Modules\Article\Service\ArticleCategoryAdministrationService::class,
    'ArticleCategoryAdministration binding changed',
);

$articleModuleRoot = dirname(__DIR__, 2) . '/app/modules/official/article';
$articleRoutes = (string)file_get_contents($articleModuleRoot . '/route/app.php');
$articlePermissions = json_decode(
    (string)file_get_contents($articleModuleRoot . '/resources/permissions.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);
$articlePermissionKeys = array_column($articlePermissions, 'key');
foreach ([
    'official.article.category.recycle.list',
    'official.article.category.recycle.detail',
    'official.article.category.restore',
    'official.article.category.force-delete',
    'official.article.recycle.list',
    'official.article.recycle.detail',
    'official.article.restore',
    'official.article.force-delete',
] as $permission) {
    expectAdminTenantCrud(
        str_contains($articleRoutes, "'{$permission}'")
            && in_array($permission, $articlePermissionKeys, true),
        'Article soft-delete action is not both routed and permission-registered: ' . $permission,
    );
}

foreach ([
    dirname(__DIR__, 2) . '/app/adminapi/controller/AbstractTenantCrudController.php',
    dirname(__DIR__, 2) . '/app/adminapi/controller/dept/AbstractOrgCrudController.php',
    dirname(__DIR__, 2) . '/app/Modules/Official/Article/Http/Controller/AbstractArticleCrudController.php',
] as $removedBase) {
    expectAdminTenantCrud(!is_file($removedBase), 'obsolete CRUD base remains: ' . $removedBase);
}

echo "ADMIN-TENANT-CRUD-CONTROLLER-CONTRACT-001 passed\n";
