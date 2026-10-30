<?php

declare(strict_types=1);

use app\adminapi\controller\decoration\DecorationPageController;
use app\adminapi\services\config\ConfigApplicationService;
use app\common\services\RichTextResourceService;
use app\common\exception\BusinessException;
use app\adminapi\validate\decoration\DecorationPageValidate;
use app\adminapi\validate\config\WebsiteValidate;
use app\common\enum\decoration\DecorationEnum;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\services\decoration\DecorationReadService;
use app\common\services\decoration\DecorationSchemaService;
use app\common\services\ProductAssetReferenceService;
use app\common\validate\InputValidator;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\exception\ValidateException;
use think\Request;

/** Production schemas and editing DTOs; no database, Article provider or browser is started. */
final class DecorationInputBoundaryTest extends TestCase
{
    public function testDefaultAvatarCanRoundTripEmptyWithoutWritingTheDisplayUrl(): void
    {
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, str_repeat('a', 26), 101, 201, 301, 'admin-web', new DateTimeImmutable('+1 hour'), 1), 'default-avatar-input');
        $settings = $this->createMock(TenantApplicationSettings::class);
        $settings->method('memberProfile')->with($context)->willReturn(['user_avatar' => '']);
        $settings->expects(self::once())->method('replaceMemberProfile')->with($context, ['user_avatar' => '']);
        $files = $this->createMock(FileReferences::class);
        $files->expects(self::never())->method('getFileUrl');
        $files->expects(self::once())->method('setTenantFileUrl')->with($context, '')->willReturn('');
        $service = new ConfigApplicationService($settings, $files,
            (new ReflectionClass(RichTextResourceService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(WebsiteConfigService::class))->newInstanceWithoutConstructor(), 'brand/avatar-member.svg',
        );
        $editor = $service->getUser($context);
        self::assertSame('', $editor['default_avatar']);
        self::assertSame('/brand/avatar-member.svg', $editor['default_avatar_url']);
        $app = new App();
        $input = (new InputValidator($app, new CurrentExecutionContext(new ExecutionContextStore())))->validateInput(
            ['default_avatar' => $editor['default_avatar']], WebsiteValidate::class . '.user', ['default_avatar'],
        )->writable();
        self::assertSame(['default_avatar' => ''], $input);
        self::assertTrue($service->saveUser($context, $input));
        foreach ([[], ['default_avatar' => null], ['default_avatar' => false], ['default_avatar' => []], ['default_avatar' => str_repeat('x', 501)]] as $invalid) {
            self::assertFalse((new WebsiteValidate())->scene('user')->check($invalid));
        }
    }

    public function testOnlyTheDeclaredAvatarDefaultBypassesFileStorage(): void
    {
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, str_repeat('a', 26), 101, 201, 301, 'admin-web', new DateTimeImmutable('+1 hour'), 1), 'declared-avatar-input');
        $default = 'brand/avatar-member.svg';
        $settings = $this->createMock(TenantApplicationSettings::class);
        $settings->method('memberProfile')->with($context)->willReturn(['user_avatar' => $default]);
        $settings->expects(self::once())->method('replaceMemberProfile')->with($context, ['user_avatar' => $default]);
        $files = $this->createMock(FileReferences::class);
        $files->expects(self::never())->method('getFileUrl');
        $files->expects(self::exactly(3))->method('setTenantFileUrl')->willThrowException(BusinessException::invalid('FILE_REFERENCE_INVALID', 'Stable file reference required.'));
        $service = new ConfigApplicationService($settings, $files,
            (new ReflectionClass(RichTextResourceService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(WebsiteConfigService::class))->newInstanceWithoutConstructor(), $default,
        );
        $editor = $service->getUser($context);
        self::assertSame($default, $editor['default_avatar']);
        self::assertSame('/brand/avatar-member.svg', $editor['default_avatar_url']);
        self::assertTrue((new WebsiteValidate())->scene('user')->check(['default_avatar' => $editor['default_avatar']]));
        self::assertTrue($service->saveUser($context, ['default_avatar' => $editor['default_avatar']]));
        foreach (['brand/avatar-admin.svg', 'brand/unregistered.svg', 'https://assets.example/default-avatar'] as $invalid) {
            try {
                $service->saveUser($context, ['default_avatar' => $invalid]);
                self::fail('An undeclared avatar value bypassed File storage.');
            } catch (BusinessException $exception) {
                self::assertSame('FILE_REFERENCE_INVALID', $exception->errorCode);
            }
        }
    }

    public function testUserAvatarEditorKeepsStableReferenceSeparateFromDeliveryUrl(): void
    {
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, str_repeat('a', 26), 101, 201, 301, 'admin-web', new DateTimeImmutable('+1 hour'), 1), 'avatar-input');
        $key = 'file_' . str_repeat('b', 32);
        $settings = $this->createMock(TenantApplicationSettings::class);
        $settings->method('memberProfile')->with($context)->willReturn(['user_avatar' => $key]);
        $settings->expects(self::once())->method('replaceMemberProfile')->with($context, ['user_avatar' => $key]);
        $files = $this->createMock(FileReferences::class);
        $files->method('getFileUrl')->with($key)->willReturn('https://assets.example/avatar');
        $files->expects(self::once())->method('setTenantFileUrl')->with($context, $key)->willReturn($key);
        $service = new ConfigApplicationService($settings, $files,
            (new ReflectionClass(RichTextResourceService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(WebsiteConfigService::class))->newInstanceWithoutConstructor(), '',
        );
        $editor = $service->getUser($context);
        self::assertSame($key, $editor['default_avatar']);
        self::assertSame('https://assets.example/avatar', $editor['default_avatar_url']);
        self::assertTrue($service->saveUser($context, ['default_avatar' => $editor['default_avatar']]));
    }

    public function testAdminImageRoundTripRetainsStableReferenceWhilePublicPresentationUsesUrl(): void
    {
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, str_repeat('a', 26), 101, 201, 301, 'admin-web', new DateTimeImmutable('+1 hour'), 1), 'decoration-input');
        $key = 'file_' . str_repeat('a', 32);
        $url = 'https://assets.example/authorized-image';
        $files = $this->createMock(FileReferences::class);
        $files->method('getFileUrl')->with($key)->willReturn($url);
        $files->expects(self::once())->method('setTenantFileUrl')->with($context, $key)->willReturn($key);
        $schema = new DecorationSchemaService(new ProductAssetReferenceService($files, 'https://app.example'));
        $read = new DecorationReadService($schema);
        $stored = ['id' => 1, 'type' => 4, 'name' => 'PC', 'data' => json_encode([['image' => $key]], JSON_THROW_ON_ERROR), 'meta' => '[]'];
        $editor = $read->formatPage($stored, forEdit: true);
        self::assertSame($key, $editor['data'][0]['image']);
        self::assertSame($url, $editor['resource_urls']->{$key});
        self::assertSame($key, $schema->resourcesForStorage($editor['data'], $context)[0]['image']);
        $public = $read->formatPage($stored);
        self::assertSame($url, $public['data'][0]['image']);
        self::assertArrayNotHasKey('resource_urls', $public);
        $empty = $read->formatPage(['data' => '[]', 'meta' => '[]'], forEdit: true);
        self::assertSame('{}', json_encode($empty['resource_urls'], JSON_THROW_ON_ERROR));
    }

    public function testBaseDecorationWorksWithoutAnArticleProviderAndRejectsUnknownNestedInput(): void
    {
        $context = new stdClass();
        $style = ['default_color' => '#666666', 'selected_color' => '#2F80ED'];
        $items = [
            ['name' => 'Home', 'selected' => '', 'unselected' => '', 'link' => ['target_type' => 'shop', 'target' => 'home'], 'is_show' => 1],
            ['name' => 'Profile', 'selected' => '', 'unselected' => '', 'link' => ['target_type' => 'shop', 'target' => 'profile'], 'is_show' => 1],
        ];
        DecorationSchemaService::validateTabbar($context, $style, $items, null);
        DecorationSchemaService::validatePage($context, DecorationEnum::MOBILE_CUSTOMER_SERVICE, [
            ['title' => 'Support', 'name' => 'customer-service', 'content' => ['title' => '', 'time' => '', 'mobile' => '', 'qrcode' => '', 'remark' => ''], 'styles' => []],
        ], [], null);
        self::assertTrue(true);
        foreach (['unknown', 'raw-flag', 'link', 'raw-name'] as $fault) {
            $invalid = $items;
            if ($fault === 'unknown') $invalid[1]['tenant_id'] = 202;
            if ($fault === 'raw-flag') $invalid[1]['is_show'] = 'junk';
            if ($fault === 'link') $invalid[1]['link']['tenant_id'] = 202;
            if ($fault === 'raw-name') $invalid[1]['name'] = ['bad'];
            try {
                DecorationSchemaService::validateTabbar($context, $style, $invalid, null);
                self::fail('Invalid Tabbar data was accepted.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(RuntimeException::class);
        DecorationSchemaService::validateLink($context, ['target_type' => 'article', 'target' => '1'], false, null);
    }

    public function testPageBoundaryRejectsIdentifierCoercionAndUnknownOwnershipFields(): void
    {
        foreach (['1.5', '1e0', true, 1.5, '9223372036854775808'] as $id) {
            self::assertFalse((new DecorationPageValidate())->scene('detail')->check(['id' => $id]));
        }
        self::assertTrue((new DecorationPageValidate())->scene('detail')->check(['id' => '1']));
        self::assertFalse((new DecorationPageValidate())->scene('save')->check(['id' => 1, 'type' => '5.0', 'data' => ['themeColorId' => 3]]));
        self::assertFalse((new DecorationPageValidate())->scene('article')->check(['limit' => '1.5']));
        $app = new App();
        $app->instance(InputValidator::class, new InputValidator($app, new CurrentExecutionContext(new ExecutionContextStore())));
        $controller = (new ReflectionClass(DecorationPageController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(DecorationPageController::class, 'app'))->setValue($controller, $app);
        (new ReflectionProperty(DecorationPageController::class, 'request'))->setValue($controller, (new Request())->withPost(['id' => 1, 'type' => 5, 'data' => ['themeColorId' => 3], 'tenant_id' => 202]));
        $this->expectException(ValidateException::class);
        (new ReflectionMethod(DecorationPageController::class, 'save'))->invoke($controller, DecorationEnum::MOBILE_TYPES);
    }
}
