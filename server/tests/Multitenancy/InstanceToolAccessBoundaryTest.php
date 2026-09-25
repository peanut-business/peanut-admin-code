<?php

declare(strict_types=1);

namespace tests\Multitenancy;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\InstanceExecutionContext;
use app\common\validation\instance\InstanceToolAccessGuard;
use PHPUnit\Framework\TestCase;

final class InstanceToolAccessBoundaryTest extends TestCase
{
    public function testHttpInstanceToolBoundaryRemainsStandaloneOnly(): void
    {
        self::assertTrue(InstanceToolAccessGuard::fromConfiguredValue('standalone')->allows());
        foreach ([null, '', 'multi-tenant', 'production', 'STANDALONE', 1] as $value) {
            self::assertFalse(InstanceToolAccessGuard::fromConfiguredValue($value)->allows());
        }

        $serverRoot = dirname(__DIR__, 2);
        $middleware = (string) file_get_contents(
            $serverRoot . '/app/platform/http/middleware/PlatformInstanceToolMiddleware.php',
        );
        self::assertStringContainsString('InstanceToolAccessGuard::fromConfiguredValue', $middleware);
        self::assertStringContainsString('->allows()', $middleware);
        self::assertStringNotContainsString('allowsCliDevelopmentMaintenance', $middleware);
    }

    public function testTrustedConsoleAllowsBothKnownEditionsInDevelopmentDebug(): void
    {
        self::assertSame('cli', PHP_SAPI);
        foreach (['standalone', 'multi-tenant'] as $mode) {
            foreach ([
                'module:install-package',
                'module:update-package',
                'module:disable-package',
                'module:uninstall-package',
            ] as $command) {
                $store = new ExecutionContextStore();
                $current = new CurrentExecutionContext($store);
                $allowed = $store->run(
                    new InstanceExecutionContext('console.' . $command, 'cli-contract-' . md5($mode . $command)),
                    fn(): bool => InstanceToolAccessGuard::fromConfiguredValue($mode)
                        ->allowsCliDevelopmentMaintenance('development', true, $current, $command, true),
                );
                self::assertTrue($allowed, "{$mode} {$command} should be available to the trusted console");
                self::assertTrue($store->isEmpty());
            }
        }
    }

    public function testCliMaintenanceFailsClosedForInvalidRuntimeState(): void
    {
        $store = new ExecutionContextStore();
        $current = new CurrentExecutionContext($store);
        $guard = InstanceToolAccessGuard::fromConfiguredValue('multi-tenant');

        self::assertFalse($guard->allowsCliDevelopmentMaintenance(
            'development',
            true,
            $current,
            'module:install-package',
            true,
        ));

        $store->run(new InstanceExecutionContext('console.module:install-package', 'cli-contract-invalid'), function () use ($guard, $current): void {
            self::assertFalse($guard->allowsCliDevelopmentMaintenance(
                'production',
                true,
                $current,
                'module:install-package',
                true,
            ));
            self::assertFalse($guard->allowsCliDevelopmentMaintenance(
                'development',
                false,
                $current,
                'module:install-package',
                true,
            ));
            self::assertFalse($guard->allowsCliDevelopmentMaintenance(
                'development',
                true,
                $current,
                'module:update-package',
                true,
            ));
            self::assertFalse(InstanceToolAccessGuard::fromConfiguredValue('unknown')->allowsCliDevelopmentMaintenance(
                'development',
                true,
                $current,
                'module:install-package',
                true,
            ));
            self::assertFalse($guard->allowsCliDevelopmentMaintenance(
                'development',
                true,
                $current,
                'module:install-package',
                false,
            ));
        });
        self::assertTrue($store->isEmpty());
    }

    public function testPersonnelOrRequestContextCannotBecomeInstanceMaintainer(): void
    {
        $store = new ExecutionContextStore();
        $current = new CurrentExecutionContext($store);
        $person = new class implements ExecutionContext {
            public function operation(): string
            {
                return 'console.module:install-package';
            }
            public function requestId(): string
            {
                return 'http-personnel-context';
            }
            public function tenantId(): int
            {
                return 101;
            }
            public function actor(): array
            {
                return ['tenant_id' => 101, 'id' => 301];
            }
        };

        $allowed = $store->run(
            $person,
            fn(): bool => InstanceToolAccessGuard::fromConfiguredValue('multi-tenant')
                ->allowsCliDevelopmentMaintenance('development', true, $current, 'module:install-package', true),
        );
        self::assertFalse($allowed);
        self::assertTrue($store->isEmpty());
    }
}
