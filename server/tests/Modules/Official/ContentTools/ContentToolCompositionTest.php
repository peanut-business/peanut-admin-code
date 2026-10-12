<?php

declare(strict_types=1);

use app\common\composition\ModuleComposition;
use app\platform\composition\plugin\ModuleDefinitionRegistryFactory;
use app\platform\infrastructure\plugin\PluginLockResolver;
use PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext;
use PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService;
use PeanutAdmin\Modules\ContentTools\Service\ContentToolRegistry;
use think\App;

$root = dirname(__DIR__, 5);
require $root . '/server/vendor/autoload.php';
$compiled = (new ModuleDefinitionRegistryFactory($root . '/server'))->fromPluginLock(
    new PluginLockResolver($root . '/server', '../plugins.lock'),
    ['roots'=>[],'kernel_version'=>'1.0.0','registered_client_keys'=>['admin-web','platform-web','pc-web','uniapp']],
);
$app = new App($root . '/server');
(new ModuleComposition($app))->register($compiled);
$count = 0;
$expect = function (bool $condition) use (&$count): void {
    if (!$condition) throw new RuntimeException('CONTENT_TOOL_COMPOSITION_INVALID');
    $count++;
};
$registry = $app->make(ContentToolRegistry::class);
$context = new AuthorizedContentContext('rich_text', null, true, true, false, 'authorized-caller-test', 'browser', ['official.rich-text']);
$service = $app->make(ContentCapabilitiesService::class);
$view = $service->resolve($context, 'view', 'rich-text.tiptap', 'rich-text.tiptap');
$edit = $service->resolve($context, 'edit', 'rich-text.tiptap', 'rich-text.tiptap');
$expect($view['canView'] && $edit['canEdit']);
$expect(count(array_filter($registry->registrations(), fn($registration) => $registration->id === 'rich-text.tiptap')) === 1);
$expect($registry === $app->make(ContentToolRegistry::class));
$expect($compiled->requireManifest('official.rich-text')->data['dependencies'][0]['module_key'] === 'official.content-tools');
$expect($registry->registrations()[0]->id === 'builtin.image');
$expect(!$registry->selected('rich-text.tiptap', $context, 'view')->supportsCollaboration);
echo 'CONTENT-TOOL-COMPOSITION-001 passed; compiled_modules=' . count($compiled->modules) . '; assertions=' . $count . '; no DB/auth qualification' . PHP_EOL;
