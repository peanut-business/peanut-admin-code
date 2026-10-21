<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use PDO;
use RuntimeException;
use app\platform\services\plugin\PluginLifecycleService;

/** Existing native mysqldump for admitted application/Plugin effects and bounded partial installation. */
final class SourceApplicationBackup
{
    // ModuleCatalogApplier.apply always synchronizes core permissions/conditions,
    // even for a single selected Module. These are whole shared catalogs, not
    // module_key-filtered recovery data. No tenant settings values are written.
    private const CATALOG_TABLES = [
        'pa_permission', 'pa_target_type', 'pa_data_condition_definition', 'pa_protected_resource',
        'pa_resource_operation', 'pa_resource_operation_permission', 'pa_resource_operation_target_type',
        'pa_resource_operation_condition', 'pa_menu_definition', 'pa_setting_definition', 'pa_reference_code_set',
        'pa_plugin_installation', 'pa_module_installation', 'pa_plugin_module',
    ];
    private const FILE_STAGES = [
        'official.file:20260823-unify-storage-service' => ['f79c35b2fe8414dc172a81a45183d2a52eadb05833a704149780b36e400ca2d4', []],
        'official.file:20260921020000_add_file_image_assets' => ['826fa9d8fd841b6834bc5f23cabced30b7ca111de4bb1f629ccbdb8501200cd4', ['pa_file_object', 'pa_file_image_asset', 'pa_file_derivative', 'pa_file_delivery_nonce']],
        'official.file:20261012030000_add_controlled_file_references' => ['005edad54fc0cf06a4eeb6afa7c0e5f374a0bc352eaa088282b2f12932251591', ['pa_file_delivery_grant', 'pa_file_reference']],
        'official.file:20261012051000_add_immutable_document_snapshots' => ['7b73c0c24f14f4c7f4fc1f75321e009b1a645d7df3f7d653dbb3d018e3b5bdbe', ['pa_file_immutable_snapshot']],
    ];
    private const DOCUMENT_STAGES = [
        'official.document:20261012050000_create_business_documents' => ['103003e0a0cbb8ec11ed2d1f039772500d7c610ad9b66bc19623fb012fa31f00',
            ['pa_document', 'pa_document_state', 'pa_document_revision', 'pa_document_file_ref', 'pa_document_resource_binding', 'pa_document_operation']],
        'official.document:20261013120000_add_document_business_barriers' => ['e015617440e433d0d443f7c4c13b2bf02e35b4403e25c1009747d5d1d707f579',
            ['pa_document_operation']],
    ];
    private const ARTICLE_STAGES = [
        'official.article:20261013230000_add_article_document_save_binding' => ['67e504df714ee1a1d0cc6959506eaa584cfaeb0ea26c3e95597586b5ec2576fe',
            ['pa_article', 'pa_article_body_save']],
    ];
    private const MEMBER_STAGES = [
        'official.member:20260921-create-member-session' => ['297fc9de7bc227d584cb79fe6e5d50f6cf8512b33696578acbb3f10243b65337',
            ['pa_member', 'pa_member_session']],
    ];
    private const IMPORT_EXPORT_STAGES = [
        'official.import-export:20260921-adopt-import-export-schema' => ['ad77642648ef414871258f1ff2ec61a9586d115651a3c883b7986e58b0d4c8ba',
            ['pa_import_export_operation', 'pa_import_export_row_error']],
    ];
    private const MODULE_DDL_STAGES = [
        'official.identity:20260716020101_create_pa_data_permission_policy' => ['9ccbb19f70c230b27f97d1eeac818dfd08197406664cdf85ddd63a25cb51750e',
            ['pa_data_permission_policy'], 'PeanutAdmin\\Modules\\Identity\\Database\\IdentitySchemaMigrationProof'],
        'official.identity:20260716020102_create_pa_data_permission_group' => ['bac2408f49ffcfa3470e854b7550b5197d9cc9cf4c96ac74dd732572b9f91cbd',
            ['pa_data_permission_group'], 'PeanutAdmin\\Modules\\Identity\\Database\\IdentitySchemaMigrationProof'],
        'official.identity:20260716020103_create_pa_data_permission_target_set' => ['d34eecc5886ee842b78e44556a8029487668a22b32190c1d275137dd9e3c2708',
            ['pa_data_permission_target_set'], 'PeanutAdmin\\Modules\\Identity\\Database\\IdentitySchemaMigrationProof'],
        'official.identity:20260716020104_create_pa_data_permission_condition' => ['52b4836e3be7e87a6ccc5f5eab410778f947fa892f0708b2fd3f332989f54530',
            ['pa_data_permission_condition'], 'PeanutAdmin\\Modules\\Identity\\Database\\IdentitySchemaMigrationProof'],
        'official.identity:20260716020105_create_pa_data_permission_target' => ['baa95b025a3bdae3ee9e56f40018443f1927959f41c71828fd40bca96bd9078e',
            ['pa_data_permission_target'], 'PeanutAdmin\\Modules\\Identity\\Database\\IdentitySchemaMigrationProof'],
        'official.identity:20260921020000_create_pa_source_read_grant' => ['e59e69ed167d4f565dd7e9b60284f69b021e93a9826fdf1f9b9a35a7bf6e7c0c',
            ['pa_source_read_grant'], 'PeanutAdmin\\Modules\\Identity\\Database\\IdentitySchemaMigrationProof'],
        'official.identity:20261007010000_department_contact_fields' => ['47626b595811ced52d25b9eb7438fc4ba9cb921a76272e47c073c2faae11b9ee',
            ['pa_department'], 'PeanutAdmin\\Modules\\Identity\\Database\\IdentitySchemaMigrationProof'],
        'official.identity:20261012010000_department_leader_member' => ['909c7bd6885a93cf2975ecbbb108cbbe993bc1e0b47f2590292968a886051532',
            ['pa_department'], 'PeanutAdmin\\Modules\\Identity\\Database\\IdentitySchemaMigrationProof'],
        'official.identity:20261015010000_index_revocation_audit_facts' => ['eacd6eddd89a9f064688ff45ba67210d896a65bbeaea1448394e7b6df3c271aa',
            ['pa_tenant_audit_event'], 'PeanutAdmin\\Modules\\Identity\\Database\\IdentitySchemaMigrationProof'],
        'peanut.workflow:20260921-register-module-schema' => ['f0a21d63c83afcd2438fc327bf6ad97a59d82daa571bc4de51d74c32eb487ff7',
            ['pa_workflow_definition', 'pa_workflow_definition_version', 'pa_workflow_instance', 'pa_workflow_work_item', 'pa_workflow_event'],
            'PeanutAdmin\\Modules\\Workflow\\Database\\WorkflowSchemaMigrationProof'],
        'peanut.workflow:20261012-workflow-managed-template' => ['fd02893a4b7a0ea60aa6c5434ff8b46f11e35bec94f10a76fc0b0f64609282a5',
            ['pa_workflow_definition'], 'PeanutAdmin\\Modules\\Workflow\\Database\\WorkflowSchemaMigrationProof'],
        'peanut.workflow:20261012-workflow-notification-facts' => ['ff26361f27773b9500fe6484729a26c0655d18f893cac8739bae9edfec8f6378',
            ['pa_workflow_event'], 'PeanutAdmin\\Modules\\Workflow\\Database\\WorkflowSchemaMigrationProof'],
        'peanut.workflow:20261012-workflow-v2-execution' => ['f46f8b9a7a567584988e3520669daa1a8f893b69d837a1dedf223deb4f2a1b09',
            ['pa_workflow_definition', 'pa_workflow_definition_version', 'pa_workflow_instance', 'pa_workflow_work_item', 'pa_workflow_event', 'pa_workflow_node_execution', 'pa_workflow_task_identity_link'],
            'PeanutAdmin\\Modules\\Workflow\\Database\\WorkflowSchemaMigrationProof'],
        'official.notification:20260921-adopt-notification-delivery-schema' => ['eaef582cd762c803f3f7d213687f61d43fd3634e9f0b984471f11648e10979fb',
            ['pa_notification_template', 'pa_notification_message', 'pa_notification_attachment', 'pa_notification_outbox', 'pa_sms_rate_bucket', 'pa_notification_event'],
            'PeanutAdmin\\Modules\\Notification\\Contract\\NotificationUpgradePreflight'],
        'official.notification:20261012-notification-producer-identity' => ['7c01bc1bf1f309e879ff3014f89418157abbce45374a58064d1a6dc94fca07ce',
            ['pa_notification_message', 'pa_notification_task_actor'], 'PeanutAdmin\\Modules\\Notification\\Contract\\NotificationUpgradePreflight'],
        'official.task:20260921-adopt-task-job-schema' => ['f04762aeab007bea0b0c4ce277d8f7781a694b17891f1193f303d0481366f21a',
            ['pa_task_job', 'pa_task_job_attempt', 'pa_task_job_event'], 'PeanutAdmin\\Modules\\Task\\Job\\Database\\TaskSchemaMigrationProof'],
        'official.task:20261015020000_add_negative_fact_job_profile' => ['2217e8a0cfc44e2c4c6c1a76a0413ed2448c5695e32f2a31a6a2a3072f44dbd4',
            ['pa_task_job', 'pa_task_job_event'], 'PeanutAdmin\\Modules\\Task\\Job\\Database\\TaskSchemaMigrationProof'],
    ];
    private const TABLES = [
        '20260909-notification-sms-reservation' => ['pa_notice_log'],
        '20261004-group-default-navigation' => ['pa_system_menu', 'pa_menu_definition'],
        '20261004-menu-stable-identities' => ['pa_system_menu', 'pa_menu_definition'],
        '20261007-auth-client-schema' => ['pa_tenant_session', 'pa_auth_security_event'],
        '20261007-readiness-permission' => ['pa_permission'],
        '20261012-align-department-canonical-permissions' => ['pa_permission', 'pa_role_permission', 'pa_role', 'pa_tenant', 'pa_system_menu'],
        '20261012-align-platform-ops-permission-ownership' => ['pa_permission'],
        '20261012-repair-seeded-permission-parents' => ['pa_system_menu'],
        '20261019-module-migration-provenance-metadata' => ['pa_module_migration'],
    ];

    /** Exact original table union; never expands from caller metadata or SQL text. */
    public static function applicationTables(array $pending): array
    {
        if (!array_is_list($pending) || count(array_filter($pending, 'is_string')) !== count($pending)
            || count(array_unique($pending, SORT_STRING)) !== count($pending)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SOURCE_UNSUPPORTED');
        }
        $tables = $pending === [] ? [] : ['pa_schema_migration'];
        foreach ($pending as $id) {
            if (!is_string($id) || !isset(self::TABLES[$id])) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SOURCE_UNSUPPORTED'); }
            array_push($tables, ...self::TABLES[$id]);
        }
        $tables = array_values(array_unique($tables)); sort($tables, SORT_STRING);
        return $tables;
    }

    /** Schema observations, never a backup receipt or implicit restore admission. */
    public static function scope(PDO $pdo, array $pending, ?array $binding = null, string $metadataTool = '', ?callable $guard = null): array
    {
        $tables = self::applicationTables($pending);
        if ($pending !== [] && $metadataTool === '') {
            self::assertEffectMetadataVisibility($pdo);
        }
        $scope = $metadataTool === '' || $tables === [] ? self::observe($pdo, $tables, false)
            : self::sourceEffectScope($pdo, $tables, false, $binding ?? [], $metadataTool, $guard);
        $scope['execution_requirements'] = [];
        foreach ($pending as $id) {
            array_push($scope['execution_requirements'], ...self::applicationStageRequirements($scope, $id));
        }
        return $scope;
    }

