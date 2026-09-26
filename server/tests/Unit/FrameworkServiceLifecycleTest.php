<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\event\AppInit;
use think\Service;

/** Real locked ThinkPHP initialization with an isolated synthetic app, not Peanut runtime installation. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FrameworkServiceLifecycleTest extends TestCase
{
    public function testApplicationServiceRegistrationPrecedesAppInitAndBootFollowsIt(): void
    {
        $root = dirname(__DIR__, 3);
        $parent = $root . '/.local/tmp/framework-service-lifecycle-tests';
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('FRAMEWORK_LIFECYCLE_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($parent));
        $temporary = $parent . '/case-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($temporary . '/app', 0700, true));
        self::assertTrue(mkdir($temporary . '/config', 0700));
        $serviceFile = $temporary . '/app/service.php';
        $configFile = $temporary . '/config/app.php';
        file_put_contents($serviceFile, "<?php\nreturn [\$this->get('lifecycle.probe')];\n");
        file_put_contents($configFile, "<?php\nreturn ['default_timezone' => 'UTC'];\n");
        $app = new App($temporary);
        $probe = new class($app) extends Service {
            public array $events = [];

            public function register(): void
            {
                $this->events[] = 'register';
            }

            public function boot(): void
            {
                $this->events[] = 'boot';
            }
        };
        $app->instance('lifecycle.probe', $probe);
        $app->event->listen(AppInit::class, static function () use ($probe): void {
            $probe->events[] = 'AppInit';
        });
        $reporting = error_reporting();
        $timezone = date_default_timezone_get();
        $previousErrorHandler = set_error_handler(static fn(): bool => false);
        restore_error_handler();
        $previousExceptionHandler = set_exception_handler(static function (): void {});
        restore_exception_handler();
        try {
            $app->initialize();
            self::assertSame(['register', 'AppInit', 'boot'], $probe->events);
            self::assertSame($probe, $app->getService($probe));
        } finally {
            // Native Error initialization installs handlers; restore the test runner's actual previous handlers.
            $currentErrorHandler = set_error_handler(static fn(): bool => false);
            restore_error_handler();
            if ($currentErrorHandler !== $previousErrorHandler) {
                restore_error_handler();
            }
            $currentExceptionHandler = set_exception_handler(static function (): void {});
            restore_exception_handler();
            if ($currentExceptionHandler !== $previousExceptionHandler) {
                restore_exception_handler();
            }
            error_reporting($reporting);
            date_default_timezone_set($timezone);
            unlink($serviceFile);
            unlink($configFile);
            rmdir($temporary . '/app');
            rmdir($temporary . '/config');
            // Runtime logs, if any, are retained in this uniquely owned temporary root.
            if ((scandir($temporary) ?: []) === ['.', '..']) {
                rmdir($temporary);
            }
        }
    }
}
