<?php
declare(strict_types=1);

namespace app\modules\official\oauth;

use app\modules\official\integration\contracts\ExternalTenantResolutionService;
use app\modules\official\oauth\services\OAuthQueryService;
use app\modules\official\oauth\services\OAuthCommandService;
use app\modules\official\oauth\contracts\OAuthCallbackLocator;
use app\modules\official\oauth\contracts\OfficialAccountCallbacks;
use app\modules\official\oauth\contracts\OAuthCommands;
use app\modules\official\oauth\contracts\OAuthPersistence;
use app\modules\official\oauth\contracts\OAuthQueries;
use app\modules\official\oauth\infrastructure\persistence\ThinkPhpOAuthCallbackLocator;
use app\modules\official\oauth\infrastructure\persistence\ThinkPhpOAuthPersistence;
use app\common\infrastructure\oauth\WechatOAuthTransport;
use PeanutAdmin\IntegrationSecurity\OAuth\OAuthTransport;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use think\App;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.oauth';
    }

    public function queries(): OAuthQueries
    {
        return new OAuthQueryService(new ThinkPhpOAuthPersistence());
    }

    public function bindings(): array
    {
        return [
            OAuthCallbackLocator::class => ThinkPhpOAuthCallbackLocator::class,
            OAuthPersistence::class => ThinkPhpOAuthPersistence::class,
            OAuthTransport::class => WechatOAuthTransport::class,
            OfficialAccountCallbacks::class => \app\modules\official\oauth\services\OfficialAccountApplicationService::class,
            OAuthCommands::class => fn(App $app): OAuthCommands => new OAuthCommandService(
                $app->make(\app\modules\official\member\contracts\MemberQueries::class),
                $app->make(\app\modules\official\member\contracts\MemberIdentityCommands::class),
                $app->make(\app\modules\official\member\contracts\MemberProfileCommands::class),
                $app->make(\app\modules\official\notification\contracts\VerificationCodeCommands::class),
                $app->make(\app\common\persistence\AdvisoryLockExecution::class),
                $app->make(\app\modules\official\settings\contracts\TenantApplicationSettings::class),
                $app->make(ExternalTenantResolutionService::class),
                $app->make(OAuthPersistence::class),
                $app->make(OAuthTransport::class),
                (string)$app->config->get('project.default_image.user_avatar', ''),
            ),
            OAuthQueries::class => OAuthQueryService::class,
        ];
    }
}
