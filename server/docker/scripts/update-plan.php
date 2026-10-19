#!/usr/bin/env php
<?php

declare(strict_types=1);

final class PeanutServerUpdatePlan
{
    private const IDENTITY = 'server/.peanut/release-identity.json';
    private const PLAN_PROTOCOL = 'peanut.server-release-inputs.v1';
    private const JOURNAL_PROTOCOL = 'peanut.server-file-intents.v1';
    private const MAINTENANCE_PROTOCOL = 'peanut.server-update-maintenance.v1';
    private const RUNTIME_DIR = 'runtime/upgrade';
    public const APPLICATION_DEPLOYMENT = 'peanut.application-deployment.v1';
    private static array $instanceLocks = [];
    private static ?array $databaseStage = null;
    private static ?array $applicationPlanning = null;

    /** The database host owns this lock, rather than inheriting a parent's claim. */
    public static function withDatabaseMigration(string $server, string $workspace, string $operation, string $scope, PDO $pdo, callable $callback): mixed
    {
        if (!in_array($scope, ['server-release', 'application-schema'], true)
            || !in_array($operation, ['migrate', 'verify'], true) || self::$databaseStage !== null) {
            throw new RuntimeException('invalid native database stage');
        }
        return self::withInstanceLock($server, static function () use ($server, $workspace, $operation, $scope, $pdo, $callback): mixed {
            self::sourceOwner($server);
            $facts = self::databasePhaseFacts($server, $workspace, $operation, $pdo, $scope);
            self::$databaseStage = ['pdo' => $pdo, 'connection' => \think\facade\Db::connect(),
                'server' => $server, 'workspace' => $workspace, 'operation' => $operation, 'scope' => $scope, 'facts' => $facts];
            try { return $callback($facts); }
            finally { self::$databaseStage = null; }
        });
    }

    /** Read-only planning has no execution admission and never acquires a file lock. */
    public static function withServerApplicationPlanning(string $server, string $workspace, PDO $pdo, array $files, array $selection, callable $callback): mixed
    {
        if (self::$applicationPlanning !== null || self::$databaseStage !== null) { throw new RuntimeException('nested database planning'); }
        [$product, $state] = self::productBinding($workspace);
        self::assertDatabaseProductPaths($server, $workspace, $product);
        $plan = self::plan($workspace);
        $prepared = $workspace . '/prepared/server';
        if (realpath($server) !== $server || realpath($workspace) !== $workspace
            || ($product['scope'] ?? null) !== 'server' || ($product['instance_root'] ?? null) . '/server' !== $server
            || ($state['phase_in_progress'] ?? null) !== 'migration-preflight'
            || ($product['inputs_sha256'] ?? null) !== hash_file('sha256', $workspace . '/plan.json')
            || ($product['candidate'] ?? null) !== $plan['update_id']
            || \think\facade\Db::connect()->getPdo() !== $pdo || $pdo->inTransaction()) {
            throw new RuntimeException('server planning is not bound to its native phase');
        }
        self::assertPreparedServer($workspace, $plan);
        self::assertInstallationFingerprints($server, $plan);
        $identity = self::identity(self::jsonFile($prepared . '/.peanut/release-identity.json', 'prepared identity'), true);
        $expected = [];
        foreach ($identity['files'] as $row) {
            if (preg_match('#^server/database/migrations/[0-9]{8}-[a-z0-9][a-z0-9_-]*\.sql$#D', $row['path']) === 1) {
                $expected[$prepared . '/' . substr($row['path'], 7)] = $row['sha256'];
            }
        }
        ksort($expected, SORT_STRING);
        if ($files !== array_keys($expected)) { throw new RuntimeException('planning application chain differs from target'); }
        foreach ($expected as $file => $sha) {
            if (is_link($file) || !is_file($file) || hash_file('sha256', $file) !== $sha) { throw new RuntimeException('planning migration changed'); }
        }
        self::$applicationPlanning = ['pdo' => $pdo, 'connection' => \think\facade\Db::connect(), 'server' => $server,
            'workspace' => $workspace, 'plan_sha256' => hash_file('sha256', $workspace . '/plan.json'),
            'product_state_sha256' => hash_file('sha256', $product['state_path']), 'files' => $expected, 'pending' => $selection['pending']];
        try { return $callback(); }
        finally { self::$applicationPlanning = null; }
    }

    public static function pendingModuleProvenanceMigration(PDO $pdo): ?array
    {
        $scope = self::$applicationPlanning;
        if ($scope === null) { return null; }
        [$product, $state] = self::productBinding($scope['workspace']);
        if ($scope['pdo'] !== $pdo || \think\facade\Db::connect() !== $scope['connection']
            || $scope['connection']->getPdo() !== $pdo
            || ($state['phase_in_progress'] ?? null) !== 'migration-preflight'
            || hash_file('sha256', $scope['workspace'] . '/plan.json') !== $scope['plan_sha256']
            || hash_file('sha256', $product['state_path']) !== $scope['product_state_sha256']) {
            throw new RuntimeException('native planning identity changed');
        }
        $id = \app\common\services\upgrade\ApplicationMigrationRunner::PROVENANCE_CORRECTION;
        if (!in_array($id, $scope['pending'], true)) { return null; }
        $file = $scope['workspace'] . '/prepared/server/database/migrations/' . $id . '.sql';
        $sha = $scope['files'][$file] ?? null;
        if (!is_string($sha) || hash_file('sha256', $file) !== $sha) { throw new RuntimeException('pending provenance migration changed'); }
        $row = $pdo->prepare('SELECT status FROM pa_schema_migration WHERE migration_id=?'); $row->execute([$id]);
        if ($row->fetchColumn() !== false) { throw new RuntimeException('pending provenance ledger changed'); }
        return ['migration_id' => $id, 'sha256' => $sha, 'reason' => 'module_migration_provenance_schema'];
    }

