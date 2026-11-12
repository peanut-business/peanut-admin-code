<?php

declare(strict_types=1);

$root = ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server');
$script = $root . '/database/seed-demo-data.php';
$wrapper = $root . '/../scripts/seed-demo-data';
$inventory = $root . '/../scaffold/application-template-inventory.json';
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
$entries = array_column(json_decode(file_get_contents($inventory), true, 512, JSON_THROW_ON_ERROR)['files'], null, 'path');
foreach (['server/database/seed-demo-data.php', 'server/database/WorkbenchDemoData.php'] as $path) {
    $expect(($entries[$path]['classification'] ?? null) === 'managed', "template must manage {$path}");
}
$dockerfile = (string) file_get_contents($productionDockerfile);
$expect(
    str_contains($dockerfile, 'COPY . .')
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
$expect($plan['preset'] === 'workbench' && count($plan['visitor']['date']) === 15
    && count($plan['sale']['date']) === 7 && $plan['today']['today_sales'] === 100, 'workbench preview is incomplete');
foreach (['visitor', 'sale'] as $series) {
    $expect(count($plan[$series]['date']) === count($plan[$series]['list'][0]['data'])
        && str_starts_with($plan[$series]['list'][0]['name'], 'Demo '), "Demo series is inconsistent: {$series}");
}
exec(escapeshellarg($script) . ' --apply 2>&1', $output, $exit);
$expect($exit === 64 && str_contains(implode("\n", $output), '--demo=workbench'), 'existing APP must reject standalone seeding');

require_once $root . '/vendor/autoload.php';
require_once $root . '/database/WorkbenchDemoData.php';
$settings = new class implements \PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery, \PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands {
    public array $documents = [];
    public function get(object $context, string $namespace, array $defaults = []): \PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot
    {
        $document = $this->documents[$context->tenantId][$namespace] ?? null;
        return new \PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot($context->tenantId, $namespace, $document ?? $defaults, $document === null ? 0 : 1, 0, 0);
    }
    public function replace(object $context, string $namespace, array $document): \PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot
    {
        $this->documents[$context->tenantId][$namespace] = $document;
        return $this->get($context, $namespace);
    }
};
$app = new \think\App($root);
$app->instance(\PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery::class, $settings);
$app->instance(\PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands::class, $settings);
WorkbenchDemoData::seed($app, 11);
$expect($settings->documents === [], 'default installation must leave demo data empty');
$app->config->set(['demo' => ['preset' => 'workbench']], 'peanut');
WorkbenchDemoData::seed($app, 22);
$before = $settings->documents;
$expect(array_keys($before) === [22] && $before[22][WorkbenchDemoData::NAMESPACE]['preset'] === 'workbench', 'workbench demo must be tenant scoped');
try {
    WorkbenchDemoData::seed($app, 22);
    throw new RuntimeException('a second initialization overwrote existing demo data');
} catch (RuntimeException $error) {
    $expect(str_starts_with($error->getMessage(), 'DEMO_DATA_PRESENT') && $settings->documents === $before, 'existing tenant demo must be preserved');
}

echo "DEMO-DATA-SEED-001 contract passed\n";
