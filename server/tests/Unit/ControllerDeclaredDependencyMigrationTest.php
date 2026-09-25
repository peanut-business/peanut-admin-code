<?php

declare(strict_types=1);

namespace tests\Unit\ControllerDeclaredDependencyMigration;

use app\BaseController;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use think\App;
use think\Container;
use think\Request;

/** Scans every owned Controller declaration, then resolves it through a real ThinkPHP App. */
final class ControllerDeclaredDependencyMigrationTest extends TestCase
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

    public function testAllOwnedControllerDeclarationsAreTypedDocumentedConflictFreeAndResolvable(): void
    {
        $controllers = $this->controllerDeclarations();
        self::assertCount(70, $controllers, 'The two CRUD samples plus all 68 migrated Controllers must be scanned.');

        foreach ($controllers as $className => $declarations) {
            $controller = new ReflectionClass($className);
            self::assertTrue($controller->isSubclassOf(BaseController::class), $className);
            self::assertSame(BaseController::class, $controller->getConstructor()?->getDeclaringClass()->getName(), $className);

            $app = $this->app();
            $expected = [];
            foreach ($declarations as $name => $declaration) {
                $property = $controller->getProperty($name . 'Class');
                $type = $property->getType();
                self::assertTrue($property->isProtected(), $className . '::$' . $name . 'Class');
                self::assertFalse($property->isStatic(), $className . '::$' . $name . 'Class');
                self::assertInstanceOf(ReflectionNamedType::class, $type);
                self::assertSame('string', $type->getName());
                self::assertFalse($type->allowsNull());
                self::assertSame($declaration['dependency'], $property->getDefaultValue());
                self::assertFalse($controller->hasProperty($name), $className . ' has a real $' . $name . ' conflict');
                self::assertStringContainsString(
                    '@property-read ' . (new ReflectionClass($declaration['dependency']))->getShortName() . ' $' . $name,
                    $controller->getDocComment() ?: '',
                    $className,
                );

                $expected[$name] = $this->dependencyInstance($declaration['dependency']);
                $app->instance($declaration['dependency'], $expected[$name]);
            }

            Container::setInstance($app);
            $instance = $controller->newInstance($app);
            foreach ($expected as $name => $dependency) {
                self::assertSame($dependency, $instance->{$name}, $className . '::$' . $name);
            }
        }
    }

    public function testARealMigratedInterfaceDeclarationRejectsAMissingBinding(): void
    {
        $app = $this->app();
        Container::setInstance($app);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not bound in the current App');
        new \app\api\controller\ArticleController($app);
    }

    /**
     * @return array<class-string<BaseController>,array<string,array{dependency:class-string<object>}>>
     */
    private function controllerDeclarations(): array
    {
        $server = dirname(__DIR__, 2);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $controllers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($server . '/app'));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php'
                || !str_ends_with($file->getFilename(), 'Controller.php')) {
                continue;
            }
            $nodes = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $nodes = $traverser->traverse($nodes);

            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                if (!isset($class->namespacedName)) {
                    continue;
                }
                $className = $class->namespacedName->toString();
                $declarations = [];
                foreach ($class->getProperties() as $property) {
                    foreach ($property->props as $prop) {
                        $propertyName = $prop->name->toString();
                        if (!str_ends_with($propertyName, 'Class')) {
                            continue;
                        }
                        $name = substr($propertyName, 0, -5);
                        self::assertNotSame('', $name, $className);
                        self::assertTrue($property->isProtected(), $className . '::$' . $propertyName);
                        self::assertFalse($property->isStatic(), $className . '::$' . $propertyName);
                        self::assertInstanceOf(Node\Identifier::class, $property->type);
                        self::assertSame('string', $property->type->toString());
                        self::assertInstanceOf(Node\Expr\ClassConstFetch::class, $prop->default);
                        self::assertInstanceOf(Node\Name::class, $prop->default->class);
                        $dependency = $prop->default->class->toString();
                        self::assertTrue(class_exists($dependency) || interface_exists($dependency), $dependency);
                        $declarations[$name] = ['dependency' => $dependency];
                    }
                }
                if ($declarations === []) {
                    continue;
                }

                foreach ($finder->findInstanceOf($class->stmts, Node\Expr\MethodCall::class) as $call) {
                    if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this'
                        && $call->name instanceof Node\Identifier) {
                        self::assertArrayNotHasKey(
                            $call->name->toString(),
                            $declarations,
                            $className . ' still calls a removed dependency Getter at line ' . $call->getStartLine(),
                        );
                    }
                }
                $controllers[$className] = $declarations;
            }
        }

        ksort($controllers);
        return $controllers;
    }

    /** @param class-string<object> $dependency */
    private function dependencyInstance(string $dependency): object
    {
        $reflection = new ReflectionClass($dependency);
        if ($reflection->isInterface() || $reflection->isAbstract()) {
            return $this->createStub($dependency);
        }
        return $reflection->newInstanceWithoutConstructor();
    }

    private function app(): App
    {
        $app = new App(sys_get_temp_dir() . '/peanut-controller-migration-' . bin2hex(random_bytes(4)));
        $app->instance(App::class, $app);
        $app->instance(Request::class, new Request());
        $app->instance(CurrentExecutionContext::class, new CurrentExecutionContext(new ExecutionContextStore()));
        return $app;
    }
}