    /** This accessor cannot create admission: only the verified held native stage can. */
    public static function databaseMigrationAdmission(PDO $pdo): array
    {
        $stage = self::$databaseStage;
        if ($stage === null || $stage['scope'] !== 'server-release' || $stage['operation'] !== 'migrate') { throw new RuntimeException('native database admission unavailable'); }
        $facts = self::assertDatabaseMigrationStage($pdo);
        $lockName = 'peanut_migrate_' . substr(hash('sha256', $facts['database_identity']['database']), 0, 48);
        $lock = $pdo->prepare('SELECT IS_USED_LOCK(?)=CONNECTION_ID()'); $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('native schema lock is not held'); }
        $admission = ['schema_version' => 1, 'scope' => 'server-release', 'instance_root' => $stage['server'],
            'source_instance_root' => $stage['server'], 'workspace' => $stage['workspace'],
            'plan_sha256' => $facts['plan_sha256'], 'update_id' => $facts['update_id'],
            'target_identity_sha256' => $facts['target_identity_sha256'], 'database_identity' => $facts['database_identity'],
            'source' => $facts['source'], 'backup_sha256' => $facts['backup_sha256'],
            'maintenance' => $facts['maintenance'], 'resource' => $facts['resource']];
        $admission['admission_digest'] = hash('sha256', self::databaseCanonical($admission));
        return $admission;
    }

    public static function assertDatabaseMigrationStage(PDO $pdo): array
    {
        $stage = self::$databaseStage;
        if ($stage === null || $stage['pdo'] !== $pdo || \think\facade\Db::connect() !== $stage['connection']
            || $stage['connection']->getPdo() !== $pdo) { throw new RuntimeException('native database stage unavailable'); }
        self::assertHeldDatabaseLock($stage['server']);
        $facts = self::databasePhaseFacts($stage['server'], $stage['workspace'], $stage['operation'], $pdo, $stage['scope']);
        if ($facts !== $stage['facts']) { throw new RuntimeException('native database stage changed'); }
        return $facts;
    }

    /** Protected native phase, recovery point, real installed pair and actual master identity. */
    private static function databasePhaseFacts(string $server, string $workspace, string $operation, PDO $pdo, string $scope): array
    {
        if (realpath($server) !== $server || realpath($workspace) !== $workspace
            || \think\facade\Db::connect()->getPdo() !== $pdo) { throw new RuntimeException('native database roots or master changed'); }
        [$product, $state] = self::productBinding($workspace);
        self::assertDatabaseProductPaths($server, $workspace, $product);
        $plan = self::plan($workspace);
        if (($product['scope'] ?? null) !== 'server' || ($product['instance_root'] ?? null) . '/server' !== $server
            || ($product['inputs_sha256'] ?? null) !== hash_file('sha256', $workspace . '/plan.json')
            || ($product['candidate'] ?? null) !== $plan['update_id']
            || ($state['database_recovery_required'] ?? null) !== true
            || !isset($state['evidence']['managed_files'], $state['evidence']['backup'], $state['evidence']['quiesce'])
            || ($state['phase_in_progress'] ?? null) !== ($operation === 'migrate' ? 'migrate' : 'migration-verify')
            || array_key_exists('recovery_context', $state)) { throw new RuntimeException('database phase is not admitted'); }
        $completedVerify = $operation === 'verify' && $scope === 'server-release'
            && ($state['activation_started'] ?? null) === true && ($state['phase'] ?? null) === 'completed';
        if (!$completedVerify && ($state['activation_started'] ?? null) !== false) { throw new RuntimeException('database mutation window has closed'); }
        if ($completedVerify) {
            self::assertCompletedDatabaseVerification($server, $workspace, $product, $state, $plan);
        } else {
            self::assertMaintenance($server, $plan['update_id']);
        }
        self::assertInstallationFingerprints($server, $plan);
        $identityBytes = self::regularBytes($server . '/.peanut/release-identity.json', 'database target identity');
        if (hash('sha256', $identityBytes) !== $plan['target']['identity_sha256']) { throw new RuntimeException('database target changed'); }
        $identity = self::identity(json_decode($identityBytes, true, 512, JSON_THROW_ON_ERROR), true);
        foreach ($identity['files'] as $row) {
            self::assertOrdinaryParents($server, substr($row['path'], 7));
            $file = self::actualPath($server, $row['path']);
            if (hash('sha256', self::regularBytes($file, 'database target file')) !== $row['sha256']) { throw new RuntimeException('database target bytes changed'); }
        }
        $backupBytes = self::regularBytes($workspace . '/backup.json', 'database recovery point');
        $backup = json_decode($backupBytes, true, 256, JSON_THROW_ON_ERROR);
        $key = self::verificationKey($server, false);
        $signature = trim(self::regularBytes($workspace . '/backup.json.hmac', 'database recovery authentication'));
        $config = guardedDatabaseConfig();
        $resource = ['resource_id' => $config['resource_id'], 'endpoint_id' => $config['endpoint_id'], 'database' => $config['database']];
        $db = \app\common\services\installation\InstallationExecutionHost::migrationDatabaseIdentity($pdo);
        if (!hash_equals(hash_hmac('sha256', $backupBytes, $key), $signature)
            || ($backup['product_plan_sha256'] ?? null) !== $product['plan_sha256']
            || ($backup['plan_sha256'] ?? null) !== hash_file('sha256', $workspace . '/plan.json')
            || ($backup['update_id'] ?? null) !== $plan['update_id']
            || self::databaseCanonical($backup['database_identity'] ?? []) !== self::databaseCanonical($resource)
            || ($backup['mysql_identity']['server_uuid'] ?? null) !== $db['server_uuid'] || $db['database'] !== $config['database']
            || ($backup['snapshots']['private/installation/installed.json'] ?? null) !== $plan['source']['installed_receipt_sha256']
            || ($backup['snapshots']['private/installation/baseline.json'] ?? null) !== $plan['source']['baseline_sha256']
            || !is_file($workspace . '/recovery/database.sql.gz') || is_link($workspace . '/recovery/database.sql.gz')
            || ($backup['database_dump_sha256'] ?? null) !== hash_file('sha256', $workspace . '/recovery/database.sql.gz')
            || ($state['evidence']['backup']['payload']['payload']['backup_sha256'] ?? null) !== hash('sha256', $backupBytes)
            || ($state['evidence']['quiesce']['payload']['payload']['write_gate'] ?? null) !== 'closed'
            || ($state['evidence']['quiesce']['payload']['payload']['backup_sha256'] ?? null) !== hash('sha256', $backupBytes)) {
            throw new RuntimeException('database recovery or resource binding changed');
        }
        $proofPath = getenv('PEANUT_RESOURCE_LEASE_PROOF');
        if (is_string($proofPath) && $proofPath !== '') {
            $metadata = activeLeaseMetadata($proofPath, time(), null); $resources = activeLeaseResources($proofPath);
            $ddl = false;
            foreach ($resources['mysql-access'] ?? [] as $access) {
                $access = json_decode($access, true, 64, JSON_THROW_ON_ERROR);
                $ddl = $ddl || (($access['complete'] ?? null) === true && in_array($access['operation'] ?? null, ['ddl', 'exclusive'], true) && ($access['tables'] ?? null) === []);
            }
            if ($operation === 'migrate' && isset($resources['mysql-access']) && !$ddl) { throw new RuntimeException('database stage requires full native DDL exclusion'); }
            $resource['lease'] = array_intersect_key($metadata, array_flip(['lease', 'owner', 'thread', 'candidate']));
        }
        return ['update_id' => $plan['update_id'], 'plan_sha256' => hash_file('sha256', $workspace . '/plan.json'),
            'product_state_sha256' => hash_file('sha256', $product['state_path']), 'target_identity_sha256' => $plan['target']['identity_sha256'],
            'database_identity' => $db, 'resource' => $resource, 'source' => $plan['source'],
            'backup_sha256' => hash('sha256', $backupBytes), 'admission_mode' => $completedVerify ? 'readonly-completed' : 'preactivation',
            'maintenance' => $completedVerify ? null : self::jsonFile(self::maintenancePath($server), 'maintenance')];
    }

    /** Completed apply reentry and normal verify never reopen a mutation window. */
    private static function assertCompletedDatabaseVerification(string $server, string $workspace, array $product, array $state, array $plan): void
    {
        if (($state['migration_completed'] ?? null) !== true
            || file_exists(self::maintenancePath($server)) || is_link(self::maintenancePath($server))) {
            throw new RuntimeException('completed database verification is not admitted');
        }
        $phases = [];
        foreach (['migrate', 'migration-verify', 'activate'] as $phase) {
            $stored = $state['evidence'][$phase] ?? null;
            $evidence = is_array($stored) ? ($stored['payload'] ?? null) : null;
            if (!is_array($evidence) || ($stored['sha256'] ?? null) !== 'sha256:' . hash('sha256', self::databaseCanonical($evidence))
                || ($evidence['protocol'] ?? null) !== 'peanut.product-upgrade-host-evidence.v1'
                || ($evidence['status'] ?? null) !== 'completed' || ($evidence['phase'] ?? null) !== $phase
                || ($evidence['candidate'] ?? null) !== $product['candidate'] || ($evidence['plan_sha256'] ?? null) !== $product['plan_sha256']
                || ($evidence['package_manifest_sha256'] ?? null) !== $product['package']['manifest_sha256']) {
                throw new RuntimeException('completed native phase evidence differs');
            }
            $phases[$phase] = $evidence['payload'];
        }
        $path = $workspace . '/migration.json';
        $bytes = self::regularBytes($path, 'completed full migration');
        $signature = trim(self::regularBytes($path . '.hmac', 'completed full migration authentication'));
        foreach ([$path, $path . '.hmac'] as $file) {
            if ((fileperms($file) & 0777) !== 0600) { throw new RuntimeException('completed migration record is unsafe'); }
        }
        $migration = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        $recorded = $phases['migrate']['database'] ?? null;
        if (is_array($recorded)) { unset($recorded['result_path']); }
        $verified = $phases['migration-verify']['database'] ?? null;
        if (!hash_equals(hash_hmac('sha256', $bytes, self::verificationKey($server, false)), $signature)
            || !is_array($migration) || !is_array($recorded) || self::databaseCanonical($recorded) !== self::databaseCanonical($migration)
            || ($migration['protocol'] ?? null) !== 'peanut.server-update-migration.v1'
            || ($migration['status'] ?? null) !== 'completed' || ($migration['operation'] ?? null) !== 'migrate'
            || ($migration['scope'] ?? null) !== 'server-release' || ($migration['plugins'] ?? null) !== 'reconciled'
            || ($migration['product_database'] ?? null) !== 'migrated' || ($migration['update_id'] ?? null) !== $plan['update_id']
            || ($migration['plan_sha256'] ?? null) !== $product['inputs_sha256']
            || !is_array($verified) || ($verified['status'] ?? null) !== 'completed' || ($verified['scope'] ?? null) !== 'server-release'
            || ($verified['plugins'] ?? null) !== 'reconciled' || ($verified['product_database'] ?? null) !== 'verified'
            || ($verified['update_id'] ?? null) !== $plan['update_id'] || ($verified['plan_sha256'] ?? null) !== $product['inputs_sha256']) {
            throw new RuntimeException('completed full migration evidence is missing or mismatched');
        }
        $deployment = self::jsonFile(self::deploymentPath($server), 'completed deployment');
        if (($deployment['protocol'] ?? null) !== 'peanut.server-deployment.v1'
            || ($deployment['update_id'] ?? null) !== $plan['update_id'] || ($deployment['plan_sha256'] ?? null) !== $product['inputs_sha256']
            || ($deployment['current_identity_sha256'] ?? null) !== $plan['target']['identity_sha256']
            || ($deployment['previous_identity_sha256'] ?? null) !== $plan['source']['identity_sha256']
            || ($deployment['generation'] ?? null) !== $plan['source']['deployment_generation'] + 1) {
            throw new RuntimeException('completed deployment differs from verified target');
        }
        self::assertTrafficReady($server);
    }

    private static function assertInstallationFingerprints(string $server, array $plan): void
    {
        \app\common\services\installation\InstallationExecutionHost::verifiedInstallationFacts($server);
        foreach (['installed.json' => 'installed_receipt_sha256', 'baseline.json' => 'baseline_sha256'] as $file => $field) {
            if (hash('sha256', self::regularBytes($server . '/private/installation/' . $file, 'installed source')) !== ($plan['source'][$field] ?? null)) {
                throw new RuntimeException('installed source originals changed');
            }
        }
    }

    private static function assertDatabaseProductPaths(string $server, string $workspace, array $product): void
    {
        $binding = self::jsonFile($workspace . '/product-binding.json', 'database product binding');
        $root = dirname($server);
        $candidate = $product['candidate'] ?? '';
        if (($binding['plan_path'] ?? null) !== $root . '/.peanut/upgrades/product-plans/' . $candidate . '.json'
            || ($product['state_path'] ?? null) !== $root . '/.peanut/upgrades/product-state/' . $candidate . '.json') {
            throw new RuntimeException('database binding is outside native product records');
        }
        $owner = fileowner($server);
        foreach ([$workspace . '/product-binding.json', $workspace . '/plan.json', $binding['plan_path'], $product['state_path']] as $path) {
            $stat = @lstat($path);
            if (realpath($path) !== $path || !is_file($path) || is_link($path) || !is_array($stat)
                || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600 || !in_array($stat['uid'], [0, $owner], true)) {
                throw new RuntimeException('native database phase record is unsafe');
            }
        }
        if ((fileperms($workspace) & 0022) !== 0) { throw new RuntimeException('native database workspace permits unrelated writers'); }
    }

    private static function assertHeldDatabaseLock(string $server): void
    {
        $runtime = $server . '/' . self::RUNTIME_DIR;
        $held = self::$instanceLocks[$runtime] ?? null;
        $stat = @lstat($runtime . '/update.lock');
        $open = is_array($held) && is_resource($held['handle']) ? fstat($held['handle']) : false;
        if (!is_array($held) || $held['pid'] !== getmypid() || ($held['transferred'] ?? false)
            || !is_array($stat) || !is_array($open) || is_link($runtime . '/update.lock')
            || $open['ino'] !== $stat['ino'] || $open['dev'] !== $stat['dev']) { throw new RuntimeException('native database update lock changed'); }
    }

    private static function databaseCanonical(array $value): string
    {
        $normalize = static function (array $items) use (&$normalize): array {
            if (!array_is_list($items)) { ksort($items, SORT_STRING); }
            foreach ($items as $key => $item) { if (is_array($item)) { $items[$key] = $normalize($item); } }
            return $items;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function databaseVerificationKey(string $server): string
    {
        return self::verificationKey($server, false);
    }

    /** The APP owner uses the existing instance lock and private update key. No Project authority is imported. */
    public static function withPartialInstallationOwner(string $root, callable $operation): mixed
    {
        self::sourceOwner($root);
        return self::withInstanceLock($root, $operation);
    }

    /** A fresh native proof of the sole internal, pre-install source-correction frontier. Not an installed identity. */
    public static function internalPreinstallContext(string $root, array $from, array $to): ?array
    {
        if (PHP_SAPI !== 'cli' || ($from['version'] ?? null) !== ($to['version'] ?? null)
            || !is_file($root . '/.peanut/internal-candidate.json')
            || file_exists($root . '/.peanut/development-runtime.json') || is_link($root . '/.peanut/development-runtime.json')
            || file_exists($root . '/private/installation/installed.json') || is_link($root . '/private/installation/installed.json')
            || file_exists($root . '/private/installation/baseline.json') || is_link($root . '/private/installation/baseline.json')
            || file_exists(self::deploymentPath($root)) || is_link(self::deploymentPath($root))) { return null; }
        self::sourceOwner($root); self::assertHeldDatabaseLock($root);
        $old = \app\common\value\installation\ServerReleaseIdentity::load($root)->document();
        $application = self::jsonFile(dirname($root) . '/.peanut/application-manifest.json', 'original APP source manifest');
        if (($old['application']['kind'] ?? null) !== 'internal-candidate'
            || ($old['application']['formal_release_eligible'] ?? null) !== false
            || ($application['generation_source']['channel'] ?? null) !== 'development'
            || ($application['generation_source']['release_version'] ?? null) !== null
            || ($application['application']['version'] ?? null) !== '0.1.0'
            || ($application['application']['profile'] ?? null) !== 'full'
            || ($application['application']['workflow'] ?? null) !== 'full'
            || ($application['template']['version'] ?? null) !== $from['version']
            || ($application['template']['source_commit'] ?? null) !== ($from['source_commit'] ?? null)
            || ($application['template']['source_tree'] ?? null) !== ($from['source_tree'] ?? null)
            || ($application['template']['inventory_sha256'] ?? null) !== ($from['inventory_sha256'] ?? null)
            || ($old['upstream']['commit'] ?? null) !== $from['source_commit']
            || ($old['upstream']['tree'] ?? null) !== $from['source_tree']
            || ($old['upstream']['inventory_sha256'] ?? null) !== ($from['inventory_sha256'] ?? null)
            || ($to['source_commit'] ?? null) === $from['source_commit']
            || preg_match('/\A[a-f0-9]{40}\z/D', (string) ($to['source_commit'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{40}\z/D', (string) ($to['source_tree'] ?? '')) !== 1) {
            throw new RuntimeException('internal preinstall source identities do not match');
        }
        $originals = [];
        foreach (['server/.peanut/release-identity.json', 'server/.peanut/internal-candidate.json',
            '.peanut/application-release.json', '.peanut/application-manifest.json', 'release-versions.json'] as $relative) {
            $path = dirname($root) . '/' . $relative;
            $bytes = self::regularBytes($path, 'internal preinstall origin'); $stat = lstat($path);
            if ($stat['nlink'] !== 1 || ($stat['mode'] & 07022) !== 0 || !in_array($stat['uid'], [0, fileowner($root)], true)) {
                throw new RuntimeException('internal preinstall origin is not protected');
            }
            $originals[$relative] = ['sha256' => hash('sha256', $bytes), 'mode' => $stat['mode'] & 0777];
        }
        if ($originals['.peanut/application-manifest.json']['sha256'] !== $old['application']['manifest_sha256']) {
            throw new RuntimeException('internal preinstall APP origin differs');
        }
        $sha = $originals['server/.peanut/release-identity.json']['sha256'];
        $partial = self::partialApplicationAnchors($root, $old, $sha, null, true);
        return ['protocol' => 'peanut.internal-preinstall-source.v1', 'server_root' => $root,
            'old_identity' => ['kind' => 'internal-candidate', 'sha256' => $sha, 'document' => $old],
            'originals' => $originals, 'application' => $application['application'], 'partial_installation' => $partial];
    }

    /** Rechecks private/DB anchors only. Old program authenticity must separately use its held native preimages. */
    public static function recheckInternalPreinstallAnchors(string $root, array $context): void
    {
        self::sourceOwner($root); self::assertHeldDatabaseLock($root);
        if (($context['protocol'] ?? null) !== 'peanut.internal-preinstall-source.v1' || ($context['server_root'] ?? null) !== $root
            || ($context['old_identity']['kind'] ?? null) !== 'internal-candidate'
            || self::databaseCanonical(self::partialApplicationAnchors($root, $context['old_identity']['document'],
                $context['old_identity']['sha256'], $context['partial_installation'], true)) !== self::databaseCanonical($context['partial_installation'])) {
            throw new RuntimeException('internal preinstall private or database anchors changed');
        }
    }

    /** Observe the real finalized frontier without replaying installation, health, or the old r1 snapshot. */
    public static function assertInternalPreinstallCompletion(string $root, array $state): void
    {
        self::sourceOwner($root); self::assertHeldDatabaseLock($root);
        $reference = $state['installation_completion'];
        if (!is_array($reference) || !is_string($reference['path'] ?? null)
            || !in_array($state['phase'] ?? null, ['admitted', 'opening', 'opened'], true)
            || hash('sha256', self::regularBytes($reference['path'], 'partial completion')) !== ($reference['sha256'] ?? null)) {
            throw new RuntimeException('internal source completion reference changed');
        }
        $record = self::readPartialOwnerRecord($root, $reference['path']);
        $plan = self::readPartialOwnerRecord($root, $record['plan']['path']);
        $context = $state['binding']['internal_preinstall'];
        $origin = $state['partial_origin_deployment'] ?? [];
        $source = $record['source'];
        if (($record['protocol'] ?? null) !== 'peanut.installation-partial-completion.v1'
            || ($record['phase'] ?? null) !== 'native-health-complete-intent'
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($record['native_health_sha256'] ?? '')) !== 1
            || !is_int($record['health_completed_at'] ?? null)
            || hash_file('sha256', $record['plan']['path']) !== ($record['plan']['sha256'] ?? null)
            || ($plan['protocol'] ?? null) !== 'peanut.app-installation-partial-plan.v1'
            || self::databaseCanonical($plan['source']) !== self::databaseCanonical($source)
            || self::databaseCanonical($plan['facts']['binding']['database']) !== self::databaseCanonical($record['database'] ?? [])
            || self::databaseCanonical(array_intersect_key($record['database'] ?? [], $context['partial_installation']['database']))
                !== self::databaseCanonical($context['partial_installation']['database'])
            || $record['health_completed_at'] < $plan['facts']['binding']['authority']['created_at']
            || $record['health_completed_at'] > $plan['facts']['binding']['authority']['expires_at']
            || $record['health_completed_at'] > time()
            || self::databaseCanonical($source['state']['binding']['deployment'] ?? []) !== self::databaseCanonical($origin)
            || self::databaseCanonical(array_diff_key($source['state']['binding'] ?? [], ['deployment' => true]))
                !== self::databaseCanonical(array_diff_key($state['binding'], ['deployment' => true]))
            || self::databaseCanonical($origin['partial_installation'] ?? []) !== self::databaseCanonical($context['partial_installation'])
            || self::databaseCanonical($source['deployment']['partial_installation'] ?? []) !== self::databaseCanonical($context['partial_installation'])
            || ($source['deployment']['head_sha256'] ?? null) !== ($source['state']['published_head_sha256'] ?? null)
            || self::databaseCanonical(array_diff_key($source['deployment'], ['head_sha256' => true]))
                !== self::databaseCanonical($source['state']['head_intent'] ?? [])
            || ($source['state']['transition_id'] ?? null) !== $state['transition_id']
            || ($source['checkpoint']['path'] ?? null) !== $state['binding']['workspace'] . '/source-runtime.json'
            || self::databaseCanonical($source['state']['binding']['internal_preinstall'] ?? []) !== self::databaseCanonical($context)
            || \app\common\services\upgrade\SourceApplicationBackup::partialInstallationBackup($record['backup']['path'], $root,
                $plan['facts']['binding'], $plan['facts']['baseline'], $plan['facts']['scope']) !== $record['backup']) {
            throw new RuntimeException('internal source completion origin is not authenticated');
        }
        $facts = \app\common\services\installation\InstallationExecutionHost::verifiedInstallationFacts($root);
        $runtime = self::jsonFile($root . '/.peanut/development-runtime.json', 'completed runtime');
        $sha = hash_file('sha256', $root . '/.peanut/development-runtime.json');
        $deployment = self::applicationDeployment($root, $runtime, $sha);
        $pending = self::sourcePending($root);
        if ($facts['receipt_sha256'] !== $record['installed_receipt_sha256'] || $facts['baseline_sha256'] !== $record['baseline_sha256']
            || $facts['receipt']['official_modules'] !== $record['modules']
            || ($facts['baseline']['source']['development_runtime_identity_sha256'] ?? null) !== $sha
            || ($facts['baseline']['source']['application_manifest_sha256'] ?? null) !== $runtime['application_manifest_sha256']
            || $sha !== $source['runtime_sha256'] || $sha !== $state['prepared_identity_sha256']
            || self::databaseCanonical($deployment) !== self::databaseCanonical($state['binding']['deployment'])
            || ($deployment['installation_completion'] ?? null) !== $reference
            || ($deployment['previous_head_sha256'] ?? null) !== $source['deployment']['head_sha256']
            || self::databaseCanonical($deployment['partial_installation_origin'] ?? []) !== self::databaseCanonical($context['partial_installation'])
            || ($state['published_head_sha256'] ?? null) !== $deployment['head_sha256']
            || self::databaseCanonical($state['head_intent'] ?? []) !== self::databaseCanonical(array_diff_key($deployment, ['head_sha256' => true]))
            || ($pending['transition_id'] ?? null) !== $state['transition_id']
            || ($pending['binding_sha256'] ?? null) !== hash('sha256', self::databaseCanonical($state['binding']))
            || ($pending['deployment_sha256'] ?? null) !== $deployment['head_sha256'] || ($pending['runtime_sha256'] ?? null) !== $sha) {
            throw new RuntimeException('internal source completion pair, head or runtime changed');
        }
        foreach (['private/resources/configuration.json', 'private/resources/project-resources.json', '.env'] as $relative) {
            if (hash('sha256', self::regularBytes($root . '/' . $relative, 'completed source configuration'))
                !== $context['partial_installation']['files'][$relative]) { throw new RuntimeException('completed source configuration changed'); }
        }
        self::assertSourceInstallationAnchors($root, $deployment);
    }

    public static function internalPreinstallDeployment(string $root, array $context): array
    {
        self::recheckInternalPreinstallAnchors($root, $context);
        if (file_exists(self::deploymentPath($root)) || is_link(self::deploymentPath($root))) {
            throw new RuntimeException('internal first source adoption requires a genuinely absent head');
        }
        $manifestSha = $context['originals']['.peanut/application-manifest.json']['sha256'];
        return ['partial_installation' => $context['partial_installation'], 'head_sha256' => null,
            'source_generation' => 0, 'runtime_revision' => 0,
            'initial_source' => ['kind' => 'application-manifest', 'sha256' => $manifestSha],
            'current_source' => ['kind' => 'application-manifest', 'sha256' => $manifestSha],
            'original_identity' => ['kind' => 'internal-candidate', 'sha256' => $context['old_identity']['sha256']]];
    }

    public static function writePartialOwnerRecord(string $root, string $path, array $record): void
    {
        self::sourceOwner($root); self::assertHeldDatabaseLock($root);
        $prefix = $root . '/private/partial-installation/';
        if (!str_starts_with($path, $prefix) || str_contains(substr($path, strlen($prefix)), '..')
            || file_exists($path) || is_link($path)) { throw new RuntimeException('partial owner record requires a new native path'); }
        self::assertOrdinaryParents($root, substr($path, strlen($root) + 1));
        $record['owner_root'] = $root;
        $record['hmac_sha256'] = hash_hmac('sha256', self::databaseCanonical($record), self::verificationKey($root, true));
        self::writeJson($path, $record);
    }

    public static function readPartialOwnerRecord(string $root, string $path): array
    {
        self::sourceOwner($root);
        $record = \app\common\services\upgrade\SourceApplicationBackup::protectedPluginJson($path, $root);
        $mac = $record['hmac_sha256'] ?? null; unset($record['hmac_sha256']);
        if (!str_starts_with($path, $root . '/private/partial-installation/') || ($record['owner_root'] ?? null) !== $root
            || !is_string($mac) || !hash_equals(hash_hmac('sha256', self::databaseCanonical($record), self::verificationKey($root, false)), $mac)) {
            throw new RuntimeException('partial owner record authentication failed');
        }
        return $record;
    }

    /** Actual admitted, unactivated source transition; never a caller-supplied maintenance assertion. */
    public static function partialInstallationSource(string $root): array
    {
        self::sourceOwner($root); self::assertHeldDatabaseLock($root);
        $identity = self::jsonFile($root . '/.peanut/development-runtime.json', 'partial runtime');
        $runtimeSha = hash_file('sha256', $root . '/.peanut/development-runtime.json');
        $deployment = self::applicationDeployment($root, $identity, $runtimeSha);
        $pending = self::sourcePending($root);
        if (!isset($deployment['partial_installation']) || $deployment['head_sha256'] === null || $pending === null) {
            throw new RuntimeException('partial continuation requires its adopted source head');
        }
        $path = $pending['checkpoint'];
        $state = \app\common\services\upgrade\SourceApplicationBackup::protectedPluginJson($path, $root);
        $sha = $state['state_sha256'] ?? null; $unsigned = $state; unset($unsigned['state_sha256']);
        if (($state['protocol'] ?? null) !== 'peanut.source-runtime-transition.v1'
            || $sha !== hash('sha256', self::databaseCanonical($unsigned))
            || ($state['binding']['instance_root'] ?? null) . '/server' !== $root
            || ($state['binding']['workspace'] ?? null) !== dirname($path) || (fileperms(dirname($path)) & 0077) !== 0
            || ($state['phase'] ?? null) !== 'admitted' || ($state['activation_started'] ?? null) !== false
            || ($pending['phase'] ?? null) !== 'admitted' || ($pending['activation_started'] ?? null) !== false
            || ($pending['transition_id'] ?? null) !== ($state['transition_id'] ?? null)
            || ($pending['binding_sha256'] ?? null) !== hash('sha256', self::databaseCanonical($state['binding']))
            || ($pending['deployment_sha256'] ?? null) !== $deployment['head_sha256']
            || ($state['published_head_sha256'] ?? null) !== $deployment['head_sha256']
            || ($pending['runtime_sha256'] ?? null) !== $runtimeSha || ($state['prepared_identity_sha256'] ?? null) !== $runtimeSha
            || ($state['binding']['tools']['server/docker/scripts/update-plan.php'] ?? null) !== hash_file('sha256', __FILE__)
            || file_exists(self::trafficReadyPath($root)) || is_link(self::trafficReadyPath($root))) {
            throw new RuntimeException('partial continuation source transition is not currently closed and admitted');
        }
        return ['deployment' => $deployment, 'runtime_sha256' => $runtimeSha,
            'checkpoint' => ['path' => $path, 'sha256' => hash_file('sha256', $path)], 'state' => $state, 'pending' => $pending];
    }

    /** Idempotent file finalization after the real Host has completed health and both installation records. */
    public static function finalizePartialInstallation(string $root, string $recordPath): array
    {
        self::sourceOwner($root); self::assertHeldDatabaseLock($root);
        $record = self::readPartialOwnerRecord($root, $recordPath);
        if (($record['protocol'] ?? null) !== 'peanut.installation-partial-completion.v1'
            || ($record['phase'] ?? null) !== 'native-health-complete-intent'
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($record['native_health_sha256'] ?? '')) !== 1
            || !is_int($record['health_completed_at'] ?? null)) { throw new RuntimeException('partial completion record required'); }
        $plan = self::readPartialOwnerRecord($root, $record['plan']['path']);
        if (hash_file('sha256', $record['plan']['path']) !== $record['plan']['sha256']
            || ($plan['protocol'] ?? null) !== 'peanut.app-installation-partial-plan.v1'
            || $plan['source'] !== $record['source'] || $plan['facts']['binding']['database'] !== ($record['database'] ?? null)
            || $record['health_completed_at'] < $plan['facts']['binding']['authority']['created_at']
            || $record['health_completed_at'] > $plan['facts']['binding']['authority']['expires_at']
            || $record['health_completed_at'] > time()
            || \app\common\services\upgrade\SourceApplicationBackup::partialInstallationBackup($record['backup']['path'], $root,
                $plan['facts']['binding'], $plan['facts']['baseline'], $plan['facts']['scope']) !== $record['backup']) {
            throw new RuntimeException('partial completion original plan or native backup changed');
        }
        $facts = \app\common\services\installation\InstallationExecutionHost::verifiedInstallationFacts($root);
        $source = $record['source']; $old = $source['deployment'];
        $runtime = self::jsonFile($root . '/.peanut/development-runtime.json', 'completed partial runtime');
        if ($facts['receipt_sha256'] !== $record['installed_receipt_sha256'] || $facts['baseline_sha256'] !== $record['baseline_sha256']
            || ($facts['baseline']['source']['development_runtime_identity_sha256'] ?? null) !== $source['runtime_sha256']
            || ($facts['baseline']['source']['application_manifest_sha256'] ?? null) !== ($runtime['application_manifest_sha256'] ?? null)
            || hash_file('sha256', $root . '/.peanut/development-runtime.json') !== $source['runtime_sha256']
            || ($facts['receipt']['official_modules'] ?? null) !== $record['modules']
            || \app\common\services\installation\InstallationExecutionHost::migrationDatabaseIdentity(\think\facade\Db::connect()->getPdo()) !== ($record['database'] ?? null)) {
            throw new RuntimeException('partial completion is not bound to its real installation records');
        }
        foreach (['private/resources/configuration.json', 'private/resources/project-resources.json', '.env'] as $relative) {
            if (hash('sha256', self::regularBytes($root . '/' . $relative, 'partial completion configuration'))
                !== ($old['partial_installation']['files'][$relative] ?? null)) { throw new RuntimeException('partial completion configuration changed'); }
        }
        $progress = $root . '/private/installation/executing.json';
        if (file_exists($progress) || is_link($progress)) {
            if (hash('sha256', self::regularBytes($progress, 'partial completion progress'))
                !== $old['partial_installation']['files']['private/installation/executing.json']) { throw new RuntimeException('partial completion progress changed'); }
        } else {
            $head = self::jsonFile(self::deploymentPath($root), 'finalized partial deployment');
            if (($head['installation_completion'] ?? null) !== ['path' => $recordPath, 'sha256' => hash_file('sha256', $recordPath)]) {
                throw new RuntimeException('partial completion progress disappeared before finalization');
            }
        }
        $next = $old;
        unset($next['head_sha256'], $next['partial_installation']);
        $next['previous_head_sha256'] = $old['head_sha256'];
        $next['partial_installation_origin'] = $old['partial_installation'];
        $next['installed_receipt_sha256'] = $facts['receipt_sha256']; $next['baseline_sha256'] = $facts['baseline_sha256'];
        $next['initial_source'] = ['kind' => 'application-manifest', 'sha256' => $runtime['application_manifest_sha256']];
        $next['installation_completion'] = ['path' => $recordPath, 'sha256' => hash_file('sha256', $recordPath)];
        $head = self::publishApplicationDeployment($root, $old['head_sha256'], $next);
        $state = $source['state'];
        $state['partial_origin_deployment'] = $state['binding']['deployment'];
        $state['binding']['deployment'] = $next + ['head_sha256' => $head];
        $state['head_intent'] = $next; $state['published_head_sha256'] = $head;
        $state['installation_completion'] = $next['installation_completion'];
        unset($state['state_sha256']);
        $state['state_sha256'] = hash('sha256', self::databaseCanonical($state));
        $path = $source['checkpoint']['path'];
        $actual = \app\common\services\upgrade\SourceApplicationBackup::protectedPluginJson($path, $root);
        if (hash_file('sha256', $path) !== $source['checkpoint']['sha256']
            && self::databaseCanonical($actual) !== self::databaseCanonical($state)) {
            throw new RuntimeException('partial completion checkpoint CAS failed; finalize only');
        }
        self::writeJson($path, $state);
        self::publishSourcePending($root, ['transition_id' => $state['transition_id'], 'checkpoint' => $path,
            'binding_sha256' => hash('sha256', self::databaseCanonical($state['binding'])), 'phase' => 'admitted',
            'activation_started' => false, 'deployment_sha256' => $head, 'runtime_sha256' => $source['runtime_sha256']]);
        // The native runtime consumer reissues its own admission for the genuine installed pair.
        self::admitSourceRuntime($root);
        return ['status' => 'finalized', 'deployment_sha256' => $head, 'completion' => $next['installation_completion']];
    }

    public static function withSourceInstanceLock(string $root, callable $operation): mixed
    {
        return self::withInstanceLock($root, $operation);
    }

    public static function sourceAdmissionHandoff(string $root, callable $operation): mixed
    {
        $runtime = $root . '/runtime/upgrade';
        $held = self::$instanceLocks[$runtime] ?? null;
        if ($held === null || $held['pid'] !== getmypid() || ($held['transferred'] ?? false)) { throw new RuntimeException('source admission requires its real instance lock'); }
        $stat = lstat($runtime . '/update.lock'); $open = fstat($held['handle']);
        if (is_link($runtime . '/update.lock') || $stat['ino'] !== $open['ino'] || $stat['dev'] !== $open['dev']) { throw new RuntimeException('source admission lock changed'); }
        self::$instanceLocks[$runtime]['transferred'] = true;
        flock($held['handle'], LOCK_UN);
        try { return $operation(); }
        finally {
            if (!flock($held['handle'], LOCK_EX | LOCK_NB)) { throw new RuntimeException('cannot reclaim source instance lock after admission'); }
            $stat = lstat($runtime . '/update.lock');
            if (is_link($runtime . '/update.lock') || $stat['ino'] !== $open['ino'] || $stat['dev'] !== $open['dev']) { throw new RuntimeException('source admission lock inode changed'); }
            self::$instanceLocks[$runtime]['transferred'] = false;
        }
    }

    private static function sourceChildAdmission(string $root, string $workspace): array
    {
        return self::withInstanceLock($root, static function () use ($root, $workspace): array {
            self::sourceOwner($root);
            $path = $workspace . '/source-runtime.json';
            $state = self::jsonFile($path, 'source checkpoint');
            $sha = $state['state_sha256'] ?? null; unset($state['state_sha256']);
            $normalize = static function (mixed $value) use (&$normalize): mixed {
                if (!is_array($value)) { return $value; }
                if (!array_is_list($value)) { ksort($value, SORT_STRING); }
                foreach ($value as $key => $child) { $value[$key] = $normalize($child); }
                return $value;
            };
            $pending = self::sourcePending($root);
            $head = self::deploymentPath($root);
            $actualHead = file_exists($head) || is_link($head) ? hash('sha256', self::regularBytes($head, 'application deployment')) : null;
            if ($state['phase'] === 'recovered' && isset($state['binding']['internal_preinstall'])) {
                throw new RuntimeException('recovered original internal candidate remains closed');
            }
            $expectedRuntime = $state['phase'] === 'recovered' ? $state['binding']['old_runtime_sha256'] : $state['prepared_identity_sha256'];
            if (realpath($path) !== $path || (fileperms($path) & 0777) !== 0600 || (fileperms($workspace) & 0077) !== 0
                || !in_array(fileowner($path), [0, fileowner($root)], true)
                || ($state['protocol'] ?? null) !== 'peanut.source-runtime-transition.v1'
                || $sha !== hash('sha256', json_encode($normalize($state), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                || ($state['binding']['instance_root'] ?? null) . '/server' !== $root || $state['binding']['workspace'] !== $workspace
                || ($state['activation_started'] ?? null) !== false || !in_array($state['phase'], ['head-published', 'recovered'], true)
                || ($pending['transition_id'] ?? null) !== $state['transition_id']
                || hash_file('sha256', __FILE__) !== ($state['binding']['tools']['server/docker/scripts/update-plan.php'] ?? null)
                || $actualHead !== ($state['published_head_sha256'] ?? $state['binding']['deployment']['head_sha256'])
                || hash_file('sha256', $root . '/.peanut/development-runtime.json') !== $expectedRuntime) {
                throw new RuntimeException('source child admission is not bound to its current owner checkpoint');
            }
            self::assertSourceInstallationAnchors($root, $state['binding']['deployment']);
            self::admitSourceRuntime($root);
            return ['status' => 'admitted', 'transition_id' => $state['transition_id']];
        });
    }

    public static function sourcePending(string $root): ?array
    {
        $path = $root . '/runtime/upgrade/source-transition.json';
        if (!file_exists($path) && !is_link($path)) { return null; }
        self::regularBytes($path, 'source transition pointer');
        $value = self::jsonFile($path, 'source transition pointer');
        if (($value['protocol'] ?? null) !== 'peanut.source-runtime-pointer.v1'
            || !is_string($value['checkpoint'] ?? null) || !is_string($value['transition_id'] ?? null)
            || (fileperms($path) & 0022) !== 0 || !in_array(fileowner($path), [0, fileowner($root)], true)) {
            throw new RuntimeException('source transition pointer is unsafe');
        }
        return $value;
    }

    public static function publishSourcePending(string $root, array $value): void
    {
        self::sourceOwner($root);
        $previous = self::sourcePending($root);
        if ($previous !== null && $previous['transition_id'] !== $value['transition_id']) {
            $deployment = self::deploymentPath($root);
            $current = file_exists($deployment) || is_link($deployment) ? hash('sha256', self::regularBytes($deployment, 'application deployment')) : null;
            if (!in_array($previous['phase'] ?? null, ['opened', 'recovered'], true)
                || ($previous['deployment_sha256'] ?? null) !== $current) {
                throw new RuntimeException('another source transition is pending');
            }
        }
        if (($value['phase'] ?? null) !== 'opened') { self::revokeTraffic($root); }
        self::writeJson($root . '/runtime/upgrade/source-transition.json', ['protocol' => 'peanut.source-runtime-pointer.v1'] + $value);
        chmod($root . '/runtime/upgrade/source-transition.json', 0644);
    }

    public static function sourceOwner(string $root): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')
            || !in_array(posix_geteuid(), [0, fileowner($root)], true)) {
            throw new RuntimeException('source runtime requires its deployment owner');
        }
        self::assertNoLegacyMaintenanceKey($root);
    }

    /** The installed receipt and initial baseline are immutable anchors, never successor manifests. */
    public static function applicationDeployment(string $root, array $identity, string $runtimeSha): array
    {
        if (($identity['protocol'] ?? null) !== 'peanut.development-server-runtime.v1'
            || ($identity['schema_version'] ?? null) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $runtimeSha) !== 1) {
            throw new RuntimeException('source runtime requires its development identity');
        }
        if (!file_exists($root . '/private/installation/installed.json') && !is_link($root . '/private/installation/installed.json')
            && !file_exists($root . '/private/installation/baseline.json') && !is_link($root . '/private/installation/baseline.json')) {
            return self::partialApplicationDeployment($root, $identity, $runtimeSha);
        }
        $baseline = self::installedBaseline($root, $identity);
        $initial = $baseline['source']['application_manifest_sha256'] ?? null;
        $sourceKind = $baseline['source']['kind'] ?? null;
        if (!in_array($sourceKind, ['generated-application', 'development-runtime'], true)
            || ($sourceKind === 'development-runtime'
                && preg_match('/^[a-f0-9]{64}$/D', (string) ($baseline['source']['development_runtime_identity_sha256'] ?? '')) !== 1)
            || !is_string($initial) || preg_match('/^[a-f0-9]{64}$/D', $initial) !== 1) {
            throw new RuntimeException('source runtime requires a generated APP installation');
        }
        $anchors = ['installed_receipt_sha256' => hash_file('sha256', $root . '/private/installation/installed.json'),
            'baseline_sha256' => hash_file('sha256', $root . '/private/installation/baseline.json')];
        $path = self::deploymentPath($root);
        if (!file_exists($path) && !is_link($path)) {
            if (($identity['application_manifest_sha256'] ?? null) !== $initial
                || ($sourceKind === 'development-runtime'
                    && !hash_equals($baseline['source']['development_runtime_identity_sha256'], $runtimeSha))) {
                throw new RuntimeException('initial APP projection does not match its installation baseline');
            }
            return $anchors + ['head_sha256' => null, 'source_generation' => 0, 'runtime_revision' => 0,
                'initial_source' => ['kind' => 'application-manifest', 'sha256' => $initial],
                'current_source' => ['kind' => 'application-manifest', 'sha256' => $initial],
                'current_runtime' => ['kind' => 'development-runtime', 'sha256' => $runtimeSha]];
        }
        $bytes = self::regularBytes($path, 'application deployment');
        $state = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (($state['protocol'] ?? null) !== self::APPLICATION_DEPLOYMENT || ($state['schema_version'] ?? null) !== 1
            || ($state['installed_receipt_sha256'] ?? null) !== $anchors['installed_receipt_sha256']
            || ($state['baseline_sha256'] ?? null) !== $anchors['baseline_sha256']
            || ($state['initial_source'] ?? null) !== ['kind' => 'application-manifest', 'sha256' => $initial]
            || ($state['current_source'] ?? null) !== ['kind' => 'application-manifest', 'sha256' => $identity['application_manifest_sha256']]
            || ($state['current_runtime'] ?? null) !== ['kind' => 'development-runtime', 'sha256' => $runtimeSha]
            || ($state['application'] ?? null) !== array_intersect_key($identity['application'], array_flip(['slug', 'edition', 'package_identity']))
            || !is_int($state['source_generation'] ?? null) || $state['source_generation'] < 0
            || !is_int($state['runtime_revision'] ?? null) || $state['runtime_revision'] < 1) {
            throw new RuntimeException('application deployment chain does not match this runtime');
        }
        $state['head_sha256'] = hash('sha256', $bytes);
        return $state;
    }

    /** Read-only owner observation; it cannot issue a database migration admission. */
    public static function assertSourceInstallationAnchors(string $root, array $deployment): void
    {
        if (isset($deployment['partial_installation'])) {
            if (isset($deployment['installed_receipt_sha256']) || isset($deployment['baseline_sha256'])) {
                throw new RuntimeException('partial and installed source anchors conflict');
            }
            $identity = self::jsonFile($root . '/.peanut/development-runtime.json', 'partial runtime identity');
            $actual = self::partialApplicationAnchors($root, $identity,
                hash_file('sha256', $root . '/.peanut/development-runtime.json'), $deployment['partial_installation']);
            if (self::databaseCanonical($actual) !== self::databaseCanonical($deployment['partial_installation'])) {
                throw new RuntimeException('partial installation anchors changed');
            }
            return;
        }
        foreach (['installed_receipt_sha256' => 'installed.json', 'baseline_sha256' => 'baseline.json'] as $key => $name) {
            if (hash_file('sha256', $root . '/private/installation/' . $name) !== ($deployment[$key] ?? null)) {
                throw new RuntimeException('installed source anchors changed');
            }
        }
    }

    private static function partialApplicationDeployment(string $root, array $identity, string $runtimeSha): array
    {
        self::sourceOwner($root);
        $path = self::deploymentPath($root);
        $state = file_exists($path) || is_link($path) ? self::jsonFile($path, 'partial deployment head') : null;
        $partial = self::partialApplicationAnchors($root, $identity, $runtimeSha, $state['partial_installation'] ?? null);
        $application = array_intersect_key($identity['application'], array_flip(['slug', 'edition', 'package_identity']));
        if ($state === null) {
            return ['partial_installation' => $partial, 'head_sha256' => null, 'source_generation' => 0, 'runtime_revision' => 0,
                'initial_source' => ['kind' => 'application-manifest', 'sha256' => $identity['application_manifest_sha256']],
                'current_source' => ['kind' => 'application-manifest', 'sha256' => $identity['application_manifest_sha256']],
                'current_runtime' => ['kind' => 'development-runtime', 'sha256' => $runtimeSha]];
        }
        self::regularBytes($path, 'partial deployment head');
        if (($state['protocol'] ?? null) !== self::APPLICATION_DEPLOYMENT || ($state['schema_version'] ?? null) !== 1
            || isset($state['installed_receipt_sha256']) || isset($state['baseline_sha256'])
            || ($state['application'] ?? null) !== $application
            || self::databaseCanonical($state['partial_installation'] ?? []) !== self::databaseCanonical($partial)
            || ($state['current_source'] ?? null) !== ['kind' => 'application-manifest', 'sha256' => $identity['application_manifest_sha256']]
            || ($state['current_runtime'] ?? null) !== ['kind' => 'development-runtime', 'sha256' => $runtimeSha]
            || !is_int($state['source_generation'] ?? null) || $state['source_generation'] < 0
            || !is_int($state['runtime_revision'] ?? null) || $state['runtime_revision'] < 1) {
            throw new RuntimeException('partial application deployment chain changed');
        }
        $state['head_sha256'] = hash_file('sha256', $path);
        return $state;
    }

    /** Exact r1/File-frontier metadata, not a substitute for the File module's complete proof. */
    private static function partialApplicationAnchors(string $root, array $identity, string $runtimeSha, ?array $recorded, bool $frozen = false): array
    {
        self::sourceOwner($root);
        foreach (['installed.json', 'baseline.json'] as $name) {
            if (file_exists($root . '/private/installation/' . $name) || is_link($root . '/private/installation/' . $name)) {
                throw new RuntimeException('partial source adoption requires absent completion anchors');
            }
        }
        $protocol = $frozen ? 'peanut.server-release.v1' : 'peanut.development-server-runtime.v1';
        if (($identity['protocol'] ?? null) !== $protocol
            || ($identity['schema_version'] ?? null) !== 1 || ($identity['application']['edition'] ?? null) !== 'multi-tenant'
            || ($frozen && (($identity['application']['kind'] ?? null) !== 'internal-candidate'
                || ($identity['application']['formal_release_eligible'] ?? null) !== false))
            || preg_match('/\A[a-f0-9]{64}\z/D', $runtimeSha) !== 1) {
            throw new RuntimeException('partial source adoption requires its actual development APP kind');
        }
        $files = [];
        foreach (['private/installation/executing.json', 'private/resources/configuration.json',
            'private/resources/project-resources.json', '.env'] as $relative) {
            self::assertOrdinaryParents($root, $relative);
            $path = $root . '/' . $relative; $bytes = self::regularBytes($path, 'partial source anchor');
            if ((fileperms($path) & 0777) !== 0600 || !in_array(fileowner($path), [0, fileowner($root)], true)
                || lstat($path)['nlink'] !== 1) { throw new RuntimeException('partial source anchor is not owner protected'); }
            $files[$relative] = hash('sha256', $bytes);
        }
        $progress = self::jsonFile($root . '/private/installation/executing.json', 'partial progress');
        $modules = ['official.identity', 'official.content-tools', 'official.file', 'official.rich-text', 'official.document', 'official.article',
            'official.collaboration', 'official.task', 'official.settings', 'official.integration', 'official.import-export', 'official.member',
            'official.notification', 'official.oauth', 'official.ops', 'official.payment', 'official.reference-codes'];
        if (($progress['schema_version'] ?? null) !== 1 || ($progress['state'] ?? null) !== 'executing'
            || ($progress['deployment_mode'] ?? null) !== 'multi-tenant'
            || !is_array($progress['official_modules'] ?? null) || !array_is_list($progress['official_modules'])
            || count(array_unique($progress['official_modules'])) !== count($progress['official_modules'])
            || $progress['official_modules'] !== $modules) {
            throw new RuntimeException('partial source module frontier is not the fixed File frontier');
        }
        $failure = self::partialApplicationFailure($root, $progress);
        $files[$failure['path']] = $failure['sha256'];
        $lock = $root . '/private/installation/execution.lock';
        self::regularBytes($lock, 'partial execution lock'); $stat = lstat($lock);
        if ($stat['nlink'] !== 1 || filesize($lock) !== 0 || !in_array($stat['uid'], [0, fileowner($root)], true)) {
            throw new RuntimeException('partial execution lock changed');
        }
        $registry = self::jsonFile($root . '/private/resources/project-resources.json', 'APP registry');
        if (($registry['authority']['role'] ?? null) !== 'application'
            || ($registry['project_id'] ?? null) !== ($identity['application']['slug'] ?? null)) {
            throw new RuntimeException('partial source adoption requires its own APP registry');
        }
        // A source transaction must not boot the temporarily changing application or import
        // maintainer configuration. Use the APP's existing pure registry readers and native PDO.
        $external = getenv('PEANUT_RESOURCE_REGISTRY');
        if (is_string($external) && trim($external) !== '') { throw new RuntimeException('partial APP source adoption forbids an external registry'); }
        // Maintenance uses its authenticated tool graph and treats R as data only.
        // Native admission/APP processes retain their own R reader and loader.
        require_once ($frozen ? dirname(__DIR__, 2) : $root) . '/database/resource-registry.php';
        $env = parse_ini_file($root . '/.env', false, INI_SCANNER_RAW);
        if (!is_array($env) || ($env['APP_ENV'] ?? null) !== 'development'
            || ($env['PEANUT_DEPLOYMENT_TARGET'] ?? null) !== 'local-multi-tenant-demo'
            || ($env['DEPLOYMENT_MODE'] ?? null) !== 'multi-tenant' || ($env['PEANUT_DATABASE_SOURCE'] ?? null) !== 'registered'
            || !is_string($env['PEANUT_DATABASE_RESOURCE_ID'] ?? null) || !is_string($env['DB_USER'] ?? null)
            || !is_string($env['DB_PASS'] ?? null)) {
            throw new RuntimeException('partial source adoption requires its registered development target');
        }
        $resource = registeredDatabase($registry, $env['PEANUT_DATABASE_RESOURCE_ID']);
        $runtimeEndpoint = registeredDatabaseEndpoint($resource, (string) ($env['PEANUT_DATABASE_CONSUMER'] ?? ''));
        $routes = ['host' => registeredDatabaseEndpoint($resource, 'host'), 'container' => registeredDatabaseEndpoint($resource, 'container')];
        $endpoint = $routes[is_file('/.dockerenv') ? 'container' : 'host'];
        if (($resource['service_type'] ?? null) !== 'mysql' || ($resource['fallback'] ?? null) !== 'none'
            || ($resource['application_runtime'] ?? null) !== true || !in_array('local-multi-tenant-demo', $resource['environments'] ?? [], true)
            || !in_array('multi-tenant', $resource['deployment_modes'] ?? [], true)
            || ($env['DB_NAME'] ?? null) !== ($resource['database'] ?? null) || ($resource['namespace'] ?? null) !== $resource['database']
            || ($env['DB_HOST'] ?? null) !== $runtimeEndpoint['host'] || (int) ($env['DB_PORT'] ?? 0) !== $runtimeEndpoint['port']
            || ($env['PEANUT_DATABASE_ENDPOINT_ID'] ?? null) !== $runtimeEndpoint['endpoint_id']
            || preg_match('/\A[a-zA-Z0-9_]+\z/D', $resource['database']) !== 1) {
            throw new RuntimeException('partial source APP endpoint binding changed');
        }
        $config = ['resource_id' => $resource['stable_resource_id'], 'endpoint_id' => $runtimeEndpoint['endpoint_id']];
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $endpoint['host'], $endpoint['port'], $resource['database']),
            $env['DB_USER'], $env['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
        unset($env);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
        try {
            $identityRow = $pdo->query('SELECT @@server_uuid AS server_uuid,@@hostname AS server_hostname,@@port AS server_port,DATABASE() AS database')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identityRow) || preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/Di', (string) ($identityRow['server_uuid'] ?? '')) !== 1
                || ($identityRow['database'] ?? null) !== $resource['database'] || !is_string($identityRow['server_hostname'] ?? null)
                || $identityRow['server_hostname'] === '' || (int) ($identityRow['server_port'] ?? 0) < 1 || (int) $identityRow['server_port'] > 65535) {
                throw new RuntimeException('partial source database identity is unavailable');
            }
            $database = ['server_uuid' => strtolower($identityRow['server_uuid']), 'server_hostname' => $identityRow['server_hostname'],
                'server_port' => (int) $identityRow['server_port'], 'database' => $identityRow['database']];
            $ledger = $pdo->query('SELECT migration_id,release_version,checksum,status,finished_at,error_code FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
            if (count($ledger) !== 1 || $ledger[0]['migration_id'] !== '20261019-clean-install-5.0.2-r1'
                || $ledger[0]['release_version'] !== '5.0.2' || $ledger[0]['status'] !== 'applied' || $ledger[0]['finished_at'] === null
                || $ledger[0]['error_code'] !== null || $ledger[0]['checksum'] !== hash_file('sha256', $root . '/database/baselines/5.0.2-r1/manifest.json')) {
                throw new RuntimeException('partial source adoption requires the real retained r1 ledger');
            }
            $moduleRows = $pdo->query("SELECT * FROM pa_module_migration WHERE module_key='official.identity' ORDER BY migration_key")->fetchAll(PDO::FETCH_ASSOC);
            if (count($moduleRows) !== 9 || (int) $pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE module_key<>'official.identity'")->fetchColumn() !== 0) {
                throw new RuntimeException('partial source module history frontier changed');
            }
            foreach ($moduleRows as $row) {
                $key = $row['migration_key'];
                if (!str_starts_with($key, 'official.identity:') || preg_match('/\A[0-9]{8,14}_[a-z0-9_]+\z/D', substr($key, 18)) !== 1
                    || $row['status'] !== 'applied' || $row['resolution_kind'] !== 'executed' || $row['error_code'] !== null
                    || ($row['finished_at'] ?? null) === null || ($row['provenance_json'] ?? null) !== null) {
                    throw new RuntimeException('partial source Identity history is not actual executed history');
                }
                $source = $root . '/app/modules/official/identity/database/migrations/' . substr($key, 18) . '.sql';
                if (hash('sha256', self::regularBytes($source, 'original Identity migration')) !== $row['checksum']) {
                    throw new RuntimeException('partial source Identity migration source changed');
                }
            }
            $active = $pdo->query('SELECT plugin_key,installed_version,status FROM pa_plugin_installation ORDER BY plugin_key')->fetchAll(PDO::FETCH_ASSOC);
            $modules = $pdo->query('SELECT module_key,installed_version,status FROM pa_module_installation ORDER BY module_key')->fetchAll(PDO::FETCH_ASSOC);
            if (array_column($active, 'plugin_key') !== ['official.content-tools', 'official.identity']
                || array_column($modules, 'module_key') !== ['official.content-tools', 'official.identity']
                || array_column($active, 'status') !== ['active', 'active'] || array_column($modules, 'status') !== ['active', 'active']
                || (int) $pdo->query('SELECT COUNT(*) FROM pa_file')->fetchColumn() !== 0
                || (int) $pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn() !== 0
                || (int) $pdo->query("SELECT COUNT(*) FROM pa_config WHERE type='storage'")->fetchColumn() !== 0) {
                throw new RuntimeException('partial source File data or active module frontier changed');
            }
        } finally { if ($pdo->inTransaction() && !$pdo->rollBack()) { throw new RuntimeException('partial source snapshot did not close'); } }
        foreach ($files as $relative => $sha) {
            if (hash('sha256', self::regularBytes($root . '/' . $relative, 'partial source anchor')) !== $sha) {
                throw new RuntimeException('partial source file changed during observation');
            }
        }
        $afterLock = lstat($lock);
        if ($afterLock['dev'] !== $stat['dev'] || $afterLock['ino'] !== $stat['ino'] || filesize($lock) !== 0) {
            throw new RuntimeException('partial execution lock changed during observation');
        }
        $origin = $frozen ? ['origin' => ['kind' => 'internal-candidate', 'sha256' => $runtimeSha]]
            : (isset($recorded['origin']) ? ['origin' => $recorded['origin']]
                : ['original_runtime_sha256' => $recorded['original_runtime_sha256'] ?? $runtimeSha]);
        $actual = $origin + ['initial_manifest_sha256' => $recorded['initial_manifest_sha256']
                ?? ($frozen ? $identity['application']['manifest_sha256'] : $identity['application_manifest_sha256']),
            'files' => $files, 'execution_lock' => ['device' => $stat['dev'], 'inode' => $stat['ino']],
            'database' => $database, 'resource_id' => $config['resource_id'], 'endpoint_id' => $config['endpoint_id'], 'registered_routes' => $routes,
            'r1_ledger_sha256' => hash('sha256', self::databaseCanonical($ledger)),
            'identity_history_sha256' => hash('sha256', self::databaseCanonical($moduleRows)),
            'active_modules_sha256' => hash('sha256', self::databaseCanonical([$active, $modules]))];
        if ($recorded !== null && self::databaseCanonical($actual) !== self::databaseCanonical($recorded)) {
            throw new RuntimeException('partial source anchors changed');
        }
        return $actual;
    }

    private static function partialApplicationFailure(string $root, array $progress): array
    {
        $started = is_string($progress['started_at'] ?? null) ? strtotime($progress['started_at']) : false;
        $logs = glob($root . '/runtime/log/*/*.log') ?: [];
        if ($started === false || count($logs) > 32) { throw new RuntimeException('partial original failure is unknown'); }
        $found = [];
        foreach ($logs as $path) {
            $relative = substr($path, strlen($root) + 1); self::assertOrdinaryParents($root, $relative);
            if (filesize($path) > 4194304) { throw new RuntimeException('partial original log is too large'); }
            $bytes = self::regularBytes($path, 'partial original log');
            foreach (explode("\n", $bytes) as $line) {
                $offset = strpos($line, '{');
                if ($offset === false || !str_contains($line, 'installation_failure')) { continue; }
                $event = json_decode(substr($line, $offset), true, 32, JSON_THROW_ON_ERROR);
                $at = preg_match('/\A\[([^\]]+)\]/', $line, $match) === 1 ? strtotime($match[1]) : false;
                if (($event['event'] ?? null) !== 'installation_failure' || $at === false || $at < $started) { continue; }
                $chain = array_filter($event['exception_chain'] ?? [], static fn(array $row): bool =>
                    ($row['class'] ?? null) === 'app\\platform\\exception\\plugin\\PluginLifecycleException'
                    && ($row['path'] ?? null) === 'server/app/platform/services/plugin/PluginLifecycleService.php' && ($row['line'] ?? null) === 362);
                if (($event['stage'] ?? null) !== 'install_modules' || ($event['error_code'] ?? null) !== 'INSTALL_EXECUTION_FAILED' || count($chain) !== 1) {
                    throw new RuntimeException('partial failure is not the fixed File adoption frontier');
                }
                $found[] = ['path' => $relative, 'sha256' => hash('sha256', $bytes)];
            }
        }
        if (count($found) !== 1) { throw new RuntimeException('partial original failure is not unique'); }
        return $found[0];
    }

    private static function protectApplicationDeployment(string $root): void
    {
        self::sourceOwner($root);
        $directory = $root . '/private/installation';
        self::assertOrdinaryParents($root, 'private/installation/deployment.json');
        // This native startup runs after install-permissions and before FPM; the rw mount alone is insufficient.
        if (!chown($directory, fileowner($root)) || !chmod($directory, 0750)) {
            throw new RuntimeException('cannot protect the canonical deployment directory');
        }
        foreach (['deployment.json', 'deployment-history'] as $name) {
            $path = $directory . '/' . $name;
            if (file_exists($path) || is_link($path)) {
                if (is_link($path) || !chown($path, fileowner($root)) || !chmod($path, is_dir($path) ? 0750 : 0644)) {
                    throw new RuntimeException('cannot protect application deployment history');
                }
            }
        }
    }

    public static function publishApplicationDeployment(string $root, ?string $expected, array $state): string
    {
        self::sourceOwner($root);
        $path = self::deploymentPath($root);
        $bytes = file_exists($path) || is_link($path) ? self::regularBytes($path, 'application deployment') : null;
        $actual = $bytes === null ? null : hash('sha256', $bytes);
        $new = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if ($actual === hash('sha256', $new)) { return $actual; }
        if ($actual !== $expected || ($state['protocol'] ?? null) !== self::APPLICATION_DEPLOYMENT
            || ($state['previous_head_sha256'] ?? null) !== $expected) {
            throw new RuntimeException('application deployment CAS failed');
        }
        self::protectApplicationDeployment($root);
        if ($bytes !== null) {
            $history = $root . '/private/installation/deployment-history/' . $actual . '.json';
            if (file_exists($history) || is_link($history)) {
                if (self::regularBytes($history, 'deployment predecessor') !== $bytes) {
                    throw new RuntimeException('application deployment predecessor changed');
                }
            } else { self::durableFile($history, $bytes, 0644); }
        }
        self::durableFile($path, $new, 0644);
        self::protectApplicationDeployment($root);
        return hash('sha256', $new);
    }

    public static function admitSourceRuntime(string $root): void
    {
        self::sourceOwner($root);
        self::revokeTraffic($root);
        self::nativeRuntimeHealth($root);
    }

    /** Roll back only this unactivated head; retain its bytes as immutable failure history. */
    public static function recoverApplicationDeployment(string $root, string $expected, ?string $previous): ?string
    {
        self::sourceOwner($root);
        $path = self::deploymentPath($root);
        $actual = file_exists($path) || is_link($path) ? self::regularBytes($path, 'application deployment') : null;
        $before = $previous === null ? null : hash('sha256', $previous);
        if (($actual === null ? null : hash('sha256', $actual)) === $before) { return $before; }
        if ($actual === null || hash('sha256', $actual) !== $expected) { throw new RuntimeException('application recovery head CAS failed'); }
        $history = $root . '/private/installation/deployment-history/' . $expected . '.json';
        if (file_exists($history) || is_link($history)) {
            if (self::regularBytes($history, 'unactivated deployment history') !== $actual) { throw new RuntimeException('application recovery history changed'); }
        } else { self::durableFile($history, $actual, 0644); }
        if ($previous === null) {
            if (!unlink($path)) { throw new RuntimeException('cannot recover initial deployment absence'); }
            self::syncDirectory(dirname($path));
        } else { self::durableFile($path, $previous, 0644); }
        return $before;
    }

    /** @var list<string> */
    private const PROTECTED_PREFIXES = [
        'server/runtime/',
        'server/public/storage/',
        'server/public/uploads/',
        'server/private/storage/',
        'server/private/installation/',
        'server/private/resources/',
        'server/docker/mysql/',
        'server/docker/secrets/',
        'server/vendor/',
        'server/.git/',
    ];

    /** @var list<string> */
    private const PROTECTED_FILES = [
        'server/.env',
        'server/docker/.env',
    ];

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        if (count($argv) < 2 || !in_array($argv[1] ?? '', [
            'plan',
            'files-apply', 'files-verify', 'files-recover', 'files-verify-recovery',
            'hold', 'health', 'publish', 'open',
            'initialize-traffic', 'initialize-owner-traffic', 'close-startup-traffic', 'publish-startup-configuration', 'configure-startup', 'source-admit',
        ], true)) {
            self::usage();
        }
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (preg_match('/^--(instance-server|archive|expected-sha256|workspace|configuration-input)=(.+)$/D', $argument, $matches) !== 1
                || isset($options[$matches[1]])) {
                self::usage();
            }
            $options[$matches[1]] = $matches[2];
        }
        $command = $argv[1];
        $requiredOptions = $command === 'configure-startup' ? ['instance-server', 'configuration-input'] : ($command === 'plan'
            ? ['instance-server', 'archive', 'expected-sha256', 'workspace']
            : (in_array($command, ['initialize-traffic', 'initialize-owner-traffic', 'close-startup-traffic', 'publish-startup-configuration'], true) ? ['instance-server'] : ['instance-server', 'workspace']));
        foreach ($requiredOptions as $required) {
            if (!isset($options[$required])) {
                self::usage();
            }
        }
        if ($command !== 'plan' && (isset($options['archive']) || isset($options['expected-sha256']))) {
            self::usage();
        }
        if (($command !== 'configure-startup' && isset($options['configuration-input']))
            || (in_array($command, ['initialize-traffic', 'initialize-owner-traffic', 'close-startup-traffic', 'publish-startup-configuration', 'configure-startup'], true) && isset($options['workspace']))) {
            self::usage();
        }

        try {
            $result = match ($command) {
                'plan' => self::build(
                    $options['instance-server'],
                    $options['archive'],
                    $options['expected-sha256'],
                    $options['workspace'],
                ),
                'files-apply', 'files-verify', 'files-recover', 'files-verify-recovery',
                'hold', 'health', 'publish', 'open' => self::productPhase($options['instance-server'], $options['workspace'], $command),
                'initialize-traffic' => self::initializeTraffic($options['instance-server']),
                'initialize-owner-traffic' => self::initializeTraffic($options['instance-server'], true),
                'close-startup-traffic' => self::closeStartupTraffic($options['instance-server']),
                'publish-startup-configuration' => self::publishStartupConfiguration($options['instance-server']),
                'configure-startup' => self::configureStartup($options['instance-server'], $options['configuration-input']),
                'source-admit' => self::sourceChildAdmission($options['instance-server'], $options['workspace']),
            };
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'server-update: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /** Initial startup may grant traffic once; an interrupted update cannot be reopened by a restart. */
    /** Immutable inventory and per-file intents are subordinate to the installed product coordinator. */
    public static function productBinding(string $workspace): array
    {
        $binding = self::jsonFile($workspace . '/product-binding.json', 'product binding');
        $product = self::jsonFile((string) ($binding['plan_path'] ?? ''), 'product plan');
        $digest = $product['plan_sha256'] ?? null;
        unset($product['plan_sha256']);
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                $value[$key] = $normalize($child);
            }
            return $value;
        };
        if (($product['protocol'] ?? null) !== 'peanut.product-upgrade-plan.v2'
            || ($product['workspace'] ?? null) !== $workspace
            || $digest !== ($binding['plan_sha256'] ?? null)
            || !is_string($digest) || !hash_equals($digest, 'sha256:' . hash('sha256', json_encode($normalize($product), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('phase inputs are not bound to the product plan');
        }
        $state = self::jsonFile((string) ($product['state_path'] ?? ''), 'product state');
        $stateDigest = $state['state_sha256'] ?? null;
        unset($state['state_sha256']);
        if (($state['protocol'] ?? null) !== 'peanut.product-upgrade-state.v1'
            || ($state['candidate'] ?? null) !== ($product['candidate'] ?? null)
            || ($state['plan_sha256'] ?? null) !== $digest
            || !is_string($stateDigest) || !hash_equals($stateDigest, 'sha256:' . hash('sha256', json_encode($normalize($state), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('product phase state binding is invalid');
        }
        return [$product + ['plan_sha256' => $digest], $state];
    }

    public static function productPhase(string $serverRoot, string $workspace, string $phase): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'instance server');
        $workspace = self::ordinaryDirectory($workspace, 'phase workspace');
        [$product, $state] = self::productBinding($workspace);
        if ($serverRoot !== $product['instance_root'] . '/server') {
            throw new RuntimeException('phase server differs from product instance');
        }
        $allowed = match ($phase) {
            'files-apply' => ['managed_files'], 'files-recover' => [null, 'recover'],
            'files-verify', 'files-verify-recovery' => [null, 'health', 'activate', 'recovery-verify'],
            'hold' => ['backup', 'quiesce', 'recover', 'activate', 'resume-old'], 'health' => ['health', 'activate', 'resume-old', 'recovery-verify'],
            'publish' => ['activate'], 'open' => ['activate', 'resume-old'],
        };
        if (!in_array($state['phase_in_progress'] ?? null, $allowed, true)
            || (str_contains($phase, 'recover') && (($state['activation_started'] ?? null) !== false || !isset($state['recovery_context'])))
            || ($phase === 'publish' && ($state['activation_started'] ?? null) !== true)) {
            throw new RuntimeException('native phase is not authorized by product state');
        }
        return self::withInstanceLock($serverRoot, static function () use ($serverRoot, $workspace, $product, $state, $phase): array {
            $id = $product['candidate'];
            self::verificationKey($serverRoot, true);
            if ($phase === 'hold') {
                self::writeMaintenance($serverRoot, ['update_id' => $id], $phase);
                return ['write_gate' => 'closed', 'candidate' => $id];
            }
            if ($phase === 'health') {
                self::nativeRuntimeHealth($serverRoot);
                return ['installed_runtime_health' => 'current', 'candidate' => $id];
            }
            if ($phase === 'open') {
                if (($state['phase_in_progress'] ?? null) === 'activate' && ($state['activation_started'] ?? null) !== true) {
                    throw new RuntimeException('product activation boundary was not persisted');
                }
                self::revokeTraffic($serverRoot);
                self::nativeRuntimeHealth($serverRoot);
                self::grantTraffic($serverRoot);
                self::clearMaintenance($serverRoot, $id, isset($state['recovery_context']) ? 'recovered' : 'completed');
                return ['write_gate' => 'open', 'candidate' => $id];
            }
            if (($product['scope'] ?? null) !== 'server') {
                throw new RuntimeException('server inventory phase requires server scope');
            }
            $input = self::plan($workspace);
            if (hash_file('sha256', $workspace . '/plan.json') !== $product['inputs_sha256']) {
                throw new RuntimeException('server inventory changed after product planning');
            }
            if ($phase === 'files-apply') {
                self::assertMaintenance($serverRoot, $id);
                $backupPath = $workspace . '/backup.json';
                $backupBytes = self::regularBytes($backupPath, 'paired backup');
                $signature = trim(self::regularBytes($backupPath . '.hmac', 'paired backup authentication'));
                $backup = json_decode($backupBytes, true, 256, JSON_THROW_ON_ERROR);
                if (!isset($state['evidence']['backup'], $state['evidence']['quiesce'])
                    || ($backup['product_plan_sha256'] ?? null) !== $product['plan_sha256']
                    || ($backup['plan_sha256'] ?? null) !== $product['inputs_sha256']
                    || !hash_equals(hash_hmac('sha256', $backupBytes, self::verificationKey($serverRoot, false)), $signature)
                    || ($state['evidence']['backup']['payload']['payload']['backup_sha256'] ?? null) !== hash('sha256', $backupBytes)) {
                    throw new RuntimeException('file phase lacks its authenticated paired recovery point');
                }
            }
            if ($phase === 'files-recover' && !isset($state['evidence']['recover'])) {
                throw new RuntimeException('product data recovery must finish before source recovery');
            }
            if ($phase === 'publish') {
                self::publishDeploymentState($serverRoot, $workspace, $input, $id);
                return ['deployment_generation' => $input['source']['deployment_generation'] + 1];
            }
            $path = self::journalPath($workspace);
            if (!file_exists($path) && !is_link($path)) {
                if (in_array($phase, ['files-recover', 'files-verify-recovery'], true)) {
                    self::assertPlanOperations($serverRoot, $workspace, $input);
                    return ['candidate' => $id, 'program_changes' => false];
                }
                if ($phase !== 'files-apply') {
                    throw new RuntimeException('file intents are missing');
                }
                self::assertPreparedServer($workspace, $input);
                self::assertPlanOperations($serverRoot, $workspace, $input);
                $journal = self::initialJournal($workspace, $input);
                $journal['product_plan_sha256'] = $product['plan_sha256'];
                self::writeJson($path, $journal);
            }
            $journal = self::journal($workspace);
            self::assertJournalPlan($workspace, $input, $journal);
            if (($journal['product_plan_sha256'] ?? null) !== $product['plan_sha256']
                || ($journal['plan_sha256'] ?? null) !== $product['inputs_sha256']) {
                throw new RuntimeException('file intents belong to another product plan');
            }
            if ($phase === 'files-apply') {
                self::assertPreparedServer($workspace, $input);
                foreach ($journal['operations'] as $index => $operation) {
                    $operation = self::recordIntent($serverRoot, $workspace, $journal, $index);
                    $journal['operations'][$index] = $operation;
                    self::writeJson($path, $journal);
                    if ($operation['status'] !== 'done') {
                        self::applyOperation($serverRoot, $workspace, $operation);
                        $journal['operations'][$index]['status'] = 'done';
                        self::writeJson($path, $journal);
                    }
                }
            } elseif ($phase === 'files-recover') {
                for ($index = count($journal['operations']) - 1; $index >= 0; --$index) {
                    if ($journal['operations'][$index]['status'] === 'pending') {
                        continue;
                    }
                    self::recoverOperation($serverRoot, $workspace, $journal['operations'][$index]);
                    $journal['operations'][$index]['status'] = 'recovered';
                    self::writeJson($path, $journal);
                }
            } else {
                foreach ($journal['operations'] as $operation) {
                    self::assertOperationState($serverRoot, $operation, $phase === 'files-verify');
                }
            }
            return ['candidate' => $id, 'operation_count' => count($journal['operations']), 'intent_sha256' => hash_file('sha256', $path)];
        });
    }

    public static function publishStartupConfiguration(string $serverRoot): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'server root');
        self::assertNoLegacyMaintenanceKey($serverRoot);
        self::assertAdmissionBoundary($serverRoot);
        return self::withInstanceLock($serverRoot, static function () use ($serverRoot): array {
            if (file_exists(self::trafficReadyPath($serverRoot)) || is_link(self::trafficReadyPath($serverRoot))
                || file_exists(self::maintenancePath($serverRoot)) || file_exists(self::currentPointerPath($serverRoot))) {
                throw new RuntimeException('configuration owner requires closed initial traffic');
            }
            if ((lstat($serverRoot . '/docker/secrets/install-token')['mode'] & 0777) !== 0600) {
                throw new RuntimeException('initial setup token is not protected');
            }
            $token = trim(self::regularBytes($serverRoot . '/docker/secrets/install-token', 'initial setup token'));
            require_once $serverRoot . '/vendor/autoload.php';
            $identity = self::runtimeIdentity($serverRoot);
            $application = new \think\App($serverRoot);
            $application->instance($identity::class, $identity);
            return (new \app\common\services\installation\InstallationConfigurationHost($serverRoot))->publishPending($token);
        });
    }

    /** Host first configuration uses the same owner boundary and canonical publisher. */
    public static function configureStartup(string $serverRoot, string $inputPath): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'server root');
        $owner = fileowner($serverRoot);
        if (!is_int($owner) || !in_array(posix_geteuid(), [0, $owner], true)) {
            throw new RuntimeException('configuration requires its deployment owner');
        }
        self::assertAdmissionBoundary($serverRoot);
        return self::withInstanceLock($serverRoot, static function () use ($serverRoot, $inputPath): array {
            if (file_exists(self::trafficReadyPath($serverRoot)) || is_link(self::trafficReadyPath($serverRoot))
                || file_exists(self::maintenancePath($serverRoot)) || file_exists(self::currentPointerPath($serverRoot))) {
                throw new RuntimeException('configuration owner requires closed initial traffic');
            }
            $stat = @lstat($inputPath);
            if ($inputPath === '' || $inputPath[0] !== '/' || realpath($inputPath) !== $inputPath
                || !is_array($stat) || !is_file($inputPath) || is_link($inputPath)
                || $stat['uid'] !== posix_geteuid() || $stat['nlink'] !== 1
                || ($stat['mode'] & 0777) !== 0600 || $stat['size'] > 65536) {
                throw new RuntimeException('configuration input requires an owner-held regular mode-0600 file');
            }
            $input = self::jsonFile($inputPath, 'configuration input');
            if (array_diff(array_keys($input), ['setup_token', 'configuration', 'resource_registry']) !== []
                || !is_string($input['setup_token'] ?? null) || !is_array($input['configuration'] ?? null)
                || (array_key_exists('resource_registry', $input) && !is_array($input['resource_registry']))) {
                throw new RuntimeException('configuration input requires setup_token, configuration and an explicit APP registry for registered databases');
            }
            require_once $serverRoot . '/vendor/autoload.php';
            $identity = self::runtimeIdentity($serverRoot);
            $application = new \think\App($serverRoot);
            $application->instance($identity::class, $identity);
            return (new \app\common\services\installation\InstallationConfigurationHost($serverRoot))
                ->configureForOwner($input['setup_token'], $input['configuration'], $input['resource_registry'] ?? null);
        });
    }

    public static function closeStartupTraffic(string $serverRoot): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'server root');
        $owner = fileowner($serverRoot);
        if (!is_int($owner) || !in_array(posix_geteuid(), [0, $owner], true)) {
            throw new RuntimeException('startup traffic requires its deployment owner');
        }
        return self::withInstanceLock($serverRoot, static function () use ($serverRoot): array {
            self::revokeTraffic($serverRoot);
            return ['status' => 'closed'];
        });
    }

    public static function initializeTraffic(string $serverRoot, bool $ownerStartup = false): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'instance server');
        if ($ownerStartup && ($serverRoot !== '/run/peanut-owner/server' || posix_geteuid() !== 0)) {
            throw new RuntimeException('readonly startup requires its native root owner');
        }
        return self::withInstanceLock($serverRoot, static function () use ($serverRoot, $ownerStartup): array {
            $source = self::sourcePending($serverRoot);
            $sourceDeployment = self::deploymentPath($serverRoot);
            $sourceDeploymentSha = file_exists($sourceDeployment) || is_link($sourceDeployment)
                ? hash('sha256', self::regularBytes($sourceDeployment, 'application deployment')) : null;
            if ($source !== null && (!in_array($source['phase'] ?? null, ['opening', 'opened'], true)
                || ($source['activation_started'] ?? null) !== true
                || ($source['deployment_sha256'] ?? null) !== $sourceDeploymentSha
                || ($source['runtime_sha256'] ?? null) !== hash_file('sha256', $serverRoot . '/.peanut/development-runtime.json'))) {
                self::revokeTraffic($serverRoot);
                throw new RuntimeException('source runtime transition is incomplete');
            }
            $maintenance = self::maintenancePath($serverRoot);
            $pointer = self::currentPointerPath($serverRoot);
            if (file_exists($maintenance) || is_link($maintenance)
                || file_exists($pointer) || is_link($pointer)) {
                self::revokeTraffic($serverRoot);
                [, , $serverRelease] = self::startupIdentity($serverRoot);
                self::prepareRuntimeAdmission($serverRoot, $serverRelease);
                return ['status' => 'closed'];
            }
            try {
                [$identity, $identityDigest, $serverRelease] = self::startupIdentity($serverRoot);
                $installedPath = $serverRoot . '/private/installation/installed.json';
                $deploymentPath = self::deploymentPath($serverRoot);
                if (is_link($installedPath) || is_link($deploymentPath)
                    || (file_exists($installedPath) && !is_file($installedPath))
                    || (file_exists($deploymentPath) && !is_file($deploymentPath))) {
                    throw new RuntimeException('installation state is unsafe for initial traffic');
                }
                if (($identity['protocol'] ?? null) === 'peanut.development-server-runtime.v1' && file_exists($installedPath)) {
                    self::applicationDeployment($serverRoot, $identity, $identityDigest);
                    if (file_exists(self::deploymentPath($serverRoot))) { self::protectApplicationDeployment($serverRoot); }
                } elseif ($serverRelease && file_exists($installedPath)) {
                    self::deploymentState($serverRoot, $identity, $identityDigest);
                } elseif (file_exists($deploymentPath)) {
                    throw new RuntimeException('source or uninstalled instance has server deployment state');
                }
            } catch (Throwable $exception) {
                // A startup failure must not leave an earlier static-page grant usable.
                self::revokeTraffic($serverRoot);
                throw $exception;
            }
            $initialized = self::trafficInitializedPath($serverRoot);
            $ready = self::trafficReadyPath($serverRoot);
            if (file_exists($initialized) || is_link($initialized)) {
                try {
                    self::assertTrafficInitialized($serverRoot);
                } catch (Throwable $exception) {
                    self::removeTrafficReady($serverRoot);
                    throw $exception;
                }
                if (!file_exists($ready) && !is_link($ready)) {
                    if (!$ownerStartup || !$serverRelease
                        || file_exists($serverRoot . '/private/resources/pending/configuration.json')
                        || is_link($serverRoot . '/private/resources/pending/configuration.json')) {
                        return ['status' => 'closed'];
                    }
                    self::prepareStartupAdmission($serverRoot, $serverRelease);
                    self::grantTraffic($serverRoot);
                    return ['status' => 'open'];
                }
                try {
                    self::assertTrafficReady($serverRoot);
                } catch (Throwable $exception) {
                    self::removeTrafficReady($serverRoot);
                    throw $exception;
                }
                self::removeTrafficReady($serverRoot);
                self::prepareStartupAdmission($serverRoot, $serverRelease);
                self::grantTraffic($serverRoot);
                return ['status' => 'open'];
            }
            if (file_exists($ready) || is_link($ready)) {
                self::revokeTraffic($serverRoot);
                throw new RuntimeException('unbound traffic permission exists before initialization');
            }
            self::durableFile($initialized, "peanut.server-traffic-initialized.v1\n", 0600);
            self::prepareStartupAdmission($serverRoot, $serverRelease);
            self::grantTraffic($serverRoot);
            return ['status' => 'open'];
        });
    }

    /** @return array{0:array<string,mixed>,1:string,2:bool} */
    private static function startupIdentity(string $serverRoot): array
    {
        $development = $serverRoot . '/.peanut/development-runtime.json';
        if (file_exists($development) || is_link($development)) {
            require_once $serverRoot . '/vendor/autoload.php';
            $source = \app\common\value\installation\ApplicationSourceIdentity::loadRuntime($serverRoot);
            return [$source->document(), $source->identitySha256(), true];
        }
        $identityPath = $serverRoot . '/.peanut/release-identity.json';
        if (file_exists($identityPath) || is_link($identityPath)) {
            $identityBytes = self::regularBytes($identityPath, 'server release identity');
            return [
                self::identity(json_decode($identityBytes, true, 512, JSON_THROW_ON_ERROR), false),
                hash('sha256', $identityBytes),
                true,
            ];
        }

        $autoload = $serverRoot . '/vendor/autoload.php';
        if (!is_file($autoload) || is_link($autoload)) {
            throw new RuntimeException('source application runtime is missing Composer autoload');
        }
        require_once $autoload;
        $source = \app\common\value\installation\ApplicationSourceIdentity::load($serverRoot);
        return [
            [
                'application' => $source->applicationIdentity(),
                'versions' => $source->versions(),
            ],
            hash('sha256', json_encode([
                'application' => $source->applicationIdentity(),
                'versions' => $source->versions(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            false,
        ];
    }

    /** @return array<string,mixed> */
    public static function build(string $serverRoot, string $archive, string $expectedSha256, string $workspace): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'instance server');
        $workspace = self::ordinaryDirectory($workspace, 'update workspace');
        if (!is_file($archive) || is_link($archive) || preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new RuntimeException('target archive or trusted SHA-256 is invalid');
        }
        $archiveDigest = hash_file('sha256', $archive);
        if (!is_string($archiveDigest) || !hash_equals($expectedSha256, $archiveDigest)) {
            throw new RuntimeException('target archive differs from trusted SHA-256');
        }

        self::assertOrdinaryParents($serverRoot, '.peanut/release-identity.json');
        $currentBytes = self::regularBytes($serverRoot . '/.peanut/release-identity.json', 'current server release identity');
        $current = self::identity(json_decode($currentBytes, true, 512, JSON_THROW_ON_ERROR), false);
        self::assertInstalledInstance($serverRoot, $current);
        $deployment = self::deploymentState($serverRoot, $current, hash('sha256', $currentBytes));
        [$targetBytes, $target, $targetFiles] = self::archive($archive);

        foreach (['slug', 'edition', 'package_identity'] as $field) {
            if (!hash_equals((string) $current['application'][$field], (string) $target['application'][$field])) {
                throw new RuntimeException('target release belongs to another application or edition');
            }
        }
        if ($current['application']['version'] === $target['application']['version']
            && !hash_equals(hash('sha256', $currentBytes), hash('sha256', $targetBytes))) {
            throw new RuntimeException('target release reuses the current application version with different bytes');
        }

        $currentMap = self::fileMap($current['files']);
        $currentMap[self::IDENTITY] = [
            'path' => self::IDENTITY,
            'sha256' => hash('sha256', $currentBytes),
            'mode' => 0644,
        ];
        $targetMap = self::fileMap($target['files']);
        $targetMap[self::IDENTITY] = [
            'path' => self::IDENTITY,
            'sha256' => hash('sha256', $targetBytes),
            'mode' => $targetFiles[self::IDENTITY]['mode'],
        ];

        $ownedDirectories = self::ownedDirectories(array_keys($currentMap));
        $operations = [];
        foreach ($targetMap as $path => $targetFile) {
            self::assertProgramPath($path);
            $relative = substr($path, strlen('server/'));
            $actualPath = $serverRoot . '/' . $relative;
            self::assertTargetParents($serverRoot, $path, $ownedDirectories, isset($currentMap[$path]));
            $exists = file_exists($actualPath) || is_link($actualPath);
            if (!isset($currentMap[$path]) && $exists) {
                throw new RuntimeException('target path collides with an unknown existing path: ' . $path);
            }
            $currentDigest = null;
            $currentMode = null;
            if ($exists) {
                if (!is_file($actualPath) || is_link($actualPath)) {
                    throw new RuntimeException('program path changed to an unsafe file type: ' . $path);
                }
                $currentDigest = hash_file('sha256', $actualPath);
                $currentMode = fileperms($actualPath) & 0777;
                if (!is_string($currentDigest)) {
                    throw new RuntimeException('cannot hash current program file: ' . $path);
                }
                if (isset($currentMap[$path]) && ($currentDigest !== $currentMap[$path]['sha256'] || $currentMode !== $currentMap[$path]['mode'])) {
                    throw new RuntimeException('managed source changed outside its published application release: ' . $path);
                }
            }
            $operations[] = [
                'path' => $path,
                'operation' => isset($currentMap[$path]) ? 'replace' : 'add',
                'current_present' => $exists,
                'current_sha256' => $currentDigest,
                'current_mode' => $currentMode,
                'target_sha256' => $targetFile['sha256'],
                'target_mode' => $targetFile['mode'],
            ];
        }

        foreach ($currentMap as $path => $currentFile) {
            if (isset($targetMap[$path])) {
                continue;
            }
            self::assertProgramPath($path);
            $relative = substr($path, strlen('server/'));
            $actualPath = $serverRoot . '/' . $relative;
            $exists = file_exists($actualPath) || is_link($actualPath);
            $currentDigest = null;
            $currentMode = null;
            if ($exists) {
                if (!is_file($actualPath) || is_link($actualPath)) {
                    throw new RuntimeException('removed program path changed to an unsafe file type: ' . $path);
                }
                $currentDigest = hash_file('sha256', $actualPath);
                $currentMode = fileperms($actualPath) & 0777;
                if (!is_string($currentDigest)) {
                    throw new RuntimeException('cannot hash removed program file: ' . $path);
                }
                if ($currentDigest !== $currentFile['sha256'] || $currentMode !== $currentFile['mode']) {
                    throw new RuntimeException('removed managed source was customized: ' . $path);
                }
            }
            $operations[] = [
                'path' => $path,
                'operation' => 'delete',
                'current_present' => $exists,
                'current_sha256' => $currentDigest,
                'current_mode' => $currentMode,
                'target_sha256' => null,
                'target_mode' => null,
            ];
        }
        usort($operations, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));

        $changedPaths = array_column($operations, 'path');
        $requirements = [
            'composer_dependencies_changed' => self::operationTouches($operations, 'server/composer.json')
                || self::operationTouches($operations, 'server/composer.lock'),
            'database_migrations_changed' => self::operationPrefix($operations, 'server/database/'),
            'runtime_environment_changed' => self::operationTouches($operations, 'server/docker/Dockerfile')
                || self::operationTouches($operations, 'server/docker/compose.yaml')
                || self::operationPrefix(
                    array_values(array_filter(
                        $operations,
                        static fn(array $operation): bool => $operation['path'] !== 'server/docker/conf/nginx.conf',
                    )),
                    'server/docker/conf/',
                ),
            'nginx_configuration_changed' => self::operationTouches($operations, 'server/docker/conf/nginx.conf'),
        ];

        $plan = [
            'schema_version' => 1,
            'protocol' => self::PLAN_PROTOCOL,
            'update_id' => 'server_update_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(6)),
            'source' => [
                'application' => $current['application'],
                'identity_sha256' => hash('sha256', $currentBytes),
                'installed_receipt_sha256' => $deployment['installed_receipt_sha256'],
                'baseline_sha256' => $deployment['baseline_sha256'],
                'deployment_generation' => $deployment['generation'],
                'deployment_state_sha256' => $deployment['state_sha256'],
            ],
            'target' => [
                'application' => $target['application'],
                'archive_sha256' => $archiveDigest,
                'identity_sha256' => hash('sha256', $targetBytes),
            ],
            'tool_sha256' => hash_file('sha256', __FILE__),
            'requirements' => $requirements,
            'protected_paths' => [...self::PROTECTED_FILES, ...self::PROTECTED_PREFIXES],
            'prepared_server' => 'prepared/server',
            'operation_count' => count($operations),
            'operations' => $operations,
            'operation_paths_sha256' => hash('sha256', implode("\n", $changedPaths) . "\n"),
        ];

        $planPath = $workspace . '/plan.json';
        if (file_exists($planPath) || is_link($planPath)) {
            throw new RuntimeException('update workspace already contains a plan');
        }
        self::extractPreparedServer($archive, $workspace, $targetMap);
        self::writeJson($planPath, $plan);
        return $plan;
    }

    private static function nativeRuntimeHealth(string $serverRoot): void
    {
        $started = hrtime(true);
        $envPath = $serverRoot . '/.env';
        self::regularBytes($envPath, 'installed runtime environment');
        putenv('PEANUT_SERVER_ENV_FILE=' . $envPath);
        chdir($serverRoot);
        self::assertAdmissionBoundary($serverRoot);
        require_once $serverRoot . '/vendor/autoload.php';
        $identity = self::runtimeIdentity($serverRoot);
        if ($identity instanceof \app\common\value\installation\ApplicationSourceIdentity) {
            self::assertDevelopmentInstalledInstance($serverRoot, $identity);
        }
        $verified = hrtime(true);
        require_once $serverRoot . '/database/install.php';
        $application = new \think\App($serverRoot);
        $application->instance($identity::class, $identity);
        $application->initialize();
        $compiled = hrtime(true);
        if ($identity instanceof \app\common\value\installation\ApplicationSourceIdentity
            && !file_exists($serverRoot . '/private/installation/installed.json') && !is_link($serverRoot . '/private/installation/installed.json')
            && !file_exists($serverRoot . '/private/installation/baseline.json') && !is_link($serverRoot . '/private/installation/baseline.json')) {
            // assertDevelopmentInstalledInstance already proved the exact retained partial
            // head. Admit that actual source without claiming installed health or opening traffic.
            \app\common\infrastructure\installation\VerifiedServerDeployment::publish($application, $identity);
            self::writeAdmissionReceipt($serverRoot, $application, $identity, $started, $verified, $compiled);
            return;
        }
        $host = $application->make(\app\common\services\installation\InstallationExecutionHost::class);
        $status = $host->status();
        if (($status['state'] ?? null) !== 'installed'
            || ($status['code'] ?? null) !== 'INSTALL_ALREADY_COMPLETED'
            || !is_array($status['health'] ?? null) || $status['health'] === []) {
            $state = in_array($status['state'] ?? null, ['installed', 'uninstalled', 'blocked'], true)
                ? $status['state'] : 'unknown';
            $codes = ['INSTALL_PREFLIGHT_BLOCKED', 'INSTALL_STATE_MIGRATION_PENDING',
                'INSTALL_STATE_MIGRATION_REQUIRED', 'INSTALL_COMPLETION_LOCK_INVALID',
                'INSTALL_LOCKED_DATABASE_UNAVAILABLE', 'INSTALL_DATABASE_UNAVAILABLE',
                'INSTALL_LOCKED_DATABASE_MISMATCH', 'INSTALL_ALREADY_COMPLETED',
                'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD', 'INSTALL_RETRY_READY', 'INSTALL_READY',
                'INSTALL_COMPLETION_LOCK_MISSING'];
            $code = in_array($status['code'] ?? null, $codes, true) ? $status['code'] : 'unknown';
            throw new RuntimeException('native installed runtime health is not current: ' . $state . '/' . $code);
        }
        $healthy = hrtime(true);
        \app\common\infrastructure\installation\VerifiedServerDeployment::publish($application, $identity);
        self::writeAdmissionReceipt($serverRoot, $application, $identity, $started, $verified, $compiled, $healthy);
    }

    private static function assertAdmissionBoundary(string $serverRoot): void
    {
        self::assertNoLegacyMaintenanceKey($serverRoot);
        require_once $serverRoot . '/app/common/value/installation/ApplicationSourceIdentity.php';
        require_once $serverRoot . '/app/common/infrastructure/installation/ReadonlyHttpMount.php';
        require_once $serverRoot . '/app/common/infrastructure/installation/VerifiedServerDeployment.php';
        \app\common\infrastructure\installation\VerifiedServerDeployment::assertImmutableProgram($serverRoot);
    }

    private static function prepareStartupAdmission(string $serverRoot, bool $serverRelease): void
    {
        if ($serverRelease && is_file($serverRoot . '/private/installation/installed.json')) {
            self::nativeRuntimeHealth($serverRoot);
        } else {
            self::prepareRuntimeAdmission($serverRoot, $serverRelease);
        }
    }

    private static function prepareRuntimeAdmission(string $serverRoot, bool $serverRelease): void
    {
        if (!$serverRelease) {
            return;
        }
        $started = hrtime(true);
        self::assertAdmissionBoundary($serverRoot);
        require_once $serverRoot . '/vendor/autoload.php';
        $identity = self::runtimeIdentity($serverRoot);
        $verified = hrtime(true);
        require_once $serverRoot . '/database/install.php';
        $application = new \think\App($serverRoot);
        $application->instance($identity::class, $identity);
        $application->initialize();
        $compiled = hrtime(true);
        \app\common\infrastructure\installation\VerifiedServerDeployment::publish($application, $identity);
        self::writeAdmissionReceipt($serverRoot, $application, $identity, $started, $verified, $compiled);
    }

    private static function writeAdmissionReceipt(
        string $serverRoot,
        \think\App $application,
        \app\common\value\installation\ServerReleaseIdentity|\app\common\value\installation\ApplicationSourceIdentity $identity,
        int $started,
        int $verified,
        int $compiled,
        ?int $healthy = null,
    ): void {
        $healthy ??= $compiled;
        self::writeJson($serverRoot . '/runtime/upgrade/verified-deployment/receipt.json', [
            'protocol' => 'peanut.server-runtime-admission-receipt.v1',
            'identity_sha256' => $identity->identitySha256(),
            'file_count' => count($identity->document()['files']),
            'module_count' => count($application->make(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class)->modules),
            'verification_ms' => ($verified - $started) / 1000000,
            'module_initialization_ms' => ($compiled - $verified) / 1000000,
            'runtime_health_ms' => ($healthy - $compiled) / 1000000,
            'publication_ms' => (hrtime(true) - $healthy) / 1000000,
        ]);
    }

    private static function runtimeIdentity(string $serverRoot): \app\common\value\installation\ServerReleaseIdentity|\app\common\value\installation\ApplicationSourceIdentity
    {
        return \app\common\value\installation\ApplicationSourceIdentity::hasRuntime($serverRoot)
            ? \app\common\value\installation\ApplicationSourceIdentity::loadRuntime($serverRoot)
            : \app\common\value\installation\ServerReleaseIdentity::load($serverRoot);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $journal @return array<string,mixed> */
    /** @param array<string,mixed> $plan @param array<string,mixed> $journal */
    private static function assertMaintenance(string $serverRoot, string $updateId): void
    {
        $marker = self::jsonFile(self::maintenancePath($serverRoot), 'server update maintenance marker');
        if (($marker['protocol'] ?? null) !== self::MAINTENANCE_PROTOCOL
            || ($marker['status'] ?? null) !== 'active'
            || ($marker['update_id'] ?? null) !== $updateId) {
            throw new RuntimeException('server update maintenance marker is not bound to plan');
        }
    }

    private static function assertNoLegacyMaintenanceKey(string $serverRoot): void
    {
        $legacy = $serverRoot . '/private/installation/update-verification.key';
        if (file_exists($legacy) || is_link($legacy)) {
            throw new RuntimeException('OWNER_MAINTENANCE_REQUIRED: finish the previous fixed-tool transaction before adopting readonly runtime; legacy verification key must be retired by its owner');
        }
    }

    private static function verificationKey(string $serverRoot, bool $create): string
    {
        self::assertNoLegacyMaintenanceKey($serverRoot);
        $directory = $serverRoot . '/private/maintenance';
        if ($create) { self::ensureDirectory($directory, 0700); }
        if (!is_dir($directory) || is_link($directory)) { throw new RuntimeException('private maintenance directory is unavailable'); }
        $owner = fileowner($serverRoot);
        $stat = lstat($directory);
        $parent = lstat($serverRoot . '/private');
        if (is_link($serverRoot . '/private') || !is_dir($serverRoot . '/private')
            || !in_array($parent['uid'], [0, $owner], true) || ($parent['mode'] & 0022) !== 0) {
            throw new RuntimeException('private maintenance parent requires its deployment owner');
        }
        if (!is_int($owner) || !in_array(posix_geteuid(), [0, $owner], true)
            || !in_array($stat['uid'], [0, $owner], true) || ($stat['mode'] & 0777) !== 0700) {
            throw new RuntimeException('private maintenance directory requires its deployment owner');
        }
        $path = $directory . '/update-verification.key';
        if (!file_exists($path) && !is_link($path) && $create) {
            self::durableFile($path, bin2hex(random_bytes(32)) . "\n", 0600);
        }
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('private update verification key has unsafe type or mode');
        }
        $key = trim(self::regularBytes($path, 'private update verification key'));
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new RuntimeException('private update verification key is invalid');
        }
        return $key;
    }

    private static function assertDevelopmentInstalledInstance(
        string $serverRoot,
        \app\common\value\installation\ApplicationSourceIdentity $identity,
    ): void {
        if (!file_exists($serverRoot . '/private/installation/installed.json') && !is_link($serverRoot . '/private/installation/installed.json')
            && !file_exists($serverRoot . '/private/installation/baseline.json') && !is_link($serverRoot . '/private/installation/baseline.json')) {
            $pending = self::sourcePending($serverRoot);
            $deployment = self::applicationDeployment($serverRoot, $identity->document(), $identity->identitySha256());
            if (!isset($deployment['partial_installation']) || $deployment['head_sha256'] === null
                || !in_array($pending['phase'] ?? null, ['head-published', 'recovered'], true)
                || ($pending['deployment_sha256'] ?? null) !== $deployment['head_sha256']) {
                throw new RuntimeException('partial development admission requires its held native source transition');
            }
            return;
        }
        self::assertOrdinaryParents($serverRoot, 'private/installation/installed.json');
        $receipt = json_decode(self::regularBytes($serverRoot . '/private/installation/installed.json', 'installed APP receipt'), true, 512, JSON_THROW_ON_ERROR);
        $edition = $identity->applicationIdentity()['edition'];
        if (!is_array($receipt) || ($receipt['schema_version'] ?? null) !== 1
            || ($receipt['protocol'] ?? null) !== 'peanut.installation-receipt.v1'
            || ($receipt['state'] ?? null) !== 'installed'
            || ($receipt['deployment_mode'] ?? null) !== $edition
            || ($receipt['baseline_manifest']['path'] ?? null) !== 'server/private/installation/baseline.json') {
            throw new RuntimeException('development runtime requires the same installed APP receipt');
        }
        self::assertOrdinaryParents($serverRoot, 'private/installation/baseline.json');
        $bytes = self::regularBytes($serverRoot . '/private/installation/baseline.json', 'installed APP baseline');
        $expected = $receipt['baseline_manifest']['sha256'] ?? null;
        if (!is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
            || !hash_equals($expected, hash('sha256', $bytes))) {
            throw new RuntimeException('installed APP baseline differs from receipt');
        }
        $baseline = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (file_exists(self::deploymentPath($serverRoot)) || is_link(self::deploymentPath($serverRoot))) {
            self::applicationDeployment($serverRoot, $identity->document(), $identity->identitySha256());
            return;
        }
        if (!is_array($baseline) || ($baseline['schema_version'] ?? null) !== 1
            || ($baseline['protocol'] ?? null) !== 'peanut.installation-baseline.v1'
            || ($baseline['deployment_mode'] ?? null) !== $edition
            || !in_array($baseline['source']['kind'] ?? null, ['generated-application', 'development-runtime'], true)
            || !is_string($baseline['source']['application_manifest_sha256'] ?? null)
            || !hash_equals($identity->manifestSha256(), $baseline['source']['application_manifest_sha256'])
            || (($baseline['source']['kind'] ?? null) === 'development-runtime'
                && (!is_string($baseline['source']['development_runtime_identity_sha256'] ?? null)
                    || !hash_equals($identity->identitySha256(), $baseline['source']['development_runtime_identity_sha256'])))) {
            throw new RuntimeException('installed APP baseline does not match the development projection');
        }
    }

    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $plan @param array<string,mixed> $journal */
    /** @param array<string,array{path:string,sha256:string,mode:int}> $targetMap */
    private static function extractPreparedServer(string $archive, string $workspace, array $targetMap): void
    {
        $prepared = $workspace . '/prepared/server';
        if (file_exists($prepared) || is_link($prepared)) {
            throw new RuntimeException('update workspace already contains prepared server files');
        }
        self::ensureDirectory($prepared, 0700);
        try {
            $phar = new PharData($archive);
        } catch (Throwable $exception) {
            throw new RuntimeException('target archive cannot be reopened for preparation', 0, $exception);
        }
        $prefix = 'phar://' . $archive . '/';
        $iterator = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            if (!$file instanceof PharFileInfo || !$file->isFile()) {
                continue;
            }
            $pathname = str_replace('\\', '/', $file->getPathname());
            if (!str_starts_with($pathname, $prefix)) {
                throw new RuntimeException('target archive path is invalid during preparation');
            }
            $parts = explode('/', substr($pathname, strlen($prefix)));
            if (count($parts) < 3 || $parts[1] !== 'server') {
                continue;
            }
            $relative = implode('/', array_slice($parts, 1));
            if (!isset($targetMap[$relative])) {
                continue;
            }
            $target = $workspace . '/prepared/' . $relative;
            $bytes = $file->getContent();
            if (hash('sha256', $bytes) !== $targetMap[$relative]['sha256']) {
                throw new RuntimeException('prepared file digest changed while extracting: ' . $relative);
            }
            self::durableFile($target, $bytes, $targetMap[$relative]['mode']);
        }
    }

    /** @return array<string,mixed> */
    private static function plan(string $workspace): array
    {
        $plan = self::jsonFile($workspace . '/plan.json', 'server update plan');
        if (($plan['schema_version'] ?? null) !== 1 || ($plan['protocol'] ?? null) !== self::PLAN_PROTOCOL
            || !is_array($plan['operations'] ?? null)
            || !is_array($plan['requirements'] ?? null)) {
            throw new RuntimeException('server update plan is invalid');
        }
        $toolDigest = hash_file('sha256', __FILE__);
        if (!is_string($toolDigest) || !hash_equals((string) ($plan['tool_sha256'] ?? ''), $toolDigest)) {
            throw new RuntimeException('server update tool differs from planned trusted copy');
        }
        self::updateId($plan);
        return $plan;
    }

    /** @return array<string,mixed> */
    private static function journal(string $workspace): array
    {
        $journal = self::jsonFile(self::journalPath($workspace), 'server update journal');
        if (($journal['schema_version'] ?? null) !== 1 || ($journal['protocol'] ?? null) !== self::JOURNAL_PROTOCOL
            || !is_array($journal['operations'] ?? null) || !is_string($journal['update_id'] ?? null)) {
            throw new RuntimeException('server update journal is invalid');
        }
        return $journal;
    }

    /** @param array<string,mixed> $plan */
    private static function updateId(array $plan): string
    {
        $updateId = $plan['update_id'] ?? null;
        if (!is_string($updateId) || preg_match('/^server_update_[0-9]{14}_[a-f0-9]{12}$/D', $updateId) !== 1) {
            throw new RuntimeException('server update id is invalid');
        }
        return $updateId;
    }

    /** @param array<string,mixed> $plan */
    private static function assertPreparedServer(string $workspace, array $plan): void
    {
        if (($plan['prepared_server'] ?? null) !== 'prepared/server') {
            throw new RuntimeException('server update prepared root is invalid');
        }
        self::assertOrdinaryParents($workspace, 'prepared/' . self::IDENTITY);
        $targetIdentity = self::regularBytes(
            self::preparedPath($workspace, self::IDENTITY),
            'prepared server release identity',
        );
        if (!hash_equals((string) ($plan['target']['identity_sha256'] ?? ''), hash('sha256', $targetIdentity))) {
            throw new RuntimeException('prepared server release identity differs from plan');
        }
        foreach ($plan['operations'] as $operation) {
            if (!is_array($operation) || !is_string($operation['path'] ?? null)) {
                throw new RuntimeException('server update operation is invalid');
            }
            if (($operation['operation'] ?? null) === 'delete') {
                continue;
            }
            $prepared = self::preparedPath($workspace, $operation['path']);
            self::assertOrdinaryParents($workspace, 'prepared/' . $operation['path']);
            if (!is_file($prepared) || is_link($prepared)) {
                throw new RuntimeException('prepared server file is unavailable: ' . $operation['path']);
            }
            $digest = hash_file('sha256', $prepared);
            if (!is_string($digest) || !hash_equals((string) $operation['target_sha256'], $digest)) {
                throw new RuntimeException('prepared server file digest differs from plan: ' . $operation['path']);
            }
        }
    }

    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $identity */
    private static function installedBaseline(string $serverRoot, array $identity): array
    {
        self::assertOrdinaryParents($serverRoot, 'private/installation/installed.json');
        $receipt = self::jsonFile($serverRoot . '/private/installation/installed.json', 'installed instance receipt');
        if (($receipt['schema_version'] ?? null) !== 1
            || ($receipt['protocol'] ?? null) !== 'peanut.installation-receipt.v1'
            || ($receipt['state'] ?? null) !== 'installed'
            || ($receipt['deployment_mode'] ?? null) !== ($identity['application']['edition'] ?? null)
            || !is_array($receipt['baseline_manifest'] ?? null)
            || ($receipt['baseline_manifest']['path'] ?? null) !== 'server/private/installation/baseline.json') {
            throw new RuntimeException('installed instance identity is invalid');
        }
        $baselineBytes = self::regularBytes(
            $serverRoot . '/private/installation/baseline.json',
            'installed instance baseline',
        );
        if (!hash_equals((string) ($receipt['baseline_manifest']['sha256'] ?? ''), hash('sha256', $baselineBytes))) {
            throw new RuntimeException('installed instance baseline differs from receipt');
        }
        $baseline = json_decode($baselineBytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($baseline) || ($baseline['schema_version'] ?? null) !== 1
            || ($baseline['protocol'] ?? null) !== 'peanut.installation-baseline.v1'
            || ($baseline['deployment_mode'] ?? null) !== $receipt['deployment_mode']) {
            throw new RuntimeException('installed instance baseline identity is invalid');
        }
        return $baseline;
    }

    /** Server-release callers retain their own source kind and identity field. */
    private static function assertInstalledInstance(string $serverRoot, array $identity): void
    {
        $baseline = self::installedBaseline($serverRoot, $identity);
        if (($baseline['source']['kind'] ?? null) !== 'server-release'
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($baseline['source']['server_release_identity_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('installed instance baseline identity is invalid');
        }
    }

    /** @param array<string,mixed> $identity @return array{installed_receipt_sha256:string,baseline_sha256:string,generation:int,state_sha256:?string} */
    private static function deploymentState(string $serverRoot, array $identity, string $currentDigest): array
    {
        self::assertInstalledInstance($serverRoot, $identity);
        $receiptPath = $serverRoot . '/private/installation/installed.json';
        $baselinePath = $serverRoot . '/private/installation/baseline.json';
        $receiptDigest = hash_file('sha256', $receiptPath);
        $baselineDigest = hash_file('sha256', $baselinePath);
        if (!is_string($receiptDigest) || !is_string($baselineDigest)) {
            throw new RuntimeException('installed identity cannot be hashed');
        }
        $path = self::deploymentPath($serverRoot);
        if (!file_exists($path) && !is_link($path)) {
            $baseline = self::jsonFile($baselinePath, 'installed instance baseline');
            if (($baseline['source']['server_release_identity_sha256'] ?? null) !== $currentDigest) {
                throw new RuntimeException('current release has no matching installed deployment chain');
            }
            return [
                'installed_receipt_sha256' => $receiptDigest,
                'baseline_sha256' => $baselineDigest,
                'generation' => 0,
                'state_sha256' => null,
            ];
        }
        $stateBytes = self::regularBytes($path, 'current deployment identity');
        $state = json_decode($stateBytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['schema_version'] ?? null) !== 1
            || ($state['protocol'] ?? null) !== 'peanut.server-deployment.v1'
            || ($state['installed_receipt_sha256'] ?? null) !== $receiptDigest
            || ($state['baseline_sha256'] ?? null) !== $baselineDigest
            || ($state['current_identity_sha256'] ?? null) !== $currentDigest
            || ($state['application']['slug'] ?? null) !== $identity['application']['slug']
            || ($state['application']['edition'] ?? null) !== $identity['application']['edition']
            || ($state['application']['package_identity'] ?? null) !== $identity['application']['package_identity']
            || !is_int($state['generation'] ?? null) || $state['generation'] < 1) {
            throw new RuntimeException('current deployment identity is invalid');
        }
        return [
            'installed_receipt_sha256' => $receiptDigest,
            'baseline_sha256' => $baselineDigest,
            'generation' => $state['generation'],
            'state_sha256' => hash('sha256', $stateBytes),
        ];
    }

    private static function deploymentPath(string $serverRoot): string
    {
        return $serverRoot . '/private/installation/deployment.json';
    }

    /** @param array<string,mixed> $plan */
    private static function publishDeploymentState(string $serverRoot, string $workspace, array $plan, string $updateId): void
    {
        $path = self::deploymentPath($serverRoot);
        $targetDigest = (string) $plan['target']['identity_sha256'];
        $sourceDigest = (string) $plan['source']['identity_sha256'];
        $generation = $plan['source']['deployment_generation'] ?? null;
        if (!is_int($generation) || $generation < 0
            || hash_file('sha256', $serverRoot . '/private/installation/installed.json') !== $plan['source']['installed_receipt_sha256']
            || hash_file('sha256', $serverRoot . '/private/installation/baseline.json') !== $plan['source']['baseline_sha256']) {
            throw new RuntimeException('installed identity changed before deployment publication');
        }
        if (file_exists($path) || is_link($path)) {
            $existingBytes = self::regularBytes($path, 'current deployment identity');
            $existing = json_decode($existingBytes, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($existing) && ($existing['update_id'] ?? null) === $updateId
                && ($existing['current_identity_sha256'] ?? null) === $targetDigest
                && ($existing['generation'] ?? null) === $generation + 1) {
                return;
            }
            if (hash('sha256', $existingBytes) !== ($plan['source']['deployment_state_sha256'] ?? null)
                || ($existing['current_identity_sha256'] ?? null) !== $sourceDigest) {
                throw new RuntimeException('deployment identity changed after planning');
            }
        } elseif (($plan['source']['deployment_state_sha256'] ?? null) !== null || $generation !== 0) {
            throw new RuntimeException('prior deployment identity is missing');
        }
        self::writeJson($path, [
            'schema_version' => 1,
            'protocol' => 'peanut.server-deployment.v1',
            'application' => [
                'slug' => $plan['target']['application']['slug'],
                'edition' => $plan['target']['application']['edition'],
                'package_identity' => $plan['target']['application']['package_identity'],
            ],
            'installed_receipt_sha256' => $plan['source']['installed_receipt_sha256'],
            'baseline_sha256' => $plan['source']['baseline_sha256'],
            'generation' => $generation + 1,
            'previous_identity_sha256' => $sourceDigest,
            'current_identity_sha256' => $targetDigest,
            'previous_deployment_state_sha256' => $plan['source']['deployment_state_sha256'],
            'update_id' => $updateId,
            'plan_sha256' => hash_file('sha256', $workspace . '/plan.json'),
        ]);
    }

    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $plan @param array<string,mixed> $journal */
    private static function assertJournalPlan(string $workspace, array $plan, array $journal): void
    {
        $digest = hash_file('sha256', $workspace . '/plan.json');
        if (!is_string($digest) || !hash_equals((string) ($journal['plan_sha256'] ?? ''), $digest)
            || ($journal['update_id'] ?? null) !== self::updateId($plan)
            || ($journal['source'] ?? null) !== ($plan['source'] ?? null)
            || ($journal['target'] ?? null) !== ($plan['target'] ?? null)) {
            throw new RuntimeException('server update journal differs from plan');
        }
        if (count($journal['operations']) !== count($plan['operations'])) {
            throw new RuntimeException('server update journal operations differ from plan');
        }
        foreach ($plan['operations'] as $index => $operation) {
            $record = $journal['operations'][$index] ?? null;
            if (!is_array($record)) {
                throw new RuntimeException('server update journal operations differ from plan');
            }
            foreach (['path', 'operation', 'current_sha256', 'current_mode', 'target_sha256', 'target_mode'] as $field) {
                if (($record[$field] ?? null) !== ($operation[$field] ?? null)) {
                    throw new RuntimeException('server update journal operations differ from plan');
                }
            }
        }
    }

    /** @param array<string,mixed> $plan */
    private static function assertPlanOperations(string $serverRoot, string $workspace, array $plan): void
    {
        $sourceBytes = self::regularBytes($serverRoot . '/.peanut/release-identity.json', 'current server release identity');
        $targetBytes = self::regularBytes(self::preparedPath($workspace, self::IDENTITY), 'prepared server release identity');
        $source = self::identity(json_decode($sourceBytes, true, 512, JSON_THROW_ON_ERROR), false);
        $target = self::identity(json_decode($targetBytes, true, 512, JSON_THROW_ON_ERROR), true);
        foreach (['slug', 'edition', 'package_identity'] as $field) {
            if (($source['application'][$field] ?? null) !== ($target['application'][$field] ?? null)) {
                throw new RuntimeException('server update plan changes application identity');
            }
        }
        $sourceMap = self::fileMap($source['files']);
        $sourceMap[self::IDENTITY] = ['sha256' => hash('sha256', $sourceBytes), 'mode' => 0644];
        $targetMap = self::fileMap($target['files']);
        $targetMap[self::IDENTITY] = [
            'sha256' => hash('sha256', $targetBytes),
            'mode' => fileperms(self::preparedPath($workspace, self::IDENTITY)) & 0777,
        ];
        $ownedDirectories = self::ownedDirectories(array_keys($sourceMap));
        $expectedPaths = array_unique([...array_keys($sourceMap), ...array_keys($targetMap)]);
        sort($expectedPaths, SORT_STRING);
        $operations = $plan['operations'];
        if (count($operations) !== count($expectedPaths)) {
            throw new RuntimeException('server update plan operations differ from release identities');
        }
        foreach ($expectedPaths as $index => $path) {
            $operation = $operations[$index] ?? null;
            self::assertProgramPath($path);
            $sourceFile = $sourceMap[$path] ?? null;
            $targetFile = $targetMap[$path] ?? null;
            $actual = self::actualPath($serverRoot, $path);
            self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
            if ($targetFile !== null) {
                self::assertTargetParents($serverRoot, $path, $ownedDirectories, $sourceFile !== null);
            }
            $present = file_exists($actual) || is_link($actual);
            if ($present && (!is_file($actual) || is_link($actual))) {
                throw new RuntimeException('program path is unsafe before update: ' . $path);
            }
            $currentDigest = $present ? hash_file('sha256', $actual) : null;
            $currentMode = $present ? (fileperms($actual) & 0777) : null;
            if (!is_array($operation) || ($operation['path'] ?? null) !== $path
                || ($operation['operation'] ?? null) !== ($targetFile === null ? 'delete' : ($sourceFile === null ? 'add' : 'replace'))
                || ($operation['current_present'] ?? null) !== $present
                || ($operation['current_sha256'] ?? null) !== $currentDigest
                || ($operation['current_mode'] ?? null) !== $currentMode
                || ($operation['target_sha256'] ?? null) !== ($targetFile['sha256'] ?? null)
                || ($operation['target_mode'] ?? null) !== ($targetFile['mode'] ?? null)) {
                throw new RuntimeException('server update plan operations differ from release identities');
            }
        }
        $requirements = [
            'composer_dependencies_changed' => self::operationTouches($operations, 'server/composer.json')
                || self::operationTouches($operations, 'server/composer.lock'),
            'database_migrations_changed' => self::operationPrefix($operations, 'server/database/'),
            'runtime_environment_changed' => self::operationTouches($operations, 'server/docker/Dockerfile')
                || self::operationTouches($operations, 'server/docker/compose.yaml')
                || self::operationPrefix(
                    array_values(array_filter(
                        $operations,
                        static fn(array $operation): bool => $operation['path'] !== 'server/docker/conf/nginx.conf',
                    )),
                    'server/docker/conf/',
                ),
            'nginx_configuration_changed' => self::operationTouches($operations, 'server/docker/conf/nginx.conf'),
        ];
        if ($plan['requirements'] !== $requirements) {
            throw new RuntimeException('server update plan requirements differ from operations');
        }
    }

    private static function assertOrdinaryParents(string $root, string $relative): void
    {
        $parts = explode('/', $relative);
        array_pop($parts);
        $cursor = $root;
        foreach ($parts as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor) || (file_exists($cursor) && !is_dir($cursor))) {
                throw new RuntimeException('server update parent is unsafe: ' . $relative);
            }
        }
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function initialJournal(string $workspace, array $plan): array
    {
        $operations = [];
        foreach ($plan['operations'] as $operation) {
            if (!is_array($operation) || !is_string($operation['path'] ?? null)
                || !in_array($operation['operation'] ?? null, ['add', 'replace', 'delete'], true)) {
                throw new RuntimeException('server update operation is invalid');
            }
            $operations[] = [
                'path' => $operation['path'],
                'operation' => $operation['operation'],
                'current_sha256' => $operation['current_sha256'] ?? null,
                'current_mode' => $operation['current_mode'] ?? null,
                'target_sha256' => $operation['target_sha256'] ?? null,
                'target_mode' => $operation['target_mode'] ?? null,
                'backup_path' => null,
                'status' => 'pending',
            ];
        }
        $planDigest = hash_file('sha256', $workspace . '/plan.json');
        if (!is_string($planDigest)) {
            throw new RuntimeException('cannot hash server update plan for journal');
        }

        return [
            'schema_version' => 1,
            'protocol' => self::JOURNAL_PROTOCOL,
            'update_id' => self::updateId($plan),
            'maintenance_key' => 'maintenance_' . bin2hex(random_bytes(16)),
            'reason_key' => 'planned-upgrade',
            'plan_sha256' => $planDigest,
            'source' => $plan['source'],
            'target' => $plan['target'],
            'operations' => $operations,
            'created_at' => self::now(),
        ];
    }

    /** @param array<string,mixed> $journal @return array<string,mixed> */
    private static function recordIntent(string $serverRoot, string $workspace, array $journal, int $index): array
    {
        $operation = $journal['operations'][$index];
        if (($operation['status'] ?? null) === 'intent_recorded') {
            try {
                self::assertOperationState($serverRoot, $operation, true);
                $operation['status'] = 'done';
                $operation['completed_at'] = self::now();
                return $operation;
            } catch (RuntimeException) {
                // The operation may have been interrupted before publication.
            }
            self::assertOperationState($serverRoot, $operation);
            return $operation;
        }
        if (($operation['status'] ?? null) === 'done') {
            self::assertOperationState($serverRoot, $operation, true);
            return $operation;
        }
        if (($operation['status'] ?? null) !== 'pending') {
            throw new RuntimeException('server update operation status is invalid');
        }
        $path = (string) $operation['path'];
        self::assertProgramPath($path);
        self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
        $actual = self::actualPath($serverRoot, $path);
        $backupPath = null;
        if (file_exists($actual) || is_link($actual)) {
            if (!is_file($actual) || is_link($actual)) {
                throw new RuntimeException('program path is not a regular file before update: ' . $path);
            }
            $digest = hash_file('sha256', $actual);
            if (!is_string($digest)) {
                throw new RuntimeException('cannot hash program path before update: ' . $path);
            }
            $expectedCurrent = $operation['current_sha256'];
            $expectedTarget = $operation['target_sha256'];
            $mode = fileperms($actual) & 0777;
            if (is_string($expectedCurrent) && (!hash_equals($expectedCurrent, $digest)
                || $mode !== ($operation['current_mode'] ?? null))) {
                throw new RuntimeException('program path changed after planning: ' . $path);
            }
            if (is_string($expectedTarget) && hash_equals($expectedTarget, $digest)
                && $mode === ($operation['target_mode'] ?? null)) {
                $operation['status'] = 'done';
                $operation['completed_at'] = self::now();
                return $operation;
            }
            $backupPath = self::backupPath($workspace, $path);
            self::assertOrdinaryParents($workspace, 'backups/' . $path);
            self::ensureDirectory(dirname($backupPath), 0700);
            if (file_exists($backupPath) || is_link($backupPath)) {
                $backupBytes = self::regularBytes($backupPath, 'existing program backup');
                if (!hash_equals($digest, hash('sha256', $backupBytes))
                    || (fileperms($backupPath) & 0777) !== $mode) {
                    throw new RuntimeException('existing program backup differs from current file: ' . $path);
                }
            } elseif (!copy($actual, $backupPath) || !chmod($backupPath, fileperms($actual) & 0777)) {
                throw new RuntimeException('cannot backup program file: ' . $path);
            }
            self::syncFile($backupPath);
            self::syncDirectory(dirname($backupPath));
        } elseif (($operation['operation'] ?? null) !== 'add') {
            if (($operation['operation'] ?? null) === 'delete') {
                $operation['status'] = 'done';
                $operation['completed_at'] = self::now();
                return $operation;
            }
            throw new RuntimeException('program path disappeared after planning: ' . $path);
        }
        $operation['backup_path'] = $backupPath;
        $operation['status'] = 'intent_recorded';
        $operation['intent_recorded_at'] = self::now();
        return $operation;
    }

    /** @param array<string,mixed> $operation */
    private static function applyOperation(string $serverRoot, string $workspace, array $operation): void
    {
        $path = (string) $operation['path'];
        self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
        self::assertOperationState($serverRoot, $operation);
        $actual = self::actualPath($serverRoot, $path);
        if (($operation['operation'] ?? null) === 'delete') {
            if (file_exists($actual) || is_link($actual)) {
                if (!is_file($actual) || is_link($actual)) {
                    throw new RuntimeException('program delete target is unsafe: ' . $path);
                }
                if (!unlink($actual)) {
                    throw new RuntimeException('cannot delete program file: ' . $path);
                }
                self::syncDirectory(dirname($actual));
            }
            return;
        }
        $prepared = self::preparedPath($workspace, $path);
        $targetSha = (string) $operation['target_sha256'];
        $digest = hash_file('sha256', $prepared);
        if (!is_string($digest) || !hash_equals($targetSha, $digest)) {
            throw new RuntimeException('prepared file digest differs before update: ' . $path);
        }
        self::ensureDirectory(dirname($actual), 0755);
        $temporary = dirname($actual) . '/.server-update-' . bin2hex(random_bytes(8)) . '.tmp';
        if (!copy($prepared, $temporary) || !chmod($temporary, (int) $operation['target_mode'])) {
            @unlink($temporary);
            throw new RuntimeException('cannot stage program file: ' . $path);
        }
        self::syncFile($temporary);
        $temporaryDigest = hash_file('sha256', $temporary);
        if (!is_string($temporaryDigest) || !hash_equals($targetSha, $temporaryDigest) || !rename($temporary, $actual)) {
            @unlink($temporary);
            throw new RuntimeException('cannot publish program file atomically: ' . $path);
        }
        self::syncDirectory(dirname($actual));
    }

    /** @param array<string,mixed> $operation */
    private static function assertOperationState(string $serverRoot, array $operation, bool $done = false): void
    {
        $path = (string) $operation['path'];
        self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
        $actual = self::actualPath($serverRoot, $path);
        $digest = null;
        $mode = null;
        if (file_exists($actual) || is_link($actual)) {
            if (!is_file($actual) || is_link($actual)) {
                throw new RuntimeException('program path is unsafe during update: ' . $path);
            }
            $digest = hash_file('sha256', $actual);
            $mode = fileperms($actual) & 0777;
        }
        $expected = $done
            ? ($operation['operation'] === 'delete' ? null : $operation['target_sha256'])
            : $operation['current_sha256'];
        $expectedMode = $done
            ? ($operation['operation'] === 'delete' ? null : ($operation['target_mode'] ?? null))
            : ($operation['current_mode'] ?? null);
        if ($digest !== $expected || $mode !== $expectedMode) {
            throw new RuntimeException('program path changed during update: ' . $path);
        }
    }

    /** @param array<string,mixed> $operation */
    private static function recoverOperation(string $serverRoot, string $workspace, array $operation): void
    {
        $path = (string) $operation['path'];
        self::assertProgramPath($path);
        self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
        $actual = self::actualPath($serverRoot, $path);
        $currentSha = is_string($operation['current_sha256'] ?? null) ? $operation['current_sha256'] : null;
        $targetSha = is_string($operation['target_sha256'] ?? null) ? $operation['target_sha256'] : null;
        $actualSha = null;
        $actualMode = null;
        if (file_exists($actual) || is_link($actual)) {
            if (!is_file($actual) || is_link($actual)) {
                throw new RuntimeException('program path is unsafe during recovery: ' . $path);
            }
            $actualSha = hash_file('sha256', $actual);
            $actualMode = fileperms($actual) & 0777;
            if (!is_string($actualSha)) {
                throw new RuntimeException('cannot hash program path during recovery: ' . $path);
            }
        }

        if (($operation['operation'] ?? null) === 'add') {
            if ($actualSha === null) {
                return;
            }
            if ($targetSha !== null && hash_equals($targetSha, $actualSha)
                && $actualMode === ($operation['target_mode'] ?? null)) {
                if (!unlink($actual)) {
                    throw new RuntimeException('cannot remove added program file during recovery: ' . $path);
                }
                self::syncDirectory(dirname($actual));
                return;
            }
            throw new RuntimeException('added program file changed after failed update: ' . $path);
        }

        if ($currentSha !== null && $actualSha !== null && hash_equals($currentSha, $actualSha)
            && $actualMode === ($operation['current_mode'] ?? null)) {
            return;
        }
        if (($operation['operation'] ?? null) === 'delete' && $actualSha !== null) {
            throw new RuntimeException('deleted program file changed after failed update: ' . $path);
        }
        if ($targetSha !== null && $actualSha !== null
            && (!hash_equals($targetSha, $actualSha) || $actualMode !== ($operation['target_mode'] ?? null))) {
            throw new RuntimeException('program file changed after failed update: ' . $path);
        }
        $backup = $operation['backup_path'] ?? null;
        if (!is_string($backup) || $backup !== self::backupPath($workspace, $path)) {
            throw new RuntimeException('program backup path is invalid during recovery: ' . $path);
        }
        self::assertOrdinaryParents($workspace, 'backups/' . $path);
        if (!is_file($backup) || is_link($backup)) {
            throw new RuntimeException('program backup is unavailable during recovery: ' . $path);
        }
        if ((fileperms($backup) & 0777) !== ($operation['current_mode'] ?? null)) {
            throw new RuntimeException('program backup mode differs during recovery: ' . $path);
        }
        self::ensureDirectory(dirname($actual), 0755);
        $temporary = dirname($actual) . '/.server-recover-' . bin2hex(random_bytes(8)) . '.tmp';
        if (!copy($backup, $temporary) || !chmod($temporary, (int) $operation['current_mode'])) {
            @unlink($temporary);
            throw new RuntimeException('cannot stage recovered program file: ' . $path);
        }
        self::syncFile($temporary);
        $restoredSha = hash_file('sha256', $temporary);
        if (!is_string($restoredSha) || $currentSha === null || !hash_equals($currentSha, $restoredSha)
            || !rename($temporary, $actual)) {
            @unlink($temporary);
            throw new RuntimeException('cannot restore program file during recovery: ' . $path);
        }
        self::syncDirectory(dirname($actual));
    }

    /** @return array<string,mixed> */
    private static function withInstanceLock(string $serverRoot, callable $callback): mixed
    {
        $runtime = self::ensureRuntimeDirectory($serverRoot);
        if (isset(self::$instanceLocks[$runtime])) {
            $held = self::$instanceLocks[$runtime];
            $stat = @lstat($runtime . '/update.lock');
            $open = fstat($held['handle']);
            if ($held['pid'] !== getmypid() || ($held['transferred'] ?? false) || !is_array($stat) || is_link($runtime . '/update.lock')
                || $open['ino'] !== $stat['ino'] || $open['dev'] !== $stat['dev']) {
                throw new RuntimeException('server update lock changed');
            }
            return $callback();
        }
        if (is_link($runtime . '/update.lock')) { throw new RuntimeException('server update lock is linked'); }
        $lock = fopen($runtime . '/update.lock', 'c');
        if (!is_resource($lock)) {
            throw new RuntimeException('cannot open server update lock');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('another server update process holds the instance lock');
        }
        self::$instanceLocks[$runtime] = ['handle' => $lock, 'pid' => getmypid()];
        try {
            return $callback();
        } finally {
            unset(self::$instanceLocks[$runtime]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function ensureRuntimeDirectory(string $serverRoot): string
    {
        $runtimeRoot = $serverRoot . '/runtime';
        if (is_link($runtimeRoot) || (file_exists($runtimeRoot) && !is_dir($runtimeRoot))) {
            throw new RuntimeException('server runtime directory is unsafe');
        }
        self::ensureDirectory($runtimeRoot, 01775);
        $runtime = $serverRoot . '/' . self::RUNTIME_DIR;
        if (is_link($runtime) || (file_exists($runtime) && !is_dir($runtime))) {
            throw new RuntimeException('server update runtime directory is unsafe');
        }
        self::ensureDirectory($runtime, 0755);
        // Keep application runtime writable without allowing replacement of the
        // owner-controlled update guard directory under the sticky parent.
        if (!chmod($runtimeRoot, 01775) || !chmod($runtime, 0755)) {
            throw new RuntimeException('cannot expose nonsecret update guard directory to nginx');
        }
        $sentinel = $runtime . '/.mount-ready';
        if (!file_exists($sentinel) && !is_link($sentinel)) {
            self::durableFile($sentinel, "peanut.server-update-guard.v1\n", 0644);
        } elseif (self::regularBytes($sentinel, 'server update guard sentinel') !== "peanut.server-update-guard.v1\n") {
            throw new RuntimeException('server update guard sentinel is invalid');
        }
        return $runtime;
    }

    private static function trafficReadyPath(string $serverRoot): string
    {
        return $serverRoot . '/' . self::RUNTIME_DIR . '/.traffic-ready';
    }

    private static function trafficInitializedPath(string $serverRoot): string
    {
        return $serverRoot . '/' . self::RUNTIME_DIR . '/.traffic-initialized';
    }

    private static function assertTrafficInitialized(string $serverRoot): void
    {
        if (self::regularBytes(self::trafficInitializedPath($serverRoot), 'traffic initialization state')
            !== "peanut.server-traffic-initialized.v1\n") {
            throw new RuntimeException('traffic initialization state is invalid');
        }
    }

    private static function assertTrafficReady(string $serverRoot): void
    {
        if (self::regularBytes(self::trafficReadyPath($serverRoot), 'traffic permission')
            !== "peanut.server-traffic-ready.v1\n") {
            throw new RuntimeException('traffic permission is invalid');
        }
    }

    private static function revokeTraffic(string $serverRoot): void
    {
        $initialized = self::trafficInitializedPath($serverRoot);
        if (!file_exists($initialized) && !is_link($initialized)) {
            self::durableFile($initialized, "peanut.server-traffic-initialized.v1\n", 0600);
        } else {
            try {
                self::assertTrafficInitialized($serverRoot);
            } catch (Throwable $exception) {
                self::removeTrafficReady($serverRoot);
                throw $exception;
            }
        }
        self::removeTrafficReady($serverRoot);
    }

    private static function removeTrafficReady(string $serverRoot): void
    {
        $ready = self::trafficReadyPath($serverRoot);
        if (file_exists($ready) || is_link($ready)) {
            if ((!is_file($ready) && !is_link($ready)) || !unlink($ready)) {
                throw new RuntimeException('cannot revoke public traffic permission');
            }
            self::syncDirectory(dirname($ready));
        }
    }

    private static function grantTraffic(string $serverRoot): void
    {
        self::assertTrafficInitialized($serverRoot);
        $ready = self::trafficReadyPath($serverRoot);
        if (file_exists($ready) || is_link($ready)) {
            self::assertTrafficReady($serverRoot);
            return;
        }
        self::durableFile($ready, "peanut.server-traffic-ready.v1\n", 0644);
    }

    /** @param array<string,mixed> $plan */
    private static function writeMaintenance(string $serverRoot, array $plan, string $phase): void
    {
        self::revokeTraffic($serverRoot);
        $updateId = (string) $plan['update_id'];
        $maintenanceKey = is_string($plan['maintenance_key'] ?? null)
            ? $plan['maintenance_key']
            : 'maintenance_' . substr(hash('sha256', $updateId), 0, 32);
        $path = self::maintenancePath($serverRoot);
        $existing = null;
        if (file_exists($path) || is_link($path)) {
            $existing = self::jsonFile($path, 'server update maintenance marker');
            if (($existing['protocol'] ?? null) !== self::MAINTENANCE_PROTOCOL
                || ($existing['update_id'] ?? null) !== $updateId) {
                throw new RuntimeException('another maintenance marker is active');
            }
        }
        self::writeJson($path, [
            'schema_version' => 1,
            'protocol' => self::MAINTENANCE_PROTOCOL,
            'status' => 'active',
            'phase' => $phase,
            'update_id' => $updateId,
            'maintenance_key' => $existing['maintenance_key'] ?? $maintenanceKey,
            'reason_key' => 'planned-upgrade',
            'updated_at' => self::now(),
        ]);
    }

    private static function clearMaintenance(string $serverRoot, string $updateId, string $finalStatus): void
    {
        $path = self::maintenancePath($serverRoot);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $marker = self::jsonFile($path, 'server update maintenance marker');
        if (($marker['protocol'] ?? null) !== self::MAINTENANCE_PROTOCOL
            || ($marker['update_id'] ?? null) !== $updateId
            || ($marker['status'] ?? null) !== 'active') {
            throw new RuntimeException('maintenance marker does not belong to this server update');
        }
        if (!in_array($finalStatus, ['completed', 'recovered'], true)) {
            throw new RuntimeException('maintenance marker clear status is invalid');
        }
        if (!unlink($path)) {
            throw new RuntimeException('cannot clear server update maintenance marker');
        }
        self::syncDirectory(dirname($path));
    }

    /** @return array<string,mixed> */
    private static function jsonFile(string $path, string $label): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException($label . ' is unavailable');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException($label . ' cannot be read');
        }
        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException($label . ' must be a JSON object');
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        if (is_link($path)) {
            throw new RuntimeException('refusing to overwrite a symbolic link: ' . $path);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        self::durableFile($path, $json, 0600);
    }

    private static function ensureDirectory(string $directory, int $mode): void
    {
        if (is_dir($directory) && !is_link($directory)) {
            return;
        }
        if (file_exists($directory) || is_link($directory)) {
            throw new RuntimeException('directory path is unsafe: ' . $directory);
        }
        self::ensureDirectory(dirname($directory), $mode);
        if (!mkdir($directory, $mode)) {
            throw new RuntimeException('cannot create directory: ' . $directory);
        }
        self::syncDirectory(dirname($directory));
    }

    private static function durableFile(string $path, string $bytes, int $mode): void
    {
        if (is_link($path)) {
            throw new RuntimeException('refusing to overwrite a symbolic link: ' . $path);
        }
        self::ensureDirectory(dirname($path), 0700);
        $temporary = dirname($path) . '/.' . basename($path) . '-' . bin2hex(random_bytes(8)) . '.tmp';
        $previousUmask = umask(0077);
        try {
            $handle = fopen($temporary, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            throw new RuntimeException('cannot stage durable file: ' . $path);
        }
        try {
            if (!chmod($temporary, $mode)) {
                throw new RuntimeException('cannot protect durable file before write: ' . $path);
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new RuntimeException('cannot write durable file: ' . $path);
                }
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('cannot sync durable file: ' . $path);
            }
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('cannot publish durable file: ' . $path);
        }
        self::syncDirectory(dirname($path));
    }

    private static function syncFile(string $path): void
    {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle) || !fsync($handle)) {
            is_resource($handle) && fclose($handle);
            throw new RuntimeException('cannot sync file: ' . $path);
        }
        fclose($handle);
    }

    private static function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'rb');
        if (!is_resource($handle) || !@fsync($handle)) {
            is_resource($handle) && fclose($handle);
            throw new RuntimeException('cannot sync directory: ' . $directory);
        }
        fclose($handle);
    }

    private static function removeDurableFile(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            if (is_link($path) || !is_file($path) || !unlink($path)) {
                throw new RuntimeException('cannot remove durable file: ' . $path);
            }
            self::syncDirectory(dirname($path));
        }
    }

    private static function actualPath(string $serverRoot, string $path): string
    {
        self::assertProgramPath($path);
        return $serverRoot . '/' . substr($path, strlen('server/'));
    }

    private static function preparedPath(string $workspace, string $path): string
    {
        self::assertProgramPath($path);
        return $workspace . '/prepared/' . $path;
    }

    private static function backupPath(string $workspace, string $path): string
    {
        self::assertProgramPath($path);
        return $workspace . '/backups/' . $path;
    }

    private static function journalPath(string $workspace): string
    {
        return $workspace . '/journal.json';
    }

    private static function currentPointerPath(string $serverRoot): string
    {
        return $serverRoot . '/' . self::RUNTIME_DIR . '/current-update.json';
    }

    private static function maintenancePath(string $serverRoot): string
    {
        return $serverRoot . '/' . self::RUNTIME_DIR . '/maintenance.json';
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /** @return array{0:string,1:array<string,mixed>,2:array<string,array{sha256:string,mode:int}>} */
    private static function archive(string $archive): array
    {
        try {
            $phar = new PharData($archive);
        } catch (Throwable $exception) {
            throw new RuntimeException('target archive cannot be opened', 0, $exception);
        }
        $prefix = 'phar://' . $archive . '/';
        $root = null;
        $files = [];
        $identityBytes = null;
        $seen = [];
        $iterator = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (!$file instanceof PharFileInfo) {
                throw new RuntimeException('target archive contains an unknown entry');
            }
            $pathname = str_replace('\\', '/', $file->getPathname());
            if (!str_starts_with($pathname, $prefix)) {
                throw new RuntimeException('target archive path is invalid');
            }
            $name = rtrim(substr($pathname, strlen($prefix)), '/');
            if ($name === '') {
                continue;
            }
            $parts = explode('/', $name);
            if (preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $parts[0]) !== 1
                || in_array('', $parts, true)
                || in_array('.', $parts, true) || in_array('..', $parts, true)
                || preg_match('/[\x00-\x1f\\\\]/', $name) === 1) {
                throw new RuntimeException('target archive contains an unsafe path');
            }
            $root ??= $parts[0];
            if ($root !== $parts[0]) {
                throw new RuntimeException('target archive contains multiple roots');
            }
            if (count($parts) === 1) {
                if (!$file->isDir()) {
                    throw new RuntimeException('target archive root must be a directory');
                }
                continue;
            }
            if ($parts[1] !== 'server') {
                throw new RuntimeException('target archive contains a non-server entry');
            }
            $folded = strtolower($name);
            if (isset($seen[$folded])) {
                throw new RuntimeException('target archive contains a duplicate or case-colliding path');
            }
            $seen[$folded] = true;
            if ($file->isLink() || (!$file->isDir() && !$file->isFile())) {
                throw new RuntimeException('target archive contains a link or special file');
            }
            if (!$file->isFile()) {
                continue;
            }
            $relative = implode('/', array_slice($parts, 1));
            $bytes = $file->getContent();
            $mode = $file->getPerms() & 0777;
            $files[$relative] = ['sha256' => hash('sha256', $bytes), 'mode' => $mode];
            if ($relative === self::IDENTITY) {
                $identityBytes = $bytes;
            }
        }
        if (!is_string($identityBytes)) {
            throw new RuntimeException('target archive lacks server release identity');
        }
        $identity = self::identity(json_decode($identityBytes, true, 512, JSON_THROW_ON_ERROR), true);
        $declared = self::fileMap($identity['files']);
        $actual = $files;
        unset($actual[self::IDENTITY]);
        $actualComparable = [];
        foreach ($actual as $path => $row) {
            $actualComparable[$path] = ['path' => $path, 'sha256' => $row['sha256'], 'mode' => $row['mode']];
        }
        ksort($actualComparable, SORT_STRING);
        if ($declared !== $actualComparable) {
            throw new RuntimeException('target archive files differ from release identity');
        }
        return [$identityBytes, $identity, $files];
    }

    /** @param mixed $value @return array<string,mixed> */
    private static function identity(mixed $value, bool $target): array
    {
        if (!is_array($value) || ($value['schema_version'] ?? null) !== 1
            || ($value['protocol'] ?? null) !== 'peanut.server-release.v1'
            || !is_array($value['application'] ?? null) || !is_array($value['files'] ?? null)) {
            throw new RuntimeException(($target ? 'target' : 'current') . ' server release identity is invalid');
        }
        $application = $value['application'];
        if ($target && (($application['kind'] ?? null) === 'internal-candidate'
            || ($application['scope'] ?? null) === 'internal-candidate'
            || ($application['formal_release_eligible'] ?? null) === false)) {
            throw new RuntimeException('internal candidate cannot be a formal server upgrade target');
        }
        foreach (['slug', 'edition', 'version', 'package_identity'] as $field) {
            if (!is_string($application[$field] ?? null) || $application[$field] === '') {
                throw new RuntimeException(($target ? 'target' : 'current') . ' application identity is incomplete');
            }
        }
        if (!in_array($application['edition'], ['standalone', 'multi-tenant'], true)) {
            throw new RuntimeException(($target ? 'target' : 'current') . ' edition is invalid');
        }
        $files = self::fileMap($value['files']);
        $canonical = json_encode(array_values($files), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($files === [] || !is_string($value['files_sha256'] ?? null)
            || !hash_equals($value['files_sha256'], hash('sha256', $canonical))) {
            throw new RuntimeException(($target ? 'target' : 'current') . ' server file list digest is invalid');
        }
        return $value;
    }

    /** @param mixed $rows @return array<string,array{path:string,sha256:string,mode:int}> */
    private static function fileMap(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('server file list must be an array');
        }
        $result = [];
        $previous = '';
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== ['path', 'sha256', 'mode']
                || !is_string($row['path']) || !str_starts_with($row['path'], 'server/')
                || $row['path'] <= $previous || isset($result[$row['path']])
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($row['sha256'] ?? '')) !== 1
                || !in_array($row['mode'] ?? null, [0644, 0755], true)) {
                throw new RuntimeException('server file list contains an invalid row');
            }
            self::safePath($row['path']);
            self::assertProgramPath($row['path']);
            $previous = $row['path'];
            $result[$row['path']] = $row;
        }
        return $result;
    }

    private static function assertProgramPath(string $path): void
    {
        self::safePath($path);
        if (in_array($path, self::PROTECTED_FILES, true)) {
            throw new RuntimeException('release identity targets a protected instance file: ' . $path);
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                throw new RuntimeException('release identity targets protected instance data: ' . $path);
            }
        }
    }

    private static function safePath(string $path): void
    {
        if (!str_starts_with($path, 'server/') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f]/', $path) === 1
            || in_array('', explode('/', $path), true)
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('unsafe server path: ' . $path);
        }
    }

    /** @param list<string> $paths @return array<string,bool> */
    private static function ownedDirectories(array $paths): array
    {
        $owned = ['server' => true];
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            array_pop($parts);
            while (count($parts) > 1) {
                $owned[implode('/', $parts)] = true;
                array_pop($parts);
            }
        }
        return $owned;
    }

    /** @param array<string,bool> $ownedDirectories */
    private static function assertTargetParents(string $serverRoot, string $path, array $ownedDirectories, bool $existingProgram): void
    {
        $parts = explode('/', substr($path, strlen('server/')));
        array_pop($parts);
        $cursor = $serverRoot;
        $relative = 'server';
        foreach ($parts as $part) {
            $cursor .= '/' . $part;
            $relative .= '/' . $part;
            if (is_link($cursor)) {
                throw new RuntimeException('target parent contains a symbolic link: ' . $path);
            }
            if (file_exists($cursor) && !is_dir($cursor)) {
                throw new RuntimeException('target parent conflicts with a file: ' . $path);
            }
            if (is_dir($cursor) && !$existingProgram && !isset($ownedDirectories[$relative])) {
                throw new RuntimeException('target parent is an unknown existing directory: ' . $relative);
            }
        }
    }

    /** @param list<array<string,mixed>> $operations */
    private static function operationTouches(array $operations, string $path): bool
    {
        foreach ($operations as $operation) {
            if ($operation['path'] === $path
                && ($operation['operation'] !== 'replace'
                    || !is_string($operation['current_sha256'])
                    || !hash_equals($operation['current_sha256'], (string) $operation['target_sha256'])
                    || ($operation['current_mode'] ?? null) !== ($operation['target_mode'] ?? null))) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $operations */
    private static function operationPrefix(array $operations, string $prefix): bool
    {
        foreach ($operations as $operation) {
            if (str_starts_with($operation['path'], $prefix)
                && ($operation['operation'] !== 'replace'
                    || !is_string($operation['current_sha256'])
                    || !hash_equals($operation['current_sha256'], (string) $operation['target_sha256'])
                    || ($operation['current_mode'] ?? null) !== ($operation['target_mode'] ?? null))) {
                return true;
            }
        }
        return false;
    }

    private static function ordinaryDirectory(string $path, string $label): string
    {
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            throw new RuntimeException($label . ' must be an absolute ordinary directory');
        }
        $real = realpath($path);
        if (!is_string($real) || $real !== $path || !is_dir($real)) {
            throw new RuntimeException($label . ' must be a canonical ordinary directory');
        }
        return $real;
    }

    private static function regularBytes(string $path, string $label): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException($label . ' is unavailable');
        }
        $stat = lstat($path);
        if (!is_array($stat) || ($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException($label . ' must have one hard link');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException($label . ' cannot be read');
        }
        return $bytes;
    }

    private static function usage(): never
    {
        fwrite(STDERR, "Usage: php update-plan.php plan --instance-server=/absolute/server --archive=/absolute/server.tar.gz --expected-sha256=<trusted-64-hex> --workspace=/absolute/update-workspace\n");
        fwrite(STDERR, "       php update-plan.php files-apply|files-verify|files-recover|files-verify-recovery|hold|health|publish|open --instance-server=/absolute/server --workspace=/absolute/update-workspace\n");
        fwrite(STDERR, "       php update-plan.php configure-startup --instance-server=/absolute/server --configuration-input=/absolute/private/input.json\n");
        exit(64);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(PeanutServerUpdatePlan::main($_SERVER['argv'] ?? []));
}
