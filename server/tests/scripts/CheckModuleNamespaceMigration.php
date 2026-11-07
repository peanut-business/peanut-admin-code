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
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
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
$locked = array_column(namespaceJson($root . '/modules.lock')['modules'], 'key');
$discovered = array_keys($moduleRoots);
sort($locked, SORT_STRING);
sort($discovered, SORT_STRING);
namespaceCheck($locked !== [] && $locked === $discovered, 'Discovered Modules differ from the locked application.');
ModulePhpNamespace::map($moduleRoots);

$hostComposer = namespaceJson($root . '/server/composer.json');
$hostPsr4 = $hostComposer['autoload']['psr-4'] ?? null;
ksort($expectedHostPsr4, SORT_STRING);
if (is_array($hostPsr4)) {
    ksort($hostPsr4, SORT_STRING);
}
namespaceCheck($hostPsr4 === $expectedHostPsr4, 'Host Composer PSR-4 mappings differ from Module declarations.');

$reflected = 0;
foreach ($moduleRoots as $moduleKey => $moduleRoot) {
    $source = $moduleRoot . '/src/';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $name = substr($file->getPathname(), strlen($source), -4);
        $symbol = $namespaces[$moduleKey] . str_replace(DIRECTORY_SEPARATOR, '\\', $name);
        namespaceCheck(class_exists($symbol) || interface_exists($symbol) || trait_exists($symbol) || enum_exists($symbol),
            "Declared Module type is not autoloadable: {$symbol}.");
        $actual = (new ReflectionClass($symbol))->getFileName();
        namespaceCheck(is_string($actual) && realpath($actual) === $file->getRealPath(), "Module type resolved outside its owner: {$symbol}.");
        ++$reflected;
    }
}
foreach ($mapping['symbols'] as $entry) {
    $old = $entry['old_fqcn'];
    namespaceCheck(!namespaceSymbolExists($entry['kind'], $old), "Relocated symbol remains autoloadable under its old name: {$old}.");
}

echo json_encode([
    'status' => 'passed',
    'modules' => count($moduleRoots),
    'symbols_reflected' => $reflected,
    'host_psr4_prefixes' => count($expectedHostPsr4),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
