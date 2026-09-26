<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands;
use PeanutAdmin\Modules\OAuth\Service\OAuthBrowserCallbackService;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsProvider;
use PeanutAdmin\Modules\Settings\Infrastructure\BrandDefaults;
use PeanutAdmin\Modules\Settings\Service\TenantSettingService;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use PHPUnit\Framework\TestCase;
use think\App;

/** Executes existing facade logic; persistence and file storage are explicit test doubles. */
final class HostModuleFacadeTest extends TestCase
{
    public function testPublishedFacadesDoNotExposeTheirPersistenceCollaborators(): void
    {
        foreach (['settings' => WebsiteConfigService::class, 'oauth' => OAuthBrowserCallbackService::class] as $module => $facade) {
            $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/' . $module . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertContains($facade, $manifest['contracts']['exports']);
            foreach ($manifest['contracts']['exports'] as $export) {
                self::assertFalse(\app\platform\validation\module\ModulePublicSurfacePolicy::isInternalPersistence($export));
            }
        }
    }

    public function testDefaultsAreFixedPublicValuesAndDoNotRequirePersistence(): void
    {
        $defaults = WebsiteConfigService::defaults();
        self::assertSame(BrandDefaults::website(), $defaults);
        self::assertSame(WebsiteConfigService::fields(), array_keys($defaults));
        $defaults['name'] = 'local change';
        self::assertNotSame($defaults, WebsiteConfigService::defaults());
        foreach (['common/services/readiness/FirstRunReadinessHost.php', 'platform/services/ApplicationTenantBootstrapService.php'] as $path) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/app/' . $path);
            self::assertStringNotContainsString('Settings\\Infrastructure\\BrandDefaults', $source);
            self::assertStringContainsString('WebsiteConfigService::defaults()', $source);
        }
    }

    public function testNativeContainerResolvesTheExistingFacade(): void
    {
        $provider = $this->createMock(TenantSettingsProvider::class);
        $provider->expects(self::never())->method('find');
        $provider->expects(self::never())->method('replace');
        $app = new App(dirname(__DIR__, 2));
        $app->instance(TenantSettingService::class, new TenantSettingService($provider));
        $app->instance(FileReferences::class, $this->createStub(FileReferences::class));
        self::assertInstanceOf(WebsiteConfigService::class, $app->make(WebsiteConfigService::class));
    }

    public function testReadPreservesTenantSelectionAndImageMapping(): void
    {
        $provider = $this->createMock(TenantSettingsProvider::class);
        $seen = [];
        $provider->expects(self::exactly(2))->method('find')->willReturnCallback(static function (int $tenant, string $namespace) use (&$seen): TenantSettingSnapshot {
            $seen[] = [$tenant, $namespace];
            return new TenantSettingSnapshot($tenant, $namespace, ['name' => 'Tenant ' . $tenant, 'web_logo' => 'tenant/' . $tenant . '.svg', 'private_extra' => 'not public'], 1, 1, 1);
        });
        $provider->expects(self::never())->method('replace');
        $files = $this->createMock(FileReferences::class);
        $files->expects(self::exactly(14))->method('getFileUrl')->willReturnCallback(static fn(string $path): string => 'public:' . $path);
        $service = new WebsiteConfigService(new TenantSettingService($provider), $files);
        foreach ([101, 202] as $tenant) {
            $result = $service->get($this->context($tenant));
            self::assertSame('Tenant ' . $tenant, $result['name']);
            self::assertSame('public:tenant/' . $tenant . '.svg', $result['web_logo']);
            self::assertArrayNotHasKey('private_extra', $result);
            self::assertSame(WebsiteConfigService::fields(), array_keys($result));
        }
        self::assertSame([[101, 'website'], [202, 'website']], $seen);
    }

    public function testSaveKeepsTenantScopedFileMappingAndOneCompleteWrite(): void
    {
        $context = $this->context(101);
        $provider = $this->createMock(TenantSettingsProvider::class);
        $provider->expects(self::never())->method('find');
        $provider->expects(self::once())->method('replace')->willReturnCallback(static function (int $tenant, string $namespace, array $document): TenantSettingSnapshot {
            self::assertSame(101, $tenant);
            self::assertSame('website', $namespace);
            self::assertSame(WebsiteConfigService::fields(), array_keys($document));
            self::assertSame('Tenant A', $document['name']);
            self::assertSame('stored:logo.svg', $document['web_logo']);
            self::assertArrayNotHasKey('private_extra', $document);
            return new TenantSettingSnapshot($tenant, $namespace, $document, 1, 1, 1);
        });
        $files = $this->createMock(FileReferences::class);
        $files->expects(self::exactly(7))->method('setTenantFileUrl')->willReturnCallback(static function ($actual, string $path) use ($context): string {
            self::assertSame($context, $actual);
            return 'stored:' . $path;
        });
        $service = new WebsiteConfigService(new TenantSettingService($provider), $files);
        $service->save($context, ['name' => ' Tenant A ', 'web_logo' => ' logo.svg ', 'private_extra' => 'ignored']);
    }

    public function testInvalidWebsiteInputAndStorageFailureRemainFailures(): void
    {
        $provider = $this->createMock(TenantSettingsProvider::class);
        $provider->expects(self::once())->method('replace')->willThrowException(new DomainException('FIXTURE_STORAGE_FAILURE'));
        $files = $this->createStub(FileReferences::class);
        $files->method('setTenantFileUrl')->willReturnArgument(1);
        $service = new WebsiteConfigService(new TenantSettingService($provider), $files);
        try {
            $service->save($this->context(101), ['name' => []]);
            self::fail('Invalid values reached persistence');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FIXTURE_STORAGE_FAILURE');
        $service->save($this->context(101), ['name' => 'Tenant A']);
    }

    public function testBrowserCallbacksKeepRoutesAndDoNotForwardUnapprovedFields(): void
    {
        self::assertSame('https://product.test/api/oauth/wechat/redirect/pc', OAuthBrowserCallbackService::callbackUrl('https://product.test/', 'open_pc'));
        self::assertSame('/pc/oauth/callback?code=code%20value&state=state', OAuthBrowserCallbackService::clientRedirectUrl('pc', ['code' => ' code value ', 'state' => 'state', 'ticket' => 'private', 'redirect' => 'https://other.test', 'error' => ['invalid']]));
        self::assertSame('/mobile/#/pages/oauth/callback?scene=oa&code=oa', OAuthBrowserCallbackService::clientRedirectUrl('official-account', ['code' => 'oa']));
        $this->expectException(InvalidArgumentException::class);
        OAuthBrowserCallbackService::clientRedirectUrl('unregistered', []);
    }

    public function testAuthorizationUsesTheStablePublicResourceIdentifier(): void
    {
        self::assertSame('peanut.import-export', ImportExportCommands::RESOURCE_KEY);
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/services/authorization/AdminAuthorizationService.php');
        self::assertStringNotContainsString('ImportExport\\Engine\\Application\\ImportExportService', $source);
        self::assertSame(2, substr_count($source, 'ImportExportCommands::RESOURCE_KEY'));
    }

    private function context(int $tenant): TenantSystemContext
    {
        return new TenantSystemContext($tenant, 'fixture', 'fixture.website', 'fixture-request');
    }
}
