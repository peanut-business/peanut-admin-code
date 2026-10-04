<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Notification\Service\VerificationCodeService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

[$script, $mobile, $code, $requestId, $barrier] = $argv;
$deadline = microtime(true) + 5;
while (!is_file($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (!is_file($barrier)) {
    throw new RuntimeException('verification concurrency barrier timed out');
}

$app = new think\App();
$app->initialize();
$context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
    501,
    'verification-concurrent-session-' . $requestId,
    101,
    1001,
    501,
    'admin-web',
    new DateTimeImmutable('2031-01-01T00:00:00Z'),
    1,
), $requestId);
$result = app(ExecutionContextStore::class)->run(
    new AdminExecutionContext($context, 'test.notice.verification.concurrent'),
    fn() => app(VerificationCodeService::class)->verify($context, 'login_code', $mobile, $code),
);
echo json_encode(['accepted' => $result->accepted, 'error' => $result->error], JSON_UNESCAPED_UNICODE) . PHP_EOL;
