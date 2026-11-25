<?php

declare(strict_types=1);

// Isolated native transport and log fixture. No HTTP network, database or APP.
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\InstanceExecutionContext;
use app\common\value\http\OutboundHttpAttemptObservation;
use app\platform\exception\module\ModulePackageException;
use app\platform\services\module\OfficialModuleReleaseService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use think\App;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

final class OfficialReleaseOutboundLogApp extends App
{
    public function runningInConsole(): bool
    {
        return false;
    }
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$app = new OfficialReleaseOutboundLogApp(dirname(__DIR__, 2));
$app->config->set([
    'default' => 'file', 'level' => [], 'type_channel' => [], 'close' => false,
    'channels' => ['file' => ['type' => 'File', 'path' => sys_get_temp_dir() . '/official-release-observation', 'single' => true]],
], 'log');
$contexts = new ExecutionContextStore();
$execution = new CurrentExecutionContext($contexts);
$app->instance(ExecutionContextStore::class, $contexts);
$app->instance(CurrentExecutionContext::class, $execution);
$request = new ReflectionMethod(OfficialModuleReleaseService::class, 'request');
$url = 'https://api.github.com/repos/peanut-opensource/peanut-module-code/releases?access_token=DO_NOT_LOG';
$events = static function () use ($app): array {
    $found = [];
    foreach ($app->log->getLog() as $record) {
        $event = json_decode((string) $record->message, true, 512, JSON_THROW_ON_ERROR);
        if (($event['event'] ?? null) === 'outbound_http_attempt') {
            $found[] = $event['attributes'];
        }
    }
    $app->log->clear();
    return $found;
};
$call = static function (array $responses, int $limit = 4096) use ($request, $execution, $contexts, $url): mixed {
    $service = new OfficialModuleReleaseService(
        dirname(__DIR__, 3),
        new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
        $execution,
    );
    return $contexts->run(new InstanceExecutionContext('official.release.discovery', 'g11-official-read-001'),
        static fn() => $request->invoke($service, $url, $limit));
};
$expect($call([new Response(200, [], 'immutable-payload')]) === 'immutable-payload', 'successful streaming bytes changed');
$success = $events();
$expect(count($success) === 2, 'expected one headers and one body event');
$expect(array_column($success, 'phase') === ['headers', 'body'], 'phase order changed');
$expect(array_column($success, 'category') === ['success', 'success'], 'successful phases misclassified');

try {
    $call([new Response(503, [], 'DO_NOT_LOG')]);
    throw new RuntimeException('HTTP 503 was incorrectly accepted');
} catch (ServerException $exception) {
    $expect($exception->getResponse()?->getStatusCode() === 503, 'Guzzle HTTP error behavior changed');
}
$failedStatus = $events();
$expect(count($failedStatus) === 1 && $failedStatus[0]['phase'] === 'headers'
    && $failedStatus[0]['category'] === 'http_5xx'
    && $failedStatus[0]['status'] === 503,
    'HTTP failure observation changed');

try {
    $call([new ConnectException('DO_NOT_LOG transport error', new Request('GET', $url))]);
    throw new RuntimeException('Transport error incorrectly accepted');
} catch (ConnectException) {
    // Preserve the original Guzzle exception type.
}
$transport = $events();
$expect(count($transport) === 1 && $transport[0]['phase'] === 'headers'
    && $transport[0]['category'] === 'transport', 'transport failure observation missing');

$contexts->run(new InstanceExecutionContext('official.release.timeout', 'g11-official-read-001'),
    static function () use ($execution, $url): void {
        OutboundHttpAttemptObservation::failure(
            $execution, 'GET', $url, 1, hrtime(true),
            new ConnectException('DO_NOT_LOG timed out', new Request('GET', $url), null, ['errno' => 28]),
            'headers',
        );
    });
$timeout = $events();
$expect(count($timeout) === 1 && $timeout[0]['category'] === 'timeout'
    && $timeout[0]['phase'] === 'headers', 'existing native timeout classifier changed');

$contexts->run(new InstanceExecutionContext('official.release.compatibility', 'g11-official-read-001'),
    static function () use ($execution, $url): void {
        OutboundHttpAttemptObservation::response($execution, 'GET', $url, 1, hrtime(true), 204);
    });
$legacy = $events();
$expect(count($legacy) === 1 && !array_key_exists('phase', $legacy[0]),
    'pre-existing observer consumers must not gain a synthetic phase');

try {
    $call([new Response(200, [], 'DO_NOT_LOG')], 3);
    throw new RuntimeException('Oversize response incorrectly accepted');
} catch (ModulePackageException $exception) {
    $expect($exception->errorCode === 'MODULE_RELEASE_DOWNLOAD_TOO_LARGE', 'stream limit behavior changed');
}
$oversize = $events();
$expect(count($oversize) === 2 && $oversize[1]['phase'] === 'body'
    && $oversize[1]['category'] === 'transport', 'stream failure phase missing');

try {
    $call([new Response(302, ['Location' => 'https://untrusted.example.test/?access_token=DO_NOT_LOG'])]);
    throw new RuntimeException('Untrusted redirect incorrectly accepted');
} catch (ModulePackageException $exception) {
    $expect($exception->errorCode === 'MODULE_RELEASE_REDIRECT_DENIED', 'redirect rejection changed');
}
$redirect = $events();
$expect(count($redirect) === 1 && $redirect[0]['phase'] === 'headers', 'redirect failure phase missing');

foreach ([$success, $failedStatus, $transport, $timeout, $legacy, $oversize, $redirect] as $group) {
    foreach ($group as $event) {
        $expect($event['method'] === 'GET' && $event['host'] === 'api.github.com'
            && $event['attempt'] === 1 && $event['request_id'] === 'g11-official-read-001'
            && is_int($event['duration_ms']) && $event['duration_ms'] >= 0,
            'outbound phase lost native request correlation');
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        foreach (['DO_NOT_LOG', 'access_token', '/repos/', 'Location', 'Authorization', 'untrusted.example.test'] as $forbidden) {
            $expect(!str_contains($encoded, $forbidden), 'sensitive outbound content escaped redaction');
        }
    }
}
echo "OFFICIAL-RELEASE-OUTBOUND-OBSERVATION-001 passed\n";
