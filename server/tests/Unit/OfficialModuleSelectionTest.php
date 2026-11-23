<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\context\PlatformOperatorContext;
use app\platform\controller\PlatformModuleLifecycleController;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\exception\module\ModulePackageException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use think\Request;

/** HTTP adapter rejection tests: no package fetch, database, or authenticated-session acceptance. */
final class OfficialModuleSelectionTest extends TestCase
{
    public static function operations(): array
    {
        return [['install'], ['update']];
    }

    private function controller(array $input, bool $context = true): PlatformModuleLifecycleController
    {
        $controller = (new ReflectionClass(PlatformModuleLifecycleController::class))->newInstanceWithoutConstructor();
        $request = (new Request())->withPost($input)->withHeader(['idempotency-key' => 'test-fixed-request-key']);
        (new ReflectionProperty($controller, 'request'))->setValue($controller, $request);
        if ($context) {
            // Only the non-null branch is exercised; no fixture can produce a validated Core session.
            $fixture = (new ReflectionClass(PlatformOperatorContext::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty($controller, 'platformContext'))->setValue($controller, $fixture);
        }
        return $controller;
    }

    #[DataProvider('operations')]
    public function testMissingPlatformContextRejectsBeforeReadingPackages(string $operation): void
    {
        $controller = $this->controller(['dry_run' => true], false);
        $this->expectException(ModuleLifecycleException::class);
        $this->expectExceptionMessage('validated Platform session');
        $controller->{'official' . ucfirst($operation)}();
    }

    #[DataProvider('operations')]
    public function testCallerCannotInjectOperationIntoPackageSelection(string $operation): void
    {
        $controller = $this->controller(['dry_run' => true, 'operation' => $operation]);
        $this->expectException(ModulePackageException::class);
        $this->expectExceptionMessage('explicit dry_run');
        $controller->{'official' . ucfirst($operation)}();
    }

    #[DataProvider('operations')]
    public function testStringDryRunCannotSubmitAWrite(string $operation): void
    {
        $controller = $this->controller(['dry_run' => 'false']);
        $this->expectException(ModulePackageException::class);
        $this->expectExceptionMessage('explicit dry_run');
        $controller->{'official' . ucfirst($operation)}();
    }

    #[DataProvider('operations')]
    public function testConfirmationCannotBeReusedForAnotherOperation(string $operation): void
    {
        $selection = [
            'module_key' => 'peanut.file', 'version' => '1.0.1', 'sha256' => str_repeat('a', 64),
            'catalog_tag' => 'v1.0.1', 'channel' => 'stable',
        ];
        $other = $operation === 'install' ? 'update' : 'install';
        $digest = hash('sha256', json_encode($selection + ['operation' => $other], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $controller = $this->controller($selection + ['dry_run' => false, 'confirm_selection_sha256' => $digest]);
        $this->expectException(ModulePackageException::class);
        $this->expectExceptionMessage('reviewed target changed');
        $controller->{'official' . ucfirst($operation)}();
    }
}
