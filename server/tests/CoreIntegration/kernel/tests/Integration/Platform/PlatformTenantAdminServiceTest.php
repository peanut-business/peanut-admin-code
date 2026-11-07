<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Platform;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class PlatformTenantAdminServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-17 05:00:00.000';

    private PlatformTenantAdminService $service;
    private int $operatorId;
    private int $operatorAccountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        $this->operatorAccountId = $this->account('Platform Operator');
        $this->operatorId = $this->insert('pa_platform_operator', [
            'account_id' => $this->operatorAccountId,
            'display_name' => 'Platform Operator',
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->service = new PlatformTenantAdminService(new AuditService());
    }

    public function testCreatesUpdatesAndTransitionsTenantWithOwnerAndRevisionGuards(): void
    {
        $tenant = $this->service->createTenant(
            $this->context('req_tenant_create'),
            'alpha-company',
            'Alpha Company',
            'Alpha',
            'zh-CN',
            'Asia/Shanghai',
        );

        self::assertSame('provisioning', $tenant['status']);
        self::assertSame('1', $tenant['revision']);
        self::assertSame(1, (int) $this->query(
            "SELECT COUNT(*) FROM pa_role WHERE tenant_id = {$tenant['id']}"
            . " AND `key` = 'core.tenant-owner' AND is_builtin = 1",
        )->fetchColumn());
        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_department')->fetchColumn());

        $tenant = $this->service->updateTenant(
            $this->context('req_tenant_update'),
            (int) $tenant['id'],
            1,
            'Alpha Company Limited',
            'Alpha Limited',
            'en-US',
            'UTC',
            'Correct legal name',
        );
        self::assertSame('2', $tenant['revision']);
        self::assertSame('Alpha Limited', $tenant['display_name']);

        $this->expectAdminError('REVISION_MISMATCH', fn(): array => $this->service->updateTenant(
            $this->context('req_tenant_stale'),
            (int) $tenant['id'],
            1,
            'Stale',
            'Stale',
            'zh-CN',
            'Asia/Shanghai',
            'Stale write',
        ));
        $this->expectAdminError('TENANT_OWNER_REQUIRED', fn(): array => $this->service->transitionTenant(
            $this->context('req_tenant_activate_without_owner'),
            (int) $tenant['id'],
            2,
            TenantStatus::Active,
            'Open tenant',
        ));

        $this->activateOwner((int) $tenant['id']);
        foreach ([
            [TenantStatus::Active, 2, 'req_tenant_activate'],
            [TenantStatus::Suspended, 3, 'req_tenant_suspend'],
            [TenantStatus::Active, 4, 'req_tenant_reactivate'],
            [TenantStatus::Closed, 5, 'req_tenant_close'],
        ] as [$status, $revision, $requestId]) {
            $tenant = $this->service->transitionTenant(
                $this->context($requestId),
                (int) $tenant['id'],
                $revision,
                $status,
                'Lifecycle test',
            );
        }

        self::assertSame('closed', $tenant['status']);
        self::assertSame('6', $tenant['revision']);
        self::assertSame(6, (int) $this->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        self::assertSame(4, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        self::assertSame(4, (int) $this->query(
            "SELECT COUNT(*) FROM pa_tenant_audit_event WHERE actor_type = 'platform_operator'",
        )->fetchColumn());
    }

    private function activateOwner(int $tenantId): void
    {
        $accountId = $this->account('Tenant Owner');
        $memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'display_name' => 'Tenant Owner',
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $roleId = (int) $this->query(
            "SELECT id FROM pa_role WHERE tenant_id = {$tenantId} AND `key` = 'core.tenant-owner'",
        )->fetchColumn();
        $this->insert('pa_member_role', [
            'tenant_id' => $tenantId,
            'tenant_member_id' => $memberId,
            'role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);
    }

    private function account(string $displayName): int
    {
        return $this->insert('pa_account', [
            'display_name' => $displayName,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function context(string $requestId): PlatformContext
    {
        return PlatformContext::fromTrustedAutomation(
            $this->operatorAccountId,
            $this->operatorId,
            'platform-web',
            $requestId,
            new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );
    }

    /** @param callable(): mixed $operation */
    private function expectAdminError(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (AdminAccessException $exception) {
            self::assertSame($code, $exception->errorCode);

            return;
        }

        self::fail("Expected {$code}.");
    }
}
