<?php

declare(strict_types=1);

use app\common\value\installation\ApplicationReleaseVersions;
use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use app\common\value\scaffold\EditionProfile;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;
use think\App;
use think\Container;

$installerArguments = $_SERVER['argv'] ?? [];
$installerIsDirect = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__;
function emitUnavailableInstallStatus(): void
{
    echo json_encode(['state' => 'blocked', 'code' => 'INSTALL_STATUS_UNAVAILABLE'], JSON_THROW_ON_ERROR), PHP_EOL;
}
if ($installerIsDirect && in_array('--preflight', $installerArguments, true)) {
    require_once dirname(__DIR__) . '/app/common/services/installation/InstallationPreflightHost.php';
    $preflight = (new \app\common\services\installation\InstallationPreflightHost(dirname(__DIR__)))->inspect();
    echo json_encode($preflight, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($preflight['status'] === 'ready' ? 0 : 1);
}

/**
 * Peanut Admin fresh-database installer.
 *
 * Run from the repository root or server directory after selecting a
 * registered database through process environment variables:
 *
 *     php server/database/install.php
 */

// A source distribution intentionally does not contain vendor/. Report the
// prerequisite before reading configuration or touching any database.
if ($installerIsDirect && !is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    fwrite(STDERR, "尚未安装后端依赖。请在产品根目录运行：\n"
        . "  scripts/project-composer prepare\n"
        . "  scripts/project-composer install --working-dir=server --no-scripts\n"
        . "然后按公开安装文档配置环境，再运行此安装入口。\n");
    if (in_array('--status', $installerArguments, true)) {
        emitUnavailableInstallStatus();
        exit(1);
    }
    exit(2);
}

try {
    require_once __DIR__ . '/environment-guard.php';
} catch (Throwable $exception) {
    if (!$installerIsDirect || !in_array('--status', $installerArguments, true)) {
        throw $exception;
    }
    fwrite(STDERR, '安装失败：INSTALL_EXECUTION_FAILED' . PHP_EOL);
    emitUnavailableInstallStatus();
    exit(1);
}

function loadConfig(string $serverDir): array
{
    $hostLeaseProof = getenv('P0E_HOST_LEASE_PROOF');
    $config = guardedDatabaseConfig(
        $hostLeaseProof === false || trim($hostLeaseProof) === '' ? null : $hostLeaseProof,
    );
    return [
        'DB_HOST' => $config['host'],
        'DB_PORT' => $config['port'],
        'DB_NAME' => $config['database'],
        'DB_USER' => $config['user'],
        'DB_PASS' => $config['password'],
    ];
}

function initialAdminPassword(string $serverDir): string
{
    $environment = getenv('ADMIN_INITIAL_PASSWORD');
    validateInitialAdminPassword($environment === false ? '' : $environment);
    return $environment === false ? '' : $environment;
}

function initialAdminEmail(string $serverDir): string
{
    $environment = getenv('ADMIN_INITIAL_EMAIL');
    $email = $environment === false ? '' : $environment;
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('ADMIN_INITIAL_EMAIL 必须是有效邮箱');
    }
    return strtolower((string) $email);
}

function validateInitialAdminPassword(string $password): void
{
    if (getenv('PEANUT_DEMO_MODE') === 'enabled') {
        if ($password !== 'peanut1234') {
            throw new RuntimeException('演示模式的初始管理员密码必须统一为 peanut1234');
        }
        return;
    }
    Container::getInstance()->make(\PeanutAdmin\Kernel\Identity\PasswordPolicy::class)->assertValid($password);
}

/** @return array{email:string,password:string}|null */
function initialPlatformCredentials(string $serverDir, string $adminEmail): ?array
{
    $environmentMode = getenv('DEPLOYMENT_MODE');
    $mode = $environmentMode === false ? '' : $environmentMode;
    if ($mode !== 'multi-tenant') {
        return null;
    }

    $environmentEmail = getenv('PLATFORM_INITIAL_EMAIL');
    $email = $environmentEmail === false ? '' : $environmentEmail;
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须是有效邮箱');
    }
    $email = strtolower((string) $email);
    if (hash_equals($adminEmail, $email)) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须与 ADMIN_INITIAL_EMAIL 不同');
    }

    $environmentPassword = getenv('PLATFORM_INITIAL_PASSWORD');
    $password = $environmentPassword === false ? '' : $environmentPassword;
    if (getenv('PEANUT_DEMO_MODE') === 'enabled') {
        if ((string) $password !== 'peanut1234') {
            throw new RuntimeException('演示模式的 Platform 初始密码必须统一为 peanut1234');
        }
        return ['email' => $email, 'password' => (string) $password];
    }
    Container::getInstance()->make(\PeanutAdmin\Kernel\Identity\PasswordPolicy::class)->assertValid((string) $password);

    return ['email' => $email, 'password' => (string) $password];
}

/**
 * @param array<string,mixed> $input
 * @return array{admin_email:string,admin_password:string,platform_credentials:array{email:string,password:string}|null}
 */
