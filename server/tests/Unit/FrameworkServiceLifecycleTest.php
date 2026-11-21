<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
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
        $cacheFile = $temporary . '/config/cache.php';
        $server = dirname(__DIR__, 2);
        copy($server . '/app/service.php', $serviceFile);
        copy($server . '/app/provider.php', $temporary . '/app/provider.php');
        file_put_contents($temporary . '/config/peanut.php', '<?php return ' . var_export([
            'bindings' => [
                Clock::class => ConfiguredLifecycleClock::class,
                PasswordPolicy::class => ConfiguredLifecyclePasswordPolicy::class,
                PasswordHasher::class => ConfiguredLifecyclePasswordHasher::class,
            ],
            'services' => [ConfiguredLifecycleService::class],
        ], true) . ';');
        file_put_contents($temporary . '/config/deployment.php', "<?php return ['mode' => 'multi-tenant'];");
        copy($server . '/config/modules.php', $temporary . '/config/modules.php');
        file_put_contents($configFile, "<?php\nreturn ['default_timezone' => 'UTC'];\n");
        file_put_contents($cacheFile, '<?php return ' . var_export([
            'default' => 'file',
            'stores' => ['file' => ['type' => 'File', 'path' => $temporary . '/runtime/cache/']],
        ], true) . ';');
        $app = new App($temporary);
        $defaultClock = $app->make(SystemClock::class);
        $app->event->listen(AppInit::class, static function () use ($app): void {
            $app->getService(ConfiguredLifecycleService::class)->events[] = 'AppInit';
        });
        $reporting = error_reporting();
        $timezone = date_default_timezone_get();
        $previousErrorHandler = set_error_handler(static fn(): bool => false);
        restore_error_handler();
        $previousExceptionHandler = set_exception_handler(static function (): void {});
        restore_exception_handler();
        try {
            $app->initialize();
            $probe = $app->getService(ConfiguredLifecycleService::class);
            self::assertSame(['register:12', 'AppInit', 'boot:2026'], $probe->events);
            self::assertInstanceOf(ConfiguredLifecyclePasswordHasher::class, $app->make(PasswordHasher::class));
            self::assertSame($defaultClock, $app->make(SystemClock::class));
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
            unlink($cacheFile);
            unlink($temporary . '/app/provider.php');
            foreach (['deployment', 'modules', 'peanut'] as $config) {
                unlink($temporary . '/config/' . $config . '.php');
            }
            rmdir($temporary . '/app');
            rmdir($temporary . '/config');
            // Runtime logs, if any, are retained in this uniquely owned temporary root.
            if ((scandir($temporary) ?: []) === ['.', '..']) {
                rmdir($temporary);
            }
        }
    }
}

final class ConfiguredLifecycleClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-21T00:00:00Z');
    }
}

final class ConfiguredLifecyclePasswordPolicy extends PasswordPolicy
{
    public function __construct()
    {
        parent::__construct(12);
    }
}

final class ConfiguredLifecyclePasswordHasher extends PasswordHasher {}

final class ConfiguredLifecycleService extends Service
{
    public array $events = [];

    public function register(): void
    {
        $this->events[] = 'register:' . $this->app->make(PasswordPolicy::class)->minimumLength();
    }

    public function boot(Clock $clock): void
    {
        $this->events[] = 'boot:' . $clock->now()->format('Y');
    }
}
