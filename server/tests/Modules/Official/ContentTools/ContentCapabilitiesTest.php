<?php

declare(strict_types=1);

use PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext;
use PeanutAdmin\Modules\ContentTools\Contract\SurfaceAdapterRegistration;
use PeanutAdmin\Modules\ContentTools\Service\ContentToolRegistry;
use PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService;
use PeanutAdmin\Modules\ContentTools\ModuleProvider;

$root = dirname(__DIR__, 5);
$loader = require $root . '/server/vendor/autoload.php';
$loader->addPsr4('PeanutAdmin\\Modules\\ContentTools\\', $root . '/server/app/modules/official/content_tools/src');
$assertions = 0;
function contentExpect(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function contentReject(callable $operation, string $code): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | LogicException $exception) {
        contentExpect($exception->getMessage() === $code, 'wrong rejection: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('expected rejection ' . $code);
}
function contentContext(string $mime = 'image/png', bool $view = true, bool $edit = true, bool $download = false, array $providers = ['official.content-tools'], array $services = [], string $revision = 'auth-1'): AuthorizedContentContext
{
    return new AuthorizedContentContext('file_resource', $mime, $view, $edit, $download, $revision, 'browser', $providers, $services);
}

$registry = ContentToolRegistry::withBuiltins();
$service = new ContentCapabilitiesService($registry);
foreach (['image/png' => 'image', 'application/pdf' => 'pdf', 'audio/mpeg' => 'audio', 'video/mp4' => 'video'] as $mime => $kind) {
    $result = $service->resolve(contentContext($mime), 'view', 'builtin.' . $kind, null);
    contentExpect($result['canView'] && !$result['canEdit'] && $result['state'] === 'read_only', 'builtin must be a real view-only capability');
    contentExpect(!$result['canDownloadOriginal'], 'view must not imply download');
}
$denied = $service->resolve(contentContext(view: false, download: true), 'view', 'builtin.image', null);
contentExpect($denied['state'] === 'permission_denied' && !$denied['canView'] && $denied['canDownloadOriginal'], 'independent permissions');
$unsupported = $service->resolve(contentContext(), 'edit', 'builtin.image', 'builtin.image');
contentExpect($unsupported['state'] === 'edit_unsupported' && !$unsupported['canEdit'], 'builtin image is not an editor');
foreach (['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream', 'image/svg+xml'] as $mime) {
    $result = $service->resolve(contentContext($mime), 'view', 'builtin.image', null);
    contentExpect($result['state'] === 'preview_unavailable' && !$result['canView'], 'no MIME spoofing/Office/SVG fallback');
}
$disabled = $service->resolve(contentContext(providers: []), 'view', 'builtin.image', null);
contentExpect($disabled['state'] === 'service_unavailable' && $disabled['unavailableReason'] === 'CONTENT_PROVIDER_DISABLED', 'module disabled');
$registry->register(new SurfaceAdapterRegistration('office.reviewed', 'example.office', '1.0.0', ['file_resource'], ['application/pdf'], ['view', 'edit'], serviceDependencies: ['office-service']));
$office = $service->resolve(contentContext('application/pdf', providers: ['example.office']), 'edit', 'office.reviewed', 'office.reviewed');
contentExpect($office['state'] === 'service_unavailable' && !$office['canEdit'], 'missing service is fail-closed');
$healthy = $service->resolve(contentContext('application/pdf', providers: ['example.office'], services: ['office-service']), 'edit', 'office.reviewed', 'office.reviewed');
contentExpect($healthy['canEdit'] && $healthy['state'] === 'available', 'explicit healthy provider');
$other = $service->resolve(contentContext('application/pdf', providers: ['example.office'], services: ['office-service'], revision: 'auth-2'), 'edit', 'office.reviewed', 'office.reviewed');
contentExpect($other['capabilityRevision'] !== $healthy['capabilityRevision'], 'authorization revision binds capabilities');
$registry->register(new SurfaceAdapterRegistration('other.pdf', 'example.office', '1.0.0', ['file_resource'], ['application/pdf'], ['view']));
contentExpect($service->resolve(contentContext('application/pdf'), 'view', null, null)['state'] === 'preview_unavailable', 'never choose by registration order');
contentReject(fn() => $registry->register($registry->registrations()[0]), 'CONTENT_ADAPTER_DUPLICATE');
contentReject(fn() => new SurfaceAdapterRegistration('bad.type', 'official.content-tools', '1.0.0', ['file_resource'], ['image/*'], ['view']), 'CONTENT_ADAPTER_INVALID');
contentReject(fn() => new SurfaceAdapterRegistration('bad.protocol', 'official.content-tools', '1.0.0', ['rich_text'], [], ['view'], protocolVersion: 'other/1'), 'CONTENT_ADAPTER_INVALID');
contentReject(fn() => contentContext('IMAGE/PNG'), 'CONTENT_CONTEXT_INVALID');
contentReject(fn() => $service->resolve(contentContext(), 'download', null, null), 'CONTENT_INTENT_INVALID');
$rich = new AuthorizedContentContext('rich_text', null, true, true, false, 'auth-rich', 'browser', ['official.rich-text']);
contentExpect($service->resolve($rich, 'edit', null, null)['state'] === 'edit_unsupported', 'missing rich-text provider is honest');

// Exercise complete composition, never a lazily instantiated provider side effect.
$manifests = [];
foreach (['content_tools', 'rich_text'] as $name) {
    $path = $root . '/server/app/modules/official/' . $name;
    $manifests[] = (new \PeanutAdmin\Kernel\Module\ManifestLoader())->load($path);
}
$compiled = new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry($manifests, [], [], [], 'content-composition');
$app = new think\App($root . '/server');
(new \app\common\composition\ModuleComposition($app))->register($compiled);
contentExpect($app->make(ContentToolRegistry::class) === $app->make(ContentToolRegistry::class), 'registry retains application scope');
contentExpect($app->make(ContentCapabilitiesService::class)->resolve(contentContext(), 'view', 'builtin.image', null)['canView'], 'native container capabilities');
contentExpect($app->make(ContentCapabilitiesService::class)->resolve($rich, 'edit', 'rich-text.tiptap', 'rich-text.tiptap')['canEdit'], 'actual RichText provider collected before service resolution');
$reverse = new think\App($root . '/server');
(new \app\common\composition\ModuleComposition($reverse))->register(new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry(array_reverse($manifests), [], [], [], 'reverse'));
contentExpect($reverse->make(ContentCapabilitiesService::class)->resolve($rich, 'edit', 'rich-text.tiptap', 'rich-text.tiptap')['canEdit'], 'provider order does not determine availability');
try {
    (new \app\common\composition\ModuleComposition(new think\App()))->register(new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([$manifests[1]], [], [], [], 'missing'));
    throw new RuntimeException('missing Content Tools must reject');
} catch (\PeanutAdmin\Kernel\Module\ModuleException $exception) {
    contentExpect($exception->errorCode === 'MODULE_CONTENT_TOOL_UNAVAILABLE', 'missing provider module rejection');
}
// Explicit synthetic contribution faults verify rejection before any binding mutation.
final class ContentContributorFixture implements \PeanutAdmin\Kernel\Module\ModuleProvider, \PeanutAdmin\Modules\ContentTools\Contract\ContentToolContributor
{
    public static bool $wrongOwner = false;
    public function moduleKey(): string { return 'example.fixture'; }
    public function bindings(): array { return []; }
    public function contentToolRegistrations(): array
    {
        return [new SurfaceAdapterRegistration('rich-text.tiptap', self::$wrongOwner ? 'example.other' : 'example.fixture', '1.0.0', ['rich_text'], [], ['view'])];
    }
}
$fixture = \PeanutAdmin\Kernel\Module\ManifestDocument::fromArray($root, ['key'=>'example.fixture','backend'=>['provider'=>ContentContributorFixture::class]]);
foreach ([false, true] as $wrongOwner) {
    ContentContributorFixture::$wrongOwner = $wrongOwner;
    $faultApp = new think\App();
    try {
        (new \app\common\composition\ModuleComposition($faultApp))->register(new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([...$manifests, $fixture], [], [], [], 'fault'));
        throw new RuntimeException('invalid contribution must reject');
    } catch (\PeanutAdmin\Kernel\Module\ModuleException | LogicException $exception) {
        contentExpect($wrongOwner ? $exception instanceof \PeanutAdmin\Kernel\Module\ModuleException && $exception->errorCode === 'MODULE_CONTENT_TOOL_INVALID' : $exception->getMessage() === 'CONTENT_ADAPTER_DUPLICATE', 'typed owner and duplicate rejection');
        contentExpect(!$faultApp->bound(ContentToolRegistry::class), 'fault does not publish partial registry');
    }
}
// Public PHP validation is also used by the legacy service, with no DB invocation.
$method = new ReflectionMethod(\PeanutAdmin\Modules\RichText\Service\RichTextDocumentService::class, 'encodeDocument');
$legacy = (new ReflectionClass(\PeanutAdmin\Modules\RichText\Service\RichTextDocumentService::class))->newInstanceWithoutConstructor();
$projection = \PeanutAdmin\Modules\RichText\Contract\RichTextProjection::empty();
contentExpect($method->invoke($legacy, json_decode($projection->json, true)) === $projection->json, 'legacy encode uses canonical public projection');
try {
    $method->invoke($legacy, ['schemaVersion'=>'peanut.richtext/1','editorModel'=>'tiptap-prosemirror','content'=>['type'=>'doc','content'=>[['type'=>'mediaPlaceholder','attrs'=>['ref'=>'asset:legacy']]]],'annotations'=>[]]);
    throw new RuntimeException('legacy invalid media must reject');
} catch (\app\common\exception\BusinessException $exception) {
    contentExpect(true, 'legacy media not silently cleaned or persisted');
}
echo 'CONTENT-CAPABILITIES-001 passed; assertions=' . $assertions . PHP_EOL;
