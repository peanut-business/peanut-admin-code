<?php
declare(strict_types=1);

use app\common\value\installation\ApplicationReleaseVersions;
use app\common\value\scaffold\EditionProfile;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;
use think\App;
use think\Container;

$installerArguments = $_SERVER['argv'] ?? [];
$installerIsDirect = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__;
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

require_once __DIR__ . '/environment-guard.php';

function loadConfig(string $serverDir): array
{
    $hostLeaseProof = getenv('P0E_HOST_LEASE_PROOF');
    $config = guardedDatabaseConfig(
        $hostLeaseProof === false || trim($hostLeaseProof) === '' ? null : $hostLeaseProof
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
    return strtolower((string)$email);
}

function validateInitialAdminPassword(string $password): void
{
    if (getenv('PEANUT_DEMO_MODE') === 'enabled') {
        if ($password !== 'peanut1234') {
            throw new RuntimeException('演示模式的初始管理员密码必须统一为 peanut1234');
        }
        return;
    }
    if (strlen($password) < 12) {
        throw new RuntimeException('ADMIN_INITIAL_PASSWORD 至少 12 位');
    }
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
    $email = strtolower((string)$email);
    if (hash_equals($adminEmail, $email)) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须与 ADMIN_INITIAL_EMAIL 不同');
    }

    $environmentPassword = getenv('PLATFORM_INITIAL_PASSWORD');
    $password = $environmentPassword === false ? '' : $environmentPassword;
    if (getenv('PEANUT_DEMO_MODE') === 'enabled') {
        if ((string)$password !== 'peanut1234') {
            throw new RuntimeException('演示模式的 Platform 初始密码必须统一为 peanut1234');
        }
        return ['email' => $email, 'password' => (string)$password];
    }
    if (strlen((string)$password) < 12) {
        throw new RuntimeException('PLATFORM_INITIAL_PASSWORD 至少 12 位');
    }

    return ['email' => $email, 'password' => (string)$password];
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

    $adminEmail = strtolower(trim((string)($input['admin_email'] ?? '')));
    if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('ADMIN_INITIAL_EMAIL 必须是有效邮箱');
    }
    $adminPassword = (string)($input['admin_password'] ?? '');
    validateInitialAdminPassword($adminPassword);

    $mode = getenv('DEPLOYMENT_MODE');
    if ($mode !== 'standalone' && $mode !== 'multi-tenant') {
        throw new RuntimeException('DEPLOYMENT_MODE 必须是 standalone 或 multi-tenant');
    }
    if ($mode === 'standalone') {
        if (trim((string)($input['platform_email'] ?? '')) !== ''
            || (string)($input['platform_password'] ?? '') !== '') {
            throw new RuntimeException('standalone 安装不得提供 Platform 初始身份');
        }
        return [
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
            'platform_credentials' => null,
        ];
    }

    $platformEmail = strtolower(trim((string)($input['platform_email'] ?? '')));
    if (filter_var($platformEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须是有效邮箱');
    }
    if (hash_equals($adminEmail, $platformEmail)) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须与 ADMIN_INITIAL_EMAIL 不同');
    }
    $platformPassword = (string)($input['platform_password'] ?? '');
    if (getenv('PEANUT_DEMO_MODE') === 'enabled') {
        if ($platformPassword !== 'peanut1234') {
            throw new RuntimeException('演示模式的 Platform 初始密码必须统一为 peanut1234');
        }
    } elseif (strlen($platformPassword) < 12) {
        throw new RuntimeException('PLATFORM_INITIAL_PASSWORD 至少 12 位');
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
        'admin_email' => (string)(getenv('ADMIN_INITIAL_EMAIL') ?: ''),
        'admin_password' => (string)(getenv('ADMIN_INITIAL_PASSWORD') ?: ''),
        'platform_email' => (string)(getenv('PLATFORM_INITIAL_EMAIL') ?: ''),
        'platform_password' => (string)(getenv('PLATFORM_INITIAL_PASSWORD') ?: ''),
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

    $projectRoot = dirname($serverDir);
    $manifestPath = $projectRoot . '/.peanut/application-manifest.json';
    if (file_exists($manifestPath) || is_link($manifestPath)) {
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_INVALID');
        }
        try {
            $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_INVALID', 0, $exception);
        }
        if (!is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 2
            || ($manifest['protocol'] ?? null) !== 'peanut.application-scaffold.v2'
            || ($manifest['application']['edition'] ?? null) !== $mode
            || ($manifest['edition']['name'] ?? null) !== $mode
            || ($manifest['edition']['deployment_mode'] ?? null) !== $mode
            || !is_string($manifest['edition']['source_sha256'] ?? null)
            || !hash_equals(
                $manifest['edition']['source_sha256'],
                (string)(isset($manifest['last_scaffold_upgrade'])
                    ? ($manifest['last_scaffold_upgrade']['edition_profile_sha256'] ?? '')
                    : ($manifest['generation_source']['edition_profile_sha256'] ?? '')),
            )) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_INVALID');
        }
        $contract = $manifest['edition']['tenant_bootstrap'] ?? null;
    } else {
        $inventoryPath = $projectRoot . '/scaffold/application-template-inventory.json';
        $profilePath = $projectRoot . '/scaffold/edition-profiles.json';
        if (!is_file($inventoryPath) || is_link($inventoryPath)) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_MISSING');
        }
        $contract = EditionProfile::load($profilePath, $mode)->identity()['tenant_bootstrap'];
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

