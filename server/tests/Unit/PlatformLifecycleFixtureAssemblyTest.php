<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\services\ApplicationTenantBootstrapService;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\DbManager;

/** Read the legacy script as AST; never require its top-level database create/drop body. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PlatformLifecycleFixtureAssemblyTest extends TestCase
{
    private function nodes(): array
    {
        $path = dirname(__DIR__) . '/Multitenancy/PlatformTenantLifecycleApiTest.php';
        $nodes = (new ParserFactory())->createForHostVersion()->parse(file_get_contents($path));
        self::assertNotNull($nodes);
        return $nodes;
    }

    public function testImportedTypesResolveToTheActualCurrentSource(): void
    {
        $count = 0;
        foreach ($this->nodes() as $node) {
            if (!$node instanceof Node\Stmt\Use_ || $node->type !== Node\Stmt\Use_::TYPE_NORMAL) {
                continue;
            }
            foreach ($node->uses as $use) {
                $name = $use->name->toString();
                self::assertTrue(class_exists($name) || interface_exists($name) || enum_exists($name), $name);
                ++$count;
            }
        }
        self::assertGreaterThan(15, $count);
    }

    public function testEveryNamedConstructorSuppliesItsRequiredArguments(): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $nodes = $traverser->traverse($this->nodes());
        $localTypes = [];
        foreach ((new NodeFinder())->findInstanceOf($nodes, Node\Stmt\Class_::class) as $type) {
            if ($type->name !== null) {
                $localTypes[$type->namespacedName->toString()] = $type;
            }
        }
        $calls = (new NodeFinder())->findInstanceOf($nodes, Node\Expr\New_::class);
        $checked = 0;
        foreach ($calls as $call) {
            if (!$call->class instanceof Node\Name) {
                continue;
            }
            $class = $call->class->toString();
            if (isset($localTypes[$class])) {
                $constructor = $localTypes[$class]->getMethod('__construct');
                $required = $constructor === null ? 0 : count(array_filter($constructor->params, static fn(Node\Param $param): bool => $param->default === null && !$param->variadic));
            } else {
                self::assertTrue(class_exists($class), $class);
                $constructor = (new \ReflectionClass($class))->getConstructor();
                $required = $constructor?->getNumberOfRequiredParameters() ?? 0;
            }
            self::assertGreaterThanOrEqual($required, count($call->args), $class);
            ++$checked;
        }
        self::assertGreaterThan(15, $checked);
    }

    public function testRealRetainedFactoryConstructsTheCurrentTypedDependenciesWithoutProvisioning(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($root . '/.local/tmp/platform-lifecycle-fixture');
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($pdo);
        $database = $app->make(DbManager::class);
        $selected = [];
        $factories = 0;
        foreach ($this->nodes() as $node) {
            if ($node instanceof Node\Stmt\Use_) {
                $selected[] = $node;
            } elseif ($node instanceof Node\Stmt\Function_ && $node->name->toString() === 'lifecycleApplicationBootstrap') {
                $selected[] = $node;
                ++$factories;
            }
        }
        self::assertSame(1, $factories);
        // Only imports and that one fixture factory are evaluated. No require,
        // global assignment, lifecycle call, CREATE or DROP statement is retained.
        $source = (new Standard())->prettyPrint($selected);
        self::assertStringNotContainsString('CREATE DATABASE', $source);
        self::assertStringNotContainsString('DROP DATABASE', $source);
        eval($source);
        $host = \lifecycleApplicationBootstrap();
        self::assertInstanceOf(ApplicationTenantBootstrapService::class, $host);
        $get = static fn(object $object, string $name): mixed => (new \ReflectionProperty($object, $name))->getValue($object);
        $settings = $get($host, 'tenantSettings');
        self::assertInstanceOf(\PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery::class, $settings);
        self::assertSame($settings, $get($host, 'settingCommands'));
        self::assertSame($database, $get($host, 'database'));
        self::assertSame($get($get($host, 'externalBindings'), 'execution'), $get($get($host, 'authorization'), 'execution'));
        self::assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertFalse($pdo->inTransaction());
    }
}
