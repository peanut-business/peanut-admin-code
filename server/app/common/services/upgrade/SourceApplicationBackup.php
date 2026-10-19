<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use PDO;
use RuntimeException;
use app\platform\services\plugin\PluginLifecycleService;

/** Native selected-table mysqldump for source application and closed Plugin lifecycle effects. */
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
    private const MODULE_DDL_STAGES = [
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
        '20261016-module-migration-provenance' => ['pa_module_migration'],
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
    public static function scope(PDO $pdo, array $pending): array
    {
        $tables = self::applicationTables($pending);
        if ($pending !== []) {
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
        $scope = self::observe($pdo, $tables, false);
        $scope['execution_requirements'] = [];
        foreach ($pending as $id) {
            array_push($scope['execution_requirements'], ...self::applicationStageRequirements($scope, $id));
        }
        return $scope;
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
                $member = self::MEMBER_STAGES[$id] ?? null;
                if ($key === 'official.member' && $member !== null && $sha === $member[0]
                    && ($migration['disposition'] ?? null) === 'execute') {
                    array_push($tables, ...$member[1]);
                    $writeRequirements[] = ['module_key' => $key, 'migration_key' => $id, 'code' => 'PLUGIN_SOURCE_MEMBER_PRESERVATION_REQUIRED',
                        'contract' => 'PeanutAdmin\\Modules\\Member\\Contract\\MemberSessionMigrationPreservation',
                        'api' => 'before(PDO, canonicalMigrationKey), after(PDO, innerPrivateState); absent targets, fixed native DDL and same-PDO completion required'];
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
    public static function pluginScope(PDO $pdo, array $effects): array
    {
        if (($effects['closure_complete'] ?? null) !== true) { throw new RuntimeException('PLUGIN_SOURCE_BACKUP_EFFECTS_INCOMPLETE'); }
        $scope = self::observe($pdo, $effects['tables'], true);
        if (($effects['notification_forward_receipt'] ?? null) !== null) {
            $expected = $effects['notification_forward_receipt'];
            $snapshot = PluginLifecycleService::notificationReceiptSnapshot((string) ($expected['path'] ?? ''));
            unset($snapshot['contents']);
            if ($snapshot !== $expected) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED'); }
            $scope['notification_forward_receipt'] = $snapshot;
        }
        return $scope;
    }

    private static function observe(PDO $pdo, array $tables, bool $allowMissing): array
    {
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
    }

    /** Calls only fixed probe/dump programs; emits no database writes or restore command execution. */
    public static function capture(array $binding, array $baseline, string $baselineSha, array $scope, array $tool, string $directory, callable $recheck, string $purpose = 'application-schema'): array
    {
        $captureStarted = time();
        if (!in_array($purpose, ['application-schema', 'plugin-lifecycle'], true)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SOURCE_UNSUPPORTED'); }
        $database = $binding['database']['database'];
        $contract = $tool['source_schema_backup'] ?? null;
        if (($tool['transport'] ?? null) !== 'ssh-docker-exec' || !is_array($contract)
            || ($contract['database_resource_id'] ?? null) !== $binding['resource_id'] || ($contract['database'] ?? null) !== $database
            || !is_array($contract['tables'] ?? null) || array_diff($scope['affected_tables'] ?? $scope['tables'], $contract['tables']) !== []
            || $scope['tables'] === [] || preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $baselineSha) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_SCOPE_REQUIRED');
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
        $dump = $directory . '/database.sql';
        $flags = ['--no-defaults', '--no-login-paths', '--single-transaction', '--skip-lock-tables', '--quick', '--hex-blob',
            '--triggers', '--skip-routines', '--skip-events', '--no-tablespaces', '--set-gtid-purged=OFF', '--column-statistics=0',
            '--order-by-primary', '--skip-comments', '--skip-dump-date', '--skip-extended-insert', '--skip-add-locks', '--skip-disable-keys',
            '--default-character-set=utf8mb4', '-uroot', '--', $database, ...$scope['tables']];
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
        if ($purpose === 'plugin-lifecycle') {
            $restore['absent_original_tables'] = $scope['absent_original_tables'];
            $restore['requirements'][] = 'Restore admission must separately cover removal of only the recorded originally absent tables created by this exact operation; the payload cannot recreate absence and no DROP is executed here.';
            $restore['requirements'][] = 'Shared catalog restoration excludes all affected writers, including other tenants/tasks. Preserve audit/failure/unknown evidence before any authorized ledger/audit rollback; never erase it as routine cleanup.';
            if ($receiptBackup !== null) {
                $restore['notification_forward_receipt'] = $receiptBackup;
                $restore['requirements'][] = 'The Notification receipt is not in database.sql. Under explicit exact-file restore authorization and the original native package/source/instance exclusion, preserve the failed receipt bytes/attempt and verify its actual current afterimage belongs to this operation before any change. Restore a present original only from the separately hashed exact byte payload, with original owner/group and 0600 mode under its protected 0700 directory; never re-encode JSON as a replacement for original bytes.';
                $restore['requirements'][] = 'If originally absent, this backup records absence, not permission to delete a current receipt. Remove only a proven receipt created by this exact operation after preserving failure evidence and verifying no later attempt/use. A directory originally absent may be removed only if proven created by this operation and still empty; never recurse or remove other files. A rename followed by directory-fsync error may already have persisted a new receipt: reconcile original-owner filesystem facts, do not assume DB rollback or automatically retry/delete.';
            }
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
        if ($purpose === 'application-schema') { $record['capture_started_at'] = $captureStarted; }
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
        if ($purpose === 'application-schema') {
            $recovery['capture_started_at'] = $captureStarted;
            $recovery['pending'] = $baseline['pending'];
            $recovery['selected'] = $record['selected'];
        } else {
            $recovery['migration_sha256'] = count($baseline['pending']) === 1 ? $baseline['selected'][$baseline['pending'][0]] : null;
        }
        if ($receiptBackup !== null) { $recovery['notification_forward_receipt'] = $receiptBackup; }
        self::write($directory . '/recovery.json', $recovery);
        return ['status' => 'captured', 'path' => $directory . '/backup.json', 'sha256' => hash_file('sha256', $directory . '/backup.json'),
            'recovery_references' => ['path' => $directory . '/recovery.json', 'sha256' => hash_file('sha256', $directory . '/recovery.json')],
            'backup_payload_sha256' => $restore['payload']['sha256'], 'tables' => $scope['tables'],
            'restore' => 'not_executed', 'execution_admitted' => false, 'qualification' => 'not_run'];
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

    private static function remote(array $tool, string $shell, string $output, string $error, string $phase, int $seconds, int $expires, callable $recheck): void
    {
        $recheck();
        if (time() + $seconds + 60 >= $expires) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_LIFETIME_REQUIRED'); }
        // Bound SSH itself, including a stalled Docker daemon/exec startup.
        // The remote timeout cannot cover that transport phase.
        $deadline = hrtime(true) + min($seconds + 15, $expires - time() - 10) * 1000000000;
        $out = fopen($output, 'x+b'); $err = fopen($error, 'x+b');
        if ($out === false || $err === false) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
        try {
            if (!chmod($output, 0600) || !chmod($error, 0600)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
            $environment = ['PATH' => '/usr/bin:/bin:/usr/sbin:/sbin', 'LANG' => 'C', 'LC_ALL' => 'C'];
            foreach (['HOME', 'SSH_AUTH_SOCK'] as $name) { if (is_string(getenv($name))) { $environment[$name] = getenv($name); } }
            $process = proc_open(self::argv($tool, $shell), [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err], $pipes, null, $environment);
            if (!is_resource($process)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROCESS_UNAVAILABLE'); }
            $timedOut = false;
            while (true) {
                $state = proc_get_status($process);
                if (!$state['running']) { break; }
                if (hrtime(true) >= $deadline) {
                    $timedOut = true;
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
            $closed = $terminal ? proc_close($process) : null;
            $rc = $terminal && $state['exitcode'] >= 0 ? $state['exitcode'] : ($closed !== null && $closed >= 0 ? $closed : null);
            if (!fflush($out) || !fsync($out) || !fflush($err) || !fsync($err)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SYNC_FAILED'); }
            self::write($output . '.process.json', ['phase' => $phase, 'local_process_terminal' => $terminal, 'rc' => $rc,
                'local_deadline_exceeded' => $timedOut, 'remote_success_terminal_confirmed' => !$timedOut && $terminal && $rc === 0,
                'stdout' => ['path' => $output, 'sha256' => hash_file('sha256', $output), 'bytes' => filesize($output)],
                'stderr_sha256' => hash_file('sha256', $error),
                'note' => 'Nonzero/transport failures preserve partial output; no success record or automatic retry.']);
            if ($timedOut || !$terminal || $rc !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROCESS_FAILED'); }
            $recheck();
        } finally { fclose($out); fclose($err); }
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
