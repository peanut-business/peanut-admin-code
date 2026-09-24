<?php
declare(strict_types=1);

use app\common\infrastructure\scaffold\ApplicationCreator;
use app\platform\infrastructure\plugin\PluginLockResolver;

$root = dirname(__DIR__, 3);
require_once $root . '/server/vendor/autoload.php';
require_once $root . '/server/app/common/validation/scaffold/ScaffoldPathGuard.php';
require_once $root . '/server/app/common/value/scaffold/ScaffoldManifest.php';
require_once $root . '/server/app/common/infrastructure/scaffold/ApplicationCreator.php';
require_once $root . '/server/app/platform/exception/plugin/PluginLifecycleException.php';
require_once $root . '/server/app/platform/value/plugin/PluginDescriptor.php';
require_once $root . '/server/app/platform/infrastructure/plugin/PluginLockResolver.php';

function createApplicationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function createApplicationDelete(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            createApplicationDelete($path . '/' . $entry);
        }
        rmdir($path);
        return;
    }
    unlink($path);
}

function createApplicationCopy(string $source, string $target): void
{
    mkdir($target, 0775, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($file->isDir()) {
            mkdir($destination, $file->getPerms() & 0777, true);
        } else {
            copy($file->getPathname(), $destination);
            chmod($destination, $file->getPerms() & 0777);
        }
    }
}

/** @param array<string,mixed> $data */
function createApplicationWriteJson(string $path, array $data): void
{
    file_put_contents($path, json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
}

function createApplicationFails(callable $operation, string $prefix): void
{
    try {
        $operation();
        throw new RuntimeException("expected {$prefix}");
    } catch (RuntimeException $exception) {
        createApplicationExpect(str_starts_with($exception->getMessage(), $prefix), "unexpected error: {$exception->getMessage()}");
    }
}

/** @param list<string> $command */
function createApplicationRun(array $command, ?string $cwd = null): string
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('unable to start application scaffold command');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException('application scaffold command failed(' . $code . '): ' . trim((string)$stderr));
    }
    return (string)$stdout;
}

function createApplicationBuildCurrentRelease(string $root, string $version, string $output): string
{
    $commit = trim(createApplicationRun(['git', '-C', $root, 'rev-parse', 'HEAD']));
    createApplicationExpect(
        preg_match('/^[a-f0-9]{40}$/D', $commit) === 1,
        'current scaffold source identity must be a full commit'
    );
    createApplicationRun([
        'php',
        $root . '/scripts/build-scaffold-release',
        '--version=' . $version,
        '--source-commit=' . $commit,
        '--output=' . $output,
    ]);
    return $commit;
}

/** @param array{commit:string,tree:string} $identity */
function createApplicationTamperedReleaseFails(
    string $root,
    string $inventoryPath,
    array $identity,
    string $releasePath,
    string $temporary,
    string $case,
    callable $mutate,
    string $error
): void {
    $releaseRoot = $temporary . '/release-' . $case;
    createApplicationCopy(dirname($releasePath), $releaseRoot);
    $manifestPath = $releaseRoot . '/scaffold-manifest.json';
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $mutate($manifest, $releaseRoot);
    createApplicationWriteJson($manifestPath, $manifest);
    $target = $temporary . '/tampered-' . $case;
    $creator = new ApplicationCreator($root, $inventoryPath, $identity, $manifestPath);
    createApplicationFails(
        fn() => $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $target, 'multi-tenant', null, 'full'),
        $error
    );
    createApplicationExpect(!file_exists($target), 'failed adoption committed target: ' . $case);
}

/** @return list<string> */
function createApplicationFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
    sort($files, SORT_STRING);
    return $files;
}

