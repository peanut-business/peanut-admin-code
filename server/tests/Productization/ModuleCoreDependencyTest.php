<?php

declare(strict_types=1);

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Native declarations must describe the same Core selection. This check does
// not resolve packages, publish versions, or prove runtime compatibility.
$root = dirname(__DIR__, 3);
$parser = new VersionParser();
$overlaps = static fn(string $host, string $module): bool => Intervals::haveIntersections(
    $parser->parseConstraints($host),
    $parser->parseConstraints($module),
);
$checks = 0;
foreach ([
    ['dev-dev', 'dev-dev', true],
    ['dev-dev', '0.1.0-alpha.5', false],
    ['dev-dev', '^1.0', false],
    ['4.0.0-rc.1', '4.0.0-rc.1', true],
    ['4.0.0', '^4.0', true],
    ['4.0.0', '^1.0', false],
] as [$host, $module, $expected]) {
    $checks++;
    if ($overlaps($host, $module) !== $expected) {
        throw new RuntimeException('Native Composer constraint comparison changed.');
    }
}
$document = static fn(string $path): array => json_decode(
    (string) file_get_contents($path),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$application = $document($root . '/server/composer.json');
$host = $application['require']['peanut-admin/core'] ?? null;
if (!is_string($host) || $host === '') {
    throw new RuntimeException('Application Core requirement is missing.');
}
$files = glob($root . '/server/app/modules/*/*/composer.json') ?: [];
sort($files, SORT_STRING);
$declared = 0;
$withoutCoreRequirement = 0;
$failures = [];
foreach ($files as $path) {
    $module = $document($path);
    $constraint = $module['require']['peanut-admin/core'] ?? null;
    if ($constraint === null) {
        $withoutCoreRequirement++;
        continue;
    }
    $checks++;
    $declared++;
    if (!is_string($constraint) || $constraint === '' || !$overlaps($host, $constraint)) {
        $failures[] = [
            'path' => substr($path, strlen($root) + 1),
            'module_requirement' => $constraint,
            'application_requirement' => $host,
        ];
    }
}
if ($declared === 0) {
    throw new RuntimeException('No declared Module Core dependencies were checked.');
}
echo json_encode([
    'status' => $failures === [] ? 'passed' : 'failed',
    'checks' => $checks,
    'module_manifests' => count($files),
    'declared_core_dependencies' => $declared,
    'without_core_requirement' => $withoutCoreRequirement,
    'failures' => $failures,
    'scope' => 'native declaration intersection only; no install or publication',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failures === [] ? 0 : 1);
