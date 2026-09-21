<?php
declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\service\http\GuzzleOutboundHttpTransport;
use app\common\service\http\OutboundHttpException;
use app\common\service\http\OutboundHttpRequest;
use PeanutAdmin\Modules\File\Composition\Storage\QcloudStorageClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use think\App;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

final class OutboundHttpLogTestApp extends App
{
    public function runningInConsole(): bool
    {
        return false;
    }
}

function expectOutboundHttp(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function outboundHttpContext(int $tenantId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $tenantId + 100,
        'outbound-http-' . $tenantId,
        $tenantId,
        $tenantId + 200,
        $tenantId + 300,
        'admin-web',
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
        1,
    ), $requestId);
}

/** @return list<array{event:string,attributes:array<string,mixed>}> */
function outboundHttpEvents(OutboundHttpLogTestApp $app): array
{
    $events = [];
    foreach ($app->log->getLog() as $record) {
        $event = json_decode((string)$record->message, true, 512, JSON_THROW_ON_ERROR);
        if (($event['event'] ?? null) === 'outbound_http_attempt') {
            $events[] = $event;
        }
    }
    $app->log->clear();
    return $events;
}

function outboundHttpTransport(array $queue): GuzzleOutboundHttpTransport
{
    return new GuzzleOutboundHttpTransport(new Client([
        'handler' => MockHandler::createWithMiddleware($queue),
    ]));
}

$serverRoot = dirname(__DIR__, 2);
putenv('PEANUT_DATABASE_RESOURCE_ID=outbound-http-observability-test');
$_ENV['PEANUT_DATABASE_RESOURCE_ID'] = 'outbound-http-observability-test';
$_SERVER['PEANUT_DATABASE_RESOURCE_ID'] = 'outbound-http-observability-test';
$app = new OutboundHttpLogTestApp($serverRoot);
$app->config->set([
    'default' => 'file',
    'level' => [],
    'type_channel' => [],
    'close' => false,
    'channels' => ['file' => ['type' => 'File', 'path' => sys_get_temp_dir() . '/outbound-http-log', 'single' => true]],
], 'log');
$contexts = new ExecutionContextStore();
$app->instance(ExecutionContextStore::class, $contexts);
$app->instance(CurrentExecutionContext::class, new CurrentExecutionContext($contexts));
$alpha = outboundHttpContext(101, 'outbound-alpha-request');
$beta = outboundHttpContext(202, 'outbound-beta-request');
$url = 'https://provider.example.test/callback?access_token=must-not-log';
$request = new OutboundHttpRequest('GET', $url, ['Authorization' => 'Bearer must-not-log'], 'token=must-not-log', retrySafe: true);

$contexts->run(new \app\common\execution\AdminExecutionContext($alpha, 'outbound.alpha.success'), static function () use ($request): void {
    $response = outboundHttpTransport([new Response(200, [], 'response-secret')])->send($request);
    expectOutboundHttp($response->status === 200 && $response->body === 'response-secret', 'first success response changed');
});
$success = outboundHttpEvents($app);
expectOutboundHttp(count($success) === 1 && $success[0]['attributes']['attempt'] === 1, 'first success attempt was not observed once');
expectOutboundHttp(
    $success[0]['attributes']['outcome'] === 'success'
        && $success[0]['attributes']['category'] === 'success'
        && $success[0]['attributes']['status'] === 200,
    'first success outcome changed',
);

$contexts->run(new \app\common\execution\AdminExecutionContext($beta, 'outbound.beta.retry'), static function () use ($request): void {
    $response = outboundHttpTransport([new Response(503), new Response(200)])->send($request);
    expectOutboundHttp($response->status === 200, 'retry recovery response changed');
});
$recovered = outboundHttpEvents($app);
expectOutboundHttp(
    array_column(array_column($recovered, 'attributes'), 'attempt') === [1, 2]
        && array_column(array_column($recovered, 'attributes'), 'category') === ['http_5xx', 'success'],
    'retry recovery attempt order or outcome changed',
);

