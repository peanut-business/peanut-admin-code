<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// The site demo maintenance seed is Code-only; it must not be bundled into thin applications.
require_once dirname(__DIR__, 3) . '/server/database/seed-multi-tenant-demo.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

final class DemoSiteSeedContractTest extends TestCase
{
    public static function rejectedInputs(): array
    {
        return [
            'disabled' => [['PHP_PEANUT_DEMO_MODE' => 'disabled'], 'PEANUT_DEMO_MODE=enabled is required'],
            'wrong resource' => [['PEANUT_DATABASE_RESOURCE_ID' => 'synthetic-unrelated'], 'resource do not match'],
            'wrong Edition' => [['PHP_DEPLOYMENT_MODE' => 'standalone'], 'multi-tenant deployment mode is required'],
            'same account' => [['PHP_PEANUT_DEMO_TENANT_B_EMAIL' => 'a@example.test'], 'must be different valid addresses'],
            'platform collision' => [['PHP_PEANUT_DEMO_TENANT_A_HOST' => 'platform.example.test'], 'hosts must be distinct'],
            'default collision' => [['PHP_PEANUT_DEMO_TENANT_B_HOST' => 'default.example.test'], 'hosts must be distinct'],
        ];
    }

    #[DataProvider('rejectedInputs')]
    public function testSeedRefusesInvalidSelectionsBeforeDatabaseAdmission(array $override, string $expected): void
    {
        $seed = dirname(__DIR__, 3) . '/server/database/seed-multi-tenant-demo.php';
        $environment = $override + [
            'PATH' => getenv('PATH'),
            'PHP_ENV_NAME' => 'demo-fixture-' . bin2hex(random_bytes(6)),
            'PHP_PEANUT_DEMO_MODE' => 'enabled', 'PHP_DEPLOYMENT_MODE' => 'multi-tenant',
            'PEANUT_DEPLOYMENT_TARGET' => 'local-multi-tenant-demo',
            'PEANUT_DATABASE_RESOURCE_ID' => 'peanut-admin-mysql84-local-multi-tenant-demo',
            'PHP_PEANUT_DEMO_TENANT_A_EMAIL' => 'a@example.test', 'PHP_PEANUT_DEMO_TENANT_B_EMAIL' => 'b@example.test',
            'PHP_PEANUT_DEMO_TENANT_A_HOST' => 'a.example.test', 'PHP_PEANUT_DEMO_TENANT_B_HOST' => 'b.example.test',
            'PHP_PLATFORM_HOSTS' => 'platform.example.test', 'PHP_PEANUT_DEMO_SHARED_PASSWORD' => 'peanut1234',
        ];
        $process = proc_open([PHP_BINARY, $seed, '--apply', '--default-host=default.example.test'],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname($seed), $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(1, proc_close($process), $output);
        self::assertStringContainsString($expected, $output);
    }

    public function testOnlyTheThreeDeclaredTenantOwnersCanClaimDemoEntries(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2030-01-01 00:00:00', 1);
        ThinkPhpTestConnection::fromPdo($pdo);
        $pdo->exec("CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, code TEXT, status TEXT);
            CREATE TABLE pa_tenant_entry_binding (id INTEGER PRIMARY KEY, tenant_id INTEGER, host TEXT, client_key TEXT, status TEXT, updated_at TEXT, UNIQUE(host,client_key));
            INSERT INTO pa_tenant VALUES (1,'default','active'),(2,'tenant-a','active'),(3,'tenant-b','active')");
        demoMultiAssertSeedState();
        foreach ([1 => 'default', 2 => 'a', 3 => 'b'] as $id => $host) {
            demoMultiBinding($id, $host . '.example.test');
        }
        demoMultiBinding(2, 'A.EXAMPLE.TEST:443');
        $rows = static fn(): array => $pdo->query('SELECT tenant_id,host,client_key,status FROM pa_tenant_entry_binding ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $before = $rows();
        self::assertSame([1,1,2,2,3,3], array_column($before, 'tenant_id'));
        self::assertSame(['admin-web','member-api','admin-web','member-api','admin-web','member-api'], array_column($before, 'client_key'));
        try {
            demoMultiBinding(3, 'a.example.test');
            self::fail('Another tenant claimed an existing demo Host.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('already owned by another Tenant', $error->getMessage());
        }
        self::assertSame($before, $rows());
        $pdo->exec("INSERT INTO pa_tenant VALUES (4,'customer','active')");
        $this->expectExceptionMessage('exact fresh baseline or its completed retry state');
        demoMultiAssertSeedState();
    }

    public function testPublicConfigurationDisclosesOnlyTheSelectedDemoTenant(): void
    {
        $config = ['enabled' => true, 'tenant_a_host' => 'a.example.test', 'tenant_b_host' => 'b.example.test',
            'tenant_a_email' => 'a@example.test', 'tenant_b_email' => 'b@example.test', 'password' => 'synthetic'];
        foreach (['a', 'b'] as $site) {
            self::assertSame(['enabled' => true, 'email' => $site . '@example.test', 'password' => 'synthetic'],
                DemoAccountPolicy::publicLoginConfiguration($site . '.example.test', $config));
        }
        $disabled = ['enabled' => false, 'email' => '', 'password' => ''];
        foreach (['unknown.example.test', 'platform.example.test', 'user@a.example.test'] as $host) {
            self::assertSame($disabled, DemoAccountPolicy::publicLoginConfiguration($host, $config));
        }
        self::assertSame($disabled, DemoAccountPolicy::publicLoginConfiguration('a.example.test', ['enabled' => false] + $config));
    }
}
