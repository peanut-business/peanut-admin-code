#!/usr/bin/env php
<?php

declare(strict_types=1);

use app\common\policy\DemoAccountPolicy;
use app\platform\contract\TenantOwnerAdminProvisioner;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;
use think\App;
use think\facade\Db;

require __DIR__ . '/install.php';

function demoMultiFail(string $message): never
{
    fwrite(STDERR, "Multi-Tenant demo seed failed: {$message}\n");
    exit(1);
}

function demoMultiRequired(string $name): string
{
    $value = trim((string) (getenv($name) ?: ''));
    if ($value === '') {
        throw new RuntimeException("{$name} is required");
    }
    return $value;
}

/** @param list<string> $clientKeys */
function demoMultiBinding(int $tenantId, string $host, array $clientKeys = ['admin-web', 'member-api']): void
{
    $host = TenantEntryBindingResolver::normalizeHost($host);
    foreach ($clientKeys as $clientKey) {
        $row = Db::name('tenant_entry_binding')
            ->where('host', $host)
            ->where('client_key', $clientKey)
            ->field('id,tenant_id,status')
            ->lock(true)
            ->find();
        if (is_array($row) && (int) $row['tenant_id'] !== $tenantId) {
            throw new RuntimeException("demo Tenant host is already owned by another Tenant: {$host}");
        }
        if (is_array($row)) {
            Db::name('tenant_entry_binding')->where('id', (int) $row['id'])->update([
                'status' => 'active',
                'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
            continue;
        }
        Db::name('tenant_entry_binding')->insert([
            'tenant_id' => $tenantId,
            'host' => $host,
            'client_key' => $clientKey,
            'status' => 'active',
        ]);
    }
}

/** @return list<string> */
function demoMultiHostList(string $name): array
{
    $hosts = [];
    foreach (explode(',', (string) (getenv($name) ?: '')) as $host) {
        $host = trim($host);
        if ($host !== '') {
            $hosts[] = TenantEntryBindingResolver::normalizeHost($host);
        }
    }
    return array_values(array_unique($hosts));
}

function demoMultiOwner(int $tenantId, string $email): array
{
    $owners = Db::name('tenant_member')->alias('member')
        ->join('account account', "account.id=member.account_id AND account.status='active'")
        ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
        ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1 AND role.status='active'")
        ->join('credential credential', "credential.account_id=member.account_id AND credential.kind='email_password' AND credential.identifier_type='email' AND credential.status='active'")
        ->where('member.tenant_id', $tenantId)
        ->where('member.status', 'active')
        ->where('credential.identifier_normalized', $email)
        ->field('member.account_id,member.id AS member_id,member.display_name,role.id AS role_id,credential.identifier_normalized AS email,credential.secret_hash')
        ->limit(2)
        ->select()
        ->toArray();
    if (count($owners) !== 1) {
        throw new RuntimeException("Tenant {$tenantId} does not have exactly one active owner for {$email}");
    }
    return $owners[0];
}

/** @return array{tenant_id:int,account_id:int,member_id:int,role_id:int,email:string} */
function demoMultiTenant(
    PlatformTenantAdminService $tenants,
    TenantOwnerAdminService $owners,
    TenantOwnerAdminProvisioner $adminProvisioner,
    PlatformContext $actor,
    string $code,
    string $name,
    string $email,
    string $password,
    PasswordHasher $passwords,
    DemoAccountPolicy $demoAccounts,
): array {
    $tenant = Db::name('tenant')->where('code', $code)->order('id')->field(
        'id,name,display_name,status,revision',
    )->find();
    if (!is_array($tenant)) {
        $bootstrapPassword = $demoAccounts->bootstrapPassword();
        $tenant = $tenants->createTenant(
            $actor,
            $code,
            $name,
            $name,
            'zh-CN',
            'Asia/Shanghai',
        );
        $tenantId = (int) $tenant['id'];
        $candidate = $owners->createCandidate(
            $actor,
            $tenantId,
            $email,
            "{$name} Owner",
            $bootstrapPassword,
        );
        $candidate = $owners->activateCandidate(
            $actor,
            $tenantId,
            (int) $candidate['member']['id'],
            (int) $candidate['member']['revision'],
            "demo-{$code}-owner-activate",
            'Provision the public demo tenant owner.',
        );
        $adminProvisioner->provision(
            $tenantId,
            (int) $candidate['member']['account_id'],
            (int) $candidate['member']['id'],
            (int) $candidate['member']['role_id'],
            $code,
            "{$name} Owner",
        );
        $tenants->transitionTenant(
            $actor,
            $tenantId,
            (int) $tenant['revision'],
            TenantStatus::Active,
            'Activate the provisioned public demo tenant.',
        );
    } else {
        $tenantId = (int) $tenant['id'];
        if ($tenant['status'] !== 'active'
            || !hash_equals($name, (string) $tenant['name'])
            || !hash_equals($name, (string) $tenant['display_name'])) {
            throw new RuntimeException("existing demo Tenant {$code} does not match the demo plan");
        }
    }

    $demoAccounts->replaceCredentialHashes([$email]);

    $owner = demoMultiOwner($tenantId, $email);
    if (!$passwords->verify($password, (string) $owner['secret_hash'])) {
        throw new RuntimeException("demo Tenant {$code} credential does not match the published password");
    }
    $adminProvisioner->provision(
        $tenantId,
        (int) $owner['account_id'],
        (int) $owner['member_id'],
        (int) $owner['role_id'],
        $code,
        "{$name} Owner",
    );
    return [
        'tenant_id' => $tenantId,
        'account_id' => (int) $owner['account_id'],
        'member_id' => (int) $owner['member_id'],
        'role_id' => (int) $owner['role_id'],
        'email' => (string) $owner['email'],
    ];
}

function demoMultiEnsureSharedOwner(
    int $tenantId,
    int $accountId,
    int $ownerRoleId,
): int {
    $member = Db::name('tenant_member')->where('tenant_id', $tenantId)
        ->where('account_id', $accountId)->lock(true)->field('id,status')->find();
    if ($member === null) {
        $now = Db::raw('UTC_TIMESTAMP(3)');
        $memberId = Db::name('tenant_member')->insertGetId([
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'display_name' => 'Tenant B Shared Admin',
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $member = ['id' => $memberId, 'status' => 'pending'];
    }
    $memberId = (int) $member['id'];
    if ($member['status'] === 'pending') {
        Db::name('tenant_member')->where('tenant_id', $tenantId)->where('id', $memberId)->update([
            'status' => 'active',
            'joined_at' => Db::raw('UTC_TIMESTAMP(3)'),
            'security_revision' => Db::raw('security_revision+1'),
            'authorization_revision' => Db::raw('authorization_revision+1'),
            'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
        ]);
    } elseif ($member['status'] !== 'active') {
        throw new RuntimeException('shared demo Account has an inactive Tenant B membership');
    }
    Db::name('tenant_member')->where('tenant_id', $tenantId)->where('id', $memberId)
        ->where('display_name', '<>', 'Tenant B Shared Admin')->update([
            'display_name' => 'Tenant B Shared Admin',
            'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
        ]);
    $hasRole = Db::name('member_role')->where('tenant_id', $tenantId)
        ->where('tenant_member_id', $memberId)->where('role_id', $ownerRoleId)->value('role_id');
    if ($hasRole === null) {
        Db::name('member_role')->insert([
            'tenant_id' => $tenantId,
            'tenant_member_id' => $memberId,
            'role_id' => $ownerRoleId,
            'assigned_at' => Db::raw('UTC_TIMESTAMP(3)'),
        ]);
        Db::name('tenant_member')->where('tenant_id', $tenantId)->where('id', $memberId)->update([
            'authorization_revision' => Db::raw('authorization_revision+1'),
            'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
        ]);
        Db::name('tenant')->where('id', $tenantId)->update([
            'authorization_revision' => Db::raw('authorization_revision+1'),
            'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
        ]);
    }
    return $memberId;
}

function demoMultiAssertIdentityClosure(): void
{
    $identityAccountIds = array_values(array_unique(array_map('intval', array_merge(
        Db::name('tenant_member')->column('account_id'),
        Db::name('platform_operator')->column('account_id'),
    ))));
    sort($identityAccountIds, SORT_NUMERIC);
    if ($identityAccountIds === []) {
        throw new RuntimeException('demo seed state has no installation identities');
    }
    $accountCount = (int) Db::name('account')->count();
    $activeAccountCount = (int) Db::name('account')->where('status', 'active')->count();
    $credentialCount = (int) Db::name('credential')->count();
    $activeCredentialCount = (int) Db::name('credential')->where('kind', 'email_password')
        ->where('identifier_type', 'email')->where('status', 'active')->count();
    if ($accountCount !== count($identityAccountIds)
        || $activeAccountCount !== $accountCount
        || $credentialCount !== $accountCount
        || $activeCredentialCount !== $accountCount) {
        throw new RuntimeException('demo seed state contains unexpected Accounts or Credentials');
    }
}

function demoMultiAssertSeedState(): void
{
    $rows = Db::name('tenant')->field('code,status')->order('code')->lock(true)->select()->toArray();
    $codes = array_column($rows, 'code');
    if ($codes !== ['default'] && $codes !== ['default', 'tenant-a', 'tenant-b']) {
        throw new RuntimeException('demo seed requires an exact fresh baseline or its completed retry state');
    }
    foreach ($rows as $row) {
        if ($row['code'] === 'default' && $row['status'] !== 'active') {
            throw new RuntimeException('fresh default Tenant is unavailable');
        }
    }
    if ($codes !== ['default']) {
        return;
    }

    $memberCount = (int) Db::name('tenant_member')->count();
    $platformCount = (int) Db::name('platform_operator')->count();
    $bindingCount = (int) Db::name('tenant_entry_binding')->count();
    $defaultOwnerCount = (int) Db::name('tenant')->alias('tenant')
        ->join('tenant_member member', "member.tenant_id=tenant.id AND member.status='active'")
        ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
        ->join('role role', 'role.tenant_id=membership.tenant_id AND role.id=membership.role_id')
        ->where('tenant.code', 'default')->where('role.key', 'core.tenant-owner')
        ->where('role.is_builtin', 1)->where('role.status', 'active')->count();
    if ($memberCount !== 1 || $platformCount !== 1 || $bindingCount !== 0 || $defaultOwnerCount !== 1) {
        throw new RuntimeException('demo seed fresh baseline contains existing identities or Host bindings');
    }

    demoMultiAssertIdentityClosure();
}

function demoMultiAssertFinalState(
    PasswordHasher $passwords,
    string $tenantAEmail,
    string $tenantBEmail,
    string $sharedPassword,
    string $tenantAHost,
    string $tenantBHost,
    array $sharedAdminHosts,
): void {
    $tenants = Db::name('tenant')->field('code,name,display_name,status')->order('code')->select()->toArray();
    $expectedTenants = [
        ['code' => 'default', 'status' => 'active'],
        ['code' => 'tenant-a', 'name' => 'Tenant A', 'display_name' => 'Tenant A', 'status' => 'active'],
        ['code' => 'tenant-b', 'name' => 'Tenant B', 'display_name' => 'Tenant B', 'status' => 'active'],
    ];
    if (count($tenants) !== count($expectedTenants)) {
        throw new RuntimeException('demo Tenant final state contains unexpected rows');
    }
    foreach ($expectedTenants as $index => $expected) {
        foreach ($expected as $field => $value) {
            if (($tenants[$index][$field] ?? null) !== $value) {
                throw new RuntimeException('demo Tenant final state does not match the plan');
            }
        }
    }

    $owners = Db::name('tenant')->alias('tenant')
        ->join('tenant_member member', "member.tenant_id=tenant.id AND member.status='active'")
        ->join('account account', "account.id=member.account_id AND account.status='active'")
        ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
        ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1 AND role.status='active'")
        ->join('credential credential', "credential.account_id=member.account_id AND credential.kind='email_password' AND credential.identifier_type='email' AND credential.status='active'")
        ->whereIn('tenant.code', ['tenant-a', 'tenant-b'])
        ->field('tenant.code,member.display_name,credential.identifier_normalized AS email,credential.secret_hash')
        ->order('tenant.code,credential.identifier_normalized')
        ->select()
        ->toArray();
    $expectedOwners = [
        "tenant-a\0{$tenantAEmail}\0Tenant A Owner",
        "tenant-b\0{$tenantAEmail}\0Tenant B Shared Admin",
        "tenant-b\0{$tenantBEmail}\0Tenant B Owner",
    ];
    sort($expectedOwners, SORT_STRING);
    $actualOwners = array_map(
        static fn(array $row): string => $row['code'] . "\0" . $row['email'] . "\0" . $row['display_name'],
        $owners,
    );
    if ($actualOwners !== $expectedOwners) {
        throw new RuntimeException('demo owner memberships do not provide the exact A/B selection model');
    }
    $memberCount = (int) Db::name('tenant_member')->count();
    $defaultOwnerCount = (int) Db::name('tenant')->alias('tenant')
        ->join('tenant_member member', "member.tenant_id=tenant.id AND member.status='active'")
        ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
        ->join('role role', 'role.tenant_id=membership.tenant_id AND role.id=membership.role_id')
        ->where('tenant.code', 'default')->where('role.key', 'core.tenant-owner')
        ->where('role.is_builtin', 1)->where('role.status', 'active')->count();
    $demoMemberCount = (int) Db::name('tenant_member')->alias('member')
        ->join('tenant tenant', 'tenant.id=member.tenant_id')
        ->whereIn('tenant.code', ['tenant-a', 'tenant-b'])->count();
    if ($memberCount !== 4 || $defaultOwnerCount !== 1 || $demoMemberCount !== 3) {
        throw new RuntimeException('demo Tenants contain unexpected membership rows');
    }
    $platformCount = (int) Db::name('platform_operator')->count();
    if ($platformCount !== 1) {
        throw new RuntimeException('demo seed final state contains unexpected PlatformOperators');
    }
    demoMultiAssertIdentityClosure();
    foreach ($owners as $owner) {
        if (!$passwords->verify($sharedPassword, (string) $owner['secret_hash'])) {
            throw new RuntimeException('published demo password does not match an owner credential');
        }
    }

    $bindings = [];
    $bindingRows = Db::name('tenant_entry_binding')->alias('binding')
        ->join('tenant tenant', 'tenant.id=binding.tenant_id')
        ->whereIn('binding.client_key', ['admin-web', 'member-api'])
        ->field('binding.host,binding.client_key,tenant.code,binding.status')
        ->order('binding.host,binding.client_key')->select()->toArray();
    foreach ($bindingRows as $row) {
        $bindings[$row['host'] . "\0" . $row['client_key']] = [$row['code'], $row['status']];
    }
    $expectedBindings = [];
    foreach ($sharedAdminHosts as $host) {
        $expectedBindings[$host . "\0member-api"] = ['default', 'active'];
    }
    foreach ([$tenantAHost => 'tenant-a', $tenantBHost => 'tenant-b'] as $host => $code) {
        foreach (['admin-web', 'member-api'] as $clientKey) {
            $expectedBindings[$host . "\0" . $clientKey] = [$code, 'active'];
        }
    }
    ksort($expectedBindings, SORT_STRING);
    $bindingCount = (int) Db::name('tenant_entry_binding')->count();
    if ($bindings !== $expectedBindings || $bindingCount !== count($expectedBindings)) {
        throw new RuntimeException('demo Tenant Host bindings do not match the final plan');
    }
}

function demoMultiMain(): int
{
    if (($_SERVER['argv'][1] ?? '') !== '--apply' || count($_SERVER['argv']) !== 2) {
        fwrite(STDERR, "Usage: server/database/seed-multi-tenant-demo.php --apply\n");
        return 64;
    }
    if (getenv('PEANUT_DEMO_MODE') !== 'enabled') {
        throw new RuntimeException('PEANUT_DEMO_MODE=enabled is required');
    }
    $target = demoMultiRequired('PEANUT_DEPLOYMENT_TARGET');
    $resource = demoMultiRequired('PEANUT_DATABASE_RESOURCE_ID');
    $allowed = [
        'production-candidate' => 'peanut-admin-production-candidate-mysql84',
        'local-multi-tenant-demo' => 'peanut-admin-mysql84-local-multi-tenant-demo',
    ];
    if (($allowed[$target] ?? null) !== $resource) {
        throw new RuntimeException('demo seed target and registered database resource do not match');
    }
    if (demoMultiRequired('DEPLOYMENT_MODE') !== 'multi-tenant') {
        throw new RuntimeException('multi-tenant deployment mode is required');
    }

    $tenantAEmail = strtolower(demoMultiRequired('PEANUT_DEMO_TENANT_A_EMAIL'));
    $tenantBEmail = strtolower(demoMultiRequired('PEANUT_DEMO_TENANT_B_EMAIL'));
    $sharedPassword = demoMultiRequired('PEANUT_DEMO_SHARED_PASSWORD');
    if ($sharedPassword !== 'peanut1234') {
        throw new RuntimeException('演示租户密码必须统一为 peanut1234');
    }
    if (filter_var($tenantAEmail, FILTER_VALIDATE_EMAIL) === false
        || filter_var($tenantBEmail, FILTER_VALIDATE_EMAIL) === false
        || $tenantAEmail === $tenantBEmail) {
        throw new RuntimeException('demo Tenant emails must be different valid addresses');
    }
    validateInitialAdminPassword($sharedPassword);
    $serverDir = dirname(__DIR__);
    loadCoreRuntime($serverDir);
    $tenantAHost = TenantEntryBindingResolver::normalizeHost(
        demoMultiRequired('PEANUT_DEMO_TENANT_A_HOST'),
    );
    $tenantBHost = TenantEntryBindingResolver::normalizeHost(
        demoMultiRequired('PEANUT_DEMO_TENANT_B_HOST'),
    );
    $sharedAdminHosts = demoMultiHostList('TENANT_ADMIN_HOSTS');
    if ($sharedAdminHosts === []) {
        throw new RuntimeException('demo shared Admin Hosts are required');
    }
    $reservedHosts = array_merge(
        demoMultiHostList('PLATFORM_HOSTS'),
        $sharedAdminHosts,
    );
    if (hash_equals($tenantAHost, $tenantBHost)
        || in_array($tenantAHost, $reservedHosts, true)
        || in_array($tenantBHost, $reservedHosts, true)) {
        throw new RuntimeException('demo Tenant hosts must be distinct from Platform and shared Admin hosts');
    }

    loadConfig($serverDir);
    require_once $serverDir . '/bootstrap/environment.php';
    $app = (new App($serverDir))->initialize();
    $tenants = $app->make(PlatformTenantAdminService::class);
    $owners = $app->make(TenantOwnerAdminService::class);
    $adminProvisioner = $app->make(TenantOwnerAdminProvisioner::class);
    $passwords = $app->make(PasswordHasher::class);
    $demoAccounts = new DemoAccountPolicy(true, [$tenantAEmail, $tenantBEmail]);
    [$tenantA, $tenantB] = Db::transaction(function () use (
        $tenants,
        $owners,
        $adminProvisioner,
        $passwords,
        $tenantAEmail,
        $tenantBEmail,
        $sharedPassword,
        $demoAccounts,
        $tenantAHost,
        $tenantBHost,
        $sharedAdminHosts
    ): array {
        demoMultiAssertSeedState();
        $platforms = Db::name('platform_operator')->where('status', 'active')
            ->field('id,account_id')->order('id')->limit(2)->lock(true)->select()->toArray();
        if (count($platforms) !== 1) {
            throw new RuntimeException('demo seed requires exactly one active PlatformOperator');
        }
        $actor = PlatformContext::fromTrustedAutomation(
            (int) $platforms[0]['account_id'],
            (int) $platforms[0]['id'],
            'demo-seed',
            'demo-multi-tenant-seed',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $tenantA = demoMultiTenant(
            $tenants,
            $owners,
            $adminProvisioner,
            $actor,
            'tenant-a',
            'Tenant A',
            $tenantAEmail,
            $sharedPassword,
            $passwords,
            $demoAccounts,
        );
        $tenantB = demoMultiTenant(
            $tenants,
            $owners,
            $adminProvisioner,
            $actor,
            'tenant-b',
            'Tenant B',
            $tenantBEmail,
            $sharedPassword,
            $passwords,
            $demoAccounts,
        );
        demoMultiEnsureSharedOwner(
            $tenantB['tenant_id'],
            $tenantA['account_id'],
            $tenantB['role_id'],
        );
        $defaultTenant = Db::name('tenant')->where('code', 'default')->where('status', 'active')
            ->field('id')->find();
        if (!is_array($defaultTenant)) {
            throw new RuntimeException('demo default Tenant is unavailable');
        }
        foreach ($sharedAdminHosts as $sharedAdminHost) {
            demoMultiBinding((int) $defaultTenant['id'], $sharedAdminHost, ['member-api']);
        }
        demoMultiBinding($tenantA['tenant_id'], $tenantAHost);
        demoMultiBinding($tenantB['tenant_id'], $tenantBHost);
        demoMultiAssertFinalState(
            $passwords,
            $tenantAEmail,
            $tenantBEmail,
            $sharedPassword,
            $tenantAHost,
            $tenantBHost,
            $sharedAdminHosts,
        );
        return [$tenantA, $tenantB];
    });

    echo json_encode([
        'status' => 'applied',
        'tenant_a_id' => $tenantA['tenant_id'],
        'tenant_a_email' => $tenantAEmail,
        'tenant_b_id' => $tenantB['tenant_id'],
        'tenant_b_email' => $tenantBEmail,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    return 0;
}

try {
    exit(demoMultiMain());
} catch (Throwable $exception) {
    demoMultiFail($exception->getMessage());
}
