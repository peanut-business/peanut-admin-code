<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\persistence\TenantPersistenceConfiguration;
use app\common\tenancy\MultiTenantDataScopePolicy;
use app\common\value\scaffold\EditionProfile;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PHPUnit\Framework\TestCase;

final class A1EditionArchitectureTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function testBothEditionsShareTenantOwnedSchemaAndDataScopePolicy(): void
    {
        $profilePath = $this->repositoryRoot . '/scaffold/edition-profiles.json';
        $standalone = EditionProfile::load($profilePath, 'standalone')->identity();
        $saas = EditionProfile::load($profilePath, 'multi-tenant')->identity();

        self::assertSame(3, $standalone['generator_version']);
        self::assertSame(MultiTenantDataScopePolicy::class, $standalone['data_scope_policy']);
        self::assertSame($standalone['data_scope_policy'], $saas['data_scope_policy']);
        self::assertSame('tenant-owned-v1', $standalone['schema']['projection']);
        self::assertSame($standalone['schema']['projection'], $saas['schema']['projection']);
        self::assertTrue($standalone['schema']['retains_core_tenant_identity']);
        self::assertTrue($saas['schema']['retains_core_tenant_identity']);
        self::assertSame([], $standalone['schema']['table_rules']);
        self::assertSame([], $saas['schema']['table_rules']);
        self::assertSame(
            'blocked-manual-migration-required',
            $standalone['schema']['legacy_tenantless_upgrade'],
        );
    }

    public function testPersistenceConfigurationOnlyAllowsTenantOwnedStorage(): void
    {
        $configuration = new TenantPersistenceConfiguration();
        self::assertSame(TenantPersistenceMode::TenantScoped, $configuration->mode);
        self::assertNull($configuration->instanceTenantId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TENANTLESS_LEGACY_SCHEMA_MANUAL_MIGRATION_REQUIRED');
        new TenantPersistenceConfiguration(TenantPersistenceMode::InstanceScoped, 1);
    }

    public function testSharedPolicyInjectsTrustedTenantAndRejectsClientOwnedMismatch(): void
    {
        $store = new ExecutionContextStore();
        $policy = new MultiTenantDataScopePolicy(new CurrentExecutionContext($store));
        $model = $this->createMock(think\Model::class);
        $model->expects(self::once())->method('getData')->willReturn([]);
        $model->expects(self::once())->method('setAttr')->with('tenant_id', 71)->willReturnSelf();

        $store->run(
            new SystemExecutionContext(new TenantSystemContext(71, 'installer', 'a1.install', 'a1-install-71')),
            static fn() => $policy->prepareWrite($model),
        );
        self::assertTrue($policy->usesTenantColumn());

        $mismatched = $this->createMock(think\Model::class);
        $mismatched->expects(self::once())->method('getData')->willReturn(['tenant_id' => 72]);
        $mismatched->expects(self::never())->method('setAttr');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TENANT_WRITE_OWNERSHIP_MISMATCH');
        $store->run(
            new SystemExecutionContext(new TenantSystemContext(71, 'installer', 'a1.install', 'a1-install-72')),
            static fn() => $policy->prepareWrite($mismatched),
        );
    }

    public function testStandaloneEntryIsPinnedToServerResolvedDefaultTenant(): void
    {
        $service = file_get_contents($this->repositoryRoot . '/server/app/AppService.php');
        self::assertIsString($service);
        self::assertStringContainsString('DefaultTenantEntryBindingLookup::class', $service);
        self::assertStringContainsString('new MultiTenantDataScopePolicy(', $service);
        self::assertStringNotContainsString('StandaloneDataScopePolicy', $service);
        self::assertStringNotContainsString('public_default_tenant_fallback', $service);
        self::assertFileDoesNotExist(
            $this->repositoryRoot . '/server/app/common/tenancy/StandaloneDataScopePolicy.php',
        );
    }
}