    /** Live source issuer, using the existing fixed remote metadata reader. No caller receipt or SQL is accepted. */
    private static function sourceEffectScope(PDO $pdo, array $tables, bool $allowMissing, array $binding, string $toolId, ?callable $guard): array
    {
        $root = $binding['source']['root'] ?? '';
        $serverRoot = $root . '/server';
        $context = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        if (PHP_SAPI !== 'cli' || !$context instanceof \app\common\execution\InstanceExecutionContext
            || !in_array($context->operation(), ['console.schema:application', 'console.plugin:install', 'console.plugin:upgrade'], true)
            || $context->instance->actorKey !== 'console' || !is_callable($guard)
            || realpath($root) !== $root || realpath($serverRoot) !== $serverRoot || $tables === []) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_CONTEXT_REQUIRED');
        }
        $actor = array_intersect_key($binding['authority'] ?? [], array_flip(['owner', 'thread', 'session', 'gate']));
        $admission = new SourceDevelopmentMaintenanceAdmission($serverRoot);
        $ddl = in_array($binding['authority']['operation'] ?? null, ['ddl', 'exclusive'], true);
        $recheck = static function () use ($pdo, $binding, $actor, $admission, $ddl, $guard, $root): void {
            $guard();
            $actual = $admission->capture($actor, $ddl) + ['database' => \app\common\services\installation\InstallationExecutionHost::migrationDatabaseIdentity($pdo)];
            if (self::pluginDigest($actual) !== self::pluginDigest($binding) || $pdo->inTransaction()) {
                throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_BINDING_CHANGED');
            }
            \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::sourceProductLock($root, static fn() => null);
            $lock = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            try {
                $lock->execute(['peanut_migrate_' . substr(hash('sha256', $binding['database']['database']), 0, 48)]);
                if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_LOCK_REQUIRED'); }
            } finally { $lock->closeCursor(); }
        };
        $recheck();
        $registry = \projectResourceRegistry($registrySha);
        $tools = array_values(array_filter($registry['resources']['tooling'] ?? [], static fn(array $row): bool => ($row['stable_resource_id'] ?? null) === $toolId));
        $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        $parent = $serverRoot . '/private/installation/source-development/effect-metadata';
        if (count($tools) !== 1 || $registrySha !== $binding['registry_sha256']
            || !in_array($toolId, $resources['tooling-resource-id'] ?? [], true)
            || !in_array($parent, $resources['output-dir'] ?? [], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_NATIVE_TOOL_OUTPUT_REQUIRED');
        }
        $tool = $tools[0]; $contract = $tool['source_schema_backup'] ?? [];
        $database = $binding['database']['database'];
        if (($tool['transport'] ?? null) !== 'ssh-docker-exec'
            || ($contract['database_resource_id'] ?? null) !== $binding['resource_id'] || ($contract['database'] ?? null) !== $database
            || !is_array($contract['tables'] ?? null) || array_diff($tables, $contract['tables']) !== []) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_TOOL_REQUIRED');
        }
        foreach (['ssh_command', 'docker_command', 'mysql_command', 'timeout_command', 'digest_command'] as $key) {
            if (!is_string($tool[$key] ?? null) || preg_match('~\A/[A-Za-z0-9_./-]+\z~D', $tool[$key]) !== 1 || str_contains($tool[$key], '..')) {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_INVALID');
            }
        }
        foreach (['ssh_alias', 'container_name'] as $key) {
            if (!is_string($tool[$key] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/D', $tool[$key]) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_INVALID');
            }
        }
        $seconds = $contract['timeout_seconds'] ?? null;
        if (!is_int($seconds) || $seconds < 30 || $seconds > 600) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_LIFETIME_REQUIRED'); }
        sort($tables, SORT_STRING);
        if (count(array_unique($tables)) !== count($tables) || count($tables) > 512) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TABLE_UNSUPPORTED'); }
        $present = [];
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/\Apa_[a-z0-9_]+\z/D', $table) !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TABLE_UNSUPPORTED'); }
            $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $query->execute([$table]); $exists = (int) $query->fetchColumn(); $query->closeCursor();
            if ($exists === 1) { $present[] = $table; }
            elseif (!$allowMissing) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TABLE_UNSUPPORTED'); }
        }
        // Metadata reads do not grant row access. The same native declaration must
        // already cover every present source table in full; DDL/exclusive is stronger.
        $covered = in_array($binding['authority']['operation'] ?? null, ['ddl', 'exclusive'], true);
        foreach ($resources['mysql-access'] ?? [] as $raw) {
            $access = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (($access['schema'] ?? null) !== \requiredEnvironment('DB_HOST') . ':' . \requiredEnvironment('DB_PORT') . '/' . $database
                || ($access['complete'] ?? null) !== true) { continue; }
            $reads = array_column(array_filter($access['tables'] ?? [], static fn(array $row): bool => ($row['rows'] ?? null) === '*'
                && in_array($row['access'] ?? null, ['read', 'write'], true)), 'table');
            $covered = $covered || array_diff($present, $reads) === [];
        }
        if (!$covered) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_READ_SCOPE_REQUIRED'); }
        $session = $pdo->query('SELECT @@SESSION.sql_mode AS sql_mode,@@SESSION.sql_quote_show_create AS quote_names')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($session) || preg_match('/\A[A-Z0-9_,]*\z/D', (string) ($session['sql_mode'] ?? '')) !== 1
            || !in_array((int) ($session['quote_names'] ?? -1), [0, 1], true)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_SESSION_INVALID'); }
        foreach ([$serverRoot . '/private', $serverRoot . '/private/installation'] as $directory) {
            if (realpath($directory) !== $directory || !is_dir($directory) || (fileperms($directory) & 0022) !== 0
                || !in_array(fileowner($directory), [0, fileowner($root)], true)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_DIRECTORY_REQUIRED'); }
        }
        foreach ([$serverRoot . '/private/installation/source-development', $parent] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0700)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_DIRECTORY_REQUIRED'); }
            if (realpath($directory) !== $directory || (fileperms($directory) & 0777) !== 0700
                || !in_array(fileowner($directory), [0, fileowner($root)], true)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_DIRECTORY_REQUIRED'); }
        }
        $nonce = bin2hex(random_bytes(16)); $directory = $parent . '/' . $nonce;
        if (!mkdir($directory, 0700)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_DIRECTORY_REQUIRED'); }
        $request = ['protocol' => 'peanut.source-development-effect-request.v1', 'binding' => $binding,
            'tool_id' => $toolId, 'tool_sha256' => self::pluginDigest($tool), 'tables' => $tables, 'present' => $present,
            'session' => $session, 'adapter_sha256' => hash_file('sha256', __FILE__), 'nonce' => $nonce, 'observed_at' => time()];
        self::write($directory . '/request.json', $request);
        $shell = self::quote([$tool['digest_command'], $tool['mysql_command']]) . ' && '
            . self::quote([$tool['mysql_command'], '--version']) . ' && ('
            . self::clientShell($tool, 'mysql_command', $seconds, ['--no-defaults', '--no-login-paths', '--default-character-set=utf8mb4',
                '--batch', '--skip-column-names', '-uroot', '--database=' . $database,
                '--execute=' . self::metadataSql($present, $session, $nonce, $tables)]) . ')';
        if (strlen($shell) > 65536) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_COVERAGE_REQUIRED'); }
        $output = $directory . '/metadata.txt'; $error = $directory . '/metadata.stderr';
        self::remote($tool, $shell, $output, $error, 'effect-metadata', $seconds, (int) $binding['authority']['expires_at'], $recheck, 8388608);
        $parsed = self::parseMetadata($output, $present, $nonce, $binding, $contract, (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
        foreach ($parsed['metadata']['foreign_keys'] as $foreign) {
            if (!(($foreign['TABLE_SCHEMA'] === $database && in_array($foreign['TABLE_NAME'], $tables, true))
                || ($foreign['REFERENCED_TABLE_SCHEMA'] === $database && in_array($foreign['REFERENCED_TABLE_NAME'], $tables, true)))) {
                throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_COVERAGE_CHANGED');
            }
        }
        foreach ($parsed['metadata']['triggers'] as $trigger) {
            if (!in_array($trigger['table'], $tables, true)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_COVERAGE_CHANGED'); }
        }
        $scope = self::observe($pdo, $tables, $allowMissing, $parsed['metadata']);
        foreach ($scope['objects'] as $table => $object) {
            if (($object['exists'] ?? null) === false ? isset($parsed['metadata']['objects'][$table])
                : $object !== ($parsed['metadata']['objects'][$table] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_METADATA_STRUCTURE_CHANGED'); }
        }
        $recheck();
        self::write($directory . '/observation.json', ['protocol' => 'peanut.source-development-effect-metadata.v1',
            'request_sha256' => hash_file('sha256', $directory . '/request.json'), 'metadata_sha256' => self::pluginDigest($parsed['metadata']),
            'client' => $parsed['client'], 'process_sha256' => hash_file('sha256', $output . '.process.json'), 'observed_at' => time()]);
        $scope['metadata_visibility'] = 'complete native source metadata; ordinary PDO unchanged';
        $scope['metadata_observer'] = ['tool_id' => $toolId, 'tool_sha256' => self::pluginDigest($tool), 'client' => $parsed['client']];
        return $scope;
    }

    private static function assertEffectMetadataVisibility(PDO $pdo): void
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $globalSelect = false; $triggerVisibility = false; $restricted = false;
        foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_NUM) as $row) {
            $grant = count($row) === 1 ? $row[0] : null;
            if (is_string($grant) && str_starts_with($grant, 'REVOKE ')) { $restricted = true; }
            if (!is_string($grant) || preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match) !== 1) { continue; }
            $privileges = array_map('trim', explode(',', $match[1]));
            $all = in_array('ALL PRIVILEGES', $privileges, true);
            $globalSelect = $globalSelect || ($match[2] === '*.*' && ($all || in_array('SELECT', $privileges, true)));
            $triggerVisibility = $triggerVisibility || (in_array($match[2], ['*.*', '`' . $database . '`.*'], true)
                && ($all || in_array('TRIGGER', $privileges, true)));
        }
        // Schema-only grants can hide inbound FKs from another schema.
        // Unknown visibility blocks this maintenance path; never grant privileges here.
        if (!$globalSelect || !$triggerVisibility || $restricted) { throw new RuntimeException('APPLICATION_SCHEMA_EFFECT_VISIBILITY_REQUIRED'); }
    }

    /** A whole-schema dump covers neither an unreviewed definer trigger nor another schema. */
    public static function partialInstallationScope(PDO $pdo, array $tables): array
    {
        try { self::assertEffectMetadataVisibility($pdo); }
        catch (RuntimeException $exception) { throw new RuntimeException('INSTALL_PARTIAL_EFFECT_VISIBILITY_REQUIRED', 0, $exception); }
        $scope = self::observe($pdo, $tables, false);
        if ($scope['triggers'] !== []) { throw new RuntimeException('INSTALL_PARTIAL_TRIGGER_EFFECT_REVIEW_REQUIRED'); }
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        foreach ($scope['foreign_keys'] as $fk) {
            if ($fk['TABLE_SCHEMA'] !== $database || $fk['REFERENCED_TABLE_SCHEMA'] !== $database) {
                throw new RuntimeException('INSTALL_PARTIAL_FOREIGN_SCHEMA_EFFECT_REVIEW_REQUIRED');
            }
        }
        $scope['whole_schema'] = true;
        $scope['effect_boundary'] = ['database' => $database, 'triggers' => 'none_visible_with_verified_visibility',
            'foreign_keys' => 'same_schema_only', 'visibility' => 'existing_native_effect_visibility_verified'];
        return $scope;
    }

    /**
     * APP partial maintenance only: collect real complete effect metadata with the
     * existing protected backup transport. The ordinary APP PDO never changes user.
     */
    public static function appPartialMetadata(PDO $pdo, array $binding, array $source, array $window, string $toolPath, callable $guard): array
    {
        $root = $binding['source']['server_root'] ?? '';
        $current = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        if (PHP_SAPI !== 'cli' || !$current instanceof \app\common\execution\InstallationExecutionContext
            || $current->operation() !== 'installation.console.partial'
            || ($binding['authority']['kind'] ?? null) !== 'app-native-owner'
            || preg_match('/\A[a-f0-9]{32}\z/D', (string) ($binding['authority']['plan_id'] ?? '')) !== 1
            || realpath($root) !== $root || !str_starts_with($toolPath, $root . '/private/partial-installation/')) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_OWNER_REQUIRED');
        }
        \PeanutServerUpdatePlan::sourceOwner($root);
        $guard();
        $tool = self::protectedPluginJson($toolPath, $root);
        $contract = $tool['source_schema_backup'] ?? [];
        $database = $binding['database']['database'];
        if (($tool['transport'] ?? null) !== 'ssh-docker-exec' || ($contract['whole_schema'] ?? null) !== true
            || ($contract['database_resource_id'] ?? null) !== $binding['resource_id'] || ($contract['database'] ?? null) !== $database
            || preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_TOOL_REQUIRED');
        }
        foreach (['ssh_command', 'docker_command', 'mysql_command', 'timeout_command', 'digest_command'] as $key) {
            if (!is_string($tool[$key] ?? null) || preg_match('~\A/[A-Za-z0-9_./-]+\z~D', $tool[$key]) !== 1 || str_contains($tool[$key], '..')) {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_INVALID');
            }
        }
        foreach (['ssh_alias', 'container_name'] as $key) {
            if (!is_string($tool[$key] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/D', $tool[$key]) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_INVALID');
            }
        }
        $seconds = $contract['timeout_seconds'] ?? null;
        if (!is_int($seconds) || $seconds < 30 || $seconds > 600 || time() + $seconds + 60 >= $binding['authority']['expires_at']) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_LIFETIME_REQUIRED');
        }
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
        if ($tables === [] || count($tables) > 512 || count(array_unique($tables)) !== count($tables)) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_COVERAGE_REQUIRED');
        }
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/\Apa_[a-z0-9_]+\z/D', $table) !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TABLE_UNSUPPORTED'); }
        }
        sort($tables, SORT_STRING);
        $session = $pdo->query('SELECT @@SESSION.sql_mode AS sql_mode,@@SESSION.sql_quote_show_create AS quote_names')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($session) || preg_match('/\A[A-Z0-9_,]*\z/D', (string) ($session['sql_mode'] ?? '')) !== 1
            || !in_array((int) ($session['quote_names'] ?? -1), [0, 1], true)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_SESSION_INVALID'); }
        $parent = $root . '/private/partial-installation/effect-metadata';
        if (!is_dir($parent) && !mkdir($parent, 0700)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_DIRECTORY_REQUIRED'); }
        if (realpath($parent) !== $parent || (fileperms($parent) & 0777) !== 0700
            || !in_array(fileowner($parent), [0, fileowner($root)], true)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_DIRECTORY_REQUIRED'); }
        $nonce = bin2hex(random_bytes(16));
        $directory = $parent . '/' . $binding['authority']['plan_id'] . '-' . $nonce;
        if (!mkdir($directory, 0700)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_DIRECTORY_REQUIRED'); }
        $request = ['protocol' => 'peanut.app-partial-effect-request.v1', 'binding' => $binding,
            'source' => self::metadataSource($source), 'window' => $window,
            'tool' => ['path' => $toolPath, 'sha256' => hash_file('sha256', $toolPath)],
            'adapter_sha256' => hash_file('sha256', __FILE__), 'nonce' => $nonce,
            'tables' => $tables, 'session' => $session, 'created_at' => time()];
        // This original owner API proves the native update lock before privileged IO.
        \PeanutServerUpdatePlan::writePartialOwnerRecord($root, $directory . '/request.json', $request);
        $sql = self::metadataSql($tables, $session, $nonce);
        $shell = self::quote([$tool['digest_command'], $tool['mysql_command']]) . ' && '
            . self::quote([$tool['mysql_command'], '--version']) . ' && ('
            . self::clientShell($tool, 'mysql_command', $seconds, ['--no-defaults', '--no-login-paths',
                '--default-character-set=utf8mb4', '--batch', '--skip-column-names', '-uroot',
                '--database=' . $database, '--execute=' . $sql]) . ')';
        if (strlen($shell) > 65536) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_COVERAGE_REQUIRED'); }
        $output = $directory . '/metadata.txt'; $error = $directory . '/metadata.stderr';
        $started = time();
        self::remote($tool, $shell, $output, $error, 'effect-metadata', $seconds,
            (int) $binding['authority']['expires_at'], $guard, 8388608);
        $parsed = self::parseMetadata($output, $tables, $nonce, $binding, $contract, (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
        $record = ['protocol' => 'peanut.app-partial-effect-metadata.v1', 'request' => [
                'path' => $directory . '/request.json', 'sha256' => hash_file('sha256', $directory . '/request.json')],
            'binding' => $binding, 'source' => $request['source'], 'window' => $window, 'tool' => $request['tool'],
            'adapter_sha256' => $request['adapter_sha256'], 'nonce' => $nonce,
            'started_at' => $started, 'ended_at' => time(), 'metadata' => $parsed['metadata'],
            'semantic_sha256' => self::pluginDigest($parsed['metadata']), 'client' => $parsed['client'],
            'process' => ['path' => $output . '.process.json', 'sha256' => hash_file('sha256', $output . '.process.json')],
            'stdout' => ['path' => $output, 'sha256' => hash_file('sha256', $output), 'bytes' => filesize($output)],
            'stderr' => ['path' => $error, 'sha256' => hash_file('sha256', $error), 'bytes' => filesize($error)]];
        \PeanutServerUpdatePlan::writePartialOwnerRecord($root, $directory . '/observation.json', $record);
        return ['path' => $directory . '/observation.json', 'sha256' => hash_file('sha256', $directory . '/observation.json')];
    }

    /** The nonce/source/receipt is authenticated; current APP structure still comes from its ordinary PDO. */
    public static function appPartialScope(PDO $pdo, array $tables, array $binding, array $source, array $window, string $toolPath, array $reference): array
    {
        $record = self::metadataRecord($reference, $binding, true);
        if (self::pluginDigest($record['source']) !== self::pluginDigest(self::metadataSource($source))
            || self::pluginDigest($record['window']) !== self::pluginDigest($window)
            || $record['tool'] !== ['path' => $toolPath, 'sha256' => hash_file('sha256', $toolPath)]) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_BINDING_CHANGED');
        }
        sort($tables, SORT_STRING);
        if ($tables !== array_keys($record['metadata']['objects'])) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_COVERAGE_CHANGED'); }
        $scope = self::observe($pdo, $tables, false, $record['metadata']);
        if (self::pluginDigest($scope['objects']) !== self::pluginDigest($record['metadata']['objects'])) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_STRUCTURE_CHANGED');
        }
        if ($scope['triggers'] !== []) { throw new RuntimeException('INSTALL_PARTIAL_TRIGGER_EFFECT_REVIEW_REQUIRED'); }
        foreach ($scope['foreign_keys'] as $fk) {
            if ($fk['TABLE_SCHEMA'] !== $binding['database']['database'] || $fk['REFERENCED_TABLE_SCHEMA'] !== $binding['database']['database']) {
                throw new RuntimeException('INSTALL_PARTIAL_FOREIGN_SCHEMA_EFFECT_REVIEW_REQUIRED');
            }
        }
        $scope['whole_schema'] = true;
        $scope['metadata_visibility'] = 'complete native privileged metadata; ordinary APP PDO unchanged';
        $scope['metadata_semantics_sha256'] = $record['semantic_sha256'];
        $scope['effect_boundary'] = ['database' => $binding['database']['database'], 'triggers' => 'none_visible_with_verified_visibility',
            'foreign_keys' => 'same_schema_only', 'visibility' => 'native_app_partial_effect_metadata_verified',
            'observer_binding_sha256' => self::pluginDigest([$record['binding'], $record['source'], $record['window'], $record['tool'], $record['client']])];
        return $scope;
    }

    private static function metadataSource(array $source): array
    {
        return ['runtime_sha256' => $source['runtime_sha256'], 'deployment_sha256' => $source['deployment']['head_sha256'],
            'current_source' => $source['deployment']['current_source'], 'checkpoint' => $source['checkpoint'],
            'transition_id' => $source['state']['transition_id']];
    }

    private static function appMetadataOrigin(array $binding, array $baseline, array $scope): array
    {
        $origin = $baseline['effect_metadata'] ?? null;
        if (!is_array($origin) || !is_array($origin['reference'] ?? null)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_PROOF_REQUIRED'); }
        $record = self::metadataRecord($origin['reference'], $binding);
        if (($origin['semantic_sha256'] ?? null) !== $record['semantic_sha256']
            || ($scope['metadata_semantics_sha256'] ?? null) !== $record['semantic_sha256']
            || ($scope['effect_boundary']['observer_binding_sha256'] ?? null)
                !== self::pluginDigest([$record['binding'], $record['source'], $record['window'], $record['tool'], $record['client']])) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_ORIGIN_CHANGED');
        }
        return $record;
    }

    private static function metadataRecord(array $reference, array $binding, bool $fresh = false): array
    {
        $root = $binding['source']['server_root'];
        if (!is_string($reference['path'] ?? null) || !str_starts_with($reference['path'], $root . '/private/partial-installation/effect-metadata/')) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_PROOF_REQUIRED');
        }
        $record = \PeanutServerUpdatePlan::readPartialOwnerRecord($root, $reference['path']);
        if (hash_file('sha256', $reference['path']) !== ($reference['sha256'] ?? null)
            || ($record['protocol'] ?? null) !== 'peanut.app-partial-effect-metadata.v1'
            || self::pluginDigest($record['binding'] ?? null) !== self::pluginDigest($binding)
            || ($record['adapter_sha256'] ?? null) !== hash_file('sha256', __FILE__)
            || ($record['semantic_sha256'] ?? null) !== self::pluginDigest($record['metadata'] ?? null)
            || !is_int($record['started_at'] ?? null) || !is_int($record['ended_at'] ?? null)
            || $record['started_at'] < $binding['authority']['created_at'] || $record['ended_at'] < $record['started_at']
            || $record['ended_at'] >= $binding['authority']['expires_at'] || $record['ended_at'] > time()
            || ($fresh && time() - $record['ended_at'] > 60)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_PROOF_CHANGED'); }
        $request = \PeanutServerUpdatePlan::readPartialOwnerRecord($root, $record['request']['path']);
        if (hash_file('sha256', $record['request']['path']) !== $record['request']['sha256']
            || ($request['protocol'] ?? null) !== 'peanut.app-partial-effect-request.v1'
            || self::pluginDigest($request['binding']) !== self::pluginDigest($binding)
            || $request['source'] !== $record['source'] || $request['window'] !== $record['window']
            || $request['tool'] !== $record['tool'] || $request['nonce'] !== $record['nonce']
            || $request['adapter_sha256'] !== $record['adapter_sha256']
            || !is_int($request['created_at'] ?? null) || $request['created_at'] < $binding['authority']['created_at']
            || $request['created_at'] > $record['started_at']) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_REQUEST_CHANGED'); }
        foreach (['stdout', 'stderr', 'process'] as $key) {
            self::pluginFile($record[$key]['path'] ?? '', $root, 8388608);
            if (hash_file('sha256', $record[$key]['path']) !== $record[$key]['sha256']) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_RECEIPT_CHANGED'); }
        }
        $process = self::protectedPluginJson($record['process']['path'], $root);
        if (($process['phase'] ?? null) !== 'effect-metadata' || ($process['rc'] ?? null) !== 0
            || ($process['local_process_terminal'] ?? null) !== true || ($process['local_deadline_exceeded'] ?? null) !== false
            || ($process['local_output_limit_exceeded'] ?? null) !== false || ($process['remote_success_terminal_confirmed'] ?? null) !== true
            || $process['stdout'] !== $record['stdout'] || $process['stderr_sha256'] !== $record['stderr']['sha256']) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_RECEIPT_CHANGED');
        }
        return $record;
    }

    /** No caller SQL: exact target metadata, plus SHOW CREATE only for validated target identifiers. */
    private static function metadataSql(array $tables, array $session, string $nonce, ?array $effects = null): string
    {
        $sql = "SET SESSION sql_mode='" . $session['sql_mode'] . "'; SET SESSION sql_quote_show_create=" . (int) $session['quote_names']
            . "; SET SESSION information_schema_stats_expiry=0; SET TRANSACTION READ ONLY; START TRANSACTION READ ONLY;"
            . " SELECT HEX(JSON_OBJECT('server_uuid',@@server_uuid,'server_hostname',@@hostname,'server_port',@@port,'database',DATABASE(),"
            . "'version',VERSION(),'partial_revokes',@@GLOBAL.partial_revokes,'stats_expiry',@@SESSION.information_schema_stats_expiry)); SHOW GRANTS; SELECT 'GRANTS_END_" . $nonce . "';"
            . " SELECT 'DDL_BEFORE_STATISTICS_" . $nonce . "';";
        // SHOW CREATE opens InnoDB's lazy allocator before TABLES statistics are read.
        // Keep the actual output, then read its real counter; never infer NULL means 1.
        foreach ($tables as $table) { $sql .= " SELECT '" . $table . "'; SHOW CREATE TABLE " . chr(96) . $table . chr(96) . ';'; }
        $sql .= " SELECT HEX(JSON_OBJECT('tables',COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT('name',TABLE_NAME,'type',TABLE_TYPE,'engine',ENGINE,"
            . "'auto_increment',CAST(AUTO_INCREMENT AS CHAR))) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()),JSON_ARRAY()),"
            . "'foreign_keys',COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT('CONSTRAINT_SCHEMA',k.CONSTRAINT_SCHEMA,'CONSTRAINT_NAME',k.CONSTRAINT_NAME,"
            . "'TABLE_SCHEMA',k.TABLE_SCHEMA,'TABLE_NAME',k.TABLE_NAME,'COLUMN_NAME',k.COLUMN_NAME,'ORDINAL_POSITION',k.ORDINAL_POSITION,"
            . "'REFERENCED_TABLE_SCHEMA',k.REFERENCED_TABLE_SCHEMA,'REFERENCED_TABLE_NAME',k.REFERENCED_TABLE_NAME,"
            . "'REFERENCED_COLUMN_NAME',k.REFERENCED_COLUMN_NAME,'UPDATE_RULE',r.UPDATE_RULE,'DELETE_RULE',r.DELETE_RULE,"
            . "'IS_LOCAL_PARENT',CAST(k.REFERENCED_TABLE_SCHEMA=DATABASE() AS UNSIGNED))) FROM information_schema.KEY_COLUMN_USAGE k"
            . " JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME"
            . " AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() OR k.REFERENCED_TABLE_SCHEMA=DATABASE()),JSON_ARRAY()),"
            . "'triggers',COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT('TRIGGER_NAME',TRIGGER_NAME,'EVENT_MANIPULATION',EVENT_MANIPULATION,"
            . "'EVENT_OBJECT_TABLE',EVENT_OBJECT_TABLE,'ACTION_TIMING',ACTION_TIMING,'ACTION_STATEMENT',ACTION_STATEMENT,'SQL_MODE',SQL_MODE,'DEFINER',DEFINER))"
            . " FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()),JSON_ARRAY())));";
        if ($effects !== null) {
            $names = implode(',', array_map(static fn(string $table): string => "'" . $table . "'", $effects));
            $sql = str_replace('WHERE TABLE_SCHEMA=DATABASE())', 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $names . '))', $sql);
            $sql = str_replace('WHERE k.TABLE_SCHEMA=DATABASE() OR k.REFERENCED_TABLE_SCHEMA=DATABASE()',
                'WHERE (k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME IN (' . $names . ')) OR (k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IN (' . $names . '))', $sql);
            $sql = str_replace('WHERE TRIGGER_SCHEMA=DATABASE())', 'WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN (' . $names . '))', $sql);
        }
        return $sql . ' ROLLBACK;';
    }

    /** Original failed-operation evidence only; the host must bind its producer, process and time. */
    public static function historicalEffectMetadata(string $path, array $request, array $tool, string $serverRoot, string $ordering): array
    {
        $bytes = self::protectedPluginBytes($path, $serverRoot, 8388608);
        $parsed = self::parseMetadata($path, $request['present'], $request['nonce'], $request['binding'],
            $tool['source_schema_backup'], $tool['source_schema_backup']['mysql_version'], $ordering, $bytes);
        return $parsed + ['ordering' => $ordering, 'qualification' => 'historical_failure_evidence_only',
            'stdout' => ['path' => $path, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)]];
    }

    private static function parseMetadata(string $path, array $tables, string $nonce, array $binding, array $contract, string $serverVersion,
        string $ordering = 'ddl-before-statistics', ?string $protectedBytes = null): array
    {
        $lines = preg_split('/\r?\n/', rtrim($protectedBytes ?? (string) file_get_contents($path), "\r\n"));
        if (count($lines) < 6 || preg_match('/\A([a-f0-9]{64})\s+/', $lines[0], $digest) !== 1) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
        $decode = static function (string $hex): array {
            if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
            $value = json_decode(hex2bin($hex), true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($value)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
            return $value;
        };
        $identity = $decode($lines[2]);
        foreach (['server_uuid', 'server_hostname', 'server_port', 'database'] as $key) {
            if (($identity[$key] ?? null) !== $binding['database'][$key]) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_REMOTE_MISMATCH'); }
        }
        if (($identity['version'] ?? null) !== $serverVersion || $serverVersion !== ($contract['mysql_version'] ?? null)
            || preg_match('/\A8\.4\.[0-9]+\z/D', $serverVersion) !== 1 || !str_contains($lines[1], 'Ver ' . $serverVersion . ' ')
            || ($identity['stats_expiry'] ?? null) !== 0
            || !in_array($identity['partial_revokes'] ?? null, [0, false], true)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_VISIBILITY_REQUIRED'); }
        $end = array_search('GRANTS_END_' . $nonce, $lines, true);
        if (!is_int($end) || $end < 4) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
        $global = false; $trigger = false; $schemaScope = chr(96) . $identity['database'] . chr(96) . '.*';
        foreach (array_slice($lines, 3, $end - 3) as $grant) {
            if (str_starts_with($grant, 'REVOKE ')) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_VISIBILITY_REQUIRED'); }
            if (preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match) !== 1) { continue; }
            $privileges = array_map('trim', explode(',', $match[1])); $all = in_array('ALL PRIVILEGES', $privileges, true);
            $global = $global || ($match[2] === '*.*' && ($all || in_array('SELECT', $privileges, true)));
            $trigger = $trigger || (in_array($match[2], ['*.*', $schemaScope], true) && ($all || in_array('TRIGGER', $privileges, true)));
        }
        if (!$global || !$trigger) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_VISIBILITY_REQUIRED'); }
        if (!in_array($ordering, ['ddl-before-statistics', 'statistics-before-ddl'], true)) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID');
        }
        $ddlStart = $end + 2;
        $statisticsAt = $ordering === 'ddl-before-statistics' ? $ddlStart + count($tables) * 2 : $end + 1;
        $expectedLines = $ddlStart + count($tables) * 2 + ($ordering === 'ddl-before-statistics' ? 1 : 0);
        if (($ordering === 'ddl-before-statistics' && ($lines[$end + 1] ?? null) !== 'DDL_BEFORE_STATISTICS_' . $nonce)
            || count($lines) !== $expectedLines) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
        $data = $decode($lines[$statisticsAt] ?? ''); $objects = [];
        foreach ($data['tables'] ?? [] as $row) {
            if (!is_string($row['name'] ?? null) || !in_array($row['name'], $tables, true) || isset($objects[$row['name']])
                || ($row['type'] ?? null) !== 'BASE TABLE' || ($row['engine'] ?? null) !== 'InnoDB') { throw new RuntimeException('INSTALL_PARTIAL_METADATA_COVERAGE_REQUIRED'); }
            $objects[$row['name']] = ['engine' => 'InnoDB', 'create_sql_sha256' => null,
                'auto_increment' => $row['auto_increment'] === null ? null : (string) $row['auto_increment']];
        }
        ksort($objects, SORT_STRING);
        if (array_keys($objects) !== $tables) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_COVERAGE_REQUIRED'); }
        foreach ($tables as $i => $table) {
            if ($lines[$ddlStart + $i * 2] !== $table) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
            $row = explode("\t", $lines[$ddlStart + 1 + $i * 2], 2);
            if (count($row) !== 2 || $row[0] !== $table) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
            $objects[$table]['create_sql_sha256'] = hash('sha256', self::mysqlBatchValue($row[1]));
        }
        $foreign = $data['foreign_keys'] ?? null; $triggers = [];
        if (!is_array($foreign) || !array_is_list($foreign) || !is_array($data['triggers'] ?? null) || !array_is_list($data['triggers'])) {
            throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID');
        }
        foreach ($foreign as &$fk) {
            foreach (['CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME', 'TABLE_SCHEMA', 'TABLE_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_SCHEMA', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME', 'UPDATE_RULE', 'DELETE_RULE'] as $key) {
                if (!is_string($fk[$key] ?? null) || $fk[$key] === '') { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
            }
            if (!is_int($fk['ORDINAL_POSITION'] ?? null) || $fk['ORDINAL_POSITION'] < 1
                || !in_array($fk['IS_LOCAL_PARENT'] ?? null, [0, 1], true)
                || ($fk['TABLE_SCHEMA'] !== $identity['database'] && $fk['REFERENCED_TABLE_SCHEMA'] !== $identity['database'])) {
                throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID');
            }
            ksort($fk, SORT_STRING);
        }
        unset($fk);
        usort($foreign, static fn(array $a, array $b): int => [$a['CONSTRAINT_SCHEMA'], $a['TABLE_NAME'], $a['CONSTRAINT_NAME'], $a['ORDINAL_POSITION']]
            <=> [$b['CONSTRAINT_SCHEMA'], $b['TABLE_NAME'], $b['CONSTRAINT_NAME'], $b['ORDINAL_POSITION']]);
        foreach ($data['triggers'] as $row) {
            foreach (['TRIGGER_NAME', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'ACTION_STATEMENT', 'SQL_MODE', 'DEFINER'] as $key) {
                if (!is_string($row[$key] ?? null)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
            }
            $triggers[] = ['table' => $row['EVENT_OBJECT_TABLE'], 'name' => $row['TRIGGER_NAME'], 'event' => $row['EVENT_MANIPULATION'],
                'timing' => $row['ACTION_TIMING'], 'definition_sha256' => self::pluginDigest($row)];
        }
        usort($triggers, static fn(array $a, array $b): int => [$a['table'], $a['name']] <=> [$b['table'], $b['name']]);
        return ['metadata' => ['objects' => $objects, 'foreign_keys' => $foreign, 'triggers' => $triggers],
            'client' => ['mysql_sha256' => $digest[1], 'version' => $serverVersion, 'global_select' => true, 'trigger_visibility' => true, 'partial_revokes' => false]];
    }

    private static function mysqlBatchValue(string $value): string
    {
        $escapes = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", '\\' => '\\'];
        $result = '';
        for ($i = 0, $size = strlen($value); $i < $size; $i++) {
            if ($value[$i] !== '\\') { $result .= $value[$i]; continue; }
            $i++;
            if ($i >= $size || !isset($escapes[$value[$i]])) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_OUTPUT_INVALID'); }
            $result .= $escapes[$value[$i]];
        }
        return $result;
    }

    /** Fixed native DML only. DDL-only targets do not fire their row triggers. */
    public static function applicationStageRequirements(array $scope, string $id): array
    {
        if (!isset(self::TABLES[$id])) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SOURCE_UNSUPPORTED'); }
        $permission = ['module_key', 'type', 'name', 'description', 'risk_level', 'status', 'manifest_version', 'updated_at', 'retired_at'];
        $effects = match ($id) {
            '20260909-notification-sms-reservation' => ['pa_notice_log' => ['UPDATE' => ['receiver_hash', 'reservation_until', 'reservation_active']]],
            '20261004-group-default-navigation' => [
                'pa_menu_definition' => ['UPDATE' => ['upstream_defaults_json']],
                'pa_system_menu' => ['INSERT' => [], 'UPDATE' => ['pid', 'name', 'upstream_defaults_json', 'menu_conflict_json']]],
            '20261004-menu-stable-identities' => [
                'pa_system_menu' => ['UPDATE' => ['menu_key', 'module_key', 'parent_key']],
                'pa_menu_definition' => ['UPDATE' => ['upstream_defaults_json']]],
            '20261007-readiness-permission' => ['pa_permission' => ['INSERT' => [], 'UPDATE' => $permission]],
            '20261012-align-department-canonical-permissions' => [
                'pa_system_menu' => ['INSERT' => [], 'UPDATE' => ['is_show', 'is_disable']],
                'pa_role_permission' => ['INSERT' => [], 'DELETE' => []],
                'pa_role' => ['UPDATE' => ['authorization_revision', 'updated_at']],
                'pa_tenant' => ['UPDATE' => ['authorization_revision', 'updated_at']],
                'pa_permission' => ['UPDATE' => ['status', 'retired_at', 'updated_at']]],
            '20261012-align-platform-ops-permission-ownership' => ['pa_permission' => ['UPDATE' => ['module_key']]],
            '20261012-repair-seeded-permission-parents' => ['pa_system_menu' => ['UPDATE' => ['pid', 'parent_key']]],
            default => [], // Auth and provenance only change target structure.
        };
        // Includes the original applying/applied/failed writes, not a second ledger.
        $effects['pa_schema_migration'] = ['INSERT' => [],
            'UPDATE' => ['release_version', 'checksum', 'status', 'started_at', 'finished_at', 'error_code']];
        foreach ($effects as $table => &$events) {
            if (isset($events['UPDATE'])) {
                // ON UPDATE values and stored/generated keys may change too.
                array_push($events['UPDATE'], ...($scope['derived_update_columns'][$table] ?? []));
            }
        }
        unset($events);
        $requirements = [];
        foreach ($scope['triggers'] as $trigger) {
            if (isset($effects[$trigger['table']][$trigger['event']])) {
                $requirements[] = ['migration_id' => $id, 'table' => $trigger['table'], 'trigger' => $trigger['name'],
                    'code' => 'APPLICATION_SCHEMA_DML_TRIGGER_EFFECT_REVIEW_REQUIRED'];
            }
        }
        foreach ($scope['foreign_keys'] as $fk) {
            // Only a changed referenced key or a real parent DELETE can cascade.
            // Even an in-scope cascade lacks an owning business proof here.
            if ((int) $fk['IS_LOCAL_PARENT'] !== 1) { continue; }
            $events = $effects[$fk['REFERENCED_TABLE_NAME']] ?? [];
            $update = in_array($fk['REFERENCED_COLUMN_NAME'], $events['UPDATE'] ?? [], true)
                && in_array($fk['UPDATE_RULE'], ['CASCADE', 'SET NULL'], true);
            $delete = isset($events['DELETE']) && in_array($fk['DELETE_RULE'], ['CASCADE', 'SET NULL'], true);
            if ($update || $delete) {
                $requirements[] = ['migration_id' => $id, 'table' => $fk['TABLE_NAME'], 'constraint' => $fk['CONSTRAINT_NAME'],
                    'code' => 'APPLICATION_SCHEMA_CASCADE_EFFECT_REVIEW_REQUIRED'];
            }
        }
        return $requirements;
    }

    /** Fixed production effects; never derives SQL permission from owned_tables or a text-table scan. */
    public static function pluginEffects(array $plan): array
    {
        $tables = self::CATALOG_TABLES; $requirements = []; $pending = []; $selected = [];
        $filePending = false; $writeRequirements = []; $receipt = null;
        if (!is_array($plan['modules'] ?? null) || $plan['modules'] === []) { throw new RuntimeException('PLUGIN_SOURCE_MODULE_PLAN_REQUIRED'); }
        foreach ($plan['modules'] as $module) {
            $key = $module['module_key'] ?? null;
            if (!is_string($key) || !is_array($module['migrations'] ?? null)) { throw new RuntimeException('PLUGIN_SOURCE_MODULE_PLAN_REQUIRED'); }
            if (($module['notification_forward_schema'] ?? null) !== null) {
                if ($key !== 'official.notification' || !is_array($module['notification_forward_receipt'] ?? null) || $receipt !== null) {
                    $requirements[] = ['module_key' => $key, 'code' => 'PLUGIN_SOURCE_NOTIFICATION_PRIVATE_RECEIPT_RECOVERY_REQUIRED'];
                } else { $receipt = $module['notification_forward_receipt']; }
            }
            foreach ($module['migrations'] as $migration) {
                $id = $migration['migration_key'] ?? null;
                $sha = $migration['sha256'] ?? null;
                if (!is_string($id) || !str_starts_with($id, $key . ':') || !is_string($sha)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $sha) !== 1 || isset($selected[$id])) {
                    throw new RuntimeException('PLUGIN_SOURCE_MIGRATION_IDENTITY_REQUIRED');
                }
                $selected[$id] = $sha;
                if (($migration['disposition'] ?? null) === 'already_applied') { continue; }
                $pending[] = $id; $tables[] = 'pa_module_migration';
                $importExport = self::IMPORT_EXPORT_STAGES[$id] ?? null;
                if ($key === 'official.import-export' && $importExport !== null && $sha === $importExport[0]
                    && ($migration['disposition'] ?? null) === 'execute') {
                    array_push($tables, ...$importExport[1]);
                    $writeRequirements[] = ['module_key' => $key, 'migration_key' => $id, 'code' => 'PLUGIN_SOURCE_IMPORT_EXPORT_PRESERVATION_REQUIRED',
                        'contract' => 'PeanutAdmin\\Modules\\ImportExport\\Contract\\ImportExportMigrationPreservation',
                        'api' => 'before(PDO, canonicalMigrationKey, nativeParentFacts), after(PDO, innerPrivateState, nativeParentFacts); required current native Host parent facts in each phase; compatible originals or absent targets, fixed native CREATE IF NOT EXISTS and same-PDO completion required'];
                    continue;
                }
                $member = self::MEMBER_STAGES[$id] ?? null;
                if ($key === 'official.member' && $member !== null && $sha === $member[0]
                    && ($migration['disposition'] ?? null) === 'execute') {
                    array_push($tables, ...$member[1]);
                    $writeRequirements[] = ['module_key' => $key, 'migration_key' => $id, 'code' => 'PLUGIN_SOURCE_MEMBER_PRESERVATION_REQUIRED',
                        'contract' => 'PeanutAdmin\\Modules\\Member\\Contract\\MemberSessionMigrationPreservation',
                        'api' => 'before(PDO, canonicalMigrationKey, nativeParentFacts), after(PDO, innerPrivateState, nativeParentFacts); required current native Host parent facts in each phase; absent targets, fixed native DDL and same-PDO completion required'];
                    continue;
                }
                $article = self::ARTICLE_STAGES[$id] ?? null;
                if ($key === 'official.article' && $article !== null && $sha === $article[0]
                    && ($migration['disposition'] ?? null) === 'execute') {
                    array_push($tables, ...$article[1]);
                    $writeRequirements[] = ['module_key' => $key, 'migration_key' => $id, 'code' => 'PLUGIN_SOURCE_ARTICLE_PRESERVATION_REQUIRED',
                        'contract' => 'PeanutAdmin\\Modules\\Article\\Contract\\ArticleMigrationPreservation',
                        'api' => 'before(PDO, canonicalMigrationKey), after(PDO, innerPrivateState); real canonical Document2 predecessors, fixed native DDL and same-PDO completion required'];
                    continue;
                }
                $document = self::DOCUMENT_STAGES[$id] ?? null;
                if ($key === 'official.document' && $document !== null && $sha === $document[0]
                    && ($migration['disposition'] ?? null) === 'execute') {
                    array_push($tables, ...$document[1]);
                    $writeRequirements[] = ['module_key' => $key, 'migration_key' => $id, 'code' => 'PLUGIN_SOURCE_DOCUMENT_PRESERVATION_REQUIRED',
                        'contract' => 'PeanutAdmin\\Modules\\Document\\Contract\\DocumentMigrationPreservation',
                        'api' => 'before(PDO, canonicalMigrationKey), after(PDO, innerPrivateState); fixed native DDL and same-PDO completion required'];
                    continue;
                }
                $ddl = self::MODULE_DDL_STAGES[$id] ?? null;
                if ($ddl !== null && $sha === $ddl[0] && in_array($migration['disposition'] ?? null, ['execute', 'after_predecessor'], true)) {
                    array_push($tables, ...$ddl[1]);
                    $writeRequirements[] = ['module_key' => $key, 'migration_key' => $id, 'code' => 'PLUGIN_SOURCE_MODULE_PRESERVATION_REQUIRED',
                        'contract' => $ddl[2], 'api' => 'before(PDO, exactMigration), after(PDO, wholeBefore); native same-stage ledger reader required'];
                    continue;
                }
                $file = self::FILE_STAGES[$id] ?? null;
                if ($key !== 'official.file' || $file === null || $sha !== $file[0]
                    || !in_array($migration['disposition'] ?? null, ['execute', 'adopt'], true)
                    || ($id === 'official.file:20260823-unify-storage-service' && $migration['disposition'] !== 'adopt')) {
                    $requirements[] = ['module_key' => $key, 'migration_key' => $id, 'code' => 'PLUGIN_SOURCE_SQL_EFFECTS_AND_PRESERVATION_REQUIRED'];
                    continue;
                }
                $filePending = true;
                if ($migration['disposition'] === 'adopt') { $tables[] = 'pa_platform_audit_event'; }
                else { array_push($tables, ...$file[1]); }
            }
        }
        $tables = array_values(array_unique($tables)); sort($tables, SORT_STRING); ksort($selected, SORT_STRING);
        if ($filePending) {
            $writeRequirements[] = ['code' => 'PLUGIN_SOURCE_FILE_PRESERVATION_REQUIRED',
                'contract' => 'PeanutAdmin\\Modules\\File\\Contract\\FileMigrationPreservation',
                'api' => 'before(PDO, canonicalMigrationKey), after(PDO, privateBefore); native File report remains disposition authority'];
        }
        return ['protocol' => 'peanut.source-plugin-effects.v1', 'tables' => $tables,
            'pending' => $pending, 'selected' => $selected, 'closure_complete' => $requirements === [], 'requirements' => $requirements,
            'write_requirements' => $writeRequirements, 'notification_forward_receipt' => $receipt,
            'transaction_boundary' => 'begin lifecycle, each migration/adoption, catalog registration and failure marking commit separately; failed status is best effort',
            'shared_catalogs' => 'whole tables including core/global keys and operation relation replacement; no tenant or module row filter',
            'non_database_effects' => ['menu conflict warnings may append runtime logs; preserve logs as failure evidence, never rewind them with the database',
                'Notification forward binding is a separate fixed protected file; preserve exact original bytes or proven absence independently of selected-table SQL'],
            'trigger_effects' => 'not authorized by the fixed source closure; inspect visible definitions and resolve external writes before any lifecycle mutation',
            'execution_admitted' => false];
    }

    /** Missing tables are explicit pre-state, never silently replaced by an empty dump table. */
    public static function pluginScope(PDO $pdo, array $effects, ?array $binding = null, string $metadataTool = '', ?callable $guard = null): array
    {
        if (($effects['closure_complete'] ?? null) !== true) { throw new RuntimeException('PLUGIN_SOURCE_BACKUP_EFFECTS_INCOMPLETE'); }
        $scope = $metadataTool === '' ? self::observe($pdo, $effects['tables'], true)
            : self::sourceEffectScope($pdo, $effects['tables'], true, $binding ?? [], $metadataTool, $guard);
        if (($effects['notification_forward_receipt'] ?? null) !== null) {
            $expected = $effects['notification_forward_receipt'];
            $snapshot = PluginLifecycleService::notificationReceiptSnapshot((string) ($expected['path'] ?? ''));
            unset($snapshot['contents']);
            if ($snapshot !== $expected) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED'); }
            $scope['notification_forward_receipt'] = $snapshot;
        }
        return $scope;
    }

    private static function observe(PDO $pdo, array $tables, bool $allowMissing, ?array $effectMetadata = null): array
    {
        $query = $pdo->query('SELECT @@SESSION.information_schema_stats_expiry AS expiry');
        $stats = $query->fetch(PDO::FETCH_ASSOC); $query->closeCursor();
        if (!is_array($stats) || preg_match('/\A[0-9]{1,10}\z/D', (string) ($stats['expiry'] ?? '')) !== 1
            || (int) $stats['expiry'] > 4294967295) { throw new RuntimeException('APPLICATION_SCHEMA_METADATA_STATS_SESSION_INVALID'); }
        $expiry = (string) $stats['expiry'];
        try {
        // TABLES.AUTO_INCREMENT must describe the same current engine state as
        // SHOW CREATE. Never accept an old information_schema statistics cache.
        $pdo->exec('SET SESSION information_schema_stats_expiry=0');
        $affected = $tables; $present = [];
        $objects = [];
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/\Apa_[a-z0-9_]+\z/D', $table) !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TABLE_UNSUPPORTED'); }
            $query = $pdo->prepare('SELECT TABLE_TYPE,ENGINE,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $query->execute([$table]); $rows = $query->fetchAll(PDO::FETCH_ASSOC); $query->closeCursor();
            if ($allowMissing && $rows === []) { $objects[$table] = ['exists' => false]; continue; }
            if (count($rows) !== 1 || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE' || $rows[0]['ENGINE'] !== 'InnoDB') {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TABLE_UNSUPPORTED');
            }
            $query = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
            $ddl = $query->fetch(PDO::FETCH_NUM); $query->closeCursor();
            if (!is_array($ddl) || !is_string($ddl[1] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_DDL_UNAVAILABLE'); }
            $objects[$table] = ['engine' => 'InnoDB', 'create_sql_sha256' => hash('sha256', $ddl[1]),
                'auto_increment' => $rows[0]['AUTO_INCREMENT'] === null ? null : (string) $rows[0]['AUTO_INCREMENT']];
            $present[] = $table;
        }
        $foreignKeys = []; $triggers = []; $derivedUpdates = [];
        if ($tables !== []) {
            $marks = implode(',', array_fill(0, count($tables), '?'));
            if ($effectMetadata !== null) {
                $foreignKeys = $effectMetadata['foreign_keys']; $triggers = $effectMetadata['triggers'];
            } else {
            $query = $pdo->prepare('SELECT k.CONSTRAINT_SCHEMA,k.CONSTRAINT_NAME,k.TABLE_SCHEMA,k.TABLE_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,'
                . 'k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE,'
                . '(k.REFERENCED_TABLE_SCHEMA=DATABASE()) AS IS_LOCAL_PARENT'
                . ' FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
                . ' ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME'
                . " WHERE (k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME IN ($marks))"
                . " OR (k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IN ($marks))"
                . ' ORDER BY k.CONSTRAINT_SCHEMA,k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION');
            $query->execute([...$tables, ...$tables]); $foreignKeys = $query->fetchAll(PDO::FETCH_ASSOC); $query->closeCursor();
            $query = $pdo->prepare('SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT,SQL_MODE,DEFINER'
                . " FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ($marks) ORDER BY EVENT_OBJECT_TABLE,TRIGGER_NAME");
            $query->execute($tables);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $triggers[] = ['table' => $row['EVENT_OBJECT_TABLE'], 'name' => $row['TRIGGER_NAME'],
                    'event' => $row['EVENT_MANIPULATION'], 'timing' => $row['ACTION_TIMING'],
                    'definition_sha256' => hash('sha256', serialize($row))];
            }
            $query->closeCursor();
            }
            $query = $pdo->prepare("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)"
                . " AND (LOWER(EXTRA) LIKE '%on update%' OR GENERATION_EXPRESSION<>'') ORDER BY TABLE_NAME,ORDINAL_POSITION");
            $query->execute($tables);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $derivedUpdates[$row['TABLE_NAME']][] = $row['COLUMN_NAME']; }
            $query->closeCursor();
        }
        $scope = ['tables' => $present, 'objects' => $objects, 'foreign_keys' => $foreignKeys, 'triggers' => $triggers,
            'derived_update_columns' => $derivedUpdates,
            'metadata_visibility' => 'current-account-visible; backup client visibility checked separately',
            'coverage_required' => ['complete_table_ddl', 'all_table_rows', 'indexes', 'checks', 'foreign_keys', 'triggers', 'auto_increment'],
            'external_dependencies' => 'referenced objects are not automatically added to restore writes; review FK/trigger effects before restoration',
            'backup_status' => 'not_created', 'restore_status' => 'not_executed', 'execution_admitted' => false];
        if ($allowMissing) { $scope['affected_tables'] = $affected; $scope['absent_original_tables'] = array_values(array_diff($affected, $present)); }
        return $scope;
        } finally { $pdo->exec('SET SESSION information_schema_stats_expiry=' . $expiry); }
    }

    /** Calls only fixed probe/dump programs; emits no database writes or restore command execution. */
    public static function capture(array $binding, array $baseline, string $baselineSha, array $scope, array $tool, string $directory, callable $recheck, string $purpose = 'application-schema'): array
    {
        $captureStarted = time();
        if (!in_array($purpose, ['application-schema', 'application-failure', 'application-restoration', 'plugin-lifecycle',
            'identity-recovery-snapshot', 'identity-recovery-postimage', 'installation-partial', 'task-failure-forensic'], true)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SOURCE_UNSUPPORTED'); }
        $taskForensic = $purpose === 'task-failure-forensic';
        if ($taskForensic && (($baseline['forensic_only'] ?? null) !== true || ($baseline['plugin_key'] ?? null) !== 'official.task'
            || !is_array($baseline['original_failure'] ?? null) || ($baseline['recovery_admission'] ?? null) !== false)) {
            throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_ORIGIN_REQUIRED');
        }
        $identitySnapshot = $purpose === 'identity-recovery-snapshot';
        $identityPostimage = $purpose === 'identity-recovery-postimage';
        if (($identitySnapshot || $identityPostimage) && (($baseline['recovery_profile'] ?? null) !== 'identity-native-install-v1'
            || !in_array($baseline['operation_status'] ?? null, ['failed', 'completed'], true)
            || !is_array($baseline['operation_receipt'] ?? null) || !is_array($baseline['original_backup'] ?? null))) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_ORIGIN_REQUIRED');
        }
        $failure = $purpose === 'application-failure';
        $restored = $purpose === 'application-restoration';
        if ($restored && (($baseline['restoration_postimage'] ?? null) !== true || !is_array($baseline['failure_snapshot'] ?? null))) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_REQUIRED');
        }
        if ($failure && (($baseline['failure_afterimage'] ?? null) !== true || !is_array($baseline['failed_migration'] ?? null)
            || !is_array($baseline['executed_prefix'] ?? null) || !array_is_list($baseline['executed_prefix'])
            || !is_array($baseline['pending'] ?? null) || !array_is_list($baseline['pending'])
            || count($baseline['executed_prefix']) >= count($baseline['pending'])
            || $baseline['executed_prefix'] !== array_slice($baseline['pending'], 0, count($baseline['executed_prefix']))
            || ($baseline['failed_migration']['migration_id'] ?? null) !== $baseline['pending'][count($baseline['executed_prefix'])]
            || !is_array($baseline['original_backup'] ?? null))) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_REQUIRED');
        }
        $partial = $purpose === 'installation-partial';
        $effectMetadata = $partial && ($binding['authority']['kind'] ?? null) === 'app-native-owner'
            ? self::appMetadataOrigin($binding, $baseline, $scope) : null;
        $database = $binding['database']['database'];
        $contract = $tool['source_schema_backup'] ?? null;
        if (($tool['transport'] ?? null) !== 'ssh-docker-exec' || !is_array($contract)
            || ($contract['database_resource_id'] ?? null) !== $binding['resource_id'] || ($contract['database'] ?? null) !== $database
            || !is_array($contract['tables'] ?? null) || array_diff($scope['affected_tables'] ?? $scope['tables'], $contract['tables']) !== []
            || $scope['tables'] === [] || preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $baselineSha) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_SCOPE_REQUIRED');
        }
        if ($partial && (($contract['whole_schema'] ?? null) !== true || ($scope['whole_schema'] ?? null) !== true)) {
            throw new RuntimeException('INSTALL_PARTIAL_WHOLE_SCHEMA_BACKUP_REQUIRED');
        }
        foreach (['ssh_command', 'docker_command', 'mysql_command', 'mysqldump_command', 'timeout_command', 'digest_command'] as $key) {
            if (!is_string($tool[$key] ?? null) || preg_match('~\A/[A-Za-z0-9_./-]+\z~D', $tool[$key]) !== 1 || str_contains($tool[$key], '..')) {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_INVALID');
            }
        }
        foreach (['ssh_alias', 'container_name'] as $key) {
            if (!is_string($tool[$key] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/D', $tool[$key]) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_INVALID');
            }
        }
        $seconds = $contract['timeout_seconds'] ?? null;
        if (!is_int($seconds) || $seconds < 30 || $seconds > 600 || time() + $seconds + 60 >= (int) $binding['authority']['expires_at']) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_LIFETIME_REQUIRED');
        }
        $parent = @lstat(dirname($directory));
        if (!str_starts_with($directory, '/') || realpath(dirname($directory)) !== dirname($directory)
            || !is_array($parent) || ($parent['mode'] & 0022) !== 0 || !in_array($parent['uid'], [0, posix_geteuid()], true)
            || file_exists($directory) || is_link($directory) || !mkdir($directory, 0700) || !chmod($directory, 0700)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE');
        }
        $recheck();
        $receiptBackup = null;
        $originalFiles = [];
        if ($partial) {
            if (!mkdir($directory . '/installation-originals', 0700)) { throw new RuntimeException('INSTALL_PARTIAL_BACKUP_FILES_REQUIRED'); }
            foreach ($baseline['original_files'] ?? [] as $name => $original) {
                if (!in_array($name, ['progress', 'configuration', 'runtime', 'failure', 'environment', 'ledger'], true)) { throw new RuntimeException('INSTALL_PARTIAL_BACKUP_FILES_REQUIRED'); }
                $runtimeOriginal = $name === 'runtime' && array_key_exists('mode', $original);
                if ($runtimeOriginal) {
                    [$actualOriginal, $bytes] = self::partialRuntimeOriginalBytes($binding['source']['server_root']);
                    if ($actualOriginal !== $original) { throw new RuntimeException('INSTALL_PARTIAL_BACKUP_FILES_CHANGED'); }
                } else {
                    self::pluginFile($original['path'] ?? '', $binding['source']['server_root']);
                    $bytes = file_get_contents($original['path']);
                }
                if (!is_string($bytes) || hash('sha256', $bytes) !== $original['sha256']) { throw new RuntimeException('INSTALL_PARTIAL_BACKUP_FILES_CHANGED'); }
                $target = $directory . '/installation-originals/' . $name;
                self::writeBytes($target, $bytes);
                $originalFiles[$name] = ['original' => $original, 'payload' => ['path' => $target, 'sha256' => hash_file('sha256', $target), 'bytes' => filesize($target)]];
                if ($runtimeOriginal) { $originalFiles[$name]['restore_mode'] = $original['mode']; }
            }
            if (count($originalFiles) !== 6) { throw new RuntimeException('INSTALL_PARTIAL_BACKUP_FILES_REQUIRED'); }
            $recheck();
        }
        if ($purpose === 'plugin-lifecycle' && isset($scope['notification_forward_receipt'])) {
            $original = $scope['notification_forward_receipt'];
            $snapshot = PluginLifecycleService::notificationReceiptSnapshot($original['path']);
            $contents = $snapshot['contents'] ?? null; unset($snapshot['contents']);
            if ($snapshot !== $original) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED'); }
            $receiptBackup = ['original' => $original, 'payload' => null, 'restore_executed' => false];
            if ($original['exists']) {
                if (!is_string($contents) || hash('sha256', $contents) !== $original['sha256'] || strlen($contents) !== $original['bytes']) {
                    throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED');
                }
                $receiptPath = $directory . '/notification-forward-original.json';
                self::writeBytes($receiptPath, $contents);
                if (hash_file('sha256', $receiptPath) !== $original['sha256'] || filesize($receiptPath) !== $original['bytes']) {
                    throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED');
                }
                $receiptBackup['payload'] = ['path' => $receiptPath, 'sha256' => $original['sha256'], 'bytes' => $original['bytes']];
            }
            unset($contents);
            $recheck();
        }
        $before = self::probe($tool, $binding, $directory . '/probe-before', 'probe-before', $seconds, $recheck);
        if ($effectMetadata !== null && ($before['mysql_sha256'] !== $effectMetadata['client']['mysql_sha256']
            || $before['identity']['version'] !== $effectMetadata['client']['version'])) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_CLIENT_CHANGED'); }
        $dump = $directory . '/database.sql';
        $flags = ['--no-defaults', '--no-login-paths', '--single-transaction', '--skip-lock-tables', '--quick', '--hex-blob',
            '--triggers', '--skip-routines', '--skip-events', '--no-tablespaces', '--set-gtid-purged=OFF', '--column-statistics=0',
            '--order-by-primary', '--skip-comments', '--skip-dump-date', '--skip-extended-insert', '--skip-add-locks', '--skip-disable-keys',
            '--default-character-set=utf8mb4', '-uroot', '--', $database, ...$scope['tables']];
        if ($partial) {
            $flags = ['--no-defaults', '--no-login-paths', '--single-transaction', '--skip-lock-tables', '--quick', '--hex-blob',
                '--triggers', '--routines', '--events', '--no-tablespaces', '--set-gtid-purged=OFF', '--column-statistics=0',
                '--order-by-primary', '--skip-comments', '--skip-dump-date', '--skip-extended-insert', '--skip-add-locks', '--skip-disable-keys',
                '--default-character-set=utf8mb4', '-uroot', '--databases', $database];
        }
        self::remote($tool, self::clientShell($tool, 'mysqldump_command', $seconds, $flags), $dump, $directory . '/dump.stderr',
            'dump', $seconds, (int) $binding['authority']['expires_at'], $recheck);
        if (filesize($dump) === 0) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_EMPTY'); }
        $after = self::probe($tool, $binding, $directory . '/probe-after', 'probe-after', $seconds, $recheck);
        $recheck();
        if ($before !== $after) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_REMOTE_CHANGED'); }
        if ($receiptBackup !== null) {
            $snapshot = PluginLifecycleService::notificationReceiptSnapshot($receiptBackup['original']['path']);
            unset($snapshot['contents']);
            if ($snapshot !== $receiptBackup['original']) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED'); }
        }
        $restore = ['protocol' => $purpose === 'application-schema' ? 'peanut.source-application-restore-instructions.v1' : 'peanut.source-plugin-restore-instructions.v1', 'source' => $binding['source'],
            'database' => $binding['database'], 'tables' => $scope['tables'], 'native_client' => $before,
            'payload' => ['path' => $dump, 'sha256' => hash_file('sha256', $dump), 'bytes' => filesize($dump)],
            'argv' => self::argv($tool, self::clientShell($tool, 'mysql_command', $seconds,
                ['--no-defaults', '--no-login-paths', '--binary-mode', '--default-character-set=utf8mb4', '-uroot', '--database=' . $database])),
            'stdin' => $dump, 'executed' => false,
            'requirements' => ['Explicit authorization and normal native DDL/exclusive lease for these exact objects; no automatic restore.',
                'Preserve failure/unknown evidence before restoring original ledgers. Exclude affected writers and verify original DB/source identity.',
                'Inspect all recorded inbound/outbound FKs and triggers; resolve outside-object effects before any restore, without expanding scope implicitly.',
                'Restore only the selected tables. Database/accounts/storage/other tables are outside this payload; never DROP DATABASE.',
                'The native dump includes selected-table DROP/CREATE/data/trigger statements. After explicit restore, verify original structure/data and outside dependencies; not only row counts.',
                'Any interrupted/unknown restore requires reconciliation by its original owner; never automatically replay.']];
        if ($partial) {
            $restore['protocol'] = 'peanut.installation-partial-restore-instructions.v1';
            $restore['whole_schema'] = true; $restore['installation_originals'] = $originalFiles;
            $restore['requirements'] = ['Explicit same-database restoration authorization; never DROP DATABASE, change credentials, or restore another schema.',
                'Payload includes the whole current partial schema, data, views, triggers, routines and events using the fixed native dump flags.',
                'Retain the actual failure and any later afterimage before restoration. Preserve exact APP identity, configuration, progress and locks from installation_originals.',
                'The recorded tables are the original object set. Later module-created objects are not removed by replaying this dump; reconcile their actual ownership and separately authorize their exact recovery, never clear the schema.',
                'An old pre-install empty dump is not this partial afterimage. No automatic restore or installation retry is performed.'];
        }
        if ($purpose === 'plugin-lifecycle' || $identitySnapshot || $identityPostimage || $taskForensic) {
            $restore['absent_original_tables'] = $scope['absent_original_tables'];
            $restore['requirements'][] = 'Restore admission must separately cover removal of only the recorded originally absent tables created by this exact operation; the payload cannot recreate absence and no DROP is executed here.';
            $restore['requirements'][] = 'Shared catalog restoration excludes all affected writers, including other tenants/tasks. Preserve audit/failure/unknown evidence before any authorized ledger/audit rollback; never erase it as routine cleanup.';
            if ($receiptBackup !== null) {
                $restore['notification_forward_receipt'] = $receiptBackup;
                $restore['requirements'][] = 'The Notification receipt is not in database.sql. Under explicit exact-file restore authorization and the original native package/source/instance exclusion, preserve the failed receipt bytes/attempt and verify its actual current afterimage belongs to this operation before any change. Restore a present original only from the separately hashed exact byte payload, with original owner/group and 0600 mode under its protected 0700 directory; never re-encode JSON as a replacement for original bytes.';
                $restore['requirements'][] = 'If originally absent, this backup records absence, not permission to delete a current receipt. Remove only a proven receipt created by this exact operation after preserving failure evidence and verifying no later attempt/use. A directory originally absent may be removed only if proven created by this operation and still empty; never recurse or remove other files. A rename followed by directory-fsync error may already have persisted a new receipt: reconcile original-owner filesystem facts, do not assume DB rollback or automatically retry/delete.';
            }
        }
        if ($failure) {
            $restore['protocol'] = 'peanut.source-application-failure-restore-instructions.v1';
            $restore['purpose'] = 'Failed afterimage preservation only; not a pre-migration backup or apply admission.';
        }
        if ($restored) { $restore['protocol'] = 'peanut.source-application-restoration-restore-instructions.v1'; }
        if ($identitySnapshot || $identityPostimage) {
            $restore['purpose'] = $identitySnapshot ? 'Preserve this actual operation afterimage; never a pre-operation backup or apply admission.'
                : 'Restoration postimage only; completion requires the original present-table bytes and every original absence.';
        }
        if ($taskForensic) {
            $restore['protocol'] = 'peanut.source-task-forensic-instructions.v1';
            $restore['purpose'] = 'Failure afterimage preservation only; no restore, lifecycle apply or recovery admission.';
        }
        self::write($directory . '/RESTORE.json', $restore);
        $receipts = [];
        foreach (['probe-before' => 'probe-before.txt', 'dump' => 'database.sql', 'probe-after' => 'probe-after.txt'] as $phase => $file) {
            $receipts[] = ['phase' => $phase, 'path' => $directory . '/' . $file . '.process.json',
                'sha256' => hash_file('sha256', $directory . '/' . $file . '.process.json')];
        }
        $record = ['protocol' => $purpose === 'application-schema' ? 'peanut.source-application-backup.v1' : 'peanut.source-plugin-backup.v1', 'status' => 'captured', 'binding' => $binding,
            'baseline_sha256' => $baselineSha, 'pending' => $baseline['pending'],
            'selected' => array_intersect_key($baseline['selected'], array_flip($baseline['pending'])), 'scope' => $scope,
            'backup_payload' => $restore['payload'], 'restore_instructions' => ['path' => $directory . '/RESTORE.json', 'sha256' => hash_file('sha256', $directory . '/RESTORE.json')],
            'native_client' => $before, 'tooling_resource_id' => $tool['stable_resource_id'], 'adapter_sha256' => hash_file('sha256', __FILE__),
            'receipts' => $receipts,
            'observed_at' => time(), 'restore_status' => 'not_executed', 'qualification' => 'not_run', 'execution_admitted' => false];
        $record['capture_started_at'] = $captureStarted;
        if ($failure) { $record['protocol'] = 'peanut.source-application-failure-snapshot.v1'; $record['failure'] = $baseline; }
        if ($restored) { $record['protocol'] = 'peanut.source-application-restoration-snapshot.v1'; $record['restoration'] = $baseline; }
        if ($identitySnapshot || $identityPostimage) {
            $record['protocol'] = $identitySnapshot ? 'peanut.source-identity-operation-snapshot.v1' : 'peanut.source-identity-operation-postimage.v1';
            $record['identity_operation'] = $baseline;
        }
        if ($taskForensic) { $record['protocol'] = 'peanut.source-task-failure-forensic.v1'; $record['task_failure'] = $baseline; $record['recovery_admission'] = false; }
        if ($partial) { $record['protocol'] = 'peanut.installation-partial-backup.v1'; $record['installation_originals'] = $originalFiles; }
        if ($receiptBackup !== null) { $record['notification_forward_receipt'] = $receiptBackup; }
        self::write($directory . '/backup.json', $record);
        $recovery = ['schema_version' => 1, 'protocol' => $purpose === 'application-schema' ? 'peanut.source-development-recovery.v1' : 'peanut.source-plugin-recovery.v1',
            'source' => $binding['source'], 'database' => $binding['database'], 'authority' => $binding['authority'],
            'registry_sha256' => $binding['registry_sha256'], 'source_database' => $binding['database'],
            'observed_at' => $record['observed_at'], 'expires_at' => (int) $binding['authority']['expires_at'],
            'scope' => $scope['tables'], 'baseline_sha256' => $baselineSha,
            'backup_manifest' => ['path' => $directory . '/backup.json', 'sha256' => hash_file('sha256', $directory . '/backup.json')],
            'backup_payload' => $restore['payload'], 'restore_tool' => ['path' => __FILE__, 'sha256' => hash_file('sha256', __FILE__)],
            'restore_contract_sha256' => hash_file('sha256', $directory . '/RESTORE.json'),
            'receipts' => $receipts];
        $recovery['capture_started_at'] = $captureStarted;
        if ($failure) { $recovery['protocol'] = 'peanut.source-application-failure-reference.v1'; }
        if ($restored) { $recovery['protocol'] = 'peanut.source-application-restoration-reference.v1'; }
        if ($identitySnapshot || $identityPostimage) { $recovery['protocol'] = 'peanut.source-identity-recovery-reference.v1'; }
        if ($taskForensic) { $recovery['protocol'] = 'peanut.source-task-forensic-reference.v1'; $recovery['recovery_admission'] = false; }
        if ($partial) { $recovery['protocol'] = 'peanut.installation-partial-recovery.v1'; }
        $recovery['pending'] = $baseline['pending'];
        $recovery['selected'] = $record['selected'];
        if ($receiptBackup !== null) { $recovery['notification_forward_receipt'] = $receiptBackup; }
        self::write($directory . '/recovery.json', $recovery);
        return ['status' => 'captured', 'path' => $directory . '/backup.json', 'sha256' => hash_file('sha256', $directory . '/backup.json'),
            'recovery_references' => ['path' => $directory . '/recovery.json', 'sha256' => hash_file('sha256', $directory . '/recovery.json')],
            'backup_payload_sha256' => $restore['payload']['sha256'], 'tables' => $scope['tables'],
            'restore' => 'not_executed', 'execution_admitted' => false, 'qualification' => 'not_run'];
    }

    /** Only a real current native partial capture, never the old empty-schema operator stdout. */
    /** Closed recovery profiles only; no caller-selected tables or SQL. */
    public static function failureRestoreTables(string $profile): array
    {
        if (str_starts_with($profile, 'prefix:')) {
            $id = substr($profile, 7); $ids = array_keys(self::TABLES); $index = array_search($id, $ids, true);
            if ($index === false || ($index !== 1 && $index < 3)) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED'); }
            return self::applicationTables(array_slice($ids, 0, $index + 1));
        }
        return match ($profile) {
            'sms-reservation' => ['pa_notice_log', 'pa_schema_migration'],
            'menu-stable-identities' => ['pa_menu_definition', 'pa_notice_log', 'pa_schema_migration', 'pa_system_menu'],
            default => throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED'),
        };
    }

    /** Fixed selected-table restoration from a verified native original, never caller SQL. */
    public static function restoreApplicationFailure(array $binding, array $tool, string $originalManifest, string $directory,
        callable $before, callable $after, string $profile = 'sms-reservation', ?string $currentManifest = null): array
    {
        $context = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        if (PHP_SAPI !== 'cli' || !$context instanceof \app\common\execution\InstanceExecutionContext
            || $context->operation() !== 'console.schema:application' || $context->instance->actorKey !== 'console'
            || !in_array($binding['authority']['operation'] ?? null, ['ddl', 'exclusive'], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_CONTEXT_REQUIRED');
        }
        $before(); $serverRoot = $binding['source']['root'] . '/server';
        $original = self::protectedPluginJson($originalManifest, $serverRoot);
        $payload = $original['backup_payload'] ?? []; $tables = self::failureRestoreTables($profile);
        $contract = $tool['source_schema_backup'] ?? [];
        if (($original['protocol'] ?? null) !== 'peanut.source-application-backup.v1'
            || ($original['binding']['database'] ?? null) !== $binding['database']
            || ($contract['database_resource_id'] ?? null) !== $binding['resource_id']
            || ($contract['database'] ?? null) !== $binding['database']['database']
            || array_diff($tables, $contract['tables'] ?? []) !== [] || ($tool['transport'] ?? null) !== 'ssh-docker-exec') {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_ORIGIN_REQUIRED');
        }
        self::pluginFile($payload['path'] ?? '', $serverRoot, null);
        $bytes = file_get_contents($payload['path']);
        if (!is_string($bytes) || hash('sha256', $bytes) !== ($payload['sha256'] ?? null) || strlen($bytes) !== ($payload['bytes'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED');
        }
        $dump = SourceApplicationDump::read($bytes, $original['scope']['tables']);
        if (($dump['tables']['pa_notice_log']['insert_rows'] ?? null) !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_NONEMPTY_UNSUPPORTED'); }
        if (str_starts_with($profile, 'prefix:')) {
            if ($currentManifest === null) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_CHANGED'); }
            $current = self::protectedPluginJson($currentManifest, $serverRoot);
            $currentPayload = $current['backup_payload'] ?? [];
            if (($current['protocol'] ?? null) !== 'peanut.source-application-failure-snapshot.v1'
                || ($current['binding']['database'] ?? null) !== $binding['database']
                || ($current['scope']['tables'] ?? null) !== $original['scope']['tables']) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_CHANGED'); }
            self::pluginFile($currentPayload['path'] ?? '', $serverRoot, null);
            $currentBytes = file_get_contents($currentPayload['path']);
            if (!is_string($currentBytes) || strlen($currentBytes) !== ($currentPayload['bytes'] ?? null)
                || hash('sha256', $currentBytes) !== ($currentPayload['sha256'] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED'); }
            $derived = SourceApplicationDump::restorePrefix($dump, SourceApplicationDump::read($currentBytes, $original['scope']['tables']), $tables);
        } else {
            $derived = SourceApplicationDump::select($dump, $tables);
        }
        if (realpath(dirname($directory)) !== dirname($directory) || file_exists($directory) || is_link($directory)
            || !mkdir($directory, 0700)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
        $path = $directory . '/selected-tables.sql'; self::writeBytes($path, $derived);
        $reference = ['path' => $path, 'sha256' => hash('sha256', $derived), 'bytes' => strlen($derived)];
        $segments = [];
        foreach ($tables as $table) { $segments[$table] = array_diff_key($dump['tables'][$table], ['bytes' => true]); }
        self::write($directory . '/derivation.json', ['protocol' => 'peanut.source-application-selected-restore.v1',
            'source_payload' => $payload, 'tables' => $tables, 'source_segments' => $segments, 'derived_payload' => $reference,
            'constraint_checks' => 'enabled throughout; fixed native disabling/restoring SETs excluded',
            'source_original_unchanged' => true, 'executed' => false]);
        $seconds = $contract['timeout_seconds'] ?? null;
        if (!is_int($seconds) || $seconds < 30 || $seconds > 600) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_LIFETIME_REQUIRED'); }
        self::remote($tool, self::clientShell($tool, 'mysql_command', $seconds,
            ['--no-defaults', '--no-login-paths', '--skip-reconnect', '--binary-mode', '--default-character-set=utf8mb4', '-uroot', '--database=' . $binding['database']['database']]),
            $directory . '/restore.stdout', $directory . '/restore.stderr', 'restore-selected-failure', $seconds,
            (int) $binding['authority']['expires_at'], $before, null, $reference, $after);
        return ['tables' => $tables, 'payload' => $reference,
            'derivation' => ['path' => $directory . '/derivation.json', 'sha256' => hash_file('sha256', $directory . '/derivation.json')],
            'terminal' => ['path' => $directory . '/restore.stdout.process.json', 'sha256' => hash_file('sha256', $directory . '/restore.stdout.process.json')]];
    }

    /** Explicit fixed Identity inverse through the same native protected-stdin executor. */
    public static function restoreIdentityOperation(PDO $pdo, array $binding, array $tool, string $originalManifest, string $capsuleManifest,
        string $directory, int $windowExpires, callable $guard): array
    {
        $host = app()->make(PluginSourceDevelopmentHost::class);
        $host->requireIdentityRecovery($pdo, 'official.identity', true, true);
        $guard(); $serverRoot = $binding['source']['root'] . '/server';
        $originalBytes = self::protectedPluginBytes($originalManifest, $serverRoot);
        $capsuleBytes = self::protectedPluginBytes($capsuleManifest, $serverRoot);
        $original = json_decode($originalBytes, true, 64, JSON_THROW_ON_ERROR);
        $capsule = json_decode($capsuleBytes, true, 64, JSON_THROW_ON_ERROR);
        $guard();
        $contract = $tool['source_schema_backup'] ?? [];
        if (($original['protocol'] ?? null) !== 'peanut.source-plugin-backup.v1'
            || ($capsule['protocol'] ?? null) !== 'peanut.source-identity-operation-snapshot.v1'
            || ($capsule['identity_operation']['recovery_profile'] ?? null) !== 'identity-native-install-v1'
            || ($capsule['identity_operation']['original_backup'] ?? null) !== ['path' => $originalManifest, 'sha256' => hash('sha256', $originalBytes)]
            || ($original['binding']['database'] ?? null) !== $binding['database'] || ($capsule['binding']['database'] ?? null) !== $binding['database']
            || ($contract['database_resource_id'] ?? null) !== $binding['resource_id'] || ($contract['database'] ?? null) !== $binding['database']['database']
            || ($tool['transport'] ?? null) !== 'ssh-docker-exec'
            || array_diff($capsule['scope']['tables'] ?? [], $contract['tables'] ?? []) !== []) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_ORIGIN_REQUIRED');
        }
        $read = static function (array $record) use ($serverRoot): array {
            $payload = $record['backup_payload'] ?? [];
            $bytes = self::protectedPluginBytes($payload['path'] ?? '', $serverRoot, null);
            if (!is_string($bytes) || hash('sha256', $bytes) !== ($payload['sha256'] ?? null) || strlen($bytes) !== ($payload['bytes'] ?? null)) {
                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_PAYLOAD_CHANGED');
            }
            return SourceApplicationDump::read($bytes, $record['scope']['tables']);
        };
        $old = $read($original); $now = $read($capsule);
        $derived = SourceApplicationDump::restoreIdentity($old, $now);
        $guard();
        if (realpath(dirname($directory)) !== dirname($directory) || file_exists($directory) || is_link($directory)
            || !mkdir($directory, 0700)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
        $path = $directory . '/identity-inverse.sql'; self::writeBytes($path, $derived);
        $reference = ['path' => $path, 'sha256' => hash('sha256', $derived), 'bytes' => strlen($derived)];
        self::write($directory . '/derivation.json', ['protocol' => 'peanut.source-identity-inverse.v1',
            'original_backup' => ['path' => $originalManifest, 'sha256' => hash('sha256', $originalBytes)],
            'operation_capsule' => ['path' => $capsuleManifest, 'sha256' => hash('sha256', $capsuleBytes)],
            'original_payload' => $original['backup_payload'], 'capsule_payload' => $capsule['backup_payload'], 'derived_payload' => $reference,
            'constraint_checks' => 'enabled throughout; shared parents never dropped; generated values never written',
            'atomicity' => 'DML transaction only; CREATE removal, ADD inverse and allocator DDL not atomic; interruption requires reconciliation',
            'executed' => false]);
        $seconds = $contract['timeout_seconds'] ?? null;
        if (!is_int($seconds) || $seconds < 30 || $seconds > 600) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_LIFETIME_REQUIRED'); }
        $recheck = static function () use ($host, $pdo, $guard): void { $host->requireIdentityRecovery($pdo, 'official.identity', true, true); $guard(); };
        self::remote($tool, self::clientShell($tool, 'mysql_command', $seconds,
            ['--no-defaults', '--no-login-paths', '--skip-reconnect', '--binary-mode', '--default-character-set=utf8mb4', '-uroot', '--database=' . $binding['database']['database']]),
            $directory . '/restore.stdout', $directory . '/restore.stderr', 'restore-identity-operation', $seconds,
            min($windowExpires, (int) $binding['authority']['expires_at']), $recheck, null, $reference, $recheck);
        return ['payload' => $reference,
            'derivation' => ['path' => $directory . '/derivation.json', 'sha256' => hash_file('sha256', $directory . '/derivation.json')],
            'terminal' => ['path' => $directory . '/restore.stdout.process.json', 'sha256' => hash_file('sha256', $directory . '/restore.stdout.process.json')]];
    }

    public static function partialInstallationBackup(string $path, string $serverRoot, array $binding, array $baseline, ?array $expectedScope = null): array
    {
        $directory = realpath(dirname($path));
        if (!is_string($directory) || !str_starts_with($directory . '/', rtrim($serverRoot, '/') . '/private/partial-installation/partial-backups/')
            || basename($path) !== 'backup.json' || (fileperms($directory) & 0777) !== 0700) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_BACKUP_REQUIRED'); }
        $record = self::protectedPluginJson($path, $serverRoot);
        $same = static fn(mixed $a, mixed $b): bool => self::pluginDigest($a) === self::pluginDigest($b);
        if (($binding['authority']['kind'] ?? null) === 'app-native-owner') {
            if ($expectedScope === null || !$same($record['scope'] ?? null, $expectedScope)) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_BACKUP_SCOPE_CHANGED'); }
            self::appMetadataOrigin($binding, $baseline, $expectedScope);
        }
        $payload = $record['backup_payload'] ?? []; $restorePath = $directory . '/RESTORE.json';
        $restore = self::protectedPluginJson($restorePath, $serverRoot);
        self::pluginFile($payload['path'] ?? '', $serverRoot, null);
        if (($record['protocol'] ?? null) !== 'peanut.installation-partial-backup.v1' || ($record['status'] ?? null) !== 'captured'
            || !$same($record['binding'] ?? null, $binding) || ($record['baseline_sha256'] ?? null) !== self::pluginDigest($baseline)
            || ($record['scope']['whole_schema'] ?? null) !== true || ($record['adapter_sha256'] ?? null) !== hash_file('sha256', __FILE__)
            || $payload['path'] !== $directory . '/database.sql' || ($payload['bytes'] ?? 0) <= 0 || filesize($payload['path']) !== $payload['bytes']
            || hash_file('sha256', $payload['path']) !== ($payload['sha256'] ?? null)
            || ($record['restore_instructions']['path'] ?? null) !== $restorePath || hash_file('sha256', $restorePath) !== ($record['restore_instructions']['sha256'] ?? null)
            || ($restore['protocol'] ?? null) !== 'peanut.installation-partial-restore-instructions.v1' || ($restore['executed'] ?? null) !== false
            || ($restore['whole_schema'] ?? null) !== true || !$same($restore['database'] ?? null, $binding['database'])
            || !$same($restore['payload'] ?? null, $payload) || !$same($restore['installation_originals'] ?? null, $record['installation_originals'] ?? null)
            || !is_int($record['capture_started_at'] ?? null) || !is_int($record['observed_at'] ?? null)
            || $record['capture_started_at'] > $record['observed_at'] || $record['observed_at'] > time()
            || $record['capture_started_at'] < $binding['authority']['created_at'] || $record['observed_at'] >= $binding['authority']['expires_at']) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_BACKUP_REQUIRED'); }
        if (array_column($record['receipts'] ?? [], 'phase') !== ['probe-before', 'dump', 'probe-after']) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_BACKUP_REQUIRED'); }
        foreach ($record['receipts'] as $receipt) {
            $out = $directory . '/' . match ($receipt['phase']) { 'probe-before' => 'probe-before.txt', 'dump' => 'database.sql', 'probe-after' => 'probe-after.txt' };
            if ($receipt['path'] !== $out . '.process.json') { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_BACKUP_REQUIRED'); }
            $terminal = self::protectedPluginJson($receipt['path'], $serverRoot); self::pluginFile($out, $serverRoot, $receipt['phase'] === 'dump' ? null : 16384);
            if (hash_file('sha256', $receipt['path']) !== $receipt['sha256'] || ($terminal['rc'] ?? null) !== 0
                || ($terminal['local_process_terminal'] ?? null) !== true || ($terminal['local_deadline_exceeded'] ?? null) !== false
                || ($terminal['remote_success_terminal_confirmed'] ?? null) !== true
                || !$same($terminal['stdout'] ?? null, ['path' => $out, 'sha256' => hash_file('sha256', $out), 'bytes' => filesize($out)])) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_BACKUP_REQUIRED'); }
        }
        if (array_keys($record['installation_originals']) !== array_keys($baseline['original_files'])) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_BACKUP_REQUIRED'); }
        foreach ($baseline['original_files'] as $name => $original) {
            $saved = $record['installation_originals'][$name]; self::pluginFile($saved['payload']['path'] ?? '', $serverRoot);
            if (!$same($saved['original'], $original) || $saved['payload']['path'] !== $directory . '/installation-originals/' . $name
                || $saved['payload']['sha256'] !== $original['sha256'] || hash_file('sha256', $saved['payload']['path']) !== $original['sha256'] || filesize($saved['payload']['path']) !== $saved['payload']['bytes']) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_BACKUP_REQUIRED'); }
            if ($name === 'runtime' && array_key_exists('mode', $original)
                && ($saved['restore_mode'] ?? null) !== $original['mode']) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_BACKUP_REQUIRED'); }
        }
        if (hash_file('sha256', $directory . '/probe-before.txt') !== hash_file('sha256', $directory . '/probe-after.txt')) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_BACKUP_REQUIRED'); }
        return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'payload' => $payload, 'restore' => ['path' => $restorePath, 'sha256' => hash_file('sha256', $restorePath)],
            'observed_at' => $record['observed_at'], 'scope' => $record['scope'], 'restore_executed' => false];
    }

    public static function writePartialInstallationRecord(string $path, array $plan): void
    {
        if (file_exists($path) || is_link($path)) { throw new RuntimeException('INSTALL_PARTIAL_PLAN_EXISTS'); }
        self::write($path, $plan);
    }

    /** Reads a protected local source document; its contents alone never authorize mutation. */
    public static function protectedPluginJson(string $path, string $serverRoot): array
    {
        $value = json_decode(self::protectedPluginBytes($path, $serverRoot), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) { throw new RuntimeException('PLUGIN_SOURCE_DOCUMENT_INVALID'); }
        return $value;
    }

    /** One protected read binds validation, parsing and digest to the same bytes. */
    public static function protectedPluginBytes(string $path, string $serverRoot, ?int $maximum = 4194304): string
    {
        $stat = self::pluginFile($path, $serverRoot, $maximum);
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) { throw new RuntimeException('PLUGIN_SOURCE_DOCUMENT_UNSAFE'); }
        try {
            $opened = fstat($stream); $bytes = stream_get_contents($stream); $after = fstat($stream);
            clearstatcache(true, $path); $physical = lstat($path);
            $fields = array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']);
            foreach ([$opened, $after, $physical] as $actual) {
                if (!is_array($actual) || array_intersect_key($stat, $fields) !== array_intersect_key($actual, $fields)) {
                    throw new RuntimeException('PLUGIN_SOURCE_DOCUMENT_CHANGED');
                }
            }
            if (is_link($path) || realpath($path) !== $path || !is_string($bytes) || strlen($bytes) !== $stat['size']) {
                throw new RuntimeException('PLUGIN_SOURCE_DOCUMENT_CHANGED');
            }
            return $bytes;
        } finally { fclose($stream); }
    }

    /** Only this canonical public program identity may be a 0644 partial original. Payloads stay 0600. */
    public static function partialRuntimeOriginalReference(string $serverRoot): array
    {
        return self::partialRuntimeOriginalBytes($serverRoot)[0];
    }

    private static function partialRuntimeOriginalBytes(string $serverRoot): array
    {
        $path = $serverRoot . '/.peanut/development-runtime.json'; $owner = fileowner($serverRoot);
        $stat = @lstat($path);
        if (realpath($serverRoot) !== $serverRoot || realpath(dirname($path)) !== dirname($path)
            || realpath($path) !== $path || !is_file($path) || is_link($path) || !is_array($stat)
            || $stat['nlink'] !== 1 || ($stat['mode'] & 07000) !== 0 || !in_array($stat['mode'] & 0777, [0600, 0644], true)
            || !in_array($stat['uid'], [0, $owner], true) || !function_exists('posix_geteuid') || !in_array(posix_geteuid(), [0, $owner], true)
            || $stat['size'] > 4194304) { throw new RuntimeException('INSTALL_PARTIAL_RUNTIME_ORIGINAL_UNSAFE'); }
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) { throw new RuntimeException('INSTALL_PARTIAL_RUNTIME_ORIGINAL_UNAVAILABLE'); }
        try {
            $opened = fstat($stream); $bytes = stream_get_contents($stream); $after = fstat($stream);
            clearstatcache(true, $path); $physical = lstat($path);
            $fields = array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']);
            if (!is_array($opened) || !is_array($after) || !is_array($physical) || is_link($path)
                || array_intersect_key($stat, $fields) !== array_intersect_key($opened, $fields)
                || array_intersect_key($stat, $fields) !== array_intersect_key($after, $fields)
                || array_intersect_key($stat, $fields) !== array_intersect_key($physical, $fields)
                || !is_string($bytes) || strlen($bytes) !== $stat['size']) { throw new RuntimeException('INSTALL_PARTIAL_RUNTIME_ORIGINAL_CHANGED'); }
        } finally { fclose($stream); }
        return [['path' => $path, 'sha256' => hash('sha256', $bytes), 'mode' => $stat['mode'] & 07777,
            'device' => $stat['dev'], 'inode' => $stat['ino'], 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'bytes' => $stat['size']], $bytes];
    }

    private static function pluginFile(string $path, string $serverRoot, ?int $maximum = 4194304): array
    {
        $stat = @lstat($path); $owner = fileowner(rtrim($serverRoot, DIRECTORY_SEPARATOR));
        if (realpath($path) !== $path || !is_file($path) || is_link($path) || !is_array($stat)
            || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600 || !in_array($stat['uid'], [0, $owner], true)
            || !function_exists('posix_geteuid') || !in_array(posix_geteuid(), [0, $owner], true)
            || ($maximum !== null && $stat['size'] > $maximum)) { throw new RuntimeException('PLUGIN_SOURCE_DOCUMENT_UNSAFE'); }
        return $stat;
    }

    /** Actual native capture and current exclusion references, never a restore or SQL authorization. */
    public static function pluginRecovery(array $binding, array $baseline, array $scope, string $maintenancePath, string $recoveryPath, string $serverRoot, ?int $recordedAt = null): array
    {
        $at = $recordedAt ?? time();
        if ($at <= 0 || $at > time()) { throw new RuntimeException('PLUGIN_SOURCE_EVIDENCE_REQUIRED'); }
        $same = static fn(mixed $a, mixed $b): bool => self::pluginDigest($a) === self::pluginDigest($b);
        $pending = $baseline['pending']; $selected = array_intersect_key($baseline['selected'], array_flip($pending));
        $maintenance = self::protectedPluginJson($maintenancePath, $serverRoot);
        $recovery = self::protectedPluginJson($recoveryPath, $serverRoot);
        foreach (['maintenance' => $maintenance, 'recovery' => $recovery] as $kind => $record) {
            if (($record['schema_version'] ?? null) !== 1 || ($record['protocol'] ?? null) !== 'peanut.source-plugin-' . $kind . '.v1'
                || !$same($record['source'] ?? null, $binding['source']) || !$same($record['database'] ?? null, $binding['database'])
                || !$same($record['authority'] ?? null, $binding['authority']) || ($record['registry_sha256'] ?? null) !== $binding['registry_sha256']
                || ($record['baseline_sha256'] ?? null) !== self::pluginDigest($baseline)
                || ($record['pending'] ?? null) !== $pending || !$same($record['selected'] ?? null, $selected)
                || ($record['scope'] ?? null) !== $scope['tables'] || !is_int($record['observed_at'] ?? null)
                || !is_int($record['expires_at'] ?? null) || $record['observed_at'] < $binding['authority']['created_at']
                || $record['observed_at'] > $at || $record['expires_at'] <= $at || $record['expires_at'] > $binding['authority']['expires_at']
                || !is_array($record['receipts'] ?? null) || $record['receipts'] === []) { throw new RuntimeException('PLUGIN_SOURCE_EVIDENCE_REQUIRED'); }
            foreach ($record['receipts'] as $receipt) {
                self::pluginFile($receipt['path'] ?? '', $serverRoot);
                if (hash_file('sha256', $receipt['path']) !== ($receipt['sha256'] ?? null)) { throw new RuntimeException('PLUGIN_SOURCE_EVIDENCE_CHANGED'); }
            }
        }
        if (($maintenance['state'] ?? null) !== 'active' || ($maintenance['excluded_writers'] ?? null) !== ['http', 'workers', 'other-writers']) {
            throw new RuntimeException('PLUGIN_SOURCE_WINDOW_REQUIRED');
        }
        $manifestPath = $recovery['backup_manifest']['path'] ?? '';
        $manifest = self::protectedPluginJson($manifestPath, $serverRoot);
        $restorePath = $manifest['restore_instructions']['path'] ?? '';
        $restore = self::protectedPluginJson($restorePath, $serverRoot);
        $payload = $recovery['backup_payload'] ?? [];
        self::pluginFile($payload['path'] ?? '', $serverRoot, null);
        if (($manifest['protocol'] ?? null) !== 'peanut.source-plugin-backup.v1' || ($manifest['status'] ?? null) !== 'captured'
            || hash_file('sha256', $manifestPath) !== ($recovery['backup_manifest']['sha256'] ?? null)
            || !$same($manifest['binding'] ?? null, $binding) || !$same($manifest['scope'] ?? null, $scope)
            || ($manifest['baseline_sha256'] ?? null) !== self::pluginDigest($baseline)
            || ($manifest['pending'] ?? null) !== $pending || !$same($manifest['selected'] ?? null, $selected)
            || !$same($manifest['backup_payload'] ?? null, $payload) || hash_file('sha256', $payload['path']) !== ($payload['sha256'] ?? null)
            || filesize($payload['path']) !== ($payload['bytes'] ?? null) || $payload['bytes'] <= 0
            || !is_int($manifest['capture_started_at'] ?? null) || $manifest['capture_started_at'] < $maintenance['observed_at']
            || $manifest['capture_started_at'] > ($manifest['observed_at'] ?? 0)
            || ($recovery['capture_started_at'] ?? null) !== $manifest['capture_started_at']
            || ($recovery['observed_at'] ?? null) !== $manifest['observed_at'] || !$same($recovery['source_database'] ?? null, $binding['database'])
            || ($recovery['restore_tool']['path'] ?? null) !== __FILE__ || ($recovery['restore_tool']['sha256'] ?? null) !== hash_file('sha256', __FILE__)
            || ($manifest['adapter_sha256'] ?? null) !== hash_file('sha256', __FILE__)
            || hash_file('sha256', $restorePath) !== ($manifest['restore_instructions']['sha256'] ?? null)
            || ($recovery['restore_contract_sha256'] ?? null) !== hash_file('sha256', $restorePath)
            || ($restore['protocol'] ?? null) !== 'peanut.source-plugin-restore-instructions.v1' || ($restore['executed'] ?? null) !== false
            || !$same($restore['source'] ?? null, $binding['source']) || !$same($restore['database'] ?? null, $binding['database'])
            || !$same($restore['native_client'] ?? null, $manifest['native_client'] ?? null)
            || !$same($restore['payload'] ?? null, $payload) || ($restore['tables'] ?? null) !== $scope['tables']
            || ($restore['absent_original_tables'] ?? null) !== $scope['absent_original_tables']
            || !$same($manifest['receipts'] ?? null, $recovery['receipts'])
            || array_column($recovery['receipts'], 'phase') !== ['probe-before', 'dump', 'probe-after']) { throw new RuntimeException('PLUGIN_SOURCE_NATIVE_CAPTURE_REQUIRED'); }
        foreach ($recovery['receipts'] as $receipt) {
            $stdout = dirname($manifestPath) . '/' . match ($receipt['phase']) {
                'probe-before' => 'probe-before.txt', 'dump' => 'database.sql', 'probe-after' => 'probe-after.txt',
            };
            if ($receipt['path'] !== $stdout . '.process.json') { throw new RuntimeException('PLUGIN_SOURCE_NATIVE_CAPTURE_REQUIRED'); }
            self::pluginFile($stdout, $serverRoot, $receipt['phase'] === 'dump' ? null : 4194304);
            $terminal = self::protectedPluginJson($receipt['path'], $serverRoot);
            $actual = ['path' => $stdout, 'sha256' => hash_file('sha256', $stdout), 'bytes' => filesize($stdout)];
            if (($terminal['phase'] ?? null) !== $receipt['phase'] || ($terminal['rc'] ?? null) !== 0
                || ($terminal['local_process_terminal'] ?? null) !== true || ($terminal['local_deadline_exceeded'] ?? null) !== false
                || ($terminal['remote_success_terminal_confirmed'] ?? null) !== true || !$same($terminal['stdout'] ?? null, $actual)
                || ($receipt['phase'] === 'dump' && !$same($actual, $payload))) { throw new RuntimeException('PLUGIN_SOURCE_NATIVE_CAPTURE_REQUIRED'); }
        }
        $original = $scope['notification_forward_receipt'] ?? null;
        if ($original !== null) {
            $receipt = $manifest['notification_forward_receipt'] ?? [];
            if (!$same($receipt['original'] ?? null, $original) || !$same($receipt, $restore['notification_forward_receipt'] ?? null)
                || !$same($receipt, $recovery['notification_forward_receipt'] ?? null) || ($receipt['restore_executed'] ?? null) !== false) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED');
            }
            if ($original['exists']) {
                $file = $receipt['payload']['path'] ?? ''; self::pluginFile($file, $serverRoot);
                if (hash_file('sha256', $file) !== $original['sha256'] || filesize($file) !== $original['bytes']
                    || !$same($receipt['payload'], ['path' => $file, 'sha256' => $original['sha256'], 'bytes' => $original['bytes']])) {
                    throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED');
                }
            } elseif (($receipt['payload'] ?? null) !== null) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED'); }
        }
        return ['maintenance' => ['path' => $maintenancePath, 'sha256' => hash_file('sha256', $maintenancePath)],
            'recovery' => ['path' => $recoveryPath, 'sha256' => hash_file('sha256', $recoveryPath)],
            'backup_manifest' => $recovery['backup_manifest'], 'backup_payload' => $payload,
            'restore_contract_sha256' => $recovery['restore_contract_sha256']];
    }

    private static function pluginDigest(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as &$child) { $child = $sort($child); }
            return $item;
        };
        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function probe(array $tool, array $binding, string $prefix, string $phase, int $seconds, callable $recheck): array
    {
        $sql = "SELECT JSON_OBJECT('server_uuid',@@server_uuid,'server_hostname',@@hostname,'server_port',@@port,'database',DATABASE(),'version',VERSION()); SHOW GRANTS;";
        $shell = self::quote([$tool['digest_command'], $tool['mysql_command'], $tool['mysqldump_command']]) . ' && '
            . self::quote([$tool['mysql_command'], '--version']) . ' && ' . self::quote([$tool['mysqldump_command'], '--version']) . ' && '
            . '(' . self::clientShell($tool, 'mysql_command', $seconds, ['--no-defaults', '--no-login-paths', '--batch', '--raw', '--skip-column-names', '-uroot', '--execute=' . $sql, '--database=' . $binding['database']['database']]) . ')';
        self::remote($tool, $shell, $prefix . '.txt', $prefix . '.stderr', $phase, $seconds, (int) $binding['authority']['expires_at'], $recheck);
        if (filesize($prefix . '.txt') > 16384) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROBE_INVALID'); }
        $lines = preg_split('/\r?\n/', trim((string) file_get_contents($prefix . '.txt')));
        if (count($lines) < 6 || preg_match('/\A([a-f0-9]{64})\s+/', $lines[0], $mysql) !== 1
            || preg_match('/\A([a-f0-9]{64})\s+/', $lines[1], $dump) !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROBE_INVALID'); }
        $identity = json_decode($lines[4], true, 32, JSON_THROW_ON_ERROR);
        foreach (['server_uuid', 'server_hostname', 'database'] as $key) {
            if (($identity[$key] ?? null) !== $binding['database'][$key]) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_REMOTE_MISMATCH'); }
        }
        $triggerVisible = false; $selectedVisible = false;
        foreach (array_slice($lines, 5) as $grant) {
            if (str_starts_with($grant, 'REVOKE ')) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_CLIENT_VISIBILITY_REQUIRED'); }
            if (preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match) !== 1
                || !in_array($match[2], ['*.*', '`' . $binding['database']['database'] . '`.*'], true)) { continue; }
            $privileges = array_map('trim', explode(',', $match[1]));
            $triggerVisible = $triggerVisible || in_array('TRIGGER', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
            $selectedVisible = $selectedVisible || in_array('SELECT', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
        }
        if ((int) ($identity['server_port'] ?? 0) !== $binding['database']['server_port'] || !$triggerVisible || !$selectedVisible
            || !is_string($identity['version'] ?? null) || preg_match('/\A8\.4\.[0-9]+\z/D', $identity['version']) !== 1
            || ($tool['source_schema_backup']['mysql_version'] ?? null) !== $identity['version']
            || !str_contains($lines[2], 'Ver ' . $identity['version'] . ' ') || !str_contains($lines[3], 'Ver ' . $identity['version'] . ' ')) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_CLIENT_UNSUPPORTED');
        }
        return ['identity' => $identity, 'mysql_sha256' => $mysql[1], 'mysqldump_sha256' => $dump[1],
            'client_visibility' => 'direct whole-schema SELECT and TRIGGER; no observed partial revoke'];
    }

    private static function clientShell(array $tool, string $client, int $seconds, array $arguments): string
    {
        return 'MYSQL_PWD=${MYSQL_ROOT_PASSWORD:?}; export MYSQL_PWD; exec '
            . self::quote([$tool['timeout_command'], '-k', '5s', $seconds . 's', $tool[$client], ...$arguments]);
    }

    private static function quote(array $arguments): string { return implode(' ', array_map('escapeshellarg', $arguments)); }

    private static function argv(array $tool, string $shell): array
    {
        $remote = self::quote([$tool['docker_command'], 'exec', '-i', $tool['container_name'], $tool['timeout_command'], '-k', '5s',
            $tool['source_schema_backup']['timeout_seconds'] . 's', '/bin/sh', '-c', $shell]);
        return [$tool['ssh_command'], '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=5',
            '-o', 'ServerAliveInterval=5', '-o', 'ServerAliveCountMax=3', $tool['ssh_alias'], $remote];
    }

    private static function remote(array $tool, string $shell, string $output, string $error, string $phase, int $seconds, int $expires, callable $recheck,
        ?int $maximumBytes = null, ?array $stdin = null, ?callable $afterRecheck = null): void
    {
        $recheck();
        if (time() + $seconds + 60 >= $expires) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_LIFETIME_REQUIRED'); }
        // Bound SSH itself, including a stalled Docker daemon/exec startup.
        // The remote timeout cannot cover that transport phase.
        $deadline = hrtime(true) + min($seconds + 15, $expires - time() - 10) * 1000000000;
        $out = fopen($output, 'x+b'); $err = fopen($error, 'x+b');
        if ($out === false || $err === false) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
        $input = null;
        try {
            if (!chmod($output, 0600) || !chmod($error, 0600)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
            if ($stdin !== null) {
                $path = $stdin['path'] ?? ''; $stat = @lstat($path);
                if (!is_string($path) || realpath($path) !== $path || !is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
                    || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid()) {
                    throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE');
                }
                $input = fopen($path, 'rb');
                if ($input === false) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED'); }
                $actual = fstat($input); $hash = hash_init('sha256'); hash_update_stream($hash, $input);
                if ($actual['ino'] !== $stat['ino'] || $actual['dev'] !== $stat['dev'] || $actual['size'] !== ($stdin['bytes'] ?? null)
                    || hash_final($hash) !== ($stdin['sha256'] ?? null) || !rewind($input)) {
                    throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED');
                }
                $recheck();
            }
            $environment = ['PATH' => '/usr/bin:/bin:/usr/sbin:/sbin', 'LANG' => 'C', 'LC_ALL' => 'C'];
            foreach (['HOME', 'SSH_AUTH_SOCK'] as $name) { if (is_string(getenv($name))) { $environment[$name] = getenv($name); } }
            $process = proc_open(self::argv($tool, $shell), [0 => $input ?? ['file', '/dev/null', 'r'], 1 => $out, 2 => $err], $pipes, null, $environment);
            if (!is_resource($process)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROCESS_UNAVAILABLE'); }
            $timedOut = false; $outputExceeded = false;
            while (true) {
                $state = proc_get_status($process);
                if (!$state['running']) { break; }
                $outputExceeded = $maximumBytes !== null && (fstat($out)['size'] + fstat($err)['size']) > $maximumBytes;
                if (hrtime(true) >= $deadline || $outputExceeded) {
                    $timedOut = hrtime(true) >= $deadline;
                    proc_terminate($process);
                    foreach ([15, 9] as $signal) {
                        if ($signal === 9) { proc_terminate($process, 9); }
                        $grace = hrtime(true) + 2000000000;
                        do { usleep(50000); $state = proc_get_status($process); }
                        while ($state['running'] && hrtime(true) < $grace);
                        if (!$state['running']) { break; }
                    }
                    break;
                }
                usleep(50000);
            }
            $terminal = !$state['running'];
            $outputExceeded = $outputExceeded || ($maximumBytes !== null && (fstat($out)['size'] + fstat($err)['size']) > $maximumBytes);
            $closed = $terminal ? proc_close($process) : null;
            $rc = $terminal && $state['exitcode'] >= 0 ? $state['exitcode'] : ($closed !== null && $closed >= 0 ? $closed : null);
            if (!fflush($out) || !fsync($out) || !fflush($err) || !fsync($err)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SYNC_FAILED'); }
            self::write($output . '.process.json', ['phase' => $phase, 'local_process_terminal' => $terminal, 'rc' => $rc,
                'local_deadline_exceeded' => $timedOut, 'local_output_limit_exceeded' => $outputExceeded,
                'remote_success_terminal_confirmed' => !$timedOut && !$outputExceeded && $terminal && $rc === 0,
                'stdout' => ['path' => $output, 'sha256' => hash_file('sha256', $output), 'bytes' => filesize($output)],
                'stderr_sha256' => hash_file('sha256', $error),
                'note' => 'Nonzero/transport failures preserve partial output; no success record or automatic retry.']);
            if ($timedOut || $outputExceeded || !$terminal || $rc !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROCESS_FAILED'); }
            ($afterRecheck ?? $recheck)();
        } finally { if (is_resource($input)) { fclose($input); } fclose($out); fclose($err); }
    }

    private static function write(string $path, array $value): void
    {
        self::writeBytes($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function writeBytes(string $path, string $data): void
    {
        $stream = fopen($path, 'x+b');
        if ($stream === false) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
        try {
            if (!chmod($path, 0600)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
            $offset = 0;
            while ($offset < strlen($data)) {
                $count = fwrite($stream, substr($data, $offset));
                if ($count === false || $count === 0) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_WRITE_FAILED'); }
                $offset += $count;
            }
            if (!fflush($stream) || !fsync($stream)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SYNC_FAILED'); }
        } finally { fclose($stream); }
        $directory = fopen(dirname($path), 'r');
        if ($directory === false) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SYNC_FAILED'); }
        try { if (!fsync($directory)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SYNC_FAILED'); } }
        finally { fclose($directory); }
    }
}
