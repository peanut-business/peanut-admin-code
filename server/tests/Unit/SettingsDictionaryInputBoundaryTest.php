<?php

declare(strict_types=1);

use app\adminapi\controller\config\ConfigController;
use app\adminapi\validate\config\WebsiteValidate;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\validate\InputValidator;
use PeanutAdmin\Modules\ReferenceCodes\Controller\DictDataController;
use PeanutAdmin\Modules\ReferenceCodes\Controller\DictTypeController;
use PeanutAdmin\Modules\ReferenceCodes\Validation\DictDataValidate;
use PeanutAdmin\Modules\ReferenceCodes\Validation\DictTypeValidate;
use PHPUnit\Framework\TestCase;
use think\App;
use think\exception\ValidateException;
use think\Request;

/** Real input boundaries only; no persistence, services or shared database is started. */
final class SettingsDictionaryInputBoundaryTest extends TestCase
{
    public function testDictionaryIdentifiersAreValidatedBeforeAnyIntegerCast(): void
    {
        foreach ([1, '1'] as $id) {
            self::assertTrue((new DictDataValidate())->scene('add')->check(['type_id' => $id, 'name' => 'Entry', 'value' => 'one']));
            self::assertTrue((new DictTypeValidate())->scene('status')->check(['id' => $id, 'is_disable' => 0]));
        }
        foreach (['1.5', '1e0', true, 1.5, '9223372036854775808'] as $id) {
            self::assertFalse((new DictDataValidate())->scene('add')->check(['type_id' => $id, 'name' => 'Entry', 'value' => 'one']));
            self::assertFalse((new DictTypeValidate())->scene('delete')->check(['id' => $id]));
        }
        self::assertFalse((new DictDataValidate())->scene('status')->check(['id' => 1]));
        self::assertFalse((new DictDataValidate())->scene('edit')->check(['id' => 1, 'name' => 'Entry', 'value' => 'one', 'sort' => '1.5']));
    }

    public function testDictionaryCrudRejectsProtectedAndParentFieldsRatherThanDroppingThem(): void
    {
        $app = new App();
        $contexts = new CurrentExecutionContext(new ExecutionContextStore());
        $app->instance(CurrentExecutionContext::class, $contexts);
        $app->instance(InputValidator::class, new InputValidator($app, $contexts));
        foreach ([
            [DictTypeController::class, 'add', ['name' => 'Type', 'type' => 'type', 'tenant_id' => 2]],
            [DictDataController::class, 'add', ['name' => 'Entry', 'value' => 'one', 'type_id' => 1, 'tenant_id' => 2]],
            [DictDataController::class, 'edit', ['id' => 1, 'name' => 'Entry', 'value' => 'one', 'type_id' => 2]],
        ] as [$type, $scene, $input]) {
            $controller = (new ReflectionClass($type))->newInstanceWithoutConstructor();
            (new ReflectionProperty($type, 'app'))->setValue($controller, $app);
            try {
                (new ReflectionMethod($type, 'validatedInput'))->invoke($controller, null, $scene, $input);
                self::fail('Protected dictionary input was accepted.');
            } catch (ValidateException) {
                self::assertTrue(true);
            }
        }
        $controller = (new ReflectionClass(DictDataController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(DictDataController::class, 'app'))->setValue($controller, $app);
        self::assertSame(['name' => 'Entry', 'value' => 'one', 'id' => 1], (new ReflectionMethod(DictDataController::class, 'validatedInput'))->invoke(
            $controller,
            null,
            'edit',
            ['id' => 1, 'name' => 'Entry', 'value' => 'one'],
        ));
    }

    public function testConfigurationBoundaryRejectsUnknownFieldsAndCoercedLoginWays(): void
    {
        $app = new App();
        $app->instance(InputValidator::class, new InputValidator($app, new CurrentExecutionContext(new ExecutionContextStore())));
        $controller = (new ReflectionClass(ConfigController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ConfigController::class, 'app'))->setValue($controller, $app);
        (new ReflectionProperty(ConfigController::class, 'request'))->setValue($controller, (new Request())->withPost(['clarity_code' => '', 'tenant_id' => 202]));
        try {
            (new ReflectionMethod(ConfigController::class, 'save'))->invoke($controller, 'statistics', 'saveStatistics');
            self::fail('Protected configuration field was accepted.');
        } catch (ValidateException) {
            self::assertTrue(true);
        }
        self::assertFalse((new WebsiteValidate())->scene('login')->check([
            'login_way' => ['1junk'], 'coerce_mobile' => 0, 'login_agreement' => 0,
            'third_auth' => 0, 'wechat_auth' => 0,
        ]));
    }


}
