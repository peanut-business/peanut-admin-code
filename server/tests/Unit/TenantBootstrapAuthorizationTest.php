<?php

declare(strict_types=1);

namespace tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** Native catalog composition remains; owner bootstrap no longer grants commercial module permissions. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TenantBootstrapAuthorizationTest extends TestCase
{
    public function testNativeModuleCatalogFixtureConstructsTheCurrentDependencies(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($root . '/.local/tmp/tenant-bootstrap-authorization-' . bin2hex(random_bytes(6)));
        $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        self::assertInstanceOf(
            \app\platform\infrastructure\module\ModuleCatalogApplier::class,
            \ThinkPhpTestConnection::moduleCatalogs($database),
        );
    }
}
