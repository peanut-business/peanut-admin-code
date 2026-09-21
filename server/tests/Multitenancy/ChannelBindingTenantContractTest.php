<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectChannelBindingTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string)file_get_contents($serverRoot . '/' . $path);

$noticeController = $read('app/modules/official/notification/controller/NoticeChannelController.php');
$notificationApplication = $read('app/modules/official/notification/services/NotificationApplicationService.php');
$noticeService = $read('app/common/services/notice/NoticeChannelService.php');
$sender = $read('app/common/infrastructure/notice/ApplicationNoticeSmsSender.php');
$verification = $read('app/modules/official/notification/services/VerificationCodeService.php');
$menuController = $read('app/modules/official/oauth/controller/OfficialAccountMenuController.php');
$menuLogic = $read('app/modules/official/oauth/services/OfficialAccountMenuApplicationService.php');

foreach ([
    app\modules\official\notification\controller\NoticeChannelController::class,
    app\modules\official\oauth\controller\OfficialAccountMenuController::class,
] as $controller) {
    $constructor = (new ReflectionClass($controller))->getConstructor();
    expectChannelBindingTenant(
        $constructor?->getDeclaringClass()->getName() === app\BaseController::class
            && count($constructor->getParameters()) === 1
            && $constructor->getParameters()[0]->getType()?->getName() === think\App::class,
        'admin controller does not inherit the current-App execution context contract'
    );
}
expectChannelBindingTenant(
    (new ReflectionMethod(
        app\modules\official\notification\controller\NoticeChannelController::class,
        'notifications',
    ))->getReturnType()?->getName() === app\modules\official\notification\services\NotificationAdminApplicationService::class
        && str_contains($noticeController, '$this->tenantAdminContext()')
        && str_contains($notificationApplication, '$this->executionContext->tenantAdmin()'),
    'notification application service drops the trusted Tenant context'
);

foreach ([
    "private const BINDING_PROVIDER = 'notice.sms'",
    '$this->bindings->mutate(',
    "'sms_default'",
    "'sms_aliyun'",
    "'sms_tencent'",
] as $marker) {
    expectChannelBindingTenant(str_contains($noticeService, $marker), 'Tenant SMS binding invariant missing: ' . $marker);
}
expectChannelBindingTenant(
    !str_contains($noticeService, 'external_channel_binding')
        && !str_contains($noticeService, "Db::name('external_channel_binding')"),
    'NoticeChannelService still accesses the shared external binding table directly'
);
expectChannelBindingTenant(
    !str_contains($noticeService, 'ConfigService'),
    'Tenant SMS configuration falls back to global pa_config'
);
expectChannelBindingTenant(
    str_contains($sender, '$this->channels->sendSms(')
        && str_contains($sender, '$this->executionContext,'),
    'application sender drops the trusted Tenant context'
);
expectChannelBindingTenant(
    preg_match('/\$this->sender->send\(\s*\$context,/', $verification) === 1,
    'verification sender call does not preserve the trusted Tenant context'
);

foreach (['$this->bindings->config(', '$this->bindings->update(',
    'ExternalProvider::WECHAT_OFFICIAL_CALLBACK'] as $marker) {
    expectChannelBindingTenant(str_contains($menuLogic, $marker), 'official-account menu binding invariant missing: ' . $marker);
}
expectChannelBindingTenant(
    str_contains($menuLogic, "(string)(\$config['app_id'] ?? '')")
        && str_contains($menuLogic, "(string)(\$config['app_secret'] ?? '')"),
    'official-account menu publish does not use the current Tenant binding credentials'
);
expectChannelBindingTenant(
    str_contains($menuLogic, "\$config['menu'] = \$menu") && !str_contains($menuLogic, 'ConfigService'),
    'official-account menu is not merged into the Tenant binding'
);

echo "CHANNEL-BINDING-TENANT-001 passed\n";
