<?php
declare(strict_types=1);

// The fast CI fixture scanner executes every PHP fixture directly. This inspector
// is active only when the MT05 harness supplies an explicit matrix mode.
if (getenv('MT05_MODE') === false || getenv('MT05_MODE') === '') {
    return;
}

require_once dirname(__DIR__, 3) . '/bootstrap/environment.php';

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing environment: {$name}");
    }
    return $value;
}

function expectInvariant(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([$table]);
    return (int)$statement->fetchColumn() === 1;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $statement->execute([$table, $index]);
    return (int)$statement->fetchColumn() > 0;
}

/** @return list<string> */
function expectedApplicationMigrationIds(string $serverRoot): array
{
    require_once $serverRoot . '/app/common/value/installation/ApplicationReleaseVersions.php';
    $targetVersion = \app\common\value\installation\ApplicationReleaseVersions::load(
        dirname($serverRoot) . '/release-versions.json'
    )->scaffoldTemplate();
    expectInvariant(
        is_string($targetVersion)
            && preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $targetVersion) === 1,
        'MT05_APPLICATION_VERSION_INVALID'
    );

    $migrationFiles = glob($serverRoot . '/database/migrations/*.sql');
    expectInvariant(
        is_array($migrationFiles) && $migrationFiles !== [],
        'MT05_APPLICATION_MIGRATION_SOURCE_INVALID'
    );
    $expected = [];
    foreach ($migrationFiles as $migrationFile) {
        $sql = file_get_contents($migrationFile);
        expectInvariant(is_string($sql) && trim($sql) !== '', 'MT05_APPLICATION_MIGRATION_SOURCE_INVALID');
        $releaseVersion = $targetVersion;
        if (preg_match('/^\s*--\s*peanut-release:\s*(\d+\.\d+\.\d+)\s*$/mi', $sql, $matches) === 1) {
            $releaseVersion = $matches[1];
        }
        if (version_compare($releaseVersion, $targetVersion, '<=')) {
            $expected[] = basename($migrationFile, '.sql');
        }
    }
    sort($expected, SORT_STRING);
    expectInvariant($expected !== [], 'MT05_APPLICATION_MIGRATION_SOURCE_INVALID');
    return $expected;
}