function normalizeInstallationCredentials(array $input): array
{
    $allowed = ['admin_email', 'admin_password', 'platform_email', 'platform_password'];
    $unknown = array_values(array_diff(array_keys($input), $allowed));
    if ($unknown !== []) {
        throw new RuntimeException('安装身份包含不支持的字段');
    }

    $adminEmail = strtolower(trim((string) ($input['admin_email'] ?? '')));
    if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('ADMIN_INITIAL_EMAIL 必须是有效邮箱');
    }
    $adminPassword = (string) ($input['admin_password'] ?? '');
    validateInitialAdminPassword($adminPassword);

    $mode = getenv('DEPLOYMENT_MODE');
    if ($mode !== 'standalone' && $mode !== 'multi-tenant') {
        throw new RuntimeException('DEPLOYMENT_MODE 必须是 standalone 或 multi-tenant');
    }
    if ($mode === 'standalone') {
        if (trim((string) ($input['platform_email'] ?? '')) !== ''
            || (string) ($input['platform_password'] ?? '') !== '') {
            throw new RuntimeException('standalone 安装不得提供 Platform 初始身份');
        }
        return [
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
            'platform_credentials' => null,
        ];
    }

    $platformEmail = strtolower(trim((string) ($input['platform_email'] ?? '')));
    if (filter_var($platformEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须是有效邮箱');
    }
    if (hash_equals($adminEmail, $platformEmail)) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须与 ADMIN_INITIAL_EMAIL 不同');
    }
    $platformPassword = (string) ($input['platform_password'] ?? '');
    if (getenv('PEANUT_DEMO_MODE') === 'enabled') {
        if ($platformPassword !== 'peanut1234') {
            throw new RuntimeException('演示模式的 Platform 初始密码必须统一为 peanut1234');
        }
    } else {
        Container::getInstance()->make(\PeanutAdmin\Kernel\Identity\PasswordPolicy::class)->assertValid($platformPassword);
    }

    return [
        'admin_email' => $adminEmail,
        'admin_password' => $adminPassword,
        'platform_credentials' => ['email' => $platformEmail, 'password' => $platformPassword],
    ];
}

/** @return array{admin_email:string,admin_password:string,platform_email:string,platform_password:string} */
function installationCredentialsFromEnvironment(): array
{
    return [
        'admin_email' => (string) (getenv('ADMIN_INITIAL_EMAIL') ?: ''),
        'admin_password' => (string) (getenv('ADMIN_INITIAL_PASSWORD') ?: ''),
        'platform_email' => (string) (getenv('PLATFORM_INITIAL_EMAIL') ?: ''),
        'platform_password' => (string) (getenv('PLATFORM_INITIAL_PASSWORD') ?: ''),
    ];
}

/** @return array<string,mixed> */
function automaticInstallationInput(): array
{
    $input = installationCredentialsFromEnvironment();
    $configuredModules = getenv('PEANUT_INSTALLATION_OFFICIAL_MODULES');
    if ($configuredModules !== false && trim($configuredModules) !== '') {
        $input['official_modules'] = array_values(array_filter(array_map(
            'trim',
            explode(',', $configuredModules),
        )));
    }
    return $input;
}

/** @return array<string, string> */
function brandWebsiteDefaults(string $serverDir): array
{
    $path = $serverDir . '/config/brand.json';
    $json = file_get_contents($path);
    $manifest = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($manifest)
        || ($manifest['schema_version'] ?? null) !== 1
        || !is_array($manifest['website'] ?? null)) {
        throw new RuntimeException('品牌默认配置格式错误');
    }
    $website = [];
    foreach ($manifest['website'] as $field => $value) {
        if (!is_string($field) || !is_string($value)) {
            throw new RuntimeException('品牌默认字段必须是字符串');
        }
        $website[$field] = $value;
    }
    return $website;
}

function sqlFiles(string $databaseDir): array
{
    return [$databaseDir . '/init.sql'];
}

function loadCoreRuntime(string $serverDir): void
{
    $autoload = $serverDir . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('缺少 Composer autoload，无法创建 Core Schema');
    }
    require_once $autoload;
    if (!class_exists(\think\migration\Migrator::class)) {
        throw new RuntimeException('缺少官方迁移依赖，请先按 server/composer.lock 执行 Composer install --no-scripts');
    }
}

/**
 * Resolve the Edition's required real-Tenant bootstrap contract.
 *
 * Generated applications are bound to the immutable application manifest. The
 * product source tree uses the same Edition profile directly for development.
 * Neither path may infer a default Tenant from a missing or mismatched contract.
 *
 * @return array{kind:string,code:string,tenant_identity:string,rbac:string,execution_context:string,module_lifecycle:string}
 */