function executeSqlFiles(PDO $pdo, array $files): void
{
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('无法读取 SQL 文件：' . basename($file));
        }
        try {
            $pdo->exec($sql);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                '执行 SQL 文件失败：' . basename($file) . '；' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }
}

function executeSqlFile(PDO $pdo, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('无法读取 SQL 文件：' . basename($file));
    }
    try {
        $pdo->exec($sql);
    } catch (Throwable $exception) {
        throw new RuntimeException(
            '执行 SQL 文件失败：' . basename($file) . '；' . $exception->getMessage(),
            0,
            $exception
        );
    }
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
    \app\common\policy\DemoAccountPolicy $demoAccounts,
    array $tenantBootstrap,
): array
{
    foreach (KernelSchema::tableNames() as $table) {
        $pdo->exec(KernelSchema::createSql($table));
    }
    ensureTenantChallengeClientKey($pdo);
    $pdo->exec(KernelSchema::addTenantMemberDepartmentForeignKeySql());

    $service = new BootstrapService(
        passwords: \app\common\security\ApplicationPasswordPolicy::hasher(),
    );
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
        'fresh-install-platform-owner'
    );
    $owner = $service->provisionTenantOwnerCandidate(
        $platform->operatorId,
        $tenantBootstrap['code'],
        'Peanut Admin',
        $email,
        $ownerPassword,
        '超级管理员',
        'fresh-install-default-owner'
    );
    $service->activateTenantOwner(
        $platform->operatorId,
        $owner->tenantId,
        $owner->memberId,
        'fresh-install-default-owner-activate'
    );
    $service->activateTenant(
        $platform->operatorId,
        $owner->tenantId,
        'fresh-install-default-tenant-activate'
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

/**
 * The alpha.5 Core package persists the client binding but its exported
 * fresh schema predates that column. Keep a fresh install compatible with the
 * repository contract until the package's canonical KernelSchema is released.
 */
function ensureTenantChallengeClientKey(PDO $pdo): void
{
    $column = $pdo->query(
        "SHOW COLUMNS FROM `pa_login_challenge` LIKE 'client_key'"
    )->fetch(PDO::FETCH_ASSOC);
    if ($column !== false) {
        return;
    }

    $pdo->exec(<<<'SQL'
ALTER TABLE `pa_login_challenge`
  ADD COLUMN `client_key` VARCHAR(64) NOT NULL AFTER `purpose`,
  ADD CONSTRAINT `chk_login_challenge_client`
    CHECK (REGEXP_LIKE(`client_key`, '^[a-z][a-z0-9-]{0,63}$', 'c'))
SQL);
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
        'tenant_count' => (int)$tenant->fetchColumn(),
        'owner_count' => (int)$owner->fetchColumn(),
        'operator_count' => (int)$pdo->query(
            "SELECT COUNT(*) FROM pa_platform_operator WHERE status = 'active'"
        )->fetchColumn(),
    ];
}

