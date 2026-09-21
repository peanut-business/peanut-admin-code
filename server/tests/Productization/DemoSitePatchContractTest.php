<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $path) use ($expect): string {
    $source = file_get_contents($path);
    $expect(is_string($source), 'source is unavailable: ' . $path);
    return $source;
};
$run = static function (array $arguments, string $workingDirectory): array {
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $output = [];
    $exitCode = 0;
    exec('cd ' . escapeshellarg($workingDirectory) . ' && ' . $command . ' 2>&1', $output, $exitCode);
    return ['exit_code' => $exitCode, 'output' => implode("\n", $output)];
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            $removeTree($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
};

$bootstrap = $read($root . '/server/app/platform/service/ApplicationTenantBootstrapService.php');
foreach ([
    'pa_role_permission',
    'pa_decorate_page',
    'pa_decorate_tabbar',
    'pa_transaction_setting',
    'pa_external_channel_binding',
] as $table) {
    $expect(str_contains($bootstrap, $table), 'new Tenant bootstrap omits ' . $table);
}
$notificationCommands = $read($root . '/server/app/modules/official/notification/src/Contract/NotificationBootstrapCommands.php');
$notificationApplication = $read($root . '/server/app/modules/official/notification/src/Service/NotificationBootstrapService.php');
$expect(
    str_contains($bootstrap, 'NotificationBootstrapCommands')
        && str_contains($bootstrap, '->provisionTenantDefaults(')
        && !str_contains($bootstrap, 'INSERT INTO pa_notice_scene')
        && str_contains($notificationCommands, 'provisionTenantDefaults')
        && str_contains($notificationApplication, 'NoticeScene::provisionDefaults('),
    'new Tenant notification defaults bypass the Notification owner command'
);
$provisioner = $read($root . '/server/app/platform/service/CoreTenantOwnerAdminProvisioner.php');
$expect(
    str_contains($provisioner, 'ApplicationTenantBootstrapService')
        && str_contains($provisioner, 'IdentityRepository')
        && str_contains($provisioner, 'MembershipRepository')
        && str_contains($provisioner, '->provision(')
        && !str_contains($provisioner, 'SELECT '),
    'Tenant owner provisioning must reuse Core identity/membership capabilities before application bootstrap'
);

$seed = $read($root . '/server/database/seed-multi-tenant-demo.php');
foreach ([
    "PEANUT_DEMO_MODE') !== 'enabled",
    'peanut-admin-production-candidate-mysql84',
    'PEANUT_DEMO_TENANT_A_EMAIL',
    'PEANUT_DEMO_TENANT_B_EMAIL',
    'PEANUT_DEMO_SHARED_PASSWORD',
    'pa_tenant_entry_binding',
    'demoMultiAssertSeedState',
    'demoMultiEnsureSharedOwner',
    'demoMultiAssertFinalState',
    '$transactions->run(',
    '$passwords->verify(',
] as $token) {
    $expect(str_contains($seed, $token), 'demo seed lost required guard: ' . $token);
}
$expect(
    str_contains($seed, "demoMultiBinding(\$pdo, (int)\$defaultTenant['id'], \$sharedAdminHost, ['member-api']);"),
    'shared Admin demo Host must leave admin-web unbound so account-driven Tenant selection is exercised'
);
$expect(
    str_contains($seed, '(new App($serverDir))->initialize()')
        && str_contains($seed, 'new NotificationBootstrapService()')
        && str_contains($seed, 'new TaskBootstrapService()')
        && str_contains($seed, 'new ThinkPhpTenantApplicationBootstrapPersistence()')
        && !str_contains($seed, 'PdoNotificationBootstrapService')
        && !str_contains($seed, 'PdoTaskBootstrapService'),
    'demo seed must boot ThinkPHP before consuming native Model bootstrap services'
);
$expect(
    str_contains($seed, "'tenant-a'")
        && str_contains($seed, "'tenant-b'")
        && str_contains($seed, "['default', 'tenant-a', 'tenant-b']"),
    'demo seed does not preserve the default Tenant plus independent A/B Tenant codes'
);

$admin = $read($root . '/server/app/adminapi/application/auth/AdminApplicationService.php');
$tenantAdminRuntime = $read($root . '/server/app/common/service/org/TenantAdminRuntime.php');
$expect(
    str_contains($admin, '$this->tenantAdmins->assertPasswordChangeAllowed')
        && str_contains($tenantAdminRuntime, '$this->demoAccounts->assertPasswordChangeAllowed'),
    'demo password mutation is not rejected by the Server'
);
$workbench = $read($root . '/server/app/adminapi/application/WorkbenchApplicationService.php');
$expect(
    str_contains($workbench, 'AdminAuthorizationService')
        && str_contains($workbench, "self::menuContainsPath(\$moduleMenus, '/system/file')"),
    'workbench file shortcut is not derived from the effective Tenant Module menu'
);
$overlayBuilder = $read($root . '/scripts/build-demo-site-patch');
$overlayFiles = [
    'deploy/docker-compose.prod.yml',
    'deploy/docker/production.Dockerfile',
    'plugins.lock',
    'plugins/official.notification/plugin.json',
    'plugins/official.task/plugin.json',
    'server/app/modules/official/notification/src/Service/NotificationBootstrapDefaults.php',
    'server/app/modules/official/notification/src/Service/NotificationBootstrapService.php',
    'server/app/modules/official/task/src/Service/TaskBootstrapService.php',
    'server/app/platform/infrastructure/ThinkPhpTenantApplicationBootstrapPersistence.php',
    'server/app/platform/service/ops/ApplicationRuntimeStatusProvider.php',
    'server/database/seed-multi-tenant-demo.php',
];
$expect(
    preg_match('/files=\(\n(?<files>.*?)\n\)/s', $overlayBuilder, $fileMatch) === 1
        && array_map('trim', preg_split('/\R/', trim((string)$fileMatch['files'])) ?: []) === $overlayFiles
        && str_contains($overlayBuilder, "git show \"\$BASE_TAG:release-versions.json\"")
        && str_contains($overlayBuilder, ".scaffold_template // empty"),
    'demo overlay must contain only the framework-free seed and deployment identity boundary'
);
$expect(
    str_contains($overlayBuilder, 'COPYFILE_DISABLE=1 tar --no-xattrs'),
    'demo overlay archive does not suppress macOS xattrs and AppleDouble files'
);
$temporaryRoot = sys_get_temp_dir() . '/peanut-demo-overlay-contract-' . bin2hex(random_bytes(6));
$repository = $temporaryRoot . '/repository';
$archive = $temporaryRoot . '/overlay.tar';
mkdir($repository . '/scripts', 0700, true);
file_put_contents($repository . '/scripts/build-demo-site-patch', $overlayBuilder);
chmod($repository . '/scripts/build-demo-site-patch', 0700);
file_put_contents(
    $repository . '/release-versions.json',
    json_encode([
        'schema_version' => 1,
        'protocol' => 'peanut.release-versions.v1',
        'product_release' => '3.0.5',
        'scaffold_template' => '3.0.4',
        'generated_application_default' => '0.1.0',
        'core_php' => '0.1.0-alpha.12',
        'core_web' => '0.1.0-alpha.12',
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
);
try {
    $paths = preg_split('/\R/', trim((string)$fileMatch['files'])) ?: [];
    foreach ($paths as $path) {
        $path = trim($path);
        $expect(preg_match('#^[A-Za-z0-9._/-]+$#D', $path) === 1, 'invalid overlay fixture path: ' . $path);
        $absolute = $repository . '/' . $path;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0700, true);
        }
        file_put_contents($absolute, $path . "\n");
    }
    foreach ([
        ['git', 'init', '-q'],
        ['git', 'add', '.'],
        ['git', '-c', 'user.name=Contract', '-c', 'user.email=contract@example.test', 'commit', '-qm', 'fixture'],
        ['git', '-c', 'user.name=Contract', '-c', 'user.email=contract@example.test', 'tag', '-am', 'fixture', 'v3.0.5'],
    ] as $command) {
        $result = $run($command, $repository);
        $expect($result['exit_code'] === 0, 'cannot prepare overlay fixture repository: ' . $result['output']);
    }
    $head = trim((string)$run(['git', 'rev-parse', 'HEAD'], $repository)['output']);
    $build = $run([$repository . '/scripts/build-demo-site-patch', 'v3.0.5', $archive], $repository);
    $expect($build['exit_code'] === 0, 'clean overlay fixture failed to build: ' . $build['output']);
    $metadataResult = $run(['tar', '-xOf', $archive, './DEMO_PATCH_METADATA.json'], $repository);
    $expect($metadataResult['exit_code'] === 0, 'generated overlay metadata is unavailable');
    $metadata = json_decode($metadataResult['output'], true, 32, JSON_THROW_ON_ERROR);
    $expect(
        is_array($metadata) && ($metadata['overlay_commit'] ?? null) === $head,
        'generated overlay metadata does not bind the exact clean HEAD commit'
    );
    $expect(
        ($metadata['base_tag'] ?? null) === 'v3.0.5'
            && ($metadata['migration_target_version'] ?? null) === '3.0.4',
        'generated overlay metadata does not keep the scaffold migration identity'
    );
    $seedMetadata = array_values(array_filter(
        is_array($metadata['files'] ?? null) ? $metadata['files'] : [],
        static fn(mixed $file): bool => is_array($file)
            && ($file['path'] ?? null) === 'server/database/seed-multi-tenant-demo.php'
    ));
    $expect(
        count($seedMetadata) === 1
            && preg_match('/^[0-9a-f]{64}$/D', (string)($seedMetadata[0]['sha256'] ?? '')) === 1,
        'generated overlay metadata does not bind the synthetic data seed'
    );

    file_put_contents($repository . '/untracked.txt', "dirty\n");
    $dirtyBuild = $run([$repository . '/scripts/build-demo-site-patch', 'v3.0.5', $archive . '.dirty'], $repository);
    $expect(
        $dirtyBuild['exit_code'] !== 0
            && str_contains($dirtyBuild['output'], 'source checkout has tracked or untracked changes'),
        'demo overlay builder does not reject an untracked dirty file'
    );
} finally {
    $removeTree($temporaryRoot);
}
$profile = $read($root . '/server/app/platform/service/module/ProductTenantModuleProfileService.php');
$expect(
    str_contains($profile, 'TenantModuleManager')
        && str_contains($profile, 'VerifiedTenantModuleRepository')
        && str_contains($profile, 'recordTenantSystem')
        && str_contains($profile, "'product_profile'")
        && !str_contains($profile, 'INSERT INTO pa_tenant_module'),
    'product profile bypasses the canonical TenantModule runtime or audit boundary'
);
$expect(
    str_contains($profile, "'standalone'")
        && str_contains($profile, "'demo'")
        && substr_count($profile, "'official.") >= 11,
    'standalone and demo product profiles are incomplete'
);

$invitation = $read($root . '/server/app/platform/invitation/TenantOwnerInvitationPublicService.php');
$expect(
    str_contains($invitation, 'ApplicationTenantBootstrapService')
        && str_contains($invitation, "(string)\$invitation['tenant_code']"),
    'public owner acceptance does not initialize application-owned Tenant defaults'
);

$deploy = $read($root . '/scripts/deploy-release');
foreach ([
    '--confirm-destroy',
    '--overlay',
    'seed-multi-tenant-demo.php',
    'down --volumes',
    '--fresh requires --confirm-destroy $TARGET',
    'scaffold downgrade ${current_scaffold_version} -> ${scaffold_migration_version} is forbidden',
    'scaffold major change ${current_scaffold_version} -> ${scaffold_migration_version} requires --fresh',
    'mktemp "${target_file}.deploy.',
    'mv -f -- "$temporary" "$target_file"',
    'server/database/install.php',
    'server/database/install.php --migrate --target-version="$scaffold_migration_version"',
    'plugin:reconcile --release-locked',
    'plugin:release-composition',
    '--current-root=/run/peanut-current-release',
    'tenant-module:apply-profile standalone',
    'tenant-module:apply-profile demo',
    'printf \'DEMO_MODE_SET=%q\\n\' "$DEMO_MODE_SET"',
    '--target-version="$migration_target_version"',
    'demo overlay migration target version does not match its migration files',
    'demo overlay metadata identity is invalid',
    'demo_overlay_commit=',
    '--expected-commit',
    '--expected-tree',
    'release_ref="$tag_commit"',
    'git archive --format=tar "$release_ref"',
    'DEPLOYMENT_RECEIPT.json',
    'peanut.deployment-receipt.v1',
    'deployment root and running PHP container identities differ',
    'candidate PHP image deployment receipt differs from staging',
] as $token) {
    $expect(str_contains($deploy, $token), 'deployment flow lost contract token: ' . $token);
}
$expect(
    substr_count($deploy, 'git show "$release_ref:') >= 4
        && str_contains($deploy, '"$tag_tree" == "$EXPECTED_TREE"'),
    'deployment does not read and archive the caller-bound immutable commit/tree'
);
$expect(
    !str_contains($deploy, 'Demo overlay deployment must consume the formal Multi-tenant Edition installer'),
    'project-owned demo deployment still requires a consumer Edition registry that cannot name private production resources'
);
$upgradeWorker = $read($root . '/scripts/ops-upgrade-worker');
$expect(
    str_contains($upgradeWorker, '.result.target_commit == $commit')
        && str_contains($upgradeWorker, '.result.target_tree == $tree')
        && str_contains($upgradeWorker, '--expected-commit "$target_commit" --expected-tree "$target_tree"'),
    'upgrade worker does not fence deployment with the task-bound commit/tree'
);
$upgradeExecution = $read($root . '/server/app/platform/service/ops/ThinkPhpUpgradeTaskExecutionService.php');
$claimStart = strpos($upgradeExecution, 'public function claim()');
$advanceStart = strpos($upgradeExecution, 'public function advance(');
$succeedStart = strpos($upgradeExecution, 'public function succeed(');
$maintenanceStart = strpos($upgradeExecution, 'private function advanceMaintenance(');
$createExecutionStart = strpos($upgradeExecution, 'private function createExecution(');
$expect(
    $claimStart !== false && $advanceStart !== false && $succeedStart !== false
        && $maintenanceStart !== false && $createExecutionStart !== false,
    'upgrade execution response boundaries are unavailable'
);
$claimResponse = substr($upgradeExecution, (int)$claimStart, (int)$advanceStart - (int)$claimStart);
$reentryResponse = substr($upgradeExecution, (int)$advanceStart, (int)$succeedStart - (int)$advanceStart);
$maintenanceResponse = substr(
    $upgradeExecution,
    (int)$maintenanceStart,
    (int)$createExecutionStart - (int)$maintenanceStart,
);
$expect(
    str_contains($claimResponse, "'target_commit' => \$payload['target_commit']")
        && str_contains($claimResponse, "'target_tree' => \$payload['target_tree']")
        && str_contains($reentryResponse, "'target_commit' => (string)\$execution['target_commit']")
        && str_contains($reentryResponse, "'target_tree' => (string)\$execution['target_tree']")
        && str_contains($maintenanceResponse, "'target_commit' => (string)\$execution['target_commit']")
        && str_contains($maintenanceResponse, "'target_tree' => (string)\$execution['target_tree']"),
    'upgrade task responses do not retain the bound deployment commit/tree'
);
$expect(
    str_contains($deploy, 'requires distinct default Admin, Platform, Tenant A and Tenant B emails'),
    'fresh demo deployment does not reject identity collisions before database work'
);
$metadataValidationPosition = strpos($deploy, "jq -e --arg tag \"\$tag\" --arg commit \"\$expected_commit\"");
$buildPosition = strpos($deploy, '"${candidate_compose[@]}" build');
$candidatePreflightPosition = strpos($deploy, 'server/database/install.php --preflight', (int)$buildPosition);
$candidatePluginLockPosition = strpos($deploy, 'server/think plugin:lock --check', (int)$candidatePreflightPosition);
$candidatePluginCompositionPosition = strpos($deploy, 'server/think plugin:release-composition', (int)$candidatePluginLockPosition);
$rootCleanupPosition = strpos($deploy, 'sudo -n find "$root" -mindepth 1 -maxdepth 1', (int)$candidatePluginCompositionPosition);
$destroyPosition = strpos($deploy, '"${current_compose[@]}" down --volumes');
$expect(
    $metadataValidationPosition !== false
        && $buildPosition !== false
        && $candidatePreflightPosition !== false
        && $candidatePluginLockPosition !== false
        && $candidatePluginCompositionPosition !== false
        && $rootCleanupPosition !== false
        && $destroyPosition !== false
        && $metadataValidationPosition < $buildPosition
        && $buildPosition < $candidatePreflightPosition
        && $candidatePreflightPosition < $candidatePluginLockPosition
        && $candidatePluginLockPosition < $candidatePluginCompositionPosition
        && $candidatePluginCompositionPosition < $rootCleanupPosition,
    'fresh deployment does not validate the exact candidate image before destructive work'
);
$freshMigration = 'server/database/install.php --migrate --target-version="$migration_target_version"';
$updateMigration = 'server/database/install.php --migrate --target-version="$scaffold_migration_version"';
$freshMigrationPosition = strpos($deploy, $freshMigration);
$reconcilePosition = strpos($deploy, 'server/think plugin:reconcile --release-locked', (int)$freshMigrationPosition);
$expect(
    substr_count($deploy, $freshMigration) === 1
        && substr_count($deploy, $updateMigration) === 1
        && $freshMigrationPosition !== false
        && $reconcilePosition !== false
        && $freshMigrationPosition < $reconcilePosition,
    'deployment does not keep scaffold migration for update while applying the verified overlay target before reconcile'
);
$expect(
    str_contains($deploy, 'computed_migration_target="$scaffold_migration_version"')
        && str_contains($deploy, '.files[].path | select(startswith("server/database/migrations/"))')
        && str_contains($deploy, '[[ "$migration_target_version" == "$computed_migration_target" ]]'),
    'deployment does not recompute the overlay migration maximum from its declared migration files'
);
$expect(
    substr_count($deploy, 'run -T --rm --no-deps') === 14,
    'remote one-shot Compose commands must not consume the deployment heredoc'
);
$expect(
    substr_count($deploy, '</dev/null') === 14,
    'remote one-shot Compose commands must close inherited standard input'
);

$index = $read($root . '/server/app/api/application/IndexApplicationService.php');
$expect(
    str_contains($index, 'in_array($host, $sharedHosts, true)')
        && str_contains($index, "return ['enabled' => false, 'email' => '', 'password' => ''];"),
    'demo public config does not fail closed for unknown Hosts'
);

echo "DEMO-SITE-PATCH-CONTRACT-001 passed\n";
