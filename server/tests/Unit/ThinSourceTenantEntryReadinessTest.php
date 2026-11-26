<?php

declare(strict_types=1);

use app\AppService;
use app\common\exception\installation\InstallationExecutionException;
use app\common\http\ApiProblemMapper;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingLookup;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use PHPUnit\Framework\TestCase;
use think\App;

final class ThinSourceTenantEntryReadinessTest extends TestCase
{
    public function testMultiTenantSourceCheckoutReportsMissingIdentityAs503NotClassFailure(): void
    {
        $this->assertMissingModuleSource('multi-tenant');
    }

    public function testStandaloneSourceCheckoutReportsMissingIdentityAs503NotClassFailure(): void
    {
        $this->assertMissingModuleSource('standalone');
    }

    public function testUnknownTenantNeverFallsBackToAnUnrelatedKnownHost(): void
    {
        $lookup = new class implements TenantEntryBindingLookup {
            public function binding(string $host, string $clientKey): ?array
            {
                return $host === 'tenant-a.example.test' && $clientKey === TenantEntryBindingResolver::MEMBER_CLIENT
                    ? ['tenant_id' => 11, 'tenant_code' => 'a'] : null;
            }
        };
        $resolver = new TenantEntryBindingResolver(null, true, $lookup);
        $known = new class {
            public function host(): string { return 'tenant-a.example.test:20185'; }
        };
        $unknown = new class {
            public function host(): string { return 'unknown.example.test'; }
        };
        self::assertSame(11, $resolver->system($known, TenantEntryBindingResolver::MEMBER_CLIENT,
            'peanut.decoration.public-read', 'decoration.config', 'synthetic-known')->tenantId);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TENANT_ENTRY_BINDING_UNAVAILABLE');
        $resolver->system($unknown, TenantEntryBindingResolver::MEMBER_CLIENT,
            'peanut.decoration.public-read', 'decoration.config', 'synthetic-unknown');
    }

    private function assertMissingModuleSource(string $mode): void
    {
        $requiredClass = $mode === 'standalone'
            ? \PeanutAdmin\Modules\Identity\Tenancy\DefaultTenantContextResolver::class
            : \PeanutAdmin\Modules\Identity\Tenancy\Infrastructure\ThinkPhpTenantEntryBindingLookup::class;
        if (class_exists($requiredClass)) {
            self::markTestSkipped('Thin source checkout only; prepared Module consumers are covered separately.');
        }
        $app = new App(dirname(__DIR__, 2));
        $app->config->set(['mode' => $mode], 'deployment');
        $service = new AppService($app);
        (new ReflectionMethod($service, 'registerPlatform'))->invoke($service);

        try {
            $app->make(TenantEntryBindingResolver::class);
            self::fail('A thin author checkout must not bind a tenant without the Identity module.');
        } catch (InstallationExecutionException $exception) {
            self::assertSame('INSTALL_MODULE_SOURCE_MISSING', $exception->errorCode);
            self::assertSame(503, $exception->httpStatus);
            self::assertSame('peanut.identity', $exception->details()['module']);
            $problem = (new ApiProblemMapper())->map($exception);
            self::assertNotNull($problem);
            self::assertSame(503, $problem->httpStatus);
            self::assertSame(50300, $problem->apiCode());
        }
    }
}
