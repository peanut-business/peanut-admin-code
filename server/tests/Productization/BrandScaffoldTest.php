<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Settings\Infrastructure\BrandDefaults;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use app\common\infrastructure\scaffold\ApplicationCreator;

require_once defined('PHPUNIT_COMPOSER_INSTALL')
    ? PHPUNIT_COMPOSER_INSTALL
    : ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';

function brandExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$website = WebsiteConfigService::defaults();
$defaultImages = BrandDefaults::defaultImages();
brandExpect(
    array_keys([...$website, ...BrandDefaults::sidebarFooter()]) === WebsiteConfigService::fields(),
    'bootstrap brand and footer defaults must cover the website Runtime fields',
);
brandExpect($website['name'] === 'Peanut Admin', 'default product name must be complete');
brandExpect($website['shop_name'] === 'Peanut Admin', 'default consumer name must be complete');
brandExpect($website['pc_title'] === 'Peanut Admin', 'default PC title must be complete');
brandExpect($website['official_url'] === '', 'environment-specific official URL must not be a template default');
brandExpect(
    $website['github_url'] === 'https://github.com/peanut-business/peanut-admin-code',
    'GitHub entry must point to the application source repository',
);

$publicRoot = ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/public/';
foreach (['web_favicon', 'web_logo', 'login_image', 'shop_logo', 'pc_logo', 'pc_ico', 'h5_favicon'] as $field) {
    $asset = $publicRoot . $website[$field];
    brandExpect(is_file($asset), "missing bootstrap asset for {$field}");
    $content = file_get_contents($asset);
    brandExpect(is_string($content) && str_contains($content, '<svg'), "invalid SVG asset for {$field}");
}

foreach ($defaultImages as $field => $relativePath) {
    $asset = $publicRoot . $relativePath;
    brandExpect(is_file($asset), "missing default image for {$field}");
}

$projectConfig = file_get_contents(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/config/project.php');
brandExpect(is_string($projectConfig), 'brand test must read project config');
foreach (['admin_avatar', 'user_avatar', 'menu', 'project_docs', 'technical_support'] as $field) {
    brandExpect(
        str_contains($projectConfig, "\$defaultImage['{$field}']"),
        "project config must read {$field} from the manifest",
    );
}

$seed = file_get_contents(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/database/schema/baseline-data.sql');
brandExpect(
    is_string($seed) && str_contains($seed, "'{$defaultImages['user_avatar']}'"),
    'initial user avatar default must match the manifest',
);

$root = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3));
$creator = new ApplicationCreator($root, $root . '/scaffold/application-template-inventory.json');
$parameters = ['PRODUCT_NAME' => 'Source Consumer', 'PACKAGE_IDENTITY' => 'consumer/application', 'SLUG' => 'source-consumer', 'APPLICATION_VERSION' => '0.1.0'];
$transform = Closure::bind(
    fn(string $content, string $path, string $kind): string => $this->transform($content, [
        'path' => $path,
        'target' => $path === 'server/resources/scaffold-application/composer.json' ? 'server/composer.json' : $path,
        'transform' => $kind,
    ], $parameters, $root),
    $creator,
    ApplicationCreator::class,
);
$documentation = "Peanut Admin\n[Releases](https://github.com/peanut-business/peanut-admin-code/releases)\n"
    . '[Sources](https://github.com/peanut-business/peanut-admin-code/tree/main)';
$renderedDocumentation = $transform($documentation, 'docs/public/release-channels.md', 'text');
preg_match_all('#https://github\.com/peanut-business/peanut-admin-code[^\s)]+#', $documentation, $sourceLinks);
preg_match_all('#https://github\.com/peanut-business/peanut-admin-code[^\s)]+#', $renderedDocumentation, $renderedLinks);
brandExpect(count($sourceLinks[0]) >= 2 && $renderedLinks[0] === $sourceLinks[0], 'generated documentation must retain actual upstream repository URLs');
$bootstrapSource = 'server/resources/scaffold-application/composer.json';
$sourceComposer = json_decode((string) file_get_contents($root . '/' . $bootstrapSource), true, 512, JSON_THROW_ON_ERROR);
$applicationComposer = json_decode($transform((string) file_get_contents($root . '/' . $bootstrapSource), $bootstrapSource, 'package'), true, 512, JSON_THROW_ON_ERROR);
brandExpect($applicationComposer['name'] === $parameters['PACKAGE_IDENTITY'], 'application package name must still render its own identity');
brandExpect($applicationComposer['description'] === 'Source Consumer application backend' && $applicationComposer['authors'][0]['name'] === 'application owner', 'application brand and author must still render');
brandExpect(
    $applicationComposer['homepage'] === $sourceComposer['homepage']
        && !isset($sourceComposer['repositories']) && !isset($applicationComposer['repositories'])
        && $applicationComposer['require'] === $sourceComposer['require']
        && isset($applicationComposer['require']['peanut-admin/core'])
        && count(array_filter(array_keys($applicationComposer['require']), static fn(string $name): bool => str_starts_with($name, 'peanut-business/peanut-'))) === 0,
    'thin Host application brand must preserve external dependency identities without bundled official author packages',
);

echo "PB08A-BRAND-SCAFFOLD-001 bootstrap passed\n";