/** @param array<string, string> $website */
function seedBrandDefaults(PDO $pdo, array $website): void
{
    $statement = $pdo->prepare(
        'INSERT INTO pa_config (type, name, value, create_time, update_time) '
        . "VALUES ('website', ?, ?, ?, ?) "
        . 'ON DUPLICATE KEY UPDATE value = VALUES(value), update_time = VALUES(update_time)'
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
    return $files;
}

/**
 * Read source product, instance release-sequence and scaffold migration axes from the root contract.
 *
 * @return array{source_product_version:string,release_sequence_version:string,scaffold_template:string}
 */
function applicationReleaseVersions(string $serverDir): array
{
    loadCoreRuntime($serverDir);
    $contract = ApplicationReleaseVersions::load(dirname($serverDir) . '/release-versions.json');
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
        $overlay = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
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
    preg_match_all('/^\s*--\s*peanut-release\b[^\r\n]*$/mi', $sql, $markerLines);
    if (count($markerLines[0]) === 0) {
        return ['release_version' => $applicationVersion, 'peanut_release' => false];
    }
    if (count($markerLines[0]) !== 1
        || preg_match(
            '/^\s*--\s*peanut-release:\s*((0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*))\s*$/iD',
            $markerLines[0][0],
            $matches,
        ) !== 1
    ) {
        throw new RuntimeException('MIGRATION_RELEASE_MARKER_INVALID');
    }
    return ['release_version' => $matches[1], 'peanut_release' => true];
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
    loadCoreRuntime($serverDir);
    $config = loadConfig($serverDir);
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $config['DB_NAME'])) {
        throw new RuntimeException('DB_NAME 只能包含字母、数字和下划线');
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT'], $config['DB_NAME']),
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
    );
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pa_schema_migration'")->fetchColumn();
    if ($exists !== 1) {
        throw new RuntimeException('MIGRATION_LEDGER_MISSING: 目标数据库不是 3.0+ 基线，请使用 fresh 重建');
    }
    $lockName = 'peanut_migrate_' . substr(hash('sha256', $config['DB_NAME']), 0, 48);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('无法获取迁移锁，请稍后重试');
    }
    try {
        $pending = [];
        foreach (applicationMigrationFiles(__DIR__) as $file) {
            $id = basename($file, '.sql');
            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('迁移文件为空：' . $id);
            }
            $checksum = hash('sha256', $sql);
            $releaseIdentity = migrationReleaseIdentity($sql, $versions['release_sequence_version']);
            $releaseVersion = $releaseIdentity['release_version'];
            if ($releaseIdentity['peanut_release'] && version_compare($releaseVersion, $targetVersion, '>')) {
                continue;
            }
            $statement = $pdo->prepare('SELECT checksum,status FROM pa_schema_migration WHERE migration_id = ?');
            $statement->execute([$id]);
            $row = $statement->fetch();
            if (is_array($row)) {
                if (!hash_equals((string)$row['checksum'], $checksum)) {
                    throw new RuntimeException('MIGRATION_CHECKSUM_CHANGED: ' . $id);
                }
                if ($row['status'] === 'applied') {
                    continue;
                }
                if ($row['status'] === 'failed') {
                    throw new RuntimeException('MIGRATION_PREVIOUSLY_FAILED: ' . $id);
                }
                if ($row['status'] === 'applying') {
                    throw new RuntimeException('MIGRATION_INCOMPLETE: ' . $id);
                }
            }
            $pending[] = ['id' => $id, 'file' => $file, 'sql' => $sql, 'checksum' => $checksum, 'release_version' => $releaseVersion, 'status' => is_array($row) ? (string)$row['status'] : null];
        }
        if ($dryRun) {
            return ['status' => $pending === [] ? 'up_to_date' : 'ready', 'target_version' => $targetVersion, 'applied' => [], 'pending' => array_column($pending, 'id')];
        }
        $applied = [];
        foreach ($pending as $migration) {
            $now = gmdate('Y-m-d H:i:s');
            $pdo->prepare(
                'INSERT INTO pa_schema_migration (migration_id,release_version,checksum,status,started_at,finished_at,error_code) VALUES (?,?,?,?,?,NULL,NULL) '
                . 'ON DUPLICATE KEY UPDATE release_version=VALUES(release_version),checksum=VALUES(checksum),status=\'applying\',started_at=VALUES(started_at),finished_at=NULL,error_code=NULL'
            )->execute([$migration['id'], $migration['release_version'], $migration['checksum'], 'applying', $now]);
            try {
                $pdo->exec($migration['sql']);
                $pdo->prepare('UPDATE pa_schema_migration SET status=\'applied\',finished_at=?,error_code=NULL WHERE migration_id=?')->execute([gmdate('Y-m-d H:i:s'), $migration['id']]);
                $applied[] = $migration['id'];
            } catch (Throwable $exception) {
                $pdo->prepare('UPDATE pa_schema_migration SET status=\'failed\',finished_at=?,error_code=? WHERE migration_id=?')->execute([gmdate('Y-m-d H:i:s'), substr($exception->getMessage(), 0, 255), $migration['id']]);
                throw new RuntimeException('MIGRATION_FAILED: ' . $migration['id'], 0, $exception);
            }
        }
        return ['status' => $applied === [] ? 'up_to_date' : 'applied', 'target_version' => $targetVersion, 'applied' => $applied, 'pending' => []];
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function migrationArguments(array $arguments): ?array
{
    if (!in_array('--migrate', $arguments, true)) {
        return null;
    }
    $target = null;
    $dryRun = in_array('--dry-run', $arguments, true);
    foreach ($arguments as $argument) {
        if (preg_match('/^--target-version=(.+)$/D', (string)$argument, $matches) === 1) {
            $target = $matches[1];
        }
    }
    if ($target === null) {
        throw new RuntimeException('--migrate requires --target-version matching the adopted scaffold identity');
    }
    return [$target, $dryRun];
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
            $config['DB_NAME']
        ),
        $config['DB_USER'],
        $config['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $tableCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
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

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function installFreshDatabase(string $serverDir, array $input): array
{
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

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['DB_HOST'],
            $config['DB_PORT'],
            $database
        ),
        $config['DB_USER'],
        $config['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );

    $lockName = 'peanut_install_' . substr(hash('sha256', $database), 0, 48);
    $lockStatement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lockStatement->execute([$lockName]);
    if ((int)$lockStatement->fetchColumn() !== 1) {
        throw new RuntimeException('无法获取安装锁，请稍后重试');
    }

    try {
        $files = sqlFiles($databaseDir);
        $expected = expectedTables($files);
        $tableCount = (int)$pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchColumn();
        if ($tableCount !== 0) {
            throw new RuntimeException('目标数据库不是空库，已拒绝执行首次安装');
        }

        $adminEmail = $credentials['admin_email'];
        $adminPassword = $credentials['admin_password'];
        $platformCredentials = $credentials['platform_credentials'];
        $demoAccounts = new \app\common\policy\DemoAccountPolicy(
            getenv('PEANUT_DEMO_MODE') === 'enabled',
            array_values(array_filter([
                $adminEmail,
                $platformCredentials['email'] ?? '',
            ])),
        );
        $coreIdentity = initializeCoreIdentity(
            $pdo,
            $adminEmail,
            $adminPassword,
            $platformCredentials,
            $demoAccounts,
            $tenantBootstrap,
        );
        if ($demoAccounts->enabled()) {
            replaceInstalledDemoCredentialHashes($pdo, [
                $adminEmail,
                $platformCredentials['email'] ?? '',
            ], $demoAccounts->credentialHash());
        }
        executeSqlFiles($pdo, $files);
        seedBrandDefaults($pdo, brandWebsiteDefaults($serverDir));

        $actual = array_map('strval', $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($expected, $actual));
        $activeMenus = (int)$pdo->query('SELECT COUNT(*) FROM pa_system_menu')->fetchColumn();
        $configCount = (int)$pdo->query('SELECT COUNT(*) FROM pa_config')->fetchColumn();
        $identityCounts = coreIdentityCounts($pdo, $tenantBootstrap['code']);
        if ($missing !== []
            || $activeMenus === 0
            || $configCount === 0
            || $identityCounts !== ['tenant_count' => 1, 'owner_count' => 1, 'operator_count' => 1]) {
            throw new RuntimeException('安装结果不完整');
        }

        return [
            'database' => $database,
            'baseline' => 'init.sql',
            'tables' => count($actual),
            'expected_tables' => count($expected),
            'active_menus' => $activeMenus,
            'configs' => $configCount,
            'tenant_bootstrap' => $tenantBootstrap,
            'default_tenant_id' => $coreIdentity['tenant_id'],
            'owner_account_id' => $coreIdentity['account_id'],
            'owner_member_id' => $coreIdentity['member_id'],
        ];
    } finally {
        $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStatement->execute([$lockName]);
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
        $migration = migrationArguments($_SERVER['argv'] ?? []);
        if ($migration !== null) {
            echo json_encode(migrateDatabase(dirname(__DIR__), $migration[0], $migration[1]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
            exit(0);
        }
        $application = ensureThinkPhpApplication(dirname(__DIR__));
        $host = $application->make(\app\common\services\installation\InstallationExecutionHost::class);
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
        $result = $host->executeAutomatic(automaticInstallationInput());
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    } catch (\app\common\exception\installation\InstallationExecutionException $exception) {
        fwrite(STDERR, '安装失败：' . $exception->errorCode . PHP_EOL);
        exit(1);
    } catch (Throwable $exception) {
        fwrite(STDERR, '安装失败：INSTALL_EXECUTION_FAILED' . PHP_EOL);
        exit(1);
    }
}
