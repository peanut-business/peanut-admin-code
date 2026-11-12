<?php

declare(strict_types=1);

// Protocol/HTTP-transport fixtures only; no database, real login or installed-APP acceptance.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\platform\exception\module\ModulePackageException;
use app\platform\services\module\OfficialModuleReleaseService;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

$checks = 0;
$expect = static function (bool $valid, string $message) use (&$checks): void {
    $checks++;
    if (!$valid) {
        throw new RuntimeException($message);
    }
};
$reject = static function (callable $run, string $code) use ($expect): void {
    try {
        $run();
    } catch (ModulePackageException $error) {
        $expect($error->errorCode === 'MODULE_RELEASE_' . $code, $error->errorCode . ' != ' . $code);
        return;
    }
    throw new RuntimeException('Unexpected acceptance: ' . $code);
};
$root = realpath(sys_get_temp_dir()) . '/peanut-release-discovery-' . bin2hex(random_bytes(8));
mkdir($root . '/.peanut', 0700, true);
file_put_contents($root . '/.peanut/application-manifest.json', json_encode([
    'edition' => ['name' => 'standalone'], 'template' => ['version' => '5.1.10'],
], JSON_THROW_ON_ERROR));
file_put_contents($root . '/modules.lock', json_encode(['schema_version' => 1, 'modules' => [
    ['key' => 'peanut.file', 'version' => '1.0.0'], ['key' => 'custom.ai', 'version' => '1.0.0'],
]], JSON_THROW_ON_ERROR));
$moduleRepo = 'peanut-opensource/peanut-module-code';
$scaffoldRepo = 'peanut-business/peanut-admin-code';
$release = static fn(string $repo, string $tag, int $id): array => [
    'id' => $id, 'url' => 'https://api.github.com/repos/' . $repo . '/releases/' . $id,
    'html_url' => 'https://github.com/' . $repo . '/releases/tag/' . $tag,
    'tag_name' => $tag, 'draft' => false, 'prerelease' => str_contains($tag, '-'),
    'published_at' => '2026-11-12T00:00:00Z', 'immutable' => true, 'assets' => [],
];
$fixture = static function (bool $v2 = false) use ($release, $moduleRepo, $scaffoldRepo): array {
    $payload = 'independently verified bytes; native installer must still verify the TAR format';
    $row = ['key' => 'peanut.file', 'version' => '1.0.1', 'asset' => 'peanut.file-1.0.1.tar',
        'sha256' => hash('sha256', $payload), 'bytes' => strlen($payload), 'license' => 'Apache-2.0',
        'editions' => ['standalone', 'multi-tenant'], 'kernel_constraint' => '^5.1', 'dependencies' => []];
    $original = $release($moduleRepo, 'v1.0.0-rc.1', 1);
    $index = ['schema_version' => 1, 'protocol' => 'peanut.module-release-index.v1',
        'repository' => $moduleRepo, 'tag' => $original['tag_name'], 'commit' => str_repeat('a', 40), 'modules' => [$row]];
    $documents = [];
    $attach = static function (array &$owner, string $name, string $bytes) use (&$documents, $moduleRepo): void {
        $url = 'https://github.com/' . $moduleRepo . '/releases/download/' . $owner['tag_name'] . '/' . $name;
        $owner['assets'][] = ['name' => $name, 'browser_download_url' => $url, 'digest' => 'sha256:' . hash('sha256', $bytes)];
        $documents[$url] = $bytes;
    };
    $attach($original, 'module-release-index.json', json_encode($index, JSON_THROW_ON_ERROR));
    $attach($original, $row['asset'], $payload);
    $selected = $original;
    if ($v2) {
        $selected = $release($moduleRepo, 'v1.0.0-rc.2', 2);
        $incremental = $index;
        $incremental['schema_version'] = 2;
        $incremental['protocol'] = 'peanut.module-release-index.v2';
        $incremental['tag'] = $selected['tag_name'];
        $incremental['modules'][0] += ['artifact_tag' => $original['tag_name'], 'source_sha256' => str_repeat('b', 64)];
        $attach($selected, 'module-release-index.json', json_encode($incremental, JSON_THROW_ON_ERROR));
    }
    $documents['https://api.github.com/repos/' . $moduleRepo . '/releases?per_page=100&page=1'] = json_encode([$selected], JSON_THROW_ON_ERROR);
    foreach ([$original, $selected] as $entry) {
        $documents['https://api.github.com/repos/' . $moduleRepo . '/releases/tags/' . $entry['tag_name']] = json_encode($entry, JSON_THROW_ON_ERROR);
    }
    $documents['https://api.github.com/repos/' . $scaffoldRepo . '/releases?per_page=100&page=1'] = json_encode([
        $release($scaffoldRepo, 'v6.0.0', 5), $release($scaffoldRepo, 'v5.1.11', 4),
    ], JSON_THROW_ON_ERROR);
    return [$documents, ['module_key' => $row['key'], 'version' => $row['version'], 'sha256' => $row['sha256'],
        'catalog_tag' => $selected['tag_name'], 'channel' => 'prerelease'], $payload];
};
$make = static function (array $documents, array &$calls) use ($root): OfficialModuleReleaseService {
    $handler = HandlerStack::create(static function (RequestInterface $request) use ($documents, &$calls) {
        $url = (string) $request->getUri();
        $calls[] = $url;
        if (!array_key_exists($url, $documents)) {
            throw new RuntimeException('Unexpected external request: ' . $url);
        }
        return Create::promiseFor($documents[$url] instanceof Response ? $documents[$url] : new Response(200, [], $documents[$url]));
    });
    return new OfficialModuleReleaseService($root, new Client(['handler' => $handler]));
};
try {
    foreach ([false, true] as $incremental) {
        [$documents, $selection, $payload] = $fixture($incremental);
        $calls = [];
        $service = $make($documents, $calls);
        $snapshot = $service->updates('prerelease');
        $expect(count($snapshot['modules']) === 1 && $snapshot['modules'][0]['status'] === 'update_available', 'Custom source was included or update missed');
        $expect($snapshot['modules'][0]['source_version'] === '1.0.0' && $snapshot['changed'] === false, 'Discovery mutated source');
        $expect($snapshot['scaffold']['available_version'] === '5.1.11', 'Unapproved cross-major discovery');
        $bytes = $service->package($selection);
        $expect($bytes['bytes'] === $payload && $bytes['sha256'] === $selection['sha256'], 'Wrong immutable artifact');
        $expect(count(array_filter($calls, static fn(string $url): bool => str_ends_with($url, '.tar'))) === 1, 'Archive downloaded more than once');
        $reject(static fn() => $service->package($selection + ['url' => 'http://127.0.0.1/private']), 'SELECTION_INVALID');
        $stable = $make($documents, $calls)->updates('stable');
        $expect($stable['modules'][0]['status'] === 'no_published_candidate', 'RC was silently adopted by stable channel');
        $reject(static fn() => $service->package(array_replace($selection, ['channel' => 'stable'])), 'PRERELEASE_OPT_IN_REQUIRED');
        $reject(static fn() => $service->package(array_replace($selection, ['sha256' => str_repeat('0', 64)])), 'SELECTION_CHANGED');
        $reject(static fn() => $service->updates('unsafe'), 'CHANNEL_INVALID');
    }
    [$documents, $selection] = $fixture(true);
    $calls = [];
    $assetUrl = 'https://github.com/' . $moduleRepo . '/releases/download/v1.0.0-rc.1/peanut.file-1.0.1.tar';
    $corrupt = $documents;
    $corrupt[$assetUrl] = 'tampered';
    $reject(static fn() => $make($corrupt, $calls)->package($selection), 'ASSET_DIGEST_MISMATCH');
    $redirect = $documents;
    $redirect[$assetUrl] = new Response(302, ['Location' => 'https://127.0.0.1/private']);
    $reject(static fn() => $make($redirect, $calls)->package($selection), 'REDIRECT_DENIED');
    $originApi = 'https://api.github.com/repos/' . $moduleRepo . '/releases/tags/v1.0.0-rc.1';
    $mutable = $documents;
    $origin = json_decode($mutable[$originApi], true, 512, JSON_THROW_ON_ERROR);
    $origin['immutable'] = false;
    $mutable[$originApi] = json_encode($origin, JSON_THROW_ON_ERROR);
    $reject(static fn() => $make($mutable, $calls)->package($selection), 'IMMUTABLE_RELEASE_REQUIRED');
    $expect(!is_dir($root . '/.local') && !is_dir($root . '/server'), 'Discovery created an environment or runtime');

    $code = dirname(__DIR__, 3);
    $routes = (string) file_get_contents($code . '/server/route/platform.php');
    $guard = (string) file_get_contents($code . '/server/app/common/validation/instance/InstanceToolAccessGuard.php');
    foreach ([['get', 'updates', 'updates', 'read'], ['post', 'official-update', 'officialUpdate', 'update']] as [$method, $suffix, $action, $permission]) {
        $pattern = "~Route::{$method}\\('instance-tools/modules/{$suffix}', \\[PlatformModuleLifecycleController::class, '{$action}'\\]\\)\\s*"
            . "->middleware\\(PlatformLoginMiddleware::class\\)\\s*->middleware\\(PlatformPermissionMiddleware::class, 'platform.module.{$permission}'\\)\\s*"
            . "->middleware\\(PlatformInstanceToolMiddleware::class\\);~";
        $expect(preg_match($pattern, $routes) === 1, 'Lost developer-only exact route protection');
        $expect(str_contains($guard, "'http.platform.{$method}.instance-tools/modules/{$suffix}' => 'platform.module.{$permission}'"), 'Lost route-specific instance permission');
    }
    $middleware = (string) file_get_contents($code . '/server/app/platform/http/middleware/PlatformInstanceToolMiddleware.php');
    $expect(str_contains($middleware, "!== 'development'") && str_contains($middleware, '!app()->isDebug()')
        && str_contains($middleware, '$this->sessions->assertAllowed('), 'Developer gate weakened');
    echo 'OFFICIAL-RELEASE-DISCOVERY-001 passed checks=' . $checks . "\n";
} finally {
    unlink($root . '/modules.lock');
    unlink($root . '/.peanut/application-manifest.json');
    rmdir($root . '/.peanut');
    rmdir($root);
}
