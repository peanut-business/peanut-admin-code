<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Settings\Infrastructure\BrandDefaults;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use app\common\infrastructure\scaffold\ApplicationCreator;

require_once defined('PHPUNIT_COMPOSER_INSTALL')
    ? PHPUNIT_COMPOSER_INSTALL
    : dirname(__DIR__, 2) . '/vendor/autoload.php';

function brandExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$website = WebsiteConfigService::defaults();
$defaultImages = BrandDefaults::defaultImages();
brandExpect(
    array_keys($website) === WebsiteConfigService::fields(),
    'bootstrap manifest and website Runtime fields must match exactly',
);
brandExpect($website['name'] === 'Peanut Admin', 'default product name must be complete');
brandExpect($website['shop_name'] === 'Peanut Admin', 'default consumer name must be complete');
brandExpect($website['pc_title'] === 'Peanut Admin', 'default PC title must be complete');
brandExpect($website['official_url'] === '', 'environment-specific official URL must not be a template default');
brandExpect(
    $website['github_url'] === 'https://github.com/peanut-business/peanut-admin-code',
    'GitHub entry must point to the application source repository',
);

$publicRoot = dirname(__DIR__, 2) . '/public/';
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

$projectConfig = file_get_contents(dirname(__DIR__, 2) . '/config/project.php');
brandExpect(is_string($projectConfig), 'brand test must read project config');
foreach (['admin_avatar', 'user_avatar', 'menu', 'project_docs', 'technical_support'] as $field) {
    brandExpect(
        str_contains($projectConfig, "\$defaultImage['{$field}']"),
        "project config must read {$field} from the manifest",
    );
}

$migration = file_get_contents(
    dirname(__DIR__, 2) . '/database/init.sql',
);
brandExpect(
    is_string($migration) && str_contains($migration, "'{$defaultImages['user_avatar']}'"),
    'legacy user avatar migration must match the manifest',
);

$root = dirname(__DIR__, 3);
$creator = new ApplicationCreator($root, $root . '/scaffold/application-template-inventory.json');
$parameters = ['PRODUCT_NAME' => 'Source Consumer', 'PACKAGE_IDENTITY' => 'consumer/application', 'SLUG' => 'source-consumer', 'APPLICATION_VERSION' => '0.1.0'];
$transform = Closure::bind(
    fn(string $content, string $path, string $kind): string => $this->transform($content, ['path' => $path, 'transform' => $kind], $parameters, $root),
    $creator,
    ApplicationCreator::class,
);
$documentation = (string) file_get_contents($root . '/docs/public/release-channels.md');
$renderedDocumentation = $transform($documentation, 'docs/public/release-channels.md', 'text');
preg_match_all('#https://github\.com/peanut-business/peanut-admin-code[^\s)]+#', $documentation, $sourceLinks);
preg_match_all('#https://github\.com/peanut-business/peanut-admin-code[^\s)]+#', $renderedDocumentation, $renderedLinks);
brandExpect(count($sourceLinks[0]) >= 2 && $renderedLinks[0] === $sourceLinks[0], 'generated documentation must retain actual upstream repository URLs');
$sourceComposer = json_decode((string) file_get_contents($root . '/server/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$applicationComposer = json_decode($transform((string) file_get_contents($root . '/server/composer.json'), 'server/composer.json', 'package'), true, 512, JSON_THROW_ON_ERROR);
brandExpect($applicationComposer['name'] === $parameters['PACKAGE_IDENTITY'], 'application package name must still render its own identity');
brandExpect($applicationComposer['description'] === 'Source Consumer application backend' && $applicationComposer['authors'][0]['name'] === 'application owner', 'application brand and author must still render');
brandExpect($applicationComposer['homepage'] === $sourceComposer['homepage'] && $applicationComposer['repositories'] === $sourceComposer['repositories'] && $applicationComposer['require'] === $sourceComposer['require'], 'external source references and dependency identities must remain unchanged');

echo "PB08A-BRAND-SCAFFOLD-001 bootstrap passed\n";
