<?php

declare(strict_types=1);

namespace tests\Unit\ControllerDeclaredDependencyMigration;

use app\api\controller\UploadController;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\File\Contract\FileUploads;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\Request;
use think\exception\ClassNotFoundException;

final class ControllerDeclaredDependencyMigrationTest extends TestCase
{
    private Container $previousContainer;
    private App $app;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->app = new App(sys_get_temp_dir() . '/peanut-controller-migration');
        $this->app->instance(App::class, $this->app);
        $this->app->instance(Request::class, new Request());
        $this->app->instance(CurrentExecutionContext::class, new CurrentExecutionContext(new ExecutionContextStore()));
        Container::setInstance($this->app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
    }

    public function testUploadControllerReceivesItsTypedDependencyFromTheNativeContainer(): void
    {
        if (!interface_exists(FileUploads::class)) {
            self::markTestSkipped('Requires official module File.');
        }
        $uploads = $this->createStub(FileUploads::class);
        $this->app->instance(FileUploads::class, $uploads);

        $controller = $this->app->make(UploadController::class);
        $property = new \ReflectionProperty($controller, 'uploads');
        self::assertTrue($property->isReadOnly());
        self::assertSame($uploads, $property->getValue($controller));
    }

    public function testUploadControllerRejectsAnUnboundDependency(): void
    {
        $this->expectException(interface_exists(FileUploads::class) ? \Error::class : ClassNotFoundException::class);
        $this->expectExceptionMessage(FileUploads::class);
        $this->app->make(UploadController::class);
    }
}
