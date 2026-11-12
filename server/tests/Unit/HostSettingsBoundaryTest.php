<?php

declare(strict_types=1);

use app\adminapi\services\config\ConfigApplicationService;
use app\adminapi\services\setting\HotSearchApplicationService;
use app\api\services\IndexApplicationService;
use app\api\services\SearchApplicationService;
use app\common\services\RichTextResourceService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\Modules\Settings\ModuleProvider;
use PeanutAdmin\Modules\Settings\Service\TenantApplicationSettingService;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;

/** Real host methods and native DI; storage, identity and transport remain explicit test doubles. */
final class HostSettingsBoundaryTest extends TestCase
{
    public static function consumers(): array
    {
        return array_map(static fn(string $type): array => [$type], [
            ConfigApplicationService::class,
            HotSearchApplicationService::class,
            IndexApplicationService::class,
            SearchApplicationService::class,
        ]);
    }

    #[DataProvider('consumers')]
    public function testHostConstructorAcceptsThePublishedContract(string $type): void
    {
        $parameters = (new ReflectionClass($type))->getConstructor()->getParameters();
        $settings = array_values(array_filter($parameters, static fn(ReflectionParameter $parameter): bool => $parameter->getName() === 'applicationSettings'));
        self::assertCount(1, $settings);
        self::assertSame(TenantApplicationSettings::class, $settings[0]->getType()->getName());
    }

    public function testNativeContainerUsesTheSelectedPublicImplementation(): void
    {
        $bindings = (new ModuleProvider())->bindings();
        self::assertSame(TenantApplicationSettingService::class, $bindings[TenantApplicationSettings::class]);
        $settings = $this->createStub(TenantApplicationSettings::class);
        $app = new App(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server'));
        $app->instance(TenantApplicationSettings::class, $settings);
        $consumer = $app->make(SearchApplicationService::class);
        self::assertSame($settings, (new ReflectionProperty($consumer, 'applicationSettings'))->getValue($consumer));
    }

    public function testApplicationCompositionRequestsTheContractRatherThanTheConcreteClass(): void
    {
        $source = file_get_contents(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/app/AppService.php');
        self::assertStringNotContainsString('Settings\\Service\\TenantApplicationSettingService::class', $source);
        self::assertSame(2, substr_count($source, 'Settings\\Contract\\TenantApplicationSettings::class'));
    }

    public function testLoginSettingsPreserveReadAndWritePayloads(): void
    {
        $context = $this->tenant();
        $settings = $this->createMock(TenantApplicationSettings::class);
        $settings->expects(self::once())->method('login')->with($context)->willReturn([
            'login_way' => [1, 2], 'coerce_mobile' => '0', 'login_agreement' => '1', 'third_auth' => '0', 'wechat_auth' => '1',
        ]);
        $settings->expects(self::once())->method('replaceLogin')->with($context, [
            'login_way' => [1, 2], 'coerce_mobile' => 0, 'login_agreement' => 1, 'third_auth' => 0, 'wechat_auth' => 1,
        ]);
        $consumer = $this->configuration($settings);
        self::assertSame([
            'login_way' => [1, 2], 'coerce_mobile' => 0, 'login_agreement' => 1, 'third_auth' => 0, 'wechat_auth' => 1,
        ], $consumer->getLogin($context));
        self::assertTrue($consumer->saveLogin($context, [
            'login_way' => ['2', '1', '2'], 'coerce_mobile' => '0', 'login_agreement' => '1', 'third_auth' => '0', 'wechat_auth' => '1',
        ]));
    }

    public function testPublicSettingsFailureIsNotSwallowed(): void
    {
        $settings = $this->createMock(TenantApplicationSettings::class);
        $settings->expects(self::once())->method('replaceStatistics')->with($this->tenant(), ['clarity_code' => 'fixture'])->willThrowException(new DomainException('SETTING_PERMISSION_DENIED'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SETTING_PERMISSION_DENIED');
        $this->configuration($settings)->saveStatistics($this->tenant(), ['clarity_code' => ' fixture ']);
    }

    private function configuration(TenantApplicationSettings $settings): ConfigApplicationService
    {
        return new ConfigApplicationService(
            $settings,
            $this->createStub(FileReferences::class),
            (new ReflectionClass(RichTextResourceService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(WebsiteConfigService::class))->newInstanceWithoutConstructor(),
            '',
        );
    }

    private function tenant(): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01J00000000000000000000000',
            101,
            301,
            501,
            'admin-web',
            new DateTimeImmutable('2031-01-01T00:00:00Z'),
            1,
            1,
        ), 'host-settings-test');
    }
}
