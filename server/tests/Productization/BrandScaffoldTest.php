<?php

declare(strict_types=1);

use app\common\service\config\BrandDefaults;
use app\common\service\config\WebsiteConfigService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function brandExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$website = BrandDefaults::website();
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
    $website['github_url'] === 'https://github.com/peanut-business/peanut-admin',
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

echo "PB08A-BRAND-SCAFFOLD-001 bootstrap passed\n";
