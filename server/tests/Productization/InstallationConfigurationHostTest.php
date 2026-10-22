<?php

declare(strict_types=1);

use app\common\exception\installation\InstallationExecutionException;
use app\common\services\installation\InstallationConfigurationHost;
use app\common\value\installation\ServerReleaseIdentity;

require_once dirname(__DIR__, 2) . '/app/common/exception/installation/InstallationExecutionException.php';
require_once dirname(__DIR__, 2) . '/app/common/services/installation/InstallationConfigurationHost.php';
require_once dirname(__DIR__, 2) . '/app/common/value/installation/ApplicationSourceIdentity.php';
require_once dirname(__DIR__, 2) . '/app/common/value/installation/ApplicationReleaseVersions.php';
require_once dirname(__DIR__, 2) . '/app/common/value/installation/ServerReleaseIdentity.php';
$autoload = getenv('PEANUT_CONTRACT_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload) || is_link($autoload)) { throw new RuntimeException('explicit ordinary Composer autoload is required for native release identity'); }
require_once $autoload;

// Reuse only the existing canonical fixture functions, never execute that suite.
$fixtureSource = (string) file_get_contents(__DIR__ . '/ServerReleaseRuntimeIdentityTest.php');
$fixtureStart = strpos($fixtureSource, 'function serverReleaseIdentityRows(');
$fixtureEnd = strpos($fixtureSource, '$projectRoot = dirname(__DIR__, 3);', $fixtureStart);
if ($fixtureStart === false || $fixtureEnd === false) { throw new RuntimeException('canonical release fixture functions unavailable'); }
eval('use app\\common\\value\\installation\\ServerReleaseIdentity;' . "\n" . substr($fixtureSource, $fixtureStart, $fixtureEnd - $fixtureStart));

/** Canonical synthetic program inventory; not a product build or actual runtime. */
function installationConfigReleaseFixture(string $root, array $application, array $projection): array
{
    $document = serverReleaseIdentityFixture($root, 'application')->document();
    $application = array_replace($document['application'], $application);
    $edition = $document['edition_contract'];
    $edition['name'] = $application['edition']; $edition['deployment_mode'] = $application['edition'];
    $manifest = ['schema_version' => 2, 'protocol' => 'peanut.application-scaffold.v2',
        'application' => array_intersect_key($application, array_flip(['name', 'slug', 'version', 'edition', 'profile', 'package_identity'])),
        'edition' => $edition, 'generation_source' => $document['upstream'] + ['edition_profile_sha256' => $document['edition_profile_sha256']],
        'template' => $document['template']];
    if (!is_dir($root . '/.peanut')) { mkdir($root . '/.peanut', 0700); }
    $manifestBytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($root . '/.peanut/application-manifest.json', $manifestBytes);
    $application['manifest_sha256'] = hash('sha256', $manifestBytes);
    $document['application'] = $application; $document['edition_contract'] = $edition;
    $document['versions']['release_sequence_version'] = $application['version'];
    file_put_contents($root . '/release-versions.json', json_encode([
        'schema_version' => 2, 'protocol' => 'peanut.release-versions.v2',
        'source_product_version' => $document['versions']['source_product_version'], 'instance_version' => $application['version'],
        'scaffold_template' => $document['versions']['scaffold_template'], 'generated_instance_default' => $application['version'],
        'core_php' => $document['versions']['source_product_version'], 'core_web' => $document['versions']['source_product_version'],
    ], JSON_THROW_ON_ERROR));
    $server = $root . '/server';
    copy(dirname(__DIR__, 2) . '/database/resource-registry.php', $server . '/database/resource-registry.php');
    file_put_contents($server . '/resources/project-resources.json', json_encode($projection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($server . '/.peanut/RELEASE_METADATA.json', json_encode([
        'source_product_version' => $document['versions']['source_product_version'], 'instance_version' => $application['version'],
        'application_identity' => $application['package_identity'],
    ], JSON_THROW_ON_ERROR));
    $document['files'] = serverReleaseIdentityRows($server, [...array_column($document['files'], 'path'), 'server/database/resource-registry.php']);
    $document['files_sha256'] = hash('sha256', json_encode($document['files'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    file_put_contents($server . '/.peanut/release-identity.json', json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    foreach ([...array_column($document['files'], 'path'), 'server/.peanut/release-identity.json'] as $path) { chmod($root . '/' . $path, 0644); }
    return ServerReleaseIdentity::load($server)->document();
}

if (($argv[1] ?? null) === 'export-release') {
    $destination = $argv[2] ?? '';
    if ($destination === '' || $destination[0] !== '/' || file_exists($destination) || is_link($destination)) {
        throw new RuntimeException('new absolute synthetic release fixture path required');
    }
    $document = installationConfigReleaseFixture($destination,
        json_decode((string) file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR),
        json_decode((string) file_get_contents($argv[4]), true, 512, JSON_THROW_ON_ERROR));
    echo json_encode($document, JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

// The pure native selector binds its defining server root. Keep each pre-existing
// fixture scenario in its own process instead of replacing that selector in tests.
$scenario = $argv[1] ?? null;
if ($scenario === null) {
    foreach (['standalone', 'multi', 'partial', 'pending'] as $case) {
        $process = proc_open([PHP_BINARY, __FILE__, $case], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('cannot start isolated configuration contract'); }
        fclose($pipes[0]);
        if (proc_close($process) !== 0) { exit(1); }
    }
    echo "INSTALLATION-CONFIGURATION-HOST all ordinary scenarios passed\n";
    exit(0);
}
if (!in_array($scenario, ['standalone', 'multi', 'partial', 'pending', 'mounted'], true)) {
    throw new RuntimeException('unknown isolated configuration scenario');
}

function installationConfigExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function installationConfigDelete(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            installationConfigDelete($path . '/' . $entry);
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** @return array<string,mixed> */
function installationConfigIdentity(string $edition): array
{
    return [
        'application' => [
            'slug' => 'fixture-app',
            'edition' => $edition,
            'version' => '1.2.3',
            'package_identity' => 'fixture-app/application',
            'name' => 'Fixture App',
        ],
        'versions' => [
            'source_product_version' => '4.0.0-rc.10',
            'release_sequence_version' => '1.2.3',
            'scaffold_template' => '4.0.0-rc.10',
        ],
    ];
}

$root = dirname(__DIR__, 3) . '/.local/tmp';
if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('cannot create checkout-local temporary root');
}
$fixture = $root . '/installation-configuration-' . bin2hex(random_bytes(6));
// Current production status/configure uses the APP-owned pure resource selector.
$server = $fixture . '/' . $scenario . ($scenario === 'mounted' ? '/server' : '');
$applicationRoot = dirname($server);
// Each ordinary scenario is a canonical server release with the same identity
// in its manifest, metadata and native loader. No injected application object.
if ($scenario !== 'mounted') {
    $applicationRoot = $fixture . '/' . $scenario . '-app';
    $server = $applicationRoot . '/server';
    installationConfigReleaseFixture($applicationRoot, installationConfigIdentity($scenario === 'multi' ? 'multi-tenant' : 'standalone')['application'],
        ['schema_version' => 1, 'project_id' => 'fixture-app', 'authority' => ['role' => 'application'], 'resources' => ['databases' => []]]);
    mkdir($server . '/private/installation', 0700, true);
    mkdir($server . '/private/resources/pending', 0700, true);
}
$token = str_repeat('t', 64);
$secret = str_repeat('a', 64);

try {
    if ($scenario === 'standalone') {
    $standalone = new InstallationConfigurationHost(
        $server,
        null,
        static fn(): string => $secret,
    );
    $status = $standalone->status();
    installationConfigExpect($status['state'] === 'unconfigured', 'fresh server must require configuration');

    try {
        $standalone->configure(str_repeat('x', 64), $token, ['deployment_target' => 'local-production-preview']);
        throw new RuntimeException('invalid setup token was accepted');
    } catch (InstallationExecutionException $exception) {
        installationConfigExpect($exception->errorCode === 'INSTALL_SETUP_TOKEN_INVALID', 'wrong token must fail closed');
    }

    $configured = $standalone->configure($token, $token, [
        'deployment_target' => 'local-production-preview',
        'database_name' => 'fixture_app',
    ]);
    installationConfigExpect($configured['state'] === 'configured', 'standalone configuration must complete');
    $envPath = $server . '/.env';
    $registryPath = $server . '/private/resources/project-resources.json';
    installationConfigExpect(is_file($envPath) && !is_link($envPath), 'final server env must exist');
    installationConfigExpect((fileperms($envPath) & 0777) === 0600, 'final server env must use mode 0600');
    installationConfigExpect(is_file($registryPath) && !is_link($registryPath), 'private instance registry must exist');
    installationConfigExpect((fileperms($registryPath) & 0777) === 0600, 'private registry must use mode 0600');

    $env = (string) file_get_contents($envPath);
    installationConfigExpect(str_contains($env, "DEPLOYMENT_MODE=standalone\n"), 'edition must come from release identity');
    installationConfigExpect(str_contains($env, "DB_HOST=mysql\nDB_PORT=3306\n"), 'bundled DB endpoint must be fixed');
    installationConfigExpect(str_contains($env, "DB_NAME=fixture_app\n"), 'chosen DB name must be stored');
    installationConfigExpect(str_contains($env, "DB_PASS={$secret}\n"), 'generated business password must be stored only in env');
    $privateEnvironment = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    installationConfigExpect(($privateEnvironment['PEANUT_INSTALLATION_SETUP_TOKEN'] ?? null) === $token, 'private final environment must retain the configuration-to-guided-install token handoff');
    installationConfigExpect(lstat($envPath)['nlink'] === 1 && fileowner($envPath) === posix_geteuid(), 'token handoff must remain in an ordinary owner-held private environment');
    installationConfigExpect(!str_contains($env, 'MYSQL_ROOT_PASSWORD'), 'MySQL root secret must not enter app env');

    $registryBytes = (string) file_get_contents($registryPath);
    installationConfigExpect(!str_contains($registryBytes, $secret), 'private registry must not contain database/app secrets');
    installationConfigExpect(!str_contains($registryBytes, $token), 'setup token must not be copied to resource declarations');
    $registry = json_decode($registryBytes, true, 512, JSON_THROW_ON_ERROR);
    installationConfigExpect(($registry['project_id'] ?? null) === 'fixture-app', 'registry must bind APP slug');
    installationConfigExpect(
        ($registry['resources']['databases'][0]['container_endpoint']['host'] ?? null) === 'mysql',
        'registry must bind bundled MySQL endpoint',
    );

    try {
        $standalone->configure($token, $token, ['deployment_target' => 'local-production-preview']);
        throw new RuntimeException('repeat first configuration was accepted');
    } catch (InstallationExecutionException $exception) {
        installationConfigExpect($exception->errorCode === 'INSTALL_CONFIGURATION_ALREADY_PRESENT', 'repeat configuration must fail closed');
    }

    }
    if ($scenario === 'multi') {
    $multi = new InstallationConfigurationHost(
        $server,
        null,
        static fn(): string => $secret,
    );
    try {
        $multi->configure($token, $token, ['deployment_target' => 'production-candidate']);
        throw new RuntimeException('multi-tenant configuration without hosts was accepted');
    } catch (InstallationExecutionException $exception) {
        installationConfigExpect($exception->errorCode === 'INSTALL_CONFIGURATION_HOSTS_REQUIRED', 'multi-tenant hosts must be required');
    }
    $multiResult = $multi->configure($token, $token, [
        'deployment_target' => 'production-candidate',
        'platform_hosts' => 'pa-platform.example.test',
        'tenant_admin_hosts' => 'pa-admin.example.test',
    ]);
    installationConfigExpect($multiResult['state'] === 'configured', 'multi-tenant configuration with hosts must complete');
    $multiEnv = (string) file_get_contents($server . '/.env');
    installationConfigExpect(str_contains($multiEnv, "PLATFORM_HOSTS=pa-platform.example.test\n"), 'platform hosts must persist');
    installationConfigExpect(str_contains($multiEnv, "TENANT_ADMIN_HOSTS=pa-admin.example.test\n"), 'tenant admin hosts must persist');

    }
    if ($scenario === 'partial') {
    $partial = new InstallationConfigurationHost(
        $server,
        null,
        static fn(): string => $secret,
    );
    file_put_contents($server . '/private/resources/configuration.lock', '');
    installationConfigExpect($partial->status()['state'] === 'blocked', 'pending configuration lock must block status');
    try {
        $partial->configure($token, $token, ['deployment_target' => 'production']);
        throw new RuntimeException('pending configuration was retried');
    } catch (InstallationExecutionException $exception) {
        installationConfigExpect($exception->errorCode === 'INSTALL_CONFIGURATION_PARTIAL', 'pending configuration must fail closed');
    }
    installationConfigExpect(!file_exists($server . '/.env'), 'pending failure must not create final env');

    }
    if ($scenario === 'pending') {
    $pending = new InstallationConfigurationHost(
        $server,
        null,
        static fn(): string => $secret,
        true,
    );
    $result = $pending->configure($token, $token, ['deployment_target' => 'local-production-preview', 'database_name' => 'pending_fixture']);
    installationConfigExpect($result['state'] === 'pending' && $result['restart_required'], 'HTTP configuration must return its pending owner transition');
    installationConfigExpect($result['database_resource_id'] === 'fixture-app-bundled-mysql84', 'ordinary unbound pending response must retain the generated resource ID');
    installationConfigExpect($pending->status()['state'] === 'pending', 'pending input must be distinguishable from canonical configuration');
    installationConfigExpect(!file_exists($server . '/.env') && !file_exists($server . '/private/resources/project-resources.json'), 'HTTP configuration must not publish canonical owner files');
    $ordinaryPending = json_decode((string) file_get_contents($server . '/private/resources/pending/configuration.json'), true, 512, JSON_THROW_ON_ERROR);
    installationConfigExpect($ordinaryPending['protocol'] === 'peanut.installation-configuration-input.v3'
        && array_key_exists('deployment_sha256', $ordinaryPending) && $ordinaryPending['deployment_sha256'] === null,
        'ordinary pending must explicitly use v3 with an absent-deployment null binding');
    installationConfigExpect(!str_contains((string) file_get_contents($server . '/private/resources/pending/configuration.json'), $token), 'pending configuration must not disclose the raw setup token');
    $owner = new InstallationConfigurationHost(
        $server,
        null,
        static fn(): string => $secret,
    );
    try {
        $owner->publishPending(str_repeat('x', 64));
        throw new RuntimeException('unbound pending setup identity accepted');
    } catch (RuntimeException $exception) {
        installationConfigExpect($exception->getMessage() === 'INSTALL_CONFIGURATION_PENDING_BINDING_INVALID', 'owner must independently verify setup identity');
    }
    $published = $owner->publishPending($token);
    installationConfigExpect($published['state'] === 'configured' && $owner->status()['configured'], 'native owner must publish canonical configuration');
    installationConfigExpect(!file_exists($server . '/private/resources/pending/configuration.json'), 'pending input must be consumed instead of becoming a second runtime source');
    installationConfigExpect(str_contains((string) file_get_contents($server . '/.env'), 'DB_NAME=pending_fixture'), 'owner must revalidate and preserve the selected database');
    }

    if ($scenario === 'mounted') {
        $planPath = $argv[2] ?? '';
        $projectionPath = $argv[3] ?? '';
        $plan = json_decode((string) file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
        installationConfigReleaseFixture($applicationRoot, $plan['application'],
            json_decode((string) file_get_contents($projectionPath), true, 512, JSON_THROW_ON_ERROR));
        mkdir($server . '/private/resources/pending', 0700, true);
        mkdir($server . '/private/installation', 0700, true);
        // Execute the wrapper's real pre-start producer against this synthetic APP.
        $wrapper = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/deploy-release');
        $producer = substr($wrapper, strpos($wrapper, '# Publish the nonsecret owner selection'));
        installationConfigExpect(preg_match('/python3 - "\$root" "\$plan" <<\x27PY\x27\n(.*?)\nPY/s', $producer, $match) === 1, 'pre-start producer must exist');
        $process = proc_open([getenv('PEANUT_CONTRACT_PYTHON'), '-', $applicationRoot, $planPath],
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('cannot invoke real receipt producer'); }
        fwrite($pipes[0], $match[1]); fclose($pipes[0]);
        installationConfigExpect(proc_close($process) === 0, 'real receipt producer must complete');
        $receipt = $server . '/private/mounted-deployment-receipt.json';
        $receiptBytes = (string) file_get_contents($receipt);
        $replaceReceipt = static function (string $bytes) use ($receipt): void {
            chmod($receipt, 0600); file_put_contents($receipt, $bytes); chmod($receipt, 0444); clearstatcache();
        };
        $generatedSecrets = 0;
        $secretFactory = static function () use (&$generatedSecrets, $secret): string { $generatedSecrets++; return $secret; };
        $pendingHost = new InstallationConfigurationHost($server, null, $secretFactory, true);
        $ownerHost = new InstallationConfigurationHost($server, null, $secretFactory);
        $status = $pendingHost->status();
        installationConfigExpect(($status['deployment_binding']['database_source'] ?? null) === $plan['database_source'], 'native status must expose mounted mode');
        $input = ['deployment_target' => $plan['target'], 'database_source' => $plan['database_source'], 'database_name' => $plan['database_name']];
        if ($plan['database_source'] === 'registered') {
            $input += ['database_resource_id' => $plan['database_resource_id'], 'database_consumer' => 'container',
                'database_user' => 'fixture_user', 'database_password' => 'fixture-password'];
        }
        $noWrites = static function () use ($server, &$generatedSecrets): void {
            foreach (['.env', 'private/resources/project-resources.json', 'private/resources/configuration.json', 'private/resources/configuration.lock', 'private/resources/pending/configuration.json'] as $relative) {
                installationConfigExpect(!file_exists($server . '/' . $relative), 'rejected choice must not write ' . $relative);
            }
            installationConfigExpect($generatedSecrets === 0, 'rejected choice must not generate secrets');
        };
        $reject = static function (Closure $action, string $expectedReason) use ($noWrites): void {
            $reason = null;
            try { $action(); }
            catch (InstallationExecutionException $exception) { $reason = $exception->errorCode; }
            catch (RuntimeException $exception) { $reason = $exception->getMessage(); }
            installationConfigExpect($reason === $expectedReason, 'native refusal must have the exact expected reason: ' . $expectedReason);
            $noWrites();
        };
        $changed = $input; $changed['database_name'] = 'other_database';
        // Registered names are already constrained by the existing resource guard.
        $reject(static fn() => $pendingHost->configure($token, $token, $changed), $plan['database_source'] === 'registered'
            ? 'INSTALL_CONFIGURATION_DATABASE_INVALID' : 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH');
        $changed = $input; $changed['deployment_target'] = 'production-candidate';
        // Both targets are independently permitted in the public fixture.
        $reject(static fn() => $pendingHost->configure($token, $token, $changed), 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH');
        if ($plan['database_source'] === 'registered') {
            $changed = $input; $changed['database_consumer'] = 'host';
            $reject(static fn() => $pendingHost->configure($token, $token, $changed), 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH');
            $changed = $input; $changed['database_resource_id'] = 'fixture-secondary-db';
            $changed['database_name'] = '';
            $reject(static fn() => $pendingHost->configure($token, $token, $changed), 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH');
            $changed = ['deployment_target' => $plan['target'], 'database_source' => 'bundled', 'database_name' => $plan['database_name']];
            $reject(static fn() => $pendingHost->configure($token, $token, $changed), 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH');
        } else {
            // A receipt cannot manufacture bundled resource or endpoint permission.
            $unpermitted = $plan; $unpermitted['database_resource_id'] = 'fixture-absent';
            $replaceReceipt(json_encode($unpermitted, JSON_THROW_ON_ERROR));
            $reject(static fn() => $pendingHost->configure($token, $token, $input), '数据库资源 fixture-absent 必须在项目登记中唯一存在');
            $unpermitted = $plan; $unpermitted['database_endpoint']['endpoint_id'] = 'fixture-absent-endpoint';
            $replaceReceipt(json_encode($unpermitted, JSON_THROW_ON_ERROR));
            $reject(static fn() => $pendingHost->configure($token, $token, $input), 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH');
            $unpermitted = $plan; $unpermitted['database_name'] = 'receipt_only_database';
            $changed = $input; $changed['database_name'] = 'receipt_only_database';
            $replaceReceipt(json_encode($unpermitted, JSON_THROW_ON_ERROR));
            $reject(static fn() => $pendingHost->configure($token, $token, $changed), 'INSTALL_CONFIGURATION_DEPLOYMENT_PERMISSION_INVALID');
            $replaceReceipt($receiptBytes);
        }
        $projection = json_decode((string) file_get_contents($projectionPath), true, 512, JSON_THROW_ON_ERROR);
        $projection['resources']['databases'][0]['container_endpoint']['port']++;
        installationConfigReleaseFixture($applicationRoot, $plan['application'], $projection);
        $reject(static fn() => $pendingHost->configure($token, $token, $input), 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH');
        $projection['resources']['databases'][0]['container_endpoint']['port']--;
        $projection['resources']['databases'][0]['container_endpoint']['consumers'] = ['host'];
        installationConfigReleaseFixture($applicationRoot, $plan['application'], $projection);
        $reject(static fn() => $pendingHost->configure($token, $token, $input), '数据库资源缺少已登记的 container endpoint');
        installationConfigReleaseFixture($applicationRoot, $plan['application'],
            json_decode((string) file_get_contents($projectionPath), true, 512, JSON_THROW_ON_ERROR));
        $foreignPlan = $plan; $foreignPlan['application']['slug'] = 'foreign-app';
        $replaceReceipt(json_encode($foreignPlan, JSON_THROW_ON_ERROR));
        $reject(static fn() => $pendingHost->configure($token, $token, $input), 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_INVALID');
        $replaceReceipt('{broken');
        $jsonRejected = false;
        try { $pendingHost->configure($token, $token, $input); }
        catch (JsonException) { $jsonRejected = true; }
        installationConfigExpect($jsonRejected, 'malformed receipt JSON must be rejected by the native JSON reader');
        $noWrites();
        $replaceReceipt($receiptBytes); chmod($receipt, 0644); clearstatcache();
        $reject(static fn() => $pendingHost->configure($token, $token, $input), 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_UNSAFE');
        chmod($receipt, 0444); clearstatcache();
        unlink($receipt); symlink($planPath, $receipt);
        $reject(static fn() => $pendingHost->configure($token, $token, $input), 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_UNSAFE');
        unlink($receipt); file_put_contents($receipt, $receiptBytes); chmod($receipt, 0444); clearstatcache();
        $staged = $pendingHost->configure($token, $token, $input);
        installationConfigExpect($staged['state'] === 'pending' && $generatedSecrets === 0, 'matching native configure must stage without generating secrets');
        installationConfigExpect($staged['database_resource_id'] === $plan['database_resource_id'], 'pending response must report the same validated resource ID as final publication');
        $pendingPath = $server . '/private/resources/pending/configuration.json';
        $pendingBytes = (string) file_get_contents($pendingPath);
        $pendingRecord = json_decode($pendingBytes, true, 512, JSON_THROW_ON_ERROR);
        installationConfigExpect($pendingRecord['protocol'] === 'peanut.installation-configuration-input.v3'
            && $pendingRecord['deployment_sha256'] === hash('sha256', $receiptBytes)
            && $pendingRecord['registry_sha256'] === hash_file('sha256', $server . '/resources/project-resources.json'),
            'native journal must freeze producer bytes and independent APP permission for both modes');
        installationConfigExpect(!str_contains($pendingBytes, $token), 'mounted pending input must not disclose the raw setup token');
        $replaceReceipt($receiptBytes . "\n");
        try { $ownerHost->publishPending($token); throw new LogicException('receipt drift accepted'); }
        catch (RuntimeException $exception) { installationConfigExpect($exception->getMessage() === 'INSTALL_CONFIGURATION_PENDING_DEPLOYMENT_CHANGED', 'owner must refuse receipt byte drift'); }
        installationConfigExpect(is_file($pendingPath) && !file_exists($server . '/.env'), 'drift must preserve pending without publication');
        $replaceReceipt($receiptBytes);
        $pendingRecord['protocol'] = 'peanut.installation-configuration-input.v2'; unset($pendingRecord['deployment_sha256']);
        file_put_contents($pendingPath, json_encode($pendingRecord, JSON_THROW_ON_ERROR));
        try { $ownerHost->publishPending($token); throw new LogicException('old pending accepted'); }
        catch (RuntimeException $exception) { installationConfigExpect($exception->getMessage() === 'INSTALL_CONFIGURATION_PENDING_BINDING_INVALID', 'v2 pending must be refused'); }
        installationConfigExpect(is_file($pendingPath) && !file_exists($server . '/.env'), 'old pending must remain untouched');
        file_put_contents($pendingPath, $pendingBytes);
        $published = $ownerHost->publishPending($token);
        installationConfigExpect($published['state'] === 'configured', 'matching producer/configure/publish chain must complete');
        $environment = parse_ini_file($server . '/.env', false, INI_SCANNER_RAW);
        foreach (['PEANUT_DATABASE_RESOURCE_ID' => $plan['database_resource_id'], 'PEANUT_DATABASE_ENDPOINT_ID' => $plan['database_endpoint']['endpoint_id'],
            'PEANUT_DATABASE_SOURCE' => $plan['database_source'], 'PEANUT_DATABASE_CONSUMER' => 'container', 'DB_HOST' => $plan['database_endpoint']['host'],
            'DB_PORT' => (string) $plan['database_endpoint']['port'], 'DB_NAME' => $plan['database_name']] as $key => $expected) {
            installationConfigExpect(($environment[$key] ?? null) === $expected, 'publication must preserve frozen ' . $key);
        }
        installationConfigExpect(!is_file($pendingPath), 'successful owner publication consumes only pending input');
        installationConfigExpect(hash_file('sha256', $receipt) === hash('sha256', $receiptBytes), 'owner publication retains immutable receipt');
        installationConfigExpect(($environment['PEANUT_INSTALLATION_SETUP_TOKEN'] ?? null) === $token
            && (fileperms($server . '/.env') & 0777) === 0600 && lstat($server . '/.env')['nlink'] === 1,
            'owner publication must retain the ordinary private guided-install token handoff');

        // Exercise the alternative native owner API from a separate fresh state
        // inside this same disposable fixture; never reset an actual instance.
        foreach (['.env', 'private/resources/project-resources.json', 'private/resources/configuration.json', 'private/resources/configuration.lock'] as $relative) {
            unlink($server . '/' . $relative);
        }
        $ownerRegistry = null;
        if ($plan['database_source'] === 'registered') {
            $database = [
                'stable_resource_id' => $plan['database_resource_id'], 'purpose' => 'Synthetic direct contract',
                'owner' => 'fixture owner', 'version' => '8.4.11', 'database' => $plan['database_name'], 'schema' => 'pa_ table prefix',
                'namespace' => $plan['database_name'], 'data_source' => 'synthetic fixture', 'freshness_requirement' => 'source contract only',
                'health_check' => 'not run: synthetic source contract', 'service_type' => 'mysql', 'fallback' => 'none',
                'application_runtime' => true, 'environments' => [$plan['target']], 'deployment_modes' => [$plan['application']['edition']],
                'container_endpoint' => $plan['database_endpoint'],
                'upstream_endpoint' => ['endpoint_id' => 'fixture-direct-host', 'host' => 'owner-host.example.invalid', 'port' => 3306, 'consumers' => ['host']],
            ];
            $ownerRegistry = ['schema_version' => 1, 'project_id' => $plan['application']['slug'],
                'authority' => ['role' => 'application'], 'resources' => ['databases' => [$database]]];
            $wrongOwnerInput = $input; $wrongOwnerInput['database_consumer'] = 'host';
            $ownerSecretCount = $generatedSecrets;
            $ownerRejected = false;
            try { $ownerHost->configureForOwner($token, $wrongOwnerInput, $ownerRegistry); }
            catch (InstallationExecutionException $exception) { $ownerRejected = $exception->errorCode === 'INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH'; }
            installationConfigExpect($ownerRejected && !file_exists($server . '/.env'), 'direct owner API must reject an otherwise permitted host route');
            installationConfigExpect($generatedSecrets === $ownerSecretCount, 'direct-owner refusal must not generate new secrets');
            foreach (['private/resources/project-resources.json', 'private/resources/configuration.json', 'private/resources/configuration.lock', 'private/resources/pending/configuration.json'] as $relative) {
                installationConfigExpect(!file_exists($server . '/' . $relative), 'direct-owner refusal must not publish configuration or locks');
            }
        }
        $direct = $ownerHost->configureForOwner($token, $input, $ownerRegistry);
        installationConfigExpect($direct['state'] === 'configured', 'matching direct owner API must publish through the shared native validator');
        $directEnvironment = parse_ini_file($server . '/.env', false, INI_SCANNER_RAW);
        installationConfigExpect(($directEnvironment['PEANUT_DATABASE_RESOURCE_ID'] ?? null) === $plan['database_resource_id']
            && ($directEnvironment['PEANUT_DATABASE_ENDPOINT_ID'] ?? null) === $plan['database_endpoint']['endpoint_id']
            && ($directEnvironment['PEANUT_INSTALLATION_SETUP_TOKEN'] ?? null) === $token,
            'direct owner publication must preserve deployment IDs and private token handoff');
        $publishedBytes = (string) file_get_contents($server . '/.env');
        file_put_contents($server . '/private/installation/installed.json', '{}');
        installationConfigExpect($ownerHost->status()['state'] === 'installed', 'completion marker must close configuration rather than authorize reinstall');
        $reconfigurationRejected = false;
        try { $ownerHost->configureForOwner($token, $input, $ownerRegistry); }
        catch (InstallationExecutionException $exception) { $reconfigurationRejected = $exception->errorCode === 'INSTALL_CONFIGURATION_ALREADY_PRESENT'; }
        installationConfigExpect($reconfigurationRejected && file_get_contents($server . '/.env') === $publishedBytes
            && is_file($server . '/private/installation/installed.json'), 'retained token must not permit reconfiguration or remove completion protection');
        // Model the supported native updater's new, valid program identity while
        // deliberately retaining the original installation receipt as history.
        $updatedApplication = $plan['application']; $updatedApplication['version'] = '1.0.1';
        $updatedApplication['commit'] = str_repeat('f', 40); $updatedApplication['tree'] = str_repeat('e', 40);
        installationConfigReleaseFixture($applicationRoot, $updatedApplication,
            json_decode((string) file_get_contents($projectionPath), true, 512, JSON_THROW_ON_ERROR));
        $updatedStatus = $ownerHost->status();
        installationConfigExpect($updatedStatus['state'] === 'installed' && $updatedStatus['code'] === 'INSTALL_CONFIGURATION_LOCKED'
            && $updatedStatus['application']['version'] === '1.0.1' && file_get_contents($receipt) === $receiptBytes,
            'valid native update must return current installed/locked status without rewriting original receipt history');
    }

    echo "INSTALLATION-CONFIGURATION-HOST passed\n";
} finally {
    installationConfigDelete($fixture);
}
