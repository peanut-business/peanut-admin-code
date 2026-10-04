<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$script = $root . '/database/seed-demo-data.php';
$wrapper = $root . '/../scripts/seed-demo-data';
$inventoryBuilder = $root . '/../scripts/build-application-template-inventory';
$productionDockerfile = $root . '/../deploy/docker/production.Dockerfile';
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expect(is_file($script), 'managed demo seed implementation is missing');
$expect(is_file($wrapper) && is_executable($wrapper), 'demo seed compatibility wrapper is missing or not executable');
$wrapperSource = (string) file_get_contents($wrapper);
$expect(
    str_contains($wrapperSource, "require dirname(__DIR__) . '/server/database/seed-demo-data.php';")
        && !str_contains($wrapperSource, 'function demoPlan'),
    'demo seed compatibility wrapper must only delegate to the managed implementation',
);
$inventoryBuilderSource = (string) file_get_contents($inventoryBuilder);
$managedSeederRule = strpos($inventoryBuilderSource, "\$path === 'server/database/seed-demo-data.php'");
$appOwnedDatabaseRule = strpos($inventoryBuilderSource, "str_starts_with(\$path, 'server/database/')");
$expect(
    $managedSeederRule !== false
        && $appOwnedDatabaseRule !== false
        && $managedSeederRule < $appOwnedDatabaseRule,
    'demo seed implementation must be classified as managed before the general app-owned database rule',
);
$dockerfile = (string) file_get_contents($productionDockerfile);
$expect(
    str_contains($dockerfile, 'COPY server/database server/database')
        && !str_contains($dockerfile, 'COPY scripts/seed-demo-data'),
    'production PHP image must install the managed demo seed implementation without the root wrapper',
);
$expect(
    str_contains($dockerfile, 'chmod +x server/think server/database/seed-demo-data.php /usr/local/bin/peanut-php-entrypoint')
        && str_contains($dockerfile, 'ln -s /var/www/peanut-admin/server/database/seed-demo-data.php /usr/local/bin/peanut-seed-demo-data'),
    'production PHP image does not expose the managed demo seed implementation as a stable command',
);
$planOutput = [];
$planExit = 0;
exec(escapeshellarg($script) . ' --plan 2>&1', $planOutput, $planExit);
$expect($planExit === 0, 'demo seed plan command failed: ' . implode("\n", $planOutput));
$plan = json_decode(implode("\n", $planOutput), true, 512, JSON_THROW_ON_ERROR);
$expect(($plan['status'] ?? null) === 'planned', 'demo seed plan status is invalid');
$expect(($plan['categories'] ?? 0) === 3, 'demo seed plan category count changed');
$expect(($plan['articles'] ?? 0) === 6, 'demo seed plan article count changed');
$expect(($plan['tags'] ?? 0) === 2, 'demo seed plan tag count changed');
$expect(($plan['members'] ?? 0) === 5, 'demo seed plan member count changed');

$applyOutput = [];
$applyExit = 0;
exec(escapeshellarg($script) . ' --apply 2>&1', $applyOutput, $applyExit);
$expect($applyExit !== 0, 'demo seed apply unexpectedly ran without explicit opt-in');
$expect(
    str_contains(implode("\n", $applyOutput), 'PEANUT_DEMO_MODE=enabled is required'),
    'demo seed apply did not fail at its explicit opt-in gate',
);

$source = (string) file_get_contents($script);
$expect(str_contains($source, "PEANUT_DEMO_MODE') !== 'enabled"), 'demo seed is missing its explicit opt-in gate');
$expect(str_contains($source, 'SELECT id FROM pa_article_cate WHERE tenant_id = :tenant_id AND name = :name LIMIT 1'), 'demo category seed must find existing rows without relying on a missing unique key');
$expect(str_contains($source, 'ON DUPLICATE KEY UPDATE'), 'demo member and tag seed must be idempotent');
$expect(str_contains($source, 'INSERT IGNORE INTO pa_member_tag_relation'), 'demo tag relations must be idempotent');
$expect(str_contains($source, 'guardedDatabaseConfig()'), 'demo seed must use the project environment guard');
$expect(str_contains($source, 'PEANUT_DEMO_TENANT_ID'), 'demo seed must require an explicit tenant');

$prepareCalls = [];
preg_match_all('/\$pdo->prepare\((.*?)\);/s', $source, $prepareCalls);
$expect(($prepareCalls[1] ?? []) !== [], 'demo seed prepared statements could not be inspected');
foreach ($prepareCalls[1] as $preparedSql) {
    $parameters = [];
    preg_match_all('/:([a-z_][a-z0-9_]*)/i', $preparedSql, $parameters);
    $duplicates = array_diff_assoc($parameters[1], array_unique($parameters[1]));
    $expect($duplicates === [], 'demo seed repeats native prepared parameter :' . reset($duplicates));
}

echo "DEMO-DATA-SEED-001 contract passed\n";
