<?php

declare(strict_types=1);

// Host-only contract. Notification and OAuth consumers are tested in the
// Module application's NotificationHostTest and OAuthChannelHostTest.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectNoticeHost(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$hostFile = $serverRoot . '/app/common/services/notice/NoticeChannelService.php';
$senderFile = $serverRoot . '/app/common/infrastructure/notice/ApplicationNoticeSmsSender.php';
expectNoticeHost(is_file($hostFile) && is_file($senderFile), 'application-owned notice host missing');

$host = (string) file_get_contents($hostFile);
$sender = (string) file_get_contents($senderFile);
foreach ([
    "private const BINDING_PROVIDER = 'notice.sms'",
    '$this->bindings->mutate(',
    "'sms_default'",
    "'sms_aliyun'",
    "'sms_tencent'",
] as $marker) {
    expectNoticeHost(str_contains($host, $marker), 'notice binding host contract missing: ' . $marker);
}
expectNoticeHost(
    !str_contains($host, "Db::name('external_channel_binding')")
        && !str_contains($host, 'ConfigService'),
    'notice host bypasses external binding owner or uses a global credential fallback',
);
expectNoticeHost(
    str_contains($sender, '$this->channels->sendSms(')
        && str_contains($sender, '$this->executionContext,')
        && str_contains($sender, 'TenantContext|TenantSystemContext $context')
        && str_contains($sender, "'delivery' => 'simulated'"),
    'notice sender lost trustworthy tenant context or the explicit development-only transport',
);
echo "NOTICE-CHANNEL-HOST-BOUNDARY-001 passed; optional Notification/OAuth acceptance belongs to Module application; HTTP/MySQL NOT_RUN\n";