function installationTenantBootstrapContract(string $serverDir): array
{
    $mode = getenv('DEPLOYMENT_MODE');
    if ($mode !== 'standalone' && $mode !== 'multi-tenant') {
        throw new RuntimeException('DEPLOYMENT_MODE 必须是 standalone 或 multi-tenant');
    }

    $serverIdentity = $serverDir . '/.peanut/release-identity.json';
    $projectRoot = dirname($serverDir);
    $manifestPath = $projectRoot . '/.peanut/application-manifest.json';
    if (file_exists($serverIdentity) || is_link($serverIdentity)) {
        $identity = ServerReleaseIdentity::resolve($serverDir);
        $contract = $identity->tenantBootstrapContract($mode);
    } elseif (ApplicationSourceIdentity::hasRuntime($serverDir)) {
        $identity = ApplicationSourceIdentity::load($serverDir);
        $contract = $identity->tenantBootstrapContract($mode);
    } elseif (file_exists($manifestPath) || is_link($manifestPath)) {
        $identity = ApplicationSourceIdentity::load($serverDir);
        $contract = $identity->tenantBootstrapContract($mode);
    } elseif (installationSourceDevelopmentMode($serverDir)) {
        $inventoryPath = $projectRoot . '/scaffold/application-template-inventory.json';
        $profilePath = $projectRoot . '/scaffold/edition-profiles.json';
        if (!is_file($inventoryPath) || is_link($inventoryPath)) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_MISSING');
        }
        $contract = EditionProfile::load($profilePath, $mode)->identity()['tenant_bootstrap'];
    } else {
        throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
    }

    $expected = [
        'kind' => 'real-default-tenant',
        'code' => 'default',
        'tenant_identity' => 'required',
        'rbac' => 'required',
        'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
        'module_lifecycle' => 'required',
    ];
    if ($contract !== $expected) {
        throw new RuntimeException('INSTALL_TENANT_BOOTSTRAP_CONTRACT_INVALID');
    }
    return $contract;
}

function installationSourceDevelopmentMode(string $serverDir): bool
{
    $root = dirname($serverDir);
    return getenv('PEANUT_INSTALLATION_SOURCE_MODE') === 'development'
        && file_exists($root . '/.git')
        && !is_link($root . '/.git')
        && (
            is_file($root . '/.peanut/application-manifest.json')
            || (
                is_file($root . '/scaffold/application-template-inventory.json')
                && is_file($root . '/scaffold/edition-profiles.json')
            )
        )
        && is_file($root . '/release-versions.json');
}

function ensureThinkPhpApplication(string $serverDir): App
{
    $container = Container::getInstance();
    if ($container instanceof App) {
        return $container;
    }

    return (new App($serverDir))->initialize();
}

