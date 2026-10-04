<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Identity\Invitation\OneTimeInvitationToken;
use PeanutAdmin\Modules\Identity\Invitation\OwnerInvitationDelivery;
use PeanutAdmin\Modules\Identity\Invitation\OwnerInvitationRuntimePolicy;
use PeanutAdmin\Modules\Identity\Invitation\TenantOwnerInvitationException;
use PeanutAdmin\Modules\Identity\Invitation\UnavailableOwnerInvitationDeliveryPort;

if (!class_exists(OneTimeInvitationToken::class)) {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
}

function ownerInvitationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$token = OneTimeInvitationToken::issue();
ownerInvitationExpect(strlen($token->expose()) === 43, 'invitation token entropy surface changed');
ownerInvitationExpect(strlen($token->hash()) === 64, 'invitation token hash surface changed');
ownerInvitationExpect(
    hash_equals($token->hash(), OneTimeInvitationToken::fromPlaintext($token->expose())->hash()),
    'invitation token lookup hash is not deterministic',
);
ownerInvitationExpect(
    ($token->__debugInfo()['token_hash'] ?? null) === $token->hash()
        && !in_array($token->expose(), $token->__debugInfo(), true),
    'invitation token debug output exposes plaintext',
);
try {
    OneTimeInvitationToken::fromPlaintext('predictable-token');
    throw new RuntimeException('weak invitation token unexpectedly passed');
} catch (TenantOwnerInvitationException $exception) {
    ownerInvitationExpect($exception->errorCode === 'INVITATION_TOKEN_INVALID', 'weak token denial changed');
}

$delivery = new OwnerInvitationDelivery(
    1,
    1,
    'Example Tenant',
    'owner@example.test',
    'Example Owner',
    new DateTimeImmutable('+1 day'),
    $token,
);
$deliveryResult = (new UnavailableOwnerInvitationDeliveryPort())->deliver($delivery);
ownerInvitationExpect($deliveryResult->status === 'pending_delivery', 'missing provider fabricated delivery');
ownerInvitationExpect($deliveryResult->provider === null, 'missing provider fabricated provider identity');
ownerInvitationExpect(!(new UnavailableOwnerInvitationDeliveryPort())->isConfigured(), 'missing provider marked configured');
ownerInvitationExpect(
    $delivery->idempotencyKey() === 'tenant-owner-invitation:1:1',
    'delivery port lacks a generation-scoped idempotency key',
);
ownerInvitationExpect(!in_array($token->expose(), $delivery->__debugInfo(), true), 'delivery debug output exposes token');

$serverRoot = dirname(__DIR__, 2);
$schema = (string) file_get_contents($serverRoot . '/database/init.sql');
foreach (['pending', 'accepted', 'revoked', 'expired'] as $status) {
    ownerInvitationExpect(str_contains($schema, "'{$status}'"), "invitation status missing: {$status}");
}
ownerInvitationExpect(str_contains($schema, '`token_hash` CHAR(64)'), 'schema does not persist a token hash');
ownerInvitationExpect(!str_contains($schema, '`token` VARCHAR'), 'schema persists a plaintext token column');
ownerInvitationExpect(
    str_contains($schema, 'GENERATED ALWAYS AS')
        && str_contains($schema, 'uk_owner_invitation_pending_tenant'),
    'schema lacks the one-pending-invitation concurrency guard',
);
ownerInvitationExpect(
    str_contains($schema, 'pending_delivery') && str_contains($schema, 'delivery_error_code'),
    'schema lacks honest delivery state',
);

