<?php

declare(strict_types=1);

use app\platform\infrastructure\module\ModuleLockResolver;
use PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext;
use PeanutAdmin\Modules\ContentTools\Contract\SurfaceAdapterRegistration;
use PeanutAdmin\Modules\ContentTools\Service\ContentToolRegistry;
use PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService;

$root = realpath($argv[1] ?? dirname(__DIR__, 5));
if ($root === false || !is_file($root . '/server/vendor/autoload.php')) {
    throw new RuntimeException('Content Tools host test requires a prepared application root.');
}
require $root . '/server/vendor/autoload.php';
$bootstrap = new think\App($root . '/server');
$bootstrap->config->set(['mode' => 'multi-tenant'], 'deployment');
(new \app\platform\composition\module\ModuleDefinitionRegistryFactory($root . '/server'))->fromModuleLock(
    new ModuleLockResolver($root . '/server', '../modules.lock'),
    ['roots' => [], 'kernel_version' => '1.0.0', 'registered_client_keys' => ['admin-web', 'platform-web', 'pc-web', 'uniapp']],
);
$assertions = 0;
function contentExpect(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function contentContext(string $mime = 'image/png', bool $view = true, bool $edit = true, bool $download = false, array $providers = ['peanut.content-tools'], array $services = [], string $revision = 'auth-1'): AuthorizedContentContext
{
    return new AuthorizedContentContext('file_resource', $mime, $view, $edit, $download, $revision, 'browser', $providers, $services);
}

$rich = new AuthorizedContentContext('rich_text', null, true, true, false, 'auth-rich', 'browser', ['official.rich-text']);

$modules = (new ModuleLockResolver($root . '/server', '../modules.lock'))->all();
$moduleRoot = $modules['peanut.content-tools']->moduleRoots['peanut.content-tools'];
$manifests = [(new \PeanutAdmin\Kernel\Module\ManifestLoader())->load($moduleRoot)];
$compiled = new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry($manifests, [], [], [], 'content-composition');
$app = new think\App($root . '/server');
(new \app\common\composition\ModuleComposition($app))->register($compiled);
contentExpect($app->make(ContentToolRegistry::class) === $app->make(ContentToolRegistry::class), 'registry retains application scope');
contentExpect($app->make(ContentCapabilitiesService::class)->resolve(contentContext(), 'view', 'builtin.image', null)['canView'], 'native container capabilities');
contentExpect(!$app->make(ContentCapabilitiesService::class)->resolve($rich, 'edit', 'rich-text.tiptap', 'rich-text.tiptap')['canEdit'], 'unselected RichText provider cannot grant editing');
$reverse = new think\App($root . '/server');
(new \app\common\composition\ModuleComposition($reverse))->register(new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry(array_reverse($manifests), [], [], [], 'reverse'));
contentExpect($reverse->make(ContentCapabilitiesService::class)->resolve(contentContext(), 'view', 'builtin.image', null)['canView'], 'native viewer remains available');
// Explicit synthetic contribution faults verify rejection before any binding mutation.
final class ContentContributorFixture implements \PeanutAdmin\Kernel\Module\ModuleProvider, \PeanutAdmin\Modules\ContentTools\Contract\ContentToolContributor
{
    public static bool $wrongOwner = false;
    public function moduleKey(): string { return 'example.fixture'; }
    public function bindings(): array { return []; }
    public function contentToolRegistrations(): array
    {
        return [new SurfaceAdapterRegistration('builtin.image', self::$wrongOwner ? 'example.other' : 'example.fixture', '1.0.0', ['file_resource'], ['image/png'], ['view'])];
    }
}
$fixture = \PeanutAdmin\Kernel\Module\ManifestDocument::fromObject($root, (object) ['key'=>'example.fixture','backend'=>(object) ['provider'=>ContentContributorFixture::class]]);
try {
    (new \app\common\composition\ModuleComposition(new think\App()))->register(new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([$fixture], [], [], [], 'missing'));
    throw new RuntimeException('missing Content Tools must reject');
} catch (\PeanutAdmin\Kernel\Module\ModuleException $exception) {
    contentExpect($exception->errorCode === 'MODULE_CONTENT_TOOL_UNAVAILABLE', 'missing provider module rejection');
}
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
echo 'CONTENT-CAPABILITIES-COMPOSITION-001 passed; assertions=' . $assertions . PHP_EOL;