$systemTemporary = realpath(sys_get_temp_dir());
createApplicationExpect(is_string($systemTemporary), 'system temporary directory must resolve');
$temporary = $systemTemporary . '/peanut-create-app-' . bin2hex(random_bytes(6));
mkdir($temporary, 0775, true);
$inventoryPath = $root . '/scaffold/application-template-inventory.json';
$inventory = json_decode((string)file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
$inventoryByPath = [];
foreach ($inventory['files'] ?? [] as $entry) {
    if (is_array($entry) && is_string($entry['path'] ?? null)) {
        $inventoryByPath[$entry['path']] = $entry;
    }
}
createApplicationExpect(
    array_filter(array_keys($inventoryByPath), static fn(string $path): bool => str_starts_with($path, 'output/')) === [],
    'source qualification evidence must not participate in application template identity'
);
foreach ([
    'README.md' => 'readme',
    'CHANGELOG.md' => 'changelog',
    'RELEASE_METADATA.json' => 'release-metadata',
    'resources/project-resources.json' => 'resources',
] as $path => $transform) {
    $semanticDigest = hash('sha256', "peanut.create-app-semantic-source.v1\0{$path}\0{$transform}");
    createApplicationExpect(
        ($inventoryByPath[$path]['source_sha256'] ?? null) === $semanticDigest,
        "{$path} must use the versioned semantic source digest"
    );
    createApplicationExpect(
        !hash_equals($semanticDigest, (string)hash_file('sha256', $root . '/' . $path)),
        "{$path} semantic digest must not depend on release prose bytes"
    );
}
// Generated application documentation has real, content-addressed template inputs, not absent source pages.
foreach (['docs-site/capabilities.md', 'docs-site/guide/application-module-lifecycle.md', 'SECURITY.md'] as $targetPath) {
    $sourcePath = 'server/resources/scaffold-application/' . $targetPath . '.stub';
    $entry = $inventoryByPath[$sourcePath] ?? [];
    $expectedClassification = $targetPath === 'SECURITY.md' ? 'app-owned' : 'generated-managed';
    createApplicationExpect(is_file($root . '/' . $sourcePath), 'application documentation template is missing');
    createApplicationExpect(
        ($entry['target'] ?? null) === $targetPath && ($entry['classification'] ?? null) === $expectedClassification
            && ($entry['transform'] ?? null) === 'docs-page'
            && ($entry['source_sha256'] ?? null) === hash_file('sha256', $root . '/' . $sourcePath),
        'application documentation must use its real template digest and declared destination',
    );
}
foreach ([
    'server/database/install.php',
    'server/app/common/services/installation/InstallationExecutionHost.php',
    'server/app/common/services/upgrade/ApplicationMigrationRunner.php',
    'scripts/upgrade',
    'scripts/product-upgrade-host',
    'scripts/product-upgrade-database',
    'scripts/scaffold-runtime/Semver.php',
    'scripts/scaffold-runtime/ReleaseDependencyIdentity.php',
    'scripts/scaffold-runtime/ScaffoldUpgradeRunner.php',
    'web/src/views/user/setting/index.vue',
] as $managedPath) {
    createApplicationExpect(
        in_array($inventoryByPath[$managedPath]['classification'] ?? null, ['managed', 'generated-managed'], true),
        "shared product source must use the upgrade baseline: {$managedPath}",
    );
}
foreach (['server/config/peanut.php', 'web/src/peanut.overrides.ts', 'resources/project-resources.json', 'SECURITY.md', 'scripts/seed-demo-data'] as $customPath) {
    $sourcePath = $customPath === 'SECURITY.md'
        ? 'server/resources/scaffold-application/SECURITY.md.stub'
        : $customPath;
    createApplicationExpect(
        ($inventoryByPath[$sourcePath]['classification'] ?? null) === 'app-owned',
        "instance customization must remain app-owned: {$customPath}",
    );
}
// Deployment test fixtures contain maintainer-only target selectors and must not enter apps or upgrade baselines.
foreach ($inventory['files'] as $entry) {
    if (str_starts_with((string)$entry['path'], 'deploy/tests/')) {
        createApplicationExpect(($entry['classification'] ?? null) === 'excluded',
            'maintainer deployment tests leaked into the generated application inventory');
    }
}
$templateVersion = (string)($inventory['template_version'] ?? '');
createApplicationExpect(
    preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', $templateVersion) === 1,
    'inventory template version must be SemVer'
);
$releaseRoot = $temporary . '/current-scaffold-release';
$releasePath = $releaseRoot . '/scaffold-manifest.json';
$identity = ['commit' => str_repeat('a', 40), 'tree' => str_repeat('b', 40)];

try {
    createApplicationBuildCurrentRelease($root, $templateVersion, $releaseRoot);
    $standardTarget = $temporary . '/standard-default';
    $standardManifest = (new ApplicationCreator($root, $inventoryPath, $identity))->create(
        'Acme Console',
        'acme-console',
        'acme/acme-console',
        $standardTarget,
        'multi-tenant',
    );
    createApplicationExpect(
        ($standardManifest['application']['profile'] ?? null) === 'standard',
        'ApplicationCreator must retain standard as the implicit profile'
    );
    foreach (['platform/src/modules/official-ops/contribution.ts', 'server/route/platform.php'] as $requiredHostPath) {
        createApplicationExpect(is_file($standardTarget . '/' . $requiredHostPath),
            'standard profile omitted a selected Module contribution or multi-tenant Host: ' . $requiredHostPath);
    }
    $creator = new ApplicationCreator($root, $inventoryPath, $identity, $releasePath);
    $first = $temporary . '/first';
    $second = $temporary . '/second';
    $other = $temporary . '/other';
    $standalone = $temporary . '/standalone';
    $manifestOne = $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $first, 'multi-tenant', null, 'full');
    $manifestTwo = $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $second, 'multi-tenant', null, 'full');
    $manifestOther = $creator->create('Beta Workspace', 'beta-workspace', 'beta/beta-workspace', $other, 'multi-tenant', null, 'full');
    $standaloneManifest = $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $standalone, 'standalone', null, 'full');
    $release = json_decode((string)file_get_contents($releasePath), true, 512, JSON_THROW_ON_ERROR)['release'];

    createApplicationExpect($manifestOne['template'] === [
        'version' => $release['version'],
        'inventory_sha256' => $release['inventory_sha256'],
        'source_commit' => $release['source_commit'],
        'source_tree' => $release['source_tree'],
    ], 'application template identity must adopt the immutable release');
    createApplicationExpect($manifestOne['generation_source'] === [
        'commit' => $identity['commit'],
        'tree' => $identity['tree'],
        'inventory_sha256' => hash_file('sha256', $inventoryPath),
        'edition_profile_sha256' => hash_file('sha256', $root . '/scaffold/edition-profiles.json'),
    ], 'generation source must record the actual source identity and current inventory');
    createApplicationExpect($manifestOther['template'] === $manifestOne['template'], 'all parameter groups must adopt the same release identity');
    createApplicationExpect($manifestOther['generation_source'] === $manifestOne['generation_source'], 'all parameter groups must record the same generation source');
    createApplicationExpect($standaloneManifest['template'] === $manifestOne['template'], 'both Editions must adopt one template identity');
    createApplicationExpect($standaloneManifest['generation_source'] === $manifestOne['generation_source'], 'both Editions must record one generation identity');
    createApplicationExpect($manifestOther['application']['name'] === 'Beta Workspace', 'second parameter group must be rendered independently');
    createApplicationExpect(
        $manifestOne['schema_version'] === 2
            && $manifestOne['protocol'] === 'peanut.application-scaffold.v2'
            && $manifestOne['application']['version'] === '0.1.0'
            && $manifestOne['application']['edition'] === 'multi-tenant'
            && $manifestOne['edition']['name'] === 'multi-tenant',
        'generated application manifest must carry the default application version contract'
    );
    createApplicationExpect(
        ($release['version'] ?? null) === $templateVersion
            && (json_decode((string)file_get_contents($releasePath), true, 512, JSON_THROW_ON_ERROR)['application']['version'] ?? null) === '0.1.0',
        'current scaffold release must expose the independent default application version'
    );

    createApplicationExpect($manifestOne === $manifestTwo, 'same template identity and parameters must produce the same manifest');
    createApplicationExpect(
        hash_file('sha256', $first . '/.peanut/application-manifest.json') === hash_file('sha256', $second . '/.peanut/application-manifest.json'),
        'application manifests must be byte-identical'
    );
    foreach ($manifestOne['files'] as $file) {
        createApplicationExpect(
            hash_file('sha256', $first . '/' . $file['path']) === hash_file('sha256', $second . '/' . $file['path']),
            'generated file changed across identical runs: ' . $file['path']
        );
    }

    $expected = ['.peanut/application-manifest.json'];
    foreach ($inventory['files'] as $entry) {
        if ($entry['classification'] === 'excluded' || !in_array('full', $entry['profiles'], true)) continue;
        $expected[] = $entry['target'];
        if (in_array($entry['classification'], ['managed', 'generated-managed'], true)) {
            $expected[] = '.peanut/scaffold-baseline/' . $inventory['template_version'] . '/files/' . $entry['target'];
        }
    }
    sort($expected, SORT_STRING);
    createApplicationExpect(createApplicationFiles($first) === $expected, 'generated tree must exactly match inventory plus declared metadata/baselines');
    createApplicationExpect(!is_dir($first . '/.git') && !is_dir($first . '/output'), 'generated application must exclude Git and historical output');
    createApplicationExpect(!is_file($first . '/AGENTS.md'), 'source governance evidence must be excluded');
    createApplicationExpect(!is_dir($first . '/docs/product-status'), 'source product capability ledger must be excluded');
    createApplicationExpect(!is_file($first . '/docs-site/.vitepress/theme/ProductStatus.vue'), 'source product status component must be excluded');
    createApplicationExpect(!is_file($first . '/docs-site/product-status.md'), 'source product status page must be excluded');
    foreach ([
        'scripts/deploy-release',
        'scripts/ops-backup-worker',
        'scripts/ops-restore-worker',
        'scripts/ops-upgrade-worker',
        'scripts/ops-module-worker',
    ] as $maintainerOperation) {
        createApplicationExpect(
            !is_file($first . '/' . $maintainerOperation),
            'maintainer-only operation tool leaked into generated application: ' . $maintainerOperation,
        );
    }
    $generatedDocsConfig = (string)file_get_contents($first . '/docs-site/.vitepress/config.ts');
    $generatedDocsTheme = (string)file_get_contents($first . '/docs-site/.vitepress/theme/index.ts');
    createApplicationExpect(
        !str_contains($generatedDocsConfig, 'productStatus')
            && !str_contains($generatedDocsTheme, 'ProductStatus'),
        'generated docs must not reference the source-only product status projection'
    );
    createApplicationExpect(!is_dir($first . '/plugins/fixture.delivery-record'), 'demo Plugin artifact must remain source-only');
    createApplicationExpect(!is_dir($first . '/server/app/modules/fixture/delivery_record'), 'demo backend Module must remain source-only');
    createApplicationExpect(!is_dir($first . '/server/fixtures/plugin-module-lifecycle'), 'demo lifecycle runner must remain source-only');
    createApplicationExpect(!is_dir($first . '/web/src/modules/fixture-delivery-record'), 'demo frontend Module must remain source-only');
    $sourcePlugins = (new PluginLockResolver($root . '/server', '../plugins.lock'))->all();
    createApplicationExpect($sourcePlugins !== [], 'source fixture lock must resolve at least one fixture Plugin');
    $generatedLock = json_decode((string)file_get_contents($first . '/plugins.lock'), true, 64, JSON_THROW_ON_ERROR);
    $expectedOfficialPlugins = array_values(array_filter(
        $sourcePlugins,
        static fn(object $plugin): bool => $plugin->key !== 'fixture.delivery-record'
    ));
    $generatedPlugins = (new PluginLockResolver($first . '/server', '../plugins.lock'))->all();
    createApplicationExpect($expectedOfficialPlugins !== [], 'source lock must contain official Plugins');
    createApplicationExpect(
        array_keys($generatedPlugins) === array_map(static fn(object $plugin): string => $plugin->key, $expectedOfficialPlugins),
        'generated Plugin lock must contain every selected production Plugin and exclude the test fixture'
    );
    createApplicationExpect(
        !array_key_exists('fixture.delivery-record', $generatedPlugins),
        'source-only fixture Plugin leaked into the generated Plugin lock'
    );
    $generatedProductionDockerfile = (string)file_get_contents($first . '/deploy/docker/production.Dockerfile');
    // 检查真正的构建依赖顺序，不要求 COPY 与 RUN 相邻；环境选择文件也必须在构建前生成。
    createApplicationExpect(preg_match('/FROM client-base AS admin-builder(.*?)FROM client-base AS mobile-builder/s',
        $generatedProductionDockerfile, $adminBuildMatch) === 1, 'admin builder stage is missing');
    $adminBuild = $adminBuildMatch[1];
    $compilePosition = strpos($adminBuild, 'pnpm exec vite build');
    foreach (['COPY plugins.lock /build/plugins.lock', 'COPY scripts/client-environment.ts', 'COPY web/ ./',
        'pnpm exec vue-tsc --noEmit', 'VITE_DEPLOYMENT_MODE=multi-tenant'] as $requiredInput) {
        $inputPosition = strpos($adminBuild, $requiredInput);
        createApplicationExpect(is_int($inputPosition) && is_int($compilePosition) && $inputPosition < $compilePosition,
            'admin production build prerequisite is missing or occurs after compilation: ' . $requiredInput);
    }
    createApplicationExpect(
        str_contains($generatedProductionDockerfile, 'PEANUT_CLIENT_ENV_FILE=/build/web/.env.multi-tenant pnpm exec vite build')
            && !str_contains($generatedProductionDockerfile, 'PEANUT_CLIENT_ENV_FILE=/build/web/.env.standalone pnpm exec vite build')
            && substr_count($generatedProductionDockerfile, 'COPY . .') === 1
            && !str_contains($generatedProductionDockerfile, 'seed-multi-tenant-demo.php'),
        'multi-tenant artifact must compile only its selected admin bundle and copy the complete fixed package without source-only demo tooling'
    );
    $standaloneDockerfile = (string)file_get_contents($standalone . '/deploy/docker/production.Dockerfile');
    createApplicationExpect(
        str_contains($standaloneDockerfile, 'PEANUT_CLIENT_ENV_FILE=/build/web/.env.standalone pnpm exec vite build')
            && !str_contains($standaloneDockerfile, 'PEANUT_CLIENT_ENV_FILE=/build/web/.env.multi-tenant pnpm exec vite build')
            && substr_count($standaloneDockerfile, 'COPY . .') === 1
            && !str_contains($standaloneDockerfile, 'AS platform-builder')
            && !str_contains($standaloneDockerfile, '/server/public/platform'),
        'Standalone artifact must copy the complete fixed package while omitting multi-tenant admin and Platform build outputs'
    );
    createApplicationExpect(
        !str_contains((string)file_get_contents($standalone . '/server/config/app.php'), "'platformapi' => 'platform'")
            && str_contains((string)file_get_contents($first . '/server/config/app.php'), "'platformapi' => 'platform'")
            && str_contains((string)file_get_contents($standalone . '/server/.env.example'), 'DEPLOYMENT_MODE=standalone')
            && str_contains((string)file_get_contents($first . '/server/.env.example'), 'DEPLOYMENT_MODE=multi-tenant'),
        'Edition route and environment composition changed outside the selected profile'
    );
    $standaloneSchema = (string)file_get_contents($standalone . '/server/database/init.sql');
    $multiTenantSchema = (string)file_get_contents($first . '/server/database/init.sql');
    createApplicationExpect(
        $standaloneSchema === $multiTenantSchema
            && str_contains($standaloneSchema, '`tenant_id`')
            && str_contains($standaloneSchema, 'FOREIGN KEY (`tenant_id`)'),
        'Both Editions must retain one Tenant-owned fresh Schema',
    );
    foreach ([
        'server/app/modules/official/file/database/migrations/20260823-unify-storage-service.sql',
        'server/database/migrations/20260824-payment-channel-grants.sql',
    ] as $projectedMigration) {
        $standaloneMigration = (string)file_get_contents($standalone . '/' . $projectedMigration);
        $multiTenantMigration = (string)file_get_contents($first . '/' . $projectedMigration);
        createApplicationExpect(
            $standaloneMigration === $multiTenantMigration
                && str_contains($standaloneMigration, '`tenant_id`'),
            'Edition migration projection changed shared Tenant persistence: ' . $projectedMigration,
        );
    }
    createApplicationExpect(
        (string)file_get_contents($standalone . '/server/database/migrations/20260828-provider-qualification-evidence.sql')
            === (string)file_get_contents($first . '/server/database/migrations/20260828-provider-qualification-evidence.sql'),
        'Edition projection must not fork migration history',
    );
    createApplicationExpect(
        str_contains($generatedProductionDockerfile, 'nginx-select-admin.sh /docker-entrypoint.d/40-select-admin.sh')
            && is_executable($first . '/deploy/docker/nginx-select-admin.sh'),
        'production Nginx image must install the executable deployment-mode selector'
    );
    $generatedProductionCompose = (string)file_get_contents($first . '/deploy/docker-compose.prod.yml');
    createApplicationExpect(preg_match('/^  nginx:\\R(.*?)(?=^  [a-z]+:|^volumes:)/ms',
        $generatedProductionCompose, $nginxService) === 1, 'production Nginx service is missing');
    $generatedSelector = (string)file_get_contents($first . '/deploy/docker/nginx-select-admin.sh');
    createApplicationExpect(
        str_contains($nginxService[1], '/var/www/peanut-admin/server/.env.source:ro')
            && str_contains($generatedSelector, 'peanut-read-backend-enum "$backend_source" DEPLOYMENT_MODE')
            && is_file($first . '/deploy/docker/read-backend-enum.sh'),
        'production Nginx must select its explicit mode from the read-only backend source'
    );
    createApplicationExpect(
        is_file($first . '/server/.env.example')
            && is_file($first . '/server/bootstrap/environment.php')
            && !str_contains((string)file_get_contents($first . '/.env.example'), 'DB_HOST=')
            && str_contains((string)file_get_contents($first . '/server/.env.example'), 'DB_HOST='),
        'generated application must keep orchestration and backend environment samples separate'
    );
    createApplicationExpect(
        !str_contains($generatedProductionCompose, 'env_file:')
            && substr_count($generatedProductionCompose, '/var/www/peanut-admin/server/.env.source:ro') === 3
            && str_contains($generatedProductionCompose, '["/usr/local/bin/peanut-php-entrypoint", "cron"]'),
        'production PHP and cron services must consume the single backend environment source safely'
    );
    createApplicationExpect(
        preg_match('/FROM php:[^\n]+ AS php(.*?)FROM nginx:/s', $generatedProductionDockerfile, $phpImageMatch) === 1
            && substr_count($phpImageMatch[1], 'COPY . .') === 1
            && !str_contains($phpImageMatch[1], 'COPY resources/project-resources.json resources/project-resources.json')
            && !str_contains($phpImageMatch[1], 'COPY server/database server/database'),
        'production PHP image must copy the complete fixed application package once instead of maintaining partial runtime copies'
    );
    $generatedResourceRegistry = json_decode(
        (string)file_get_contents($first . '/resources/project-resources.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    createApplicationExpect(
        ($generatedResourceRegistry['schema_version'] ?? null) === 1
            && ($generatedResourceRegistry['project_id'] ?? null) === 'acme-console'
            && is_array($generatedResourceRegistry['resources']['databases'] ?? null),
        'whole-package production image context must contain the generated application resource registry consumed by the database environment guard'
    );
    foreach ([
        'server/database/install.php' => false,
        'server/database/environment-guard.php' => false,
        'server/app/common/services/installation/InstallationExecutionHost.php' => false,
        'server/app/common/services/upgrade/ApplicationMigrationRunner.php' => false,
        'scripts/upgrade' => true,
        'scripts/product-upgrade-host' => true,
        'scripts/product-upgrade-database' => true,
        'scripts/scaffold-runtime/ScaffoldPathGuard.php' => false,
        'scripts/scaffold-runtime/ScaffoldManifest.php' => false,
        'scripts/scaffold-runtime/ScaffoldUpgradeLedger.php' => false,
        'scripts/scaffold-runtime/ScaffoldUpgradeRunner.php' => false,
        'scripts/scaffold-runtime/EditionUpgradePackage.php' => false,
        'scripts/scaffold-runtime/Semver.php' => false,
        'scripts/scaffold-runtime/ReleaseDependencyIdentity.php' => false,
    ] as $managedRuntimePath => $mustBeExecutable) {
        $generatedRuntimePath = $first . '/' . $managedRuntimePath;
        $generatedRuntimeBaseline = $first . '/.peanut/scaffold-baseline/'
            . $inventory['template_version'] . '/files/' . $managedRuntimePath;
        createApplicationExpect(
            is_file($generatedRuntimePath)
                && is_file($generatedRuntimeBaseline)
                && (!$mustBeExecutable || is_executable($generatedRuntimePath))
                && hash_file('sha256', $generatedRuntimePath) === hash_file('sha256', $generatedRuntimeBaseline),
            'whole-package production image context must contain the managed installation/upgrade runtime and matching baseline: '
                . $managedRuntimePath,
        );
    }
    createApplicationExpect(
        !str_contains($generatedProductionDockerfile, 'COPY scripts/seed-demo-data')
            && str_contains($generatedProductionDockerfile, 'chmod +x server/think server/database/seed-demo-data.php /usr/local/bin/peanut-php-entrypoint')
            && str_contains($generatedProductionDockerfile, 'ln -s /var/www/peanut-admin/server/database/seed-demo-data.php /usr/local/bin/peanut-seed-demo-data')
            && is_executable($first . '/server/database/seed-demo-data.php')
            && is_file($first . '/.peanut/scaffold-baseline/' . $inventory['template_version'] . '/files/server/database/seed-demo-data.php'),
        'generated application and production PHP image must use the managed demo seeder without the root wrapper'
    );
    $generatedModulesConfig = (string)file_get_contents($first . '/server/config/modules.php');
    createApplicationExpect(!str_contains($generatedModulesConfig, 'fixture.delivery-record'), 'demo Module identity leaked into generated deployment config');
    createApplicationExpect(str_contains($generatedModulesConfig, "env('PEANUT_PLUGIN_LOCK', '../plugins.lock')"), 'generated deployment must enable its scaffold-owned official Plugin lock');
    $releaseMetadata = json_decode((string)file_get_contents($first . '/RELEASE_METADATA.json'), true, 512, JSON_THROW_ON_ERROR);
    $generatedReleaseVersion = in_array(($releaseMetadata['schema_version'] ?? null), [2, 3], true)
        && in_array(($releaseMetadata['protocol'] ?? null), [
            'peanut.release-metadata.v2',
            'peanut.release-metadata.v3',
        ], true)
            ? ($releaseMetadata['instance_version'] ?? null)
            : ($releaseMetadata['version'] ?? null);
    createApplicationExpect($releaseMetadata['product'] === 'Acme Console' && $generatedReleaseVersion === '0.1.0', 'release metadata must be regenerated for the new application');
    createApplicationExpect(str_contains((string)file_get_contents($first . '/CHANGELOG.md'), "## 0.1.0\n"), 'changelog must use application.version');
    $sbom = json_decode((string)file_get_contents($first . '/RELEASE_SBOM.spdx.json'), true, 512, JSON_THROW_ON_ERROR);
    $sbomRoots = array_values(array_filter(
        $sbom['packages'] ?? [],
        static fn(array $package): bool => ($package['SPDXID'] ?? null) === 'SPDXRef-Package-Peanut-Admin'
    ));
    createApplicationExpect(
        count($sbomRoots) === 1
            && ($sbomRoots[0]['name'] ?? null) === 'Acme Console'
            && ($sbomRoots[0]['versionInfo'] ?? null) === '0.1.0',
        'SBOM root package must use application.version'
    );
    $sourceVersionContract = json_decode(
        (string)file_get_contents($root . '/release-versions.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $coreWebPackages = $sourceVersionContract['core_web']['packages'] ?? null;
    createApplicationExpect(is_array($coreWebPackages), 'source Core Web package identities must be declared');
    $clientCorePackages = [
        'web' => ['@peanut-admin/ui-vue', '@peanut-admin/vue'],
        'platform' => ['@peanut-admin/ui-vue', '@peanut-admin/vue'],
        'pc' => ['@peanut-admin/client', '@peanut-admin/nuxt'],
        'uniapp' => ['@peanut-admin/client', '@peanut-admin/uniapp'],
    ];
    foreach ($clientCorePackages as $client => $packageNames) {
        $package = json_decode((string)file_get_contents($first . "/{$client}/package.json"), true, 512, JSON_THROW_ON_ERROR);
        createApplicationExpect(($package['version'] ?? null) === '0.1.0', "{$client} root package must use application.version");
        foreach ($packageNames as $packageName) {
            $archive = $coreWebPackages[$packageName]['archive'] ?? null;
            createApplicationExpect(is_string($archive) && $archive !== '', "{$packageName} archive identity is unavailable");
            $expected = 'file:../' . $archive;
            createApplicationExpect(
                ($package['dependencies'][$packageName] ?? null) === $expected,
                "{$client} dependency {$packageName} must remain {$expected}"
            );
        }
    }
    foreach (['platform', 'pc', 'uniapp'] as $client) {
        $lock = json_decode((string)file_get_contents($first . "/{$client}/package-lock.json"), true, 512, JSON_THROW_ON_ERROR);
        createApplicationExpect(
            ($lock['version'] ?? null) === '0.1.0' && ($lock['packages']['']['version'] ?? null) === '0.1.0',
            "{$client} root lock metadata must use application.version"
        );
        foreach ($clientCorePackages[$client] as $packageName) {
            $expected = 'file:../' . $coreWebPackages[$packageName]['archive'];
            createApplicationExpect(
                ($lock['packages']['']['dependencies'][$packageName] ?? null) === $expected,
                "{$client} lock dependency {$packageName} must remain {$expected}"
            );
        }
    }
    foreach (['server/config/project.php', 'server/app/adminapi/services/WorkbenchApplicationService.php', 'server/app/api/services/IndexApplicationService.php'] as $versionSurface) {
        createApplicationExpect(
            !str_contains((string)file_get_contents($first . '/' . $versionSurface), "'2.0.1'"),
            $versionSurface . ' must not retain a historical product version'
        );
    }
    createApplicationExpect(
        str_contains((string)file_get_contents($first . '/server/config/project.php'), 'release-versions.json')
            && str_contains((string)file_get_contents($first . '/uniapp/src/pages/as_us/as_us.vue'), "'0.1.0'"),
        'generated Runtime version surfaces must use the application version authority'
    );
    $uniappManifest = (string)file_get_contents($first . '/uniapp/src/manifest.json');
    createApplicationExpect(
        preg_match('/"versionName"\s*:\s*"0\.1\.0"/', $uniappManifest) === 1
            && preg_match('/"versionCode"\s*:\s*"10"/', $uniappManifest) === 1,
        'new UniApp must use versionName 0.1.0 and versionCode 10'
    );
    createApplicationExpect(!str_contains((string)file_get_contents($first . '/server/database/init.sql'), "MD5(CONCAT(MD5('admin123456')"), 'shared default password must be absent');
    createApplicationExpect((string)json_decode((string)file_get_contents($first . '/server/config/brand.json'), true)['website']['name'] === 'Acme Console', 'generated brand identity must be used');
    createApplicationExpect(is_file($first . '/server/config/peanut.php') && is_file($first . '/web/src/peanut.overrides.ts'), 'stable Host override entries must be preserved');
    $sourceComposerLock = json_decode((string)file_get_contents($root . '/server/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    foreach (createApplicationFiles($first) as $path) {
        $absolute = $first . '/' . $path;
        if (filesize($absolute) > 5_000_000) continue;
        $content = file_get_contents($absolute);
        if (!is_string($content)) continue;
        $applicationContent = $content;
        if ($path === 'server/composer.lock'
            || $path === '.peanut/scaffold-baseline/' . $templateVersion . '/files/server/composer.lock') {
            $generatedComposerLock = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            // 上游包描述、作者和许可属于依赖身份，不随应用改名。先核原样保留，再检查应用字段。
            foreach (['packages', 'packages-dev'] as $dependencyGroup) {
                createApplicationExpect(($generatedComposerLock[$dependencyGroup] ?? []) === ($sourceComposerLock[$dependencyGroup] ?? []),
                    'generated Composer lock changed upstream dependency metadata: ' . $path);
                unset($generatedComposerLock[$dependencyGroup]);
            }
            $applicationContent = json_encode($generatedComposerLock, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $upstreamLicense = 'web/src/modules/official-file/optional-runtime/LICENSE';
        if ($path === $upstreamLicense
            || $path === '.peanut/scaffold-baseline/' . $templateVersion . '/files/' . $upstreamLicense) {
            createApplicationExpect($content === (string)file_get_contents($root . '/' . $upstreamLicense),
                'application generation changed upstream license attribution: ' . $path);
            $applicationContent = '';
        }
        createApplicationExpect(!str_contains($applicationContent, 'Peanut Admin'), 'source application brand leaked into ' . $path);
        createApplicationExpect(!str_contains($applicationContent, '花生科技'), 'source company brand leaked into ' . $path);
        createApplicationExpect(!str_contains($content, '/Users/xing'), 'personal path leaked into ' . $path);
        createApplicationExpect(!str_contains($content, '192.168.192.2'), 'source infrastructure leaked into ' . $path);
        createApplicationExpect(!str_contains($content, 'peanut-admin.007345.xyz'), 'source production domain leaked into ' . $path);
    }

    mkdir($temporary . '/non-empty');
    file_put_contents($temporary . '/non-empty/keep.txt', 'keep');
    createApplicationFails(fn() => $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $temporary . '/non-empty', 'multi-tenant', null, 'full'), 'CREATE_APP_TARGET_NOT_EMPTY');
    createApplicationFails(fn() => $creator->create('Acme Console', '../bad', 'acme/acme-console', $temporary . '/bad-slug', 'multi-tenant', null, 'full'), 'CREATE_APP_SLUG_INVALID');
    createApplicationFails(fn() => $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $temporary . '/bad-version', 'multi-tenant', 'not-a-version', 'full'), 'CREATE_APP_APPLICATION_VERSION_INVALID');
    createApplicationFails(fn() => $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $temporary . '/bad-edition', 'other', null, 'full'), 'CREATE_APP_EDITION_INVALID');
    createApplicationFails(fn() => $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $temporary . '/../escape', 'multi-tenant', null, 'full'), 'CREATE_APP_TARGET_PATH_INVALID');

    mkdir($temporary . '/outside');
    symlink($temporary . '/outside', $temporary . '/linked-target');
    createApplicationFails(fn() => $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $temporary . '/linked-target', 'multi-tenant', null, 'full'), 'CREATE_APP_TARGET_SYMLINK_REJECTED');
    mkdir($temporary . '/outside-parent');
    symlink($temporary . '/outside-parent', $temporary . '/linked-parent');
    createApplicationFails(fn() => $creator->create('Acme Console', 'acme-console', 'acme/acme-console', $temporary . '/linked-parent/escape', 'multi-tenant', null, 'full'), 'CREATE_APP_TARGET_SYMLINK_REJECTED');

    $generatedCi = (string)file_get_contents($first . '/.github/workflows/ci.yml');
    createApplicationExpect(
        str_contains($generatedCi, 'name: Application CI')
            && str_contains($generatedCi, 'composer validate --strict')
            && str_contains($generatedCi, 'pnpm install --frozen-lockfile')
            && str_contains($generatedCi, 'npm ci')
            && !str_contains($generatedCi, 'stale-facts:')
            && !str_contains($generatedCi, 'create-app:')
            && !str_contains($generatedCi, 'ci-server-check')
            && !str_contains($generatedCi, 'check-test-integrity')
            && !str_contains($generatedCi, 'consumer-module-reference-chain')
            && !str_contains($generatedCi, 'plugin-contribution.test.ts'),
        'generated CI must use only shipped application build inputs',
    );
    $generatedReadme = (string)file_get_contents($first . '/README.md');
    createApplicationExpect(
        str_contains($generatedReadme, '`server/app/modules/custom/`')
            && str_contains($generatedReadme, '`resources/project-resources.json`')
            && str_contains($generatedReadme, 'Product backend, frontend, database, and public documentation files are managed')
            && !str_contains($generatedReadme, 'Application business code and the stable Host override files are app-owned'),
        'generated README must describe the exact managed and app-owned boundaries',
    );
    createApplicationExpect(is_file($first . '/server/database/environment-guard.php'), 'production database guard must remain in the deployment inventory');
    $generatedSchema = (string)file_get_contents($first . '/server/database/init.sql');
    createApplicationExpect(str_contains($generatedSchema, 'pa_schema_migration'), 'generated application is missing the application migration ledger');
    createApplicationExpect(!str_contains((string)file_get_contents($first . '/server/database/install.php'), "'--migrate'"), 'generated fresh installer still exposes the retired upgrade mode');
    createApplicationExpect(is_file($first . '/scripts/upgrade'), 'generated application is missing the standalone product upgrade entry');
    foreach ([
        'pa_tenant_setting',
        'pa_tenant_entry_binding',
        'pa_tenant_owner_invitation',
        'pa_tenant_idempotency_record',
        'pa_system_dict_type',
        'pa_system_dict_data',
    ] as $baselineTable) {
        createApplicationExpect(
            str_contains($generatedSchema, 'CREATE TABLE `' . $baselineTable . '`'),
            'generated application fresh schema is missing: ' . $baselineTable
        );
    }

    $builderTarget = $temporary . '/builder-identity';
    $builderManifest = (new ApplicationCreator($root, $inventoryPath, $identity))->create(
        'Builder Token', 'builder-token', 'builder/builder-token', $builderTarget, 'multi-tenant'
    );
    createApplicationExpect(
        $builderManifest['template']['source_commit'] === $identity['commit']
            && $builderManifest['template']['source_tree'] === $identity['tree']
            && $builderManifest['generation_source']['commit'] === $identity['commit']
            && $builderManifest['generation_source']['tree'] === $identity['tree'],
        'release builder mode must keep its explicit source identity without recursive adoption'
    );

    $sourceOnlyInventory = $inventory;
    // Replace one app-owned generated security page with another real source file.
    // This exercises preserved app-owned customization without mutating any managed bytes.
    $sourceOnlyInventory['files'] = array_values(array_filter($sourceOnlyInventory['files'],
        static fn(array $entry): bool => $entry['path'] !== 'SECURITY.md'));
    foreach ($sourceOnlyInventory['files'] as &$entry) {
        if ($entry['path'] === 'server/resources/scaffold-application/SECURITY.md.stub') {
            $entry['path'] = 'SECURITY.md';
            $entry['transform'] = 'copy';
            $entry['source_sha256'] = hash_file('sha256', $root . '/SECURITY.md');
            break;
        }
    }
    unset($entry);
    $sourceOnlyInventory['files'][] = [
        'path' => 'source-only/adoption-proof.txt',
        'target' => 'source-only/adoption-proof.txt',
        'classification' => 'excluded',
        'owner' => 'template-source-only',
        'transform' => 'copy',
        'mode' => 0644,
        'profiles' => ['minimal', 'standard', 'full'],
    ];
    $sourceOnlyInventoryPath = $temporary . '/source-only-inventory.json';
    createApplicationWriteJson($sourceOnlyInventoryPath, $sourceOnlyInventory);
    $sourceOnlyTarget = $temporary . '/source-only-allowed';
    $sourceOnlyManifest = (new ApplicationCreator($root, $sourceOnlyInventoryPath, $identity, $releasePath))->create(
        'Acme Console', 'acme-console', 'acme/acme-console', $sourceOnlyTarget, 'multi-tenant', null, 'full'
    );
    createApplicationExpect($sourceOnlyManifest['template'] === $manifestOne['template'], 'app-owned/excluded-only source changes must retain release adoption');
    createApplicationExpect(
        $sourceOnlyManifest['generation_source']['inventory_sha256'] === hash_file('sha256', $sourceOnlyInventoryPath),
        'source-only inventory change must remain visible in generation source'
    );
    createApplicationExpect(
        $sourceOnlyManifest['digests']['app_owned_tree_sha256'] !== $manifestOne['digests']['app_owned_tree_sha256'],
        'app-owned-only change fixture must actually change app-owned output'
    );
    createApplicationExpect(!file_exists($sourceOnlyTarget . '/source-only/adoption-proof.txt'), 'excluded-only source must not enter the generated application');

    $managedChangeInventory = $inventory;
    foreach ($managedChangeInventory['files'] as &$entry) {
        if ($entry['path'] === 'scripts/project-resource-registry') {
            $entry['transform'] = 'copy';
            $entry['source_sha256'] = hash_file('sha256', $root . '/' . $entry['path']);
            break;
        }
    }
    unset($entry);
    $managedChangeInventoryPath = $temporary . '/managed-change-inventory.json';
    createApplicationWriteJson($managedChangeInventoryPath, $managedChangeInventory);
    $managedChangeTarget = $temporary . '/managed-change-rejected';
    $managedChangeCreator = new ApplicationCreator($root, $managedChangeInventoryPath, $identity, $releasePath);
    createApplicationFails(
        fn() => $managedChangeCreator->create('Acme Console', 'acme-console', 'acme/acme-console', $managedChangeTarget, 'multi-tenant', null, 'full'),
        'CREATE_APP_ADOPTION_RENDER_MISMATCH'
    );
    createApplicationExpect(!file_exists($managedChangeTarget), 'managed source change committed an unsealed target');

    createApplicationTamperedReleaseFails(
        $root, $inventoryPath, $identity, $releasePath, $temporary, 'token',
        static function (array &$manifest): void { $manifest['release']['tokens']['slug'] = 'tampered-slug-token'; },
        'CREATE_APP_ADOPTION_RENDER_MISMATCH'
    );
    createApplicationTamperedReleaseFails(
        $root, $inventoryPath, $identity, $releasePath, $temporary, 'token-keys',
        static function (array &$manifest): void { $manifest['release']['tokens']['extra'] = 'extra-token'; },
        'CREATE_APP_ADOPTION_MANIFEST_INVALID: SCAFFOLD_MANIFEST_APPLICATION_INVALID'
    );
    createApplicationTamperedReleaseFails(
        $root, $inventoryPath, $identity, $releasePath, $temporary, 'artifact',
        static function (array &$manifest, string $releaseRoot): void {
            file_put_contents($releaseRoot . '/' . $manifest['files'][0]['source'], "\ntampered\n", FILE_APPEND);
        },
        'CREATE_APP_ADOPTION_ARTIFACT_DIGEST_MISMATCH'
    );
    createApplicationTamperedReleaseFails(
        $root, $inventoryPath, $identity, $releasePath, $temporary, 'mode',
        static function (array &$manifest): void { $manifest['files'][0]['mode'] = 0755; },
        'CREATE_APP_ADOPTION_FILE_METADATA_MISMATCH'
    );
    createApplicationTamperedReleaseFails(
        $root, $inventoryPath, $identity, $releasePath, $temporary, 'classification',
        static function (array &$manifest): void {
            foreach ($manifest['files'] as &$file) {
                if ($file['classification'] === 'generated-managed') {
                    $file['classification'] = 'managed';
                    $file['policy'] = 'managed';
                    break;
                }
            }
            unset($file);
        },
        'CREATE_APP_ADOPTION_FILE_METADATA_MISMATCH'
    );
    createApplicationTamperedReleaseFails(
        $root, $inventoryPath, $identity, $releasePath, $temporary, 'managed-added',
        static function (array &$manifest): void {
            $extra = $manifest['files'][0];
            $extra['path'] = 'extra-managed.txt';
            $manifest['files'][] = $extra;
        },
        'CREATE_APP_ADOPTION_MANAGED_SET_MISMATCH'
    );
    createApplicationTamperedReleaseFails(
        $root, $inventoryPath, $identity, $releasePath, $temporary, 'managed-removed',
        static function (array &$manifest): void { array_pop($manifest['files']); },
        'CREATE_APP_ADOPTION_MANAGED_SET_MISMATCH'
    );
    createApplicationTamperedReleaseFails(
        $root, $inventoryPath, $identity, $releasePath, $temporary, 'managed-tree',
        static function (array &$manifest): void { $manifest['release']['managed_tree_sha256'] = str_repeat('0', 64); },
        'CREATE_APP_ADOPTION_MANAGED_TREE_MISMATCH'
    );
    createApplicationTamperedReleaseFails(
        $root, $inventoryPath, $identity, $releasePath, $temporary, 'application-version',
        static function (array &$manifest): void { $manifest['application']['version'] = '0.2.0'; },
        'CREATE_APP_ADOPTION_APPLICATION_VERSION_MISMATCH'
    );

    $unknown = $inventory;
    foreach ($unknown['files'] as &$entry) {
        if ($entry['classification'] !== 'excluded') {
            $entry['transform'] = 'unknown-transform';
            break;
        }
    }
    unset($entry);
    $unknownPath = $temporary . '/unknown.json';
    file_put_contents($unknownPath, json_encode($unknown, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $unknownCreator = new ApplicationCreator($root, $unknownPath, $identity);
    createApplicationFails(fn() => $unknownCreator->create('Acme Console', 'acme-console', 'acme/acme-console', $temporary . '/unknown', 'multi-tenant'), 'CREATE_APP_INVENTORY_ENTRY_INVALID');

    $unknownVariable = $inventory;
    $unknownVariable['variables'][] = 'UNDECLARED_INPUT';
    $unknownVariablePath = $temporary . '/unknown-variable.json';
    file_put_contents($unknownVariablePath, json_encode($unknownVariable, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $unknownVariableCreator = new ApplicationCreator($root, $unknownVariablePath, $identity);
    createApplicationFails(fn() => $unknownVariableCreator->create('Acme Console', 'acme-console', 'acme/acme-console', $temporary . '/unknown-variable', 'multi-tenant'), 'CREATE_APP_INVENTORY_UNKNOWN_VARIABLE');
} finally {
    createApplicationDelete($temporary);
}

echo "CREATE-APP-001 passed\n";