try {
    $mode = requiredEnvironment('MT05_MODE');
    $deploymentMode = requiredEnvironment('MT05_DEPLOYMENT_MODE');
    $candidate = requiredEnvironment('MT05_CANDIDATE');
    $runId = requiredEnvironment('MT05_RUN_ID');
    $database = requiredEnvironment('DB_NAME');

    expectInvariant(
        in_array($mode, ['standalone-empty', 'v1-forward', 'multitenant-empty'], true),
        'MT05_MODE_INVALID'
    );
    expectInvariant(
        in_array($deploymentMode, ['standalone', 'multi-tenant'], true),
        'MT05_DEPLOYMENT_MODE_INVALID'
    );
    expectInvariant(preg_match('/^[0-9a-f]{40}$/', $candidate) === 1, 'MT05_CANDIDATE_INVALID');

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            requiredEnvironment('DB_HOST'),
            requiredEnvironment('DB_PORT'),
            $database
        ),
        requiredEnvironment('DB_USER'),
        requiredEnvironment('DB_PASS'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    expectInvariant(tableExists($pdo, 'pa_schema_migration'), 'MT05_APPLICATION_MIGRATION_LEDGER_MISSING');
    $applicationMigrationIds = $pdo->query(
        "SELECT migration_id FROM pa_schema_migration WHERE status = 'applied' ORDER BY migration_id"
    )->fetchAll(PDO::FETCH_COLUMN);
    $expectedApplicationMigrationIds = expectedApplicationMigrationIds(dirname(__DIR__, 3));
    expectInvariant(
        $applicationMigrationIds === $expectedApplicationMigrationIds,
        'MT05_APPLICATION_MIGRATION_LEDGER_INVALID'
    );
    $applicationMigrationCount = count($applicationMigrationIds);

    foreach ([
        'pa_account',
        'pa_tenant',
        'pa_tenant_member',
        'pa_credential',
        'pa_role',
        'pa_member_role',
        'pa_platform_operator',
        'pa_module_installation',
        'pa_tenant_module',
        'pa_module_migration',
        'pa_tenant_setting',
        'pa_tenant_idempotency_record',
        'pa_system_dict_type',
        'pa_system_dict_data',
    ] as $requiredTable) {
        expectInvariant(tableExists($pdo, $requiredTable), 'MT05_REQUIRED_TABLE_MISSING:' . $requiredTable);
    }

    $multiTenantOnlyTables = [
        'pa_tenant_entry_binding',
        'pa_tenant_owner_invitation',
        'pa_provider_qualification_evidence',
    ];
    foreach ($multiTenantOnlyTables as $table) {
        expectInvariant(
            tableExists($pdo, $table) === ($deploymentMode === 'multi-tenant'),
            ($deploymentMode === 'multi-tenant'
                ? 'MT05_REQUIRED_TABLE_MISSING:'
                : 'MT05_STANDALONE_FORBIDDEN_TABLE_PRESENT:') . $table
        );
    }

    $requiredIndexes = [
        ['pa_tenant_setting', 'uk_tenant_setting_namespace'],
        ['pa_tenant_idempotency_record', 'uk_tenant_idempotency'],
        ['pa_refund_record', 'idx_refund_record_tenant_order_amount'],
        ['pa_system_dict_type', 'uk_system_dict_type_code'],
        ['pa_system_dict_data', 'uk_system_dict_data_type_value'],
    ];
    if ($deploymentMode === 'multi-tenant') {
        $requiredIndexes = [
            ...$requiredIndexes,
            ['pa_tenant_entry_binding', 'uk_tenant_entry_binding'],
            ['pa_tenant_owner_invitation', 'uk_owner_invitation_pending_tenant'],
            ['pa_provider_qualification_evidence', 'uk_provider_qualification_evidence_key'],
            ['pa_provider_qualification_evidence', 'idx_provider_qualification_subject_observed'],
            ['pa_provider_qualification_evidence', 'idx_provider_qualification_expiry'],
        ];
    }
    foreach ($requiredIndexes as [$table, $index]) {
        expectInvariant(indexExists($pdo, $table, $index), 'MT05_REQUIRED_INDEX_MISSING:' . $index);
    }

    $systemDictionaryTypes = (int)$pdo->query(
        "SELECT COUNT(*) FROM pa_system_dict_type "
        . "WHERE code IN ('member_sex','member_status','member_channel','payment_status','refund_status')"
    )->fetchColumn();
    expectInvariant($systemDictionaryTypes === 5, 'MT05_SYSTEM_DICTIONARY_TYPE_SEED_INVALID');
    $systemDictionaryData = (int)$pdo->query(
        "SELECT COUNT(*) FROM pa_system_dict_data "
        . "WHERE type_code IN ('member_sex','member_status','member_channel','payment_status','refund_status')"
    )->fetchColumn();
    expectInvariant($systemDictionaryData === 16, 'MT05_SYSTEM_DICTIONARY_DATA_SEED_INVALID');

    $tableCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )->fetchColumn();
    expectInvariant($tableCount > 0, 'MT05_TABLE_COUNT_EMPTY');

    $defaultRows = $pdo->query(
        "SELECT id, code, name, display_name, status FROM pa_tenant WHERE code = 'default' ORDER BY id"
    )->fetchAll();
    expectInvariant(count($defaultRows) === 1, 'MT05_DEFAULT_TENANT_COUNT_INVALID');
    $defaultTenant = $defaultRows[0];
    expectInvariant((string)$defaultTenant['status'] === 'active', 'MT05_DEFAULT_TENANT_NOT_ACTIVE');

    $ownerStatement = $pdo->prepare(<<<'SQL'
SELECT t.id AS tenant_id, tm.account_id AS owner_account_id, tm.id AS owner_member_id,
       a.status AS account_status, tm.status AS member_status, c.status AS credential_status,
       COUNT(DISTINCT r.id) AS owner_role_count
FROM pa_tenant t
JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.status = 'active'
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_member_role mr
  ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r
  ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
 AND r.`key` = 'core.tenant-owner' AND r.is_builtin = 1 AND r.status = 'active'
WHERE t.id = ? AND t.code = 'default' AND t.status = 'active'
GROUP BY t.id, tm.account_id, tm.id, a.status, tm.status, c.status
SQL);
    $ownerStatement->execute([(int)$defaultTenant['id']]);
    $owners = $ownerStatement->fetchAll();
    expectInvariant(count($owners) === 1, 'MT05_DEFAULT_OWNER_COUNT_INVALID');
    $owner = $owners[0];
    expectInvariant((string)$owner['account_status'] === 'active', 'MT05_OWNER_ACCOUNT_NOT_ACTIVE');
    expectInvariant((string)$owner['member_status'] === 'active', 'MT05_OWNER_MEMBER_NOT_ACTIVE');
    expectInvariant((string)$owner['credential_status'] === 'active', 'MT05_OWNER_CREDENTIAL_NOT_ACTIVE');
    expectInvariant((int)$owner['owner_role_count'] === 1, 'MT05_OWNER_ROLE_INVALID');

    $platformOperator = null;
    if ($mode === 'multitenant-empty') {
        $platformOperators = $pdo->query(<<<'SQL'
SELECT po.id, po.account_id, po.display_name, po.status, a.status AS account_status
FROM pa_platform_operator po
JOIN pa_account a ON a.id = po.account_id
WHERE po.status = 'active'
ORDER BY po.id
SQL)->fetchAll();
        expectInvariant(count($platformOperators) === 1, 'MT05_PLATFORM_OPERATOR_COUNT_INVALID');
        $platformOperator = $platformOperators[0];
        expectInvariant(
            (int)$platformOperator['account_id'] !== (int)$owner['owner_account_id'],
            'MT05_PLATFORM_OPERATOR_REUSES_DEFAULT_OWNER_ACCOUNT'
        );
        expectInvariant(
            (string)$platformOperator['account_status'] === 'active',
            'MT05_PLATFORM_OPERATOR_ACCOUNT_NOT_ACTIVE'
        );
        $platformMembershipStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM pa_tenant_member WHERE account_id = ?'
        );
        $platformMembershipStatement->execute([(int)$platformOperator['account_id']]);
        expectInvariant(
            (int)$platformMembershipStatement->fetchColumn() === 0,
            'MT05_PLATFORM_OPERATOR_HAS_TENANT_MEMBERSHIP'
        );
    }

    $moduleInstallationCount = (int)$pdo->query('SELECT COUNT(*) FROM pa_module_installation')->fetchColumn();
    $tenantModuleCount = (int)$pdo->query('SELECT COUNT(*) FROM pa_tenant_module')->fetchColumn();
    $enabledTenantModuleCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM pa_tenant_module WHERE status = 'enabled'"
    )->fetchColumn();
    $invalidModuleInstallations = (int)$pdo->query(
        "SELECT COUNT(*) FROM pa_module_installation "
        . "WHERE status NOT IN ('installing','active','upgrading','maintenance','failed')"
    )->fetchColumn();
    $invalidTenantModules = (int)$pdo->query(
        "SELECT COUNT(*) FROM pa_tenant_module WHERE status NOT IN ('disabled','enabled','expired')"
    )->fetchColumn();
    $orphanTenantModules = (int)$pdo->query(<<<'SQL'
SELECT COUNT(*) FROM pa_tenant_module tm
LEFT JOIN pa_tenant t ON t.id = tm.tenant_id
LEFT JOIN pa_module_installation mi ON mi.module_key = tm.module_key
WHERE t.id IS NULL OR mi.id IS NULL
SQL)->fetchColumn();
    expectInvariant($invalidModuleInstallations === 0, 'MT05_MODULE_INSTALLATION_STATUS_INVALID');
    expectInvariant($invalidTenantModules === 0, 'MT05_TENANT_MODULE_STATUS_INVALID');
    expectInvariant($orphanTenantModules === 0, 'MT05_TENANT_MODULE_REFERENCE_INVALID');

    $result = [
        'schema_version' => 1,
        'candidate_commit' => $candidate,
        'run_id' => $runId,
        'mode' => $mode,
        'deployment_mode' => $deploymentMode,
        'database' => $database,
        'fresh_schema' => [
            'application_migration_ledger_applied_count' => $applicationMigrationCount,
            'system_dictionary_type_count' => $systemDictionaryTypes,
            'system_dictionary_data_count' => $systemDictionaryData,
        ],
        'table_count' => $tableCount,
        'default_tenant' => $defaultTenant,
        'owner' => $owner,
        'module_baseline' => [
            'installation_count' => $moduleInstallationCount,
            'tenant_module_count' => $tenantModuleCount,
            'enabled_tenant_module_count' => $enabledTenantModuleCount,
            'invalid_installation_status_count' => $invalidModuleInstallations,
            'invalid_tenant_module_status_count' => $invalidTenantModules,
            'orphan_tenant_module_count' => $orphanTenantModules,
        ],
        'status' => 'passed',
    ];
    if ($platformOperator !== null) {
        $result['platform_operator'] = $platformOperator;
    }
    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'MT05 invariant failure: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
