<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpTenantAuthRepository;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthentication;
use PeanutAdmin\Modules\Identity\Auth\TenantSelectionRequired;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../Support/CiBootstrap.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';

function tenantHostExpect(bool $condition, string $message): void
{
    $GLOBALS['tenantHostChecks'] = ($GLOBALS['tenantHostChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tenantHostRejects(Closure $operation): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('expected Tenant auth rejection');
}

$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $databaseCreated] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IdentityMysqlFixture::install($pdo);

    $passwords = new PasswordHasher();
    ThinkPhpTestConnection::fromPdo($pdo);
    $bootstrap = new BootstrapService(new AuditService(), $passwords, new PasswordPolicy());
    $platform = $bootstrap->bootstrapPlatformOwner(
        'multi-owner@example.test',
        'MultiTenantPassword2026',
        'Multi Owner',
        'mt04-platform',
    );
    $alpha = $bootstrap->provisionTenantOwnerCandidate(
        $platform->operatorId,
        'alpha-host',
        'Alpha Host',
        'multi-owner@example.test',
        null,
        'Multi Owner',
        'mt04-alpha',
    );
    $bootstrap->activateTenantOwner($platform->operatorId, $alpha->tenantId, $alpha->memberId, 'mt04-alpha-owner');
    $bootstrap->activateTenant($platform->operatorId, $alpha->tenantId, 'mt04-alpha-active');
    $beta = $bootstrap->provisionTenantOwnerCandidate(
        $platform->operatorId,
        'beta-host',
        'Beta Host',
        'multi-owner@example.test',
        null,
        'Multi Owner',
        'mt04-beta',
    );
    $bootstrap->activateTenantOwner($platform->operatorId, $beta->tenantId, $beta->memberId, 'mt04-beta-owner');
    $bootstrap->activateTenant($platform->operatorId, $beta->tenantId, 'mt04-beta-active');

    $auth = new TenantAuthService(
        new ThinkPhpTenantAuthRepository(),
        $passwords,
        new SystemClock(),
        new TokenIssuer(),
        str_repeat('t', 32),
    );
    $selection = $auth->login(
        'multi-owner@example.test',
        'MultiTenantPassword2026',
        null,
        '127.0.0.1',
        'MT04 fixture',
        'mt04-login',
    );
    tenantHostExpect($selection instanceof TenantSelectionRequired, 'multi-Tenant login skipped selection');
    tenantHostExpect(count($selection->tenants) === 2, 'selection omitted an available Tenant');
    $alphaAuth = $auth->selectTenant(
        $selection->challenge->expose(),
        $alpha->tenantId,
        '127.0.0.1',
        'MT04 fixture',
        'mt04-select-alpha',
    );
    tenantHostExpect($alphaAuth->context->tenantId === $alpha->tenantId, 'selection established the wrong Tenant');

    $switch = $auth->switchChallenge(
        $alphaAuth->tokens->access->expose(),
        '127.0.0.1',
        'MT04 fixture',
        'mt04-switch-challenge',
    );
    tenantHostExpect(count($switch->tenants) === 1 && $switch->tenants[0]->tenantId === $beta->tenantId, 'switch exposed the current or wrong Tenant');
    $betaAuth = $auth->selectTenant(
        $switch->challenge->expose(),
        $beta->tenantId,
        '127.0.0.1',
        'MT04 fixture',
        'mt04-select-beta',
    );
    tenantHostExpect($betaAuth instanceof TenantAuthentication && $betaAuth->context->tenantId === $beta->tenantId, 'switch did not establish Beta');
    tenantHostRejects(static fn() => $auth->context($alphaAuth->tokens->access->expose(), 'mt04-old-context'));
    tenantHostExpect($auth->context($betaAuth->tokens->access->expose(), 'mt04-beta-context')->tenantId === $beta->tenantId, 'new context was not active');

    $auth->logout($betaAuth->tokens->access->expose(), 'mt04-logout');
    tenantHostRejects(static fn() => $auth->context($betaAuth->tokens->access->expose(), 'mt04-after-logout'));

    $route = peanut_route_registry_source(dirname(__DIR__, 2));
    foreach (['login', 'select', 'switch', 'logout'] as $action) {
        tenantHostExpect(str_contains($route, "tenant/session/{$action}"), "Tenant session {$action} route is missing");
    }

    echo 'MT04-TENANT-SESSION-HOST-001 passed checks=' . $GLOBALS['tenantHostChecks'] . PHP_EOL;
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $databaseCreated);
}