$connectionFailure = new ConnectException('connection refused', new Request('GET', $url));
$contexts->run(new \app\common\execution\AdminExecutionContext($alpha, 'outbound.alpha.failure'), static function () use ($request, $connectionFailure): void {
    try {
        outboundHttpTransport([$connectionFailure, $connectionFailure])->send($request);
        throw new RuntimeException('all failures unexpectedly succeeded');
    } catch (OutboundHttpException $exception) {
        expectOutboundHttp($exception->getMessage() === '外部服务当前不可达' && $exception->getPrevious() instanceof ConnectException, 'final outbound exception changed');
    }
});
$failed = outboundHttpEvents($app);
expectOutboundHttp(
    array_column(array_column($failed, 'attributes'), 'attempt') === [1, 2]
        && array_column(array_column($failed, 'attributes'), 'category') === ['transport', 'transport'],
    'all failure attempts were not observed in order',
);

$timeout = new ConnectException('operation timed out', new Request('GET', $url), null, ['errno' => 28]);
$contexts->run(new \app\common\execution\AdminExecutionContext($beta, 'outbound.beta.timeout'), static function () use ($request, $timeout): void {
    $response = outboundHttpTransport([$timeout, new Response(204)])->send($request);
    expectOutboundHttp($response->status === 204, 'timeout retry response changed');
});
$timeoutEvents = outboundHttpEvents($app);
expectOutboundHttp(
    array_column(array_column($timeoutEvents, 'attributes'), 'category') === ['timeout', 'success'],
    'timeout was not classified before retry success',
);

$qcloud = (new QcloudStorageClientFactory(new CurrentExecutionContext($contexts)))->make([
    'resolved_credentials' => ['access_key' => 'test-access-key', 'secret_key' => 'test-secret-key'],
], ['region' => 'ap-shanghai']);
$qcloudHandler = $qcloud->httpClient->getConfig('handler');
expectOutboundHttp($qcloudHandler instanceof HandlerStack, 'Qcloud retry handler is unavailable for attempt observation');
$qcloudHandler->setHandler(new MockHandler([new Response(503), new Response(200)]));
$contexts->run(new \app\common\execution\AdminExecutionContext($alpha, 'outbound.alpha.qcloud'), static function () use ($qcloud, $url): void {
    $response = $qcloud->httpClient->request('GET', $url);
    expectOutboundHttp($response->getStatusCode() === 200, 'Qcloud retry response changed');
});
$qcloudEvents = outboundHttpEvents($app);
expectOutboundHttp(
    array_column(array_column($qcloudEvents, 'attributes'), 'attempt') === [1, 2]
        && array_column(array_column($qcloudEvents, 'attributes'), 'category') === ['http_5xx', 'success'],
    'Qcloud retry attempts bypassed shared observation',
);

$allEvents = [...$success, ...$recovered, ...$failed, ...$timeoutEvents, ...$qcloudEvents];
foreach ($allEvents as $event) {
    $attributes = $event['attributes'];
    expectOutboundHttp(
        $attributes['host'] === 'provider.example.test'
            && is_int($attributes['duration_ms']) && $attributes['duration_ms'] >= 0,
        'attempt lost its safe target or duration',
    );
    expectOutboundHttp(
        isset($attributes['request_id'], $attributes['tenant_id'], $attributes['operation'], $attributes['runtime_id']),
        'attempt lost request, Tenant, or trace correlation',
    );
}
expectOutboundHttp(
    $success[0]['attributes']['tenant_id'] === 101
        && $recovered[0]['attributes']['tenant_id'] === 202
        && $success[0]['attributes']['request_id'] === 'outbound-alpha-request'
        && $recovered[0]['attributes']['request_id'] === 'outbound-beta-request',
    'Tenant or request attribution crossed outbound attempts',
);
$encoded = json_encode($allEvents, JSON_THROW_ON_ERROR);
foreach (['must-not-log', 'access_token', '/callback?', 'Authorization', 'response-secret'] as $forbidden) {
    expectOutboundHttp(!str_contains($encoded, $forbidden), 'outbound attempt diagnostics leaked: ' . $forbidden);
}

echo "OUTBOUND-HTTP-ATTEMPT-OBSERVABILITY-001 passed\n";
