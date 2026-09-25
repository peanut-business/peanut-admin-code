<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\controller\PlatformModuleLifecycleController;
use app\platform\exception\plugin\PluginLifecycleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use think\Request;

final class ModuleCreateInputTest extends TestCase
{
    public static function invalidInputs(): array
    {
        return [
            [[]],
            [['module_key' => 7]],
            [['module_key' => 'acme.sample', 'tenant_id' => 9]],
            [['module_key' => 'acme.sample', 'vendor' => []]],
            [['module_key' => 'acme.sample', 'client' => false]],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidFieldsFailBeforeResolvingOrCallingTheGenerator(array $input): void
    {
        $controller = (new ReflectionClass(PlatformModuleLifecycleController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($controller, 'request'))->setValue($controller, (new Request())->withPost($input));
        $this->expectException(PluginLifecycleException::class);
        $this->expectExceptionMessage('only declared string fields');
        $controller->create();
    }
}
