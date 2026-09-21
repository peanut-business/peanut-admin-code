#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\common\value\module\ModulePhpNamespace;

$root = dirname(__DIR__, 3);
$mappingPath = $argv[1] ?? $root . '/server/tests/fixtures/module-namespace-migration-map.json';
require $root . '/server/vendor/autoload.php';

function namespaceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,mixed> */
function namespaceJson(string $path): array
{
    $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    namespaceCheck(is_array($value), "JSON object is invalid: {$path}");
    return $value;
}

function namespaceSymbolExists(string $kind, string $symbol): bool
{
    return match ($kind) {
        'class' => class_exists($symbol),
        'interface' => interface_exists($symbol),
        'trait' => trait_exists($symbol),
        'enum' => function_exists('enum_exists') && enum_exists($symbol),
        default => false,
    };
}

$mapping = namespaceJson($mappingPath);
namespaceCheck(($mapping['counts']['modules'] ?? null) === 18, 'Migration mapping Module count changed.');
$moduleRoots = [];
$namespaces = [];
$expectedHostPsr4 = ['app\\' => 'app'];
foreach (glob($root . '/server/app/modules/*/*/module.json') ?: [] as $manifestPath) {
    $moduleRoot = dirname($manifestPath);
    $manifest = namespaceJson($manifestPath);
    $moduleKey = $manifest['key'] ?? null;
    namespaceCheck(is_string($moduleKey) && $moduleKey !== '' && !isset($moduleRoots[$moduleKey]), 'Module key is missing or duplicated.');
    $prefix = ModulePhpNamespace::fromModuleRoot($moduleRoot);
    $moduleRoots[$moduleKey] = $moduleRoot;
    $namespaces[$moduleKey] = $prefix;
    $relativeRoot = substr($moduleRoot, strlen($root . '/server/'));
    $expectedHostPsr4[$prefix] = $relativeRoot . '/src/';

    $provider = $manifest['backend']['provider'] ?? null;
    namespaceCheck(is_string($provider) && $provider === $prefix . 'ModuleProvider', "Provider is not namespace-derived: {$moduleKey}.");
    namespaceCheck(class_exists($provider), "Provider is not autoloadable: {$provider}.");
    foreach (($manifest['contracts']['exports'] ?? []) as $export) {
        namespaceCheck(is_string($export) && namespaceSymbolExists('class', $export)
            || is_string($export) && namespaceSymbolExists('interface', $export)
            || is_string($export) && namespaceSymbolExists('trait', $export)
            || is_string($export) && namespaceSymbolExists('enum', $export), "Export is not autoloadable: {$moduleKey}.");
    }
}
namespaceCheck(count($moduleRoots) === 18, 'Discovered Module count changed.');
ModulePhpNamespace::map($moduleRoots);

$hostComposer = namespaceJson($root . '/server/composer.json');
$hostPsr4 = $hostComposer['autoload']['psr-4'] ?? null;
ksort($expectedHostPsr4, SORT_STRING);
if (is_array($hostPsr4)) {
    ksort($hostPsr4, SORT_STRING);
}
namespaceCheck($hostPsr4 === $expectedHostPsr4, 'Host Composer PSR-4 mappings differ from Module declarations.');

$reflected = 0;
foreach ($mapping['symbols'] ?? [] as $entry) {
    namespaceCheck(is_array($entry), 'Migration symbol entry is invalid.');
    $old = $entry['old_fqcn'] ?? null;
    $new = $entry['new_fqcn'] ?? null;
    $kind = $entry['kind'] ?? null;
    $relativeFile = $entry['new_file'] ?? null;
    namespaceCheck(is_string($old) && is_string($new) && is_string($kind) && is_string($relativeFile), 'Migration symbol metadata is incomplete.');
    namespaceCheck(namespaceSymbolExists($kind, $new), "Migrated symbol is not autoloadable: {$new}.");
    $reflection = new ReflectionClass($new);
    $actual = $reflection->getFileName();
    namespaceCheck(is_string($actual) && realpath($actual) === realpath($root . '/' . $relativeFile), "Migrated symbol resolved from another file: {$new}.");
    namespaceCheck(!namespaceSymbolExists($kind, $old), "Relocated symbol remains autoloadable under its old name: {$old}.");
    $reflected++;
}

echo json_encode([
    'status' => 'passed',
    'modules' => count($moduleRoots),
    'symbols_reflected' => $reflected,
    'host_psr4_prefixes' => count($expectedHostPsr4),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