$adminService = (string) file_get_contents(
    $serverRoot . '/app/modules/official/identity/src/Invitation/TenantOwnerInvitationAdminService.php',
);
$publicService = (string) file_get_contents(
    $serverRoot . '/app/modules/official/identity/src/Invitation/TenantOwnerInvitationPublicService.php',
);
$runtimePolicy = (string) file_get_contents(
    $serverRoot . '/app/modules/official/identity/src/Invitation/OwnerInvitationRuntimePolicy.php',
);
ownerInvitationExpect(str_contains($adminService, "TenantStatus::Provisioning"), 'Tenant is not left provisioning');
ownerInvitationExpect(str_contains($adminService, "'token_hash' => \$token->hash()"), 'plaintext token may reach persistence');
ownerInvitationExpect(!str_contains($adminService, 'Log::'), 'invitation service logs token-bearing state');
ownerInvitationExpect(
    str_contains($adminService, 'lockInvitableTenant')
        && str_contains($adminService, 'TenantStatus::Active->value')
        && str_contains($adminService, "\$this->ownerMemberExists(\$tenantId, ['active'])"),
    'active Tenant owner invitation path is missing',
);
ownerInvitationExpect(
    str_contains($publicService, 'TenantStatus::Active->value')
        && str_contains($publicService, 'TENANT_ACTIVE_OWNER_REQUIRED')
        && str_contains($publicService, 'ACCOUNT_ALREADY_TENANT_OWNER'),
    'acceptance does not support additional owners safely',
);
ownerInvitationExpect(
    str_contains($runtimePolicy, "['local', 'development']")
        && str_contains($runtimePolicy, 'isConfigured')
        && str_contains($runtimePolicy, 'OWNER_INVITATION_DELIVERY_UNAVAILABLE'),
    'invitation runtime exposure policy is not fail-closed',
);
$developmentPolicy = OwnerInvitationRuntimePolicy::fromEnvironment('development');
$localPolicy = OwnerInvitationRuntimePolicy::fromEnvironment('local');
$productionPolicy = OwnerInvitationRuntimePolicy::fromEnvironment('production');
$manualProductionPolicy = OwnerInvitationRuntimePolicy::fromEnvironment('production', 'manual');
ownerInvitationExpect($developmentPolicy->allowsPlaintextTokenResponse(), 'development token exposure was disabled');
ownerInvitationExpect($localPolicy->allowsPlaintextTokenResponse(), 'local token exposure was disabled');
ownerInvitationExpect(!$productionPolicy->allowsPlaintextTokenResponse(), 'production token exposure was enabled');
ownerInvitationExpect($manualProductionPolicy->allowsPlaintextTokenResponse(), 'explicit manual handoff was disabled');
$manualProductionPolicy->assertIssuanceAllowed(new UnavailableOwnerInvitationDeliveryPort());
try {
    OwnerInvitationRuntimePolicy::fromEnvironment('production', 'unknown');
    throw new RuntimeException('unknown invitation delivery mode was accepted');
} catch (InvalidArgumentException) {
}
try {
    $productionPolicy->assertIssuanceAllowed(new UnavailableOwnerInvitationDeliveryPort());
    throw new RuntimeException('production invitation issuance bypassed missing delivery provider');
} catch (TenantOwnerInvitationException $exception) {
    ownerInvitationExpect(
        $exception->errorCode === 'OWNER_INVITATION_DELIVERY_UNAVAILABLE'
            && $exception->httpStatus === 503,
        'production invitation delivery failure is not fail-closed',
    );
}
ownerInvitationExpect(str_contains($publicService, '->lock(true)'), 'acceptance lacks a row lock');
ownerInvitationExpect(
    str_contains($publicService, "'token_hash' => hash('sha256', random_bytes(32))"),
    'accepted invitation does not invalidate its one-time token',
);
ownerInvitationExpect(
    str_contains($publicService, "\$this->ownerMemberExists(\$tenantId, ['pending', 'active'])"),
    'acceptance lacks a first-owner concurrency check',
);
ownerInvitationExpect(
    str_contains($publicService, 'EXISTING_ACCOUNT_PASSWORD_FORBIDDEN'),
    'acceptance can overwrite an existing account password',
);
ownerInvitationExpect(
    str_contains($publicService, "'status' => 'active'")
        && str_contains($publicService, "'core.tenant-owner'"),
    'acceptance does not activate and authorize the owner membership',
);
ownerInvitationExpect(
    str_contains($publicService, 'recordTenantSystem'),
    'acceptance does not append a Core audit event',
);
$memberAdmin = (string) file_get_contents(
    $serverRoot . '/app/modules/official/identity/src/Membership/Application/MemberAdminService.php',
);
ownerInvitationExpect(
    substr_count($memberAdmin, 'assertOwnerRemovalAllowed') >= 3
        && substr_count($memberAdmin, 'activeOwnerMemberIdsForUpdate') >= 3
        && str_contains($memberAdmin, 'LAST_ACTIVE_OWNER_REQUIRED'),
    'core final active Owner guard is missing',
);

echo "TENANT-OWNER-INVITATION-CONTRACT-001 passed\n";
