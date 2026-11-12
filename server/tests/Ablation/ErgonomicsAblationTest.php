<?php

declare(strict_types=1);

namespace tests\Ablation;

require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';

use PeanutAdmin\Modules\ReferenceCodes\Controller\DictTypeController;
use ReflectionClass;

/**
 * EXP-03: bounded controller structure check.
 * It reports current Reflection facts only; it does not quantify legacy code reduction.
 */
final class ErgonomicsAblationTest
{
    private static function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public static function run(): void
    {
        // ReferenceCodes is selected by this host and exercises the shared CRUD implementation.
        $reflection = new ReflectionClass(DictTypeController::class);
        self::expect($reflection->getParentClass()?->getName() === 'app\\adminapi\\controller\\BaseAdminController', 'DictTypeController parent contract changed');
        self::expect(in_array('app\\common\\traits\\CrudTrait', $reflection->getTraitNames(), true), 'DictTypeController no longer composes CrudTrait');

        foreach (['performLists', 'performDetail', 'performAdd', 'performEdit', 'performDelete', 'performStatusUpdate'] as $methodName) {
            self::expect($reflection->hasMethod($methodName), "missing CRUD hook: {$methodName}");
            $method = $reflection->getMethod($methodName);
            $parameters = $method->getParameters();
            self::expect($parameters !== [] && $parameters[0]->getName() === 'context', "CRUD hook {$methodName} lost its execution-context parameter");
        }
        self::expect(!$reflection->hasMethod('missingCrudHook'), 'negative control found an impossible CRUD hook');

        echo "ERGONOMICS-ABLATION-001 passed: current controller traits, parent, and context hooks are verified\n";
    }
}

ErgonomicsAblationTest::run();
