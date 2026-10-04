<?php

declare(strict_types=1);

namespace tests\Unit\ControllerDeclaredDependency;

use app\BaseController;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use LogicException;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\db\BaseQuery;
use think\Model;
use think\Request;
use think\Validate;

interface ProbeContract {}
interface CycleContract {}

class ProbeA implements ProbeContract {}
class ProbeB implements ProbeContract {}
class ProbeValidate extends Validate {}
abstract class AbstractProbe implements ProbeContract {}
final class BoundProbe extends AbstractProbe {}
class NativeDeclarationController extends BaseController
{
    protected string $nativeValidatorClass = Validate::class;
    public function nativeValidator(): Validate
    {
        return $this->nativeValidator;
    }
}
class AbstractDeclarationController extends BaseController
{
    protected string $probeClass = AbstractProbe::class;
    public function probe(): AbstractProbe
    {
        return $this->probe;
    }
}

class ProbeQuery extends BaseQuery
{
    /** 此测试只验证 Query 对象不复用，不连接数据库。 */
    public function __construct() {}
}
class ProbeModel extends Model
{
    /** 此测试只验证 Controller 生命周期，不连接数据库。 */
    public function __construct(array|object $data = []) {}
}

class DeclaredController extends BaseController
{
    protected string $probeClass = ProbeContract::class;
    protected string $validatorClass = ProbeValidate::class;
    protected string $queryClass = ProbeQuery::class;
    protected string $recordClass = ProbeModel::class;

    public function probe(): ProbeContract
    {
        return $this->probe;
    }
    public function validator(): ProbeValidate
    {
        return $this->validator;
    }
    public function query(): ProbeQuery
    {
        return $this->query;
    }
    public function record(): ProbeModel
    {
        return $this->record;
    }
    public function resetRecord(): void
    {
        $this->resetControllerModel('record');
    }
}

class ParentOverrideController extends BaseController
{
    protected string $probeClass = ProbeA::class;
    public function probe(): ProbeContract
    {
        return $this->probe;
    }
}

class ChildOverrideController extends ParentOverrideController
{
    protected string $probeClass = ProbeB::class;
}

class CycleController extends BaseController
{
    protected string $cycleClass = CycleContract::class;
    public function cycle(): CycleContract
    {
        return $this->cycle;
    }
}

class ReservedDeclarationController extends BaseController
{
    protected string $contextClass = ProbeA::class;
}

class ConflictingDeclarationController extends BaseController
{
    protected ProbeA $probe;
    protected string $probeClass = ProbeA::class;
}

final class ControllerDeclaredDependencyTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
    }

    public function testCurrentAppBindingsInheritanceAndMutableLifecycles(): void
    {
        $appA = $this->app();
        $appB = $this->app();
        $appA->instance(ProbeContract::class, new ProbeA());
        $appB->instance(ProbeContract::class, new ProbeB());

        $controllerA = new DeclaredController($appA);
        $controllerB = new DeclaredController($appB);
        self::assertInstanceOf(ProbeA::class, $controllerA->probe());
        self::assertInstanceOf(ProbeB::class, $controllerB->probe());
        self::assertNotSame($controllerA->validator(), $controllerA->validator());
        self::assertNotSame($controllerA->query(), $controllerA->query());
        self::assertSame($controllerA->record(), $controllerA->record());
        $record = $controllerA->record();
        $controllerA->resetRecord();
        self::assertNotSame($record, $controllerA->record());
        self::assertInstanceOf(ProbeB::class, (new ChildOverrideController($appA))->probe());
    }

    public function testRequestCannotSelectClassAndInvalidBindingsFailClosed(): void
    {
        $app = $this->app((new Request())->withGet(['probeClass' => ProbeB::class]));
        $app->instance(ProbeContract::class, new ProbeA());
        self::assertInstanceOf(ProbeA::class, (new DeclaredController($app))->probe());

        $bad = $this->app();
        $bad->instance(ProbeContract::class, new \stdClass());
        $this->expectException(LogicException::class);
        (new DeclaredController($bad))->probe();
    }

    public function testReservedAndConflictingDeclarationsAreRejectedAtConstruction(): void
    {
        try {
            new ReservedDeclarationController($this->app());
            self::fail('reserved declaration was accepted');
        } catch (LogicException $exception) {
            self::assertStringContainsString('Reserved controller property', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('conflicts with a real property');
        new ConflictingDeclarationController($this->app());
    }

    public function testCircularResolutionIncludesThePropertyChain(): void
    {
        $app = $this->app();
        $controller = null;
        $app->bind(CycleContract::class, static function () use (&$controller): CycleContract {
            return $controller->cycle();
        });
        $controller = new CycleController($app);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CycleController::$cycle ->');
        $controller->cycle();
    }

    public function testNativeValidatorIsNotSharedAndAbstractBindingIsExplicit(): void
    {
        $app = $this->app();
        $controller = new NativeDeclarationController($app);
        self::assertNotSame($controller->nativeValidator(), $controller->nativeValidator());
        $app->instance(AbstractProbe::class, new BoundProbe());
        self::assertInstanceOf(BoundProbe::class, (new AbstractDeclarationController($app))->probe());
    }

    public function testUnrelatedFactoryTypeErrorIsNotMisreportedAsAControllerCycle(): void
    {
        $app = $this->app();
        $app->bind(ProbeContract::class, static function (): ProbeContract {
            return null;
        });
        $controller = new DeclaredController($app);
        $this->expectException(\TypeError::class);
        $controller->probe();
    }

    private function app(?Request $request = null): App
    {
        $app = new App(sys_get_temp_dir() . '/peanut-controller-declaration-' . bin2hex(random_bytes(4)));
        $app->instance(App::class, $app);
        $app->instance(Request::class, $request ?? new Request());
        $app->instance(CurrentExecutionContext::class, new CurrentExecutionContext(new ExecutionContextStore()));
        Container::setInstance($app);
        return $app;
    }
}