function expectedTables(array $files): array
{
    $tables = array_fill_keys(KernelSchema::tableNames(), true);
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('无法读取 SQL 文件：' . basename($file));
        }
        preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`([^`]+)`/i', $sql, $matches);
        foreach ($matches[1] as $table) {
            $tables[$table] = true;
        }
    }
    $names = array_keys($tables);
    sort($names, SORT_STRING);
    return $names;
}

/**
 * @param array{email:string,password:string}|null $platformCredentials
 * @param array{kind:string,code:string,tenant_identity:string,rbac:string,execution_context:string,module_lifecycle:string} $tenantBootstrap
 * @return array{tenant_id:int,account_id:int,member_id:int,operator_id:int}
 */
function initializeCoreIdentity(
    PDO $pdo,
    string $email,
    string $password,
    ?array $platformCredentials,
    \PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy $demoAccounts,
    array $tenantBootstrap,
): array {
    $service = Container::getInstance()->make(BootstrapService::class);
    $separatePlatformOperator = $platformCredentials !== null;
    $demoBootstrapPassword = $demoAccounts->enabled()
        ? $demoAccounts->bootstrapPassword()
        : null;
    $platformPassword = $demoBootstrapPassword
        ?? ($platformCredentials['password'] ?? $password);
    $ownerPassword = $separatePlatformOperator
        ? ($demoBootstrapPassword ?? $password)
        : null;
    $platform = $service->bootstrapPlatformOwner(
        $platformCredentials['email'] ?? $email,
        $platformPassword,
        $separatePlatformOperator ? 'Platform Operator' : '超级管理员',
        'fresh-install-platform-owner',
    );
    $owner = $service->provisionTenantOwnerCandidate(
        $platform->operatorId,
        $tenantBootstrap['code'],
        'Peanut Admin',
        $email,
        $ownerPassword,
        '超级管理员',
        'fresh-install-default-owner',
    );
    $service->activateTenantOwner(
        $platform->operatorId,
        $owner->tenantId,
        $owner->memberId,
        'fresh-install-default-owner-activate',
    );
    $service->activateTenant(
        $platform->operatorId,
        $owner->tenantId,
        'fresh-install-default-tenant-activate',
    );

    return [
        'tenant_id' => $owner->tenantId,
        'account_id' => $owner->accountId,
        'member_id' => $owner->memberId,
        'operator_id' => $platform->operatorId,
    ];
}

/** @param list<string> $emails */
function replaceInstalledDemoCredentialHashes(PDO $pdo, array $emails, string $hash): void
{
    $statement = $pdo->prepare(<<<'SQL'
UPDATE pa_credential
SET secret_hash = :secret_hash,
    failed_attempts = 0,
    locked_until = NULL,
    secret_changed_at = UTC_TIMESTAMP(3),
    revision = revision + 1,
    updated_at = UTC_TIMESTAMP(3)
WHERE identifier_type = 'email'
  AND kind = 'email_password'
  AND status = 'active'
  AND identifier_normalized = :email
SQL);
    foreach (array_values(array_unique(array_map('strtolower', $emails))) as $email) {
        if (trim($email) !== '') {
            $statement->execute(['secret_hash' => $hash, 'email' => trim($email)]);
        }
    }
}

/** @return array{tenant_count:int,owner_count:int,operator_count:int} */
function coreIdentityCounts(PDO $pdo, string $tenantCode): array
{
    $tenant = $pdo->prepare("SELECT COUNT(*) FROM pa_tenant WHERE code = ? AND status = 'active'");
    $tenant->execute([$tenantCode]);
    $owner = $pdo->prepare(<<<'SQL'
SELECT COUNT(DISTINCT tm.id)
FROM pa_tenant t
JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.status = 'active'
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE t.code = ? AND t.status = 'active' AND r.`key` = 'core.tenant-owner'
SQL);
    $owner->execute([$tenantCode]);
    return [
        'tenant_count' => (int) $tenant->fetchColumn(),
        'owner_count' => (int) $owner->fetchColumn(),
        'operator_count' => (int) $pdo->query(
            "SELECT COUNT(*) FROM pa_platform_operator WHERE status = 'active'",
        )->fetchColumn(),
    ];
}

/** @param array<string, string> $website */
function seedBrandDefaults(PDO $pdo, array $website): void
{
    $statement = $pdo->prepare(
        'INSERT INTO pa_config (type, name, value, create_time, update_time) '
        . "VALUES ('website', ?, ?, ?, ?) "
        . 'ON DUPLICATE KEY UPDATE value = VALUES(value), update_time = VALUES(update_time)',
    );
    $now = time();
    $pdo->beginTransaction();
    try {
        foreach ($website as $field => $value) {
            $statement->execute([$field, $value, $now, $now]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @return list<string> */
function applicationMigrationFiles(string $databaseDir): array
{
    $directory = $databaseDir . '/migrations';
    if (!is_dir($directory)) {
        return [];
    }
    $files = [];
    foreach (glob($directory . '/*.sql') ?: [] as $file) {
        if (!is_file($file) || is_link($file) || preg_match('/^[0-9]{8}-[a-z0-9][a-z0-9_-]*\.sql$/D', basename($file)) !== 1) {
            throw new RuntimeException('迁移文件名无效：' . basename($file));
        }
        $files[] = $file;
    }
    sort($files, SORT_STRING);
    return \app\common\services\upgrade\ApplicationMigrationRunner::effectiveFiles($files);
}

/**
 * Read source product, instance release-sequence and scaffold migration axes from the root contract.
 *
 * @return array{source_product_version:string,release_sequence_version:string,scaffold_template:string}
 */
function applicationReleaseVersions(string $serverDir): array
{
    loadCoreRuntime($serverDir);
    $serverIdentity = $serverDir . '/.peanut/release-identity.json';
    if (file_exists($serverIdentity) || is_link($serverIdentity)) {
        return ServerReleaseIdentity::resolve($serverDir)->versions();
    }
    if (ApplicationSourceIdentity::hasRuntime($serverDir)) {
        return ApplicationSourceIdentity::load($serverDir)->versions();
    }
    $projectRoot = dirname($serverDir);
    $applicationManifest = $projectRoot . '/.peanut/application-manifest.json';
    if (!installationSourceDevelopmentMode($serverDir)) {
        if (!file_exists($applicationManifest) && !is_link($applicationManifest)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        if (!is_file($applicationManifest) || is_link($applicationManifest)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
    }
    $contract = ApplicationReleaseVersions::load($projectRoot . '/release-versions.json');
    return [
        'source_product_version' => $contract->sourceProductVersion(),
        'release_sequence_version' => $contract->releaseSequenceVersion(),
        'scaffold_template' => $contract->scaffoldTemplate(),
    ];
}

/**
 * Resolve the SQL target from this scaffold and its optional deployment-verified demo overlay.
 *
 * @param array{source_product_version:string,release_sequence_version:string,scaffold_template:string} $versions
 */
function applicationMigrationTargetVersion(string $serverDir, array $versions): string
{
    $base = $versions['scaffold_template'];
    $path = dirname($serverDir) . '/DEMO_PATCH_METADATA.json';
    if (!file_exists($path)) {
        return $base;
    }
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('MIGRATION_TARGET_CONTRACT_INVALID');
    }
    try {
        $overlay = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('MIGRATION_TARGET_CONTRACT_INVALID', 0, $exception);
    }
    if (!is_array($overlay)
        || ($overlay['schema_version'] ?? null) !== 1
        || ($overlay['kind'] ?? null) !== 'peanut-admin-demo-site-overlay'
        || ($overlay['base_tag'] ?? null) !== 'v' . $versions['source_product_version']
        || !is_array($overlay['files'] ?? null)
        || !is_string($overlay['migration_target_version'] ?? null)
        || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $overlay['migration_target_version']) !== 1
        || version_compare($overlay['migration_target_version'], $base, '<')
    ) {
        throw new RuntimeException('MIGRATION_TARGET_CONTRACT_INVALID');
    }
    return $overlay['migration_target_version'];
}

/**
 * Refuse a caller-provided SQL target that differs from the adopted scaffold/overlay identity.
 *
 * @param array{source_product_version:string,release_sequence_version:string,scaffold_template:string} $versions
 */
function validatedMigrationTargetVersion(string $serverDir, string $targetVersion, array $versions): string
{
    if (!hash_equals(applicationMigrationTargetVersion($serverDir, $versions), $targetVersion)) {
        throw new RuntimeException('MIGRATION_TARGET_CONTRACT_MISMATCH');
    }
    return $targetVersion;
}

/**
 * Resolve immutable Peanut markers separately from the three hash-pinned application migrations.
 *
 * @return array{release_version:string,peanut_release:bool}
 */
function migrationReleaseIdentity(string $sql, string $applicationVersion): array
{
    return \app\common\services\upgrade\ApplicationMigrationRunner::releaseIdentity(
        $sql,
        $applicationVersion,
    );
}

/**
 * Select and apply eligible immutable migrations while preserving their checksum ledger.
 *
 * @return array{status:string,target_version:string,applied:list<string>,pending:list<string>}
 */
function migrateDatabase(string $serverDir, string $targetVersion, bool $dryRun = false): array
{
    // 版本格式由已固定的 ApplicationReleaseVersions 校验，CLI 目标必须逐字匹配。
    // 开发候选与正式版本共用同一权威来源；不得用旧 RELEASE_METADATA 代替候选迁移目标。
    $versions = applicationReleaseVersions($serverDir);
    $targetVersion = validatedMigrationTargetVersion($serverDir, $targetVersion, $versions);
    $config = loadConfig($serverDir);
    return (new \app\common\services\upgrade\ApplicationMigrationRunner([
        'host' => $config['DB_HOST'],
        'port' => $config['DB_PORT'],
        'database' => $config['DB_NAME'],
        'user' => $config['DB_USER'],
        'password' => $config['DB_PASS'],
    ]))->run(
        applicationMigrationFiles(__DIR__),
        $targetVersion,
        $versions['release_sequence_version'],
        $dryRun,
    );
}

/**
 * @return array{state:string,code:string,health:array<string,int>|null}
 */
function installationDatabaseState(string $serverDir): array
{
    loadCoreRuntime($serverDir);
    $config = loadConfig($serverDir);
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['DB_HOST'],
            $config['DB_PORT'],
            $config['DB_NAME'],
        ),
        $config['DB_USER'],
        $config['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $tableCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
    )->fetchColumn();
    if ($tableCount === 0) {
        return ['state' => 'uninstalled', 'code' => 'INSTALL_DATABASE_EMPTY', 'health' => null];
    }

    try {
        $health = assertCurrentDatabase($pdo);
        return ['state' => 'installed', 'code' => 'INSTALL_DATABASE_CURRENT', 'health' => $health];
    } catch (Throwable) {
        return ['state' => 'blocked', 'code' => 'INSTALL_DATABASE_PARTIAL', 'health' => null];
    }
}

function installationStateBlocksFreshDatabase(string $serverDir): bool
{
    foreach ([
        $serverDir . '/private/installation/installed.json',
        $serverDir . '/private/installation/migration.json',
        $serverDir . '/runtime/installation/executing.json',
        $serverDir . '/runtime/installation/installed.json',
        $serverDir . '/runtime/installation/baseline.json',
    ] as $path) {
        if (file_exists($path) || is_link($path)) {
            return true;
        }
    }
    return false;
}

/** @return array{cause_code?:string,module_key?:string} */
function installationFailureDiagnostic(string $serverDir, string $stage, Throwable $exception, ?string $currentModule = null): array
{
    $diagnostic = [];
    if ($stage !== 'install_modules') {
        return $diagnostic;
    }
    try {
        do {
            // A typed producer and finite public codes are required; never copy messages.
            if ($exception instanceof \app\platform\exception\plugin\PluginLifecycleException
                && in_array($exception->errorCode, [
                    'MODULE_MIGRATION_ADOPTION_REJECTED', 'MODULE_MIGRATION_ADOPTION_UNAUTHORIZED',
                    'MODULE_MIGRATION_ADOPTION_WINDOW_REQUIRED', 'MODULE_MIGRATION_POSTSTATE_REJECTED',
                    'MODULE_MIGRATION_PRESERVATION_REQUIRED', 'MODULE_MIGRATION_PDO_MISMATCH',
                    'MODULE_MIGRATION_NATIVE_MASTER_CHANGED', 'MODULE_MIGRATION_CHECKSUM_MISMATCH',
                    'MODULE_MIGRATION_PREDECESSOR_REQUIRED', 'MODULE_MIGRATION_FAILED',
                ], true)) {
                $diagnostic['cause_code'] = $exception->errorCode;
                break;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);
        if ($diagnostic === []) {
            return $diagnostic;
        }
        // Independently bind the Host's current iteration to its protected selection.
        $progressPath = rtrim($serverDir, DIRECTORY_SEPARATOR) . '/private/installation/executing.json';
        $stat = @lstat($progressPath);
        if (is_string($currentModule) && preg_match('/^official\.[a-z][a-z0-9-]{0,63}$/D', $currentModule) === 1
            && realpath($progressPath) === $progressPath && is_file($progressPath) && !is_link($progressPath)
            && is_array($stat) && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0600
            && in_array($stat['uid'], [0, fileowner($serverDir)], true) && $stat['size'] <= 65536) {
            $progress = json_decode((string) file_get_contents($progressPath), true, 32);
            if (is_array($progress) && ($progress['schema_version'] ?? null) === 1
                && ($progress['state'] ?? null) === 'executing'
                && is_array($progress['official_modules'] ?? null)
                && array_is_list($progress['official_modules'])
                && in_array($currentModule, $progress['official_modules'], true)) {
                $diagnostic['module_key'] = $currentModule;
            }
        }
    } catch (Throwable) {
        // Optional context must never replace the original failure or typed cause.
    }
    return $diagnostic;
}

/** Record only diagnostic identifiers; exception messages and debug data can contain credentials. */
function logInstallationFailure(string $serverDir, string $stage, string $errorCode, Throwable $exception, ?string $currentModule = null): void
{
    try {
        $diagnostic = installationFailureDiagnostic($serverDir, $stage, $exception, $currentModule);
        $projectRoot = rtrim(str_replace('\\', '/', dirname($serverDir)), '/') . '/';
        $chain = [];
        do {
            $file = str_replace('\\', '/', $exception->getFile());
            $class = get_class($exception);
            $entry = [
                'class' => str_contains($class, "\0") ? 'anonymous-class' : $class,
                'path' => str_starts_with($file, $projectRoot) ? substr($file, strlen($projectRoot)) : null,
                'line' => $exception->getLine(),
            ];
            // Only this fixed producer's finite proof codes are safe to audit.
            // Keep them on their actual chain entry, never in the public response.
            if ($exception instanceof RuntimeException
                && $file === rtrim(str_replace('\\', '/', $serverDir), '/') . '/app/modules/official/identity/src/Database/IdentitySchemaMigrationProof.php'
                && str_starts_with($exception->getMessage(), 'IDENTITY_SCHEMA_STAGE_')
                && in_array(substr($exception->getMessage(), strlen('IDENTITY_SCHEMA_STAGE_')), [
                    'IDENTITY_INVALID', 'INPUT_INVALID', 'SOURCE_CHANGED',
                    'LEDGER_READER_REQUIRED', 'LEDGER_INVALID', 'LEDGER_CHANGED',
                    'CREATE_TARGET_EXISTS', 'ALTER_TARGET_MISSING', 'TARGET_MISSING', 'CREATE_POSTSTATE_INVALID',
                    'ALTER_POSTSTATE_INVALID', 'COLUMN_PRESTATE_INVALID', 'COLUMN_PREDECESSOR_MISSING', 'LEADER_INDEX_INVALID',
                    'AUDIT_TARGET_EXISTS', 'AUDIT_INDEX_EXISTS', 'AUDIT_COLUMN_MISSING', 'STAGE_UNKNOWN',
                    'ORIGINAL_ROWS_CHANGED', 'NEW_FIELD_VALUES_INVALID', 'PARENT_KEY_UNSUPPORTED',
                    'TABLE_UNSUPPORTED', 'TABLE_COLLATION_UNKNOWN', 'CONNECTION_UNSUPPORTED', 'STATISTICS_UNAVAILABLE', 'SNAPSHOT_CHANGED',
                    'RECOVERY_INPUT_INVALID', 'RECOVERY_CREATE_POSTSTATE_CHANGED',
                ], true)) {
                $entry['cause_code'] = $exception->getMessage();
            }
            $sqlState = null;
            $driverCode = null;
            if ($exception instanceof PDOException) {
                $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
                $driverCode = $exception->errorInfo[1] ?? null;
            } elseif ($exception instanceof \think\db\exception\PDOException) {
                $pdoError = $exception->getData()['PDO Error Info'] ?? [];
                $sqlState = $pdoError['SQLSTATE'] ?? null;
                $driverCode = $pdoError['Driver Error Code'] ?? null;
            }
            if (is_string($sqlState) && preg_match('/^[A-Z0-9]{5}$/D', $sqlState) === 1) {
                $entry['sqlstate'] = $sqlState;
            }
            if (is_int($driverCode) || (is_string($driverCode) && preg_match('/^[0-9]+$/D', $driverCode) === 1)) {
                $entry['driver_code'] = (int) $driverCode;
            }
            $chain[] = $entry;
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        \think\facade\Log::write(json_encode([
            'event' => 'installation_failure',
            'stage' => $stage,
            'error_code' => $errorCode,
            'exception_chain' => $chain,
            ...$diagnostic,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'error');
    } catch (Throwable) {
        // A logging failure must never replace the installation failure.
    }
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function installFreshDatabase(string $serverDir, array $input): array
{
    if (installationStateBlocksFreshDatabase($serverDir)) {
        throw new RuntimeException('INSTALL_STATE_PRESENT: 安装身份状态已存在，拒绝再次初始化数据库');
    }
    $databaseDir = $serverDir . '/database';
    loadCoreRuntime($serverDir);
    ensureThinkPhpApplication($serverDir);
    $tenantBootstrap = installationTenantBootstrapContract($serverDir);
    $credentials = normalizeInstallationCredentials($input);
    $config = loadConfig($serverDir);
    $database = $config['DB_NAME'];
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
        throw new RuntimeException('DB_NAME 只能包含字母、数字和下划线');
    }

    $connection = \think\facade\Db::connect();
    foreach (['hostname' => 'DB_HOST', 'hostport' => 'DB_PORT', 'database' => 'DB_NAME', 'username' => 'DB_USER', 'password' => 'DB_PASS'] as $key => $inputKey) {
        if ((string) $connection->getConfig($key) !== (string) $config[$inputKey]) { throw new RuntimeException('INSTALL_NATIVE_DATABASE_CONFIG_CONFLICT'); }
    }
    if ($connection->getConfig('prefix') !== 'pa_' || (int) $connection->getConfig('deploy') !== 0
        || $connection->getConfig('break_reconnect') !== false) { throw new RuntimeException('INSTALL_NATIVE_DATABASE_CONFIG_CONFLICT'); }
    $connection->query('SELECT 1', [], true);
    $pdo = $connection->getPdo();
    if (!$pdo instanceof PDO || $pdo->inTransaction()) { throw new RuntimeException('INSTALL_NATIVE_MASTER_REQUIRED'); }
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) { throw new RuntimeException('INSTALL_NATIVE_DATABASE_IDENTITY_CHANGED'); }
    $baseline = \app\common\infrastructure\migration\CleanInstallBaseline::load($databaseDir);
    $versions = applicationReleaseVersions($serverDir);
    if (version_compare($versions['source_product_version'], $baseline->manifest['source_product_version'], '<')) { throw new RuntimeException('INSTALL_BASELINE_VERSION_CONFLICT'); }

    $lockName = 'peanut_install_' . substr(hash('sha256', $database), 0, 48);
    $lockStatement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lockStatement->execute([$lockName]);
    $lockAcquired = (int) $lockStatement->fetchColumn() === 1;
    $lockStatement->closeCursor();
    if (!$lockAcquired) {
        throw new RuntimeException('无法获取安装锁，请稍后重试');
    }

    $installationFailure = null;
    try {
        if (installationStateBlocksFreshDatabase($serverDir)) {
            throw new RuntimeException('INSTALL_STATE_PRESENT: 安装身份状态已存在，拒绝再次初始化数据库');
        }
        $expected = array_merge($baseline->manifest['tables'], ['pa_schema_migration']);
        $tableCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        )->fetchColumn();
        if ($tableCount !== 0) {
            throw new RuntimeException('目标数据库不是空库，已拒绝执行首次安装');
        }

        $adminEmail = $credentials['admin_email'];
        $adminPassword = $credentials['admin_password'];
        $platformCredentials = $credentials['platform_credentials'];
        $demoAccounts = new \PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy(
            getenv('PEANUT_DEMO_MODE') === 'enabled',
            array_values(array_filter([
                $adminEmail,
                $platformCredentials['email'] ?? '',
            ])),
        );
        $coreIdentity = [];
        $baselineResult = $baseline->install($pdo,
            function () use ($pdo, $adminEmail, $adminPassword, $platformCredentials, $demoAccounts, $tenantBootstrap, &$coreIdentity): void {
                $coreIdentity = initializeCoreIdentity($pdo, $adminEmail, $adminPassword, $platformCredentials, $demoAccounts, $tenantBootstrap);
                if ($demoAccounts->enabled()) { replaceInstalledDemoCredentialHashes($pdo, [$adminEmail, $platformCredentials['email'] ?? ''], $demoAccounts->credentialHash()); }
                $pdo->exec('SET @pa_default_tenant_id = ' . (int) $coreIdentity['tenant_id']);
                $pdo->prepare('SET @pa_default_tenant_code = ?')->execute([$tenantBootstrap['code']]);
            },
            function () use ($pdo, $serverDir): void {
                (new CorePermissionCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize();
                seedBrandDefaults($pdo, brandWebsiteDefaults($serverDir));
            },
            function () use ($pdo, $connection, $expected, $tenantBootstrap, $lockName): void {
                if (\think\facade\Db::connect() !== $connection || $connection->getPdo() !== $pdo || $pdo->inTransaction()) { throw new RuntimeException('INSTALL_NATIVE_MASTER_CHANGED'); }
                $lock = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()'); $lock->execute([$lockName]);
                if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('INSTALL_DATABASE_LOCK_LOST'); }
                $lock->closeCursor();
                $actual = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                if (array_diff($expected, $actual) !== [] || count($actual) !== count($expected)
                    || coreIdentityCounts($pdo, $tenantBootstrap['code']) !== ['tenant_count' => 1, 'owner_count' => 1, 'operator_count' => 1]
                    || (int) $pdo->query('SELECT COUNT(*) FROM pa_system_menu')->fetchColumn() === 0
                    || (int) $pdo->query('SELECT COUNT(*) FROM pa_config')->fetchColumn() === 0) { throw new RuntimeException('INSTALL_BASELINE_RESULT_INCOMPLETE'); }
            });

        $actual = array_map('strval', $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
        )->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($expected, $actual));
        $activeMenus = (int) $pdo->query('SELECT COUNT(*) FROM pa_system_menu')->fetchColumn();
        $configCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_config')->fetchColumn();
        $identityCounts = coreIdentityCounts($pdo, $tenantBootstrap['code']);
        if ($missing !== []
            || $activeMenus === 0
            || $configCount === 0
            || $identityCounts !== ['tenant_count' => 1, 'owner_count' => 1, 'operator_count' => 1]) {
            throw new RuntimeException('安装结果不完整');
        }

        return [
            'database' => $database,
            'baseline' => $baselineResult,
            'tables' => count($actual),
            'expected_tables' => count($expected),
            'active_menus' => $activeMenus,
            'configs' => $configCount,
            'tenant_bootstrap' => $tenantBootstrap,
            'default_tenant_id' => $coreIdentity['tenant_id'],
            'owner_account_id' => $coreIdentity['account_id'],
            'owner_member_id' => $coreIdentity['member_id'],
        ];
    } catch (Throwable $exception) {
        $installationFailure = $exception;
        throw $exception;
    } finally {
        try {
            $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStatement->execute([$lockName]);
        } catch (Throwable $exception) {
            logInstallationFailure($serverDir, 'release_database_lock', 'INSTALL_LOCK_RELEASE_FAILED', $exception);
            if ($installationFailure === null) {
                throw $exception;
            }
        }
    }
}

function main(): int
{
    $result = installFreshDatabase(dirname(__DIR__), installationCredentialsFromEnvironment());
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    return 0;
}

if ($installerIsDirect) {
    try {
        $application = ensureThinkPhpApplication(dirname(__DIR__));
        $host = $application->make(\app\common\services\installation\InstallationExecutionHost::class);
        $partial = array_values(array_filter(array_slice($_SERVER['argv'] ?? [], 1), static fn(string $arg): bool => str_starts_with($arg, '--partial=')));
        if ($partial === [] && array_filter(array_slice($_SERVER['argv'] ?? [], 1), static fn(string $arg): bool => str_starts_with($arg, '--partial-input=')) !== []) { throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID'); }
        if ($partial !== []) {
            $inputs = array_values(array_filter(array_slice($_SERVER['argv'], 1), static fn(string $arg): bool => str_starts_with($arg, '--partial-input=')));
            if (count($partial) !== 1 || count($inputs) !== 1 || count($_SERVER['argv']) !== 3) { throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID'); }
            $operation = substr($partial[0], strlen('--partial=')); $input = substr($inputs[0], strlen('--partial-input='));
            $result = $application->make(\app\common\execution\ExecutionContextStore::class)->run(
                new \app\common\execution\InstallationExecutionContext('installation.console.partial', 'install-' . bin2hex(random_bytes(16))),
                static fn() => $host->partialInstallation($operation, $input),
            );
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
            exit(0);
        }
        if (in_array('--status', $_SERVER['argv'] ?? [], true)) {
            $status = $host->status();
            echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
            exit($status['state'] === 'installed' ? 0 : 1);
        }
        $status = $host->status();
        if ($status['state'] === 'installed'
            && in_array('--skip-if-installed', $_SERVER['argv'] ?? [], true)) {
            echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
            exit(0);
        }
        $result = $application->make(\app\common\execution\ExecutionContextStore::class)->run(
            new \app\common\execution\InstallationExecutionContext(
                'installation.console.execute',
                'install-' . bin2hex(random_bytes(16)),
            ),
            static fn() => $host->executeAutomatic(automaticInstallationInput()),
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    } catch (\app\common\exception\installation\InstallationExecutionException $exception) {
        fwrite(STDERR, '安装失败：' . $exception->errorCode . PHP_EOL);
        if (in_array('--status', $_SERVER['argv'] ?? [], true)) {
            emitUnavailableInstallStatus();
        }
        exit(1);
    } catch (Throwable $exception) {
        $code = isset($partial) && $partial !== [] && preg_match('/\A(?:INSTALL_(?:PARTIAL|SETUP_TOKEN)|MIGRATION_(?:CORRECTION|ORIGINAL)|APPLICATION_(?:DEVELOPMENT_RUNTIME|SOURCE))_[A-Z_]+\z/D', $exception->getMessage()) === 1
            ? $exception->getMessage() : 'INSTALL_EXECUTION_FAILED';
        fwrite(STDERR, '安装失败：' . $code . PHP_EOL);
        if (in_array('--status', $_SERVER['argv'] ?? [], true)) {
            emitUnavailableInstallStatus();
        }
        exit(1);
    }
}
