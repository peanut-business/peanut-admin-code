<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use DomainException;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationRevisionRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;

require_once __DIR__ . '/DatabaseTestCase.php';

final class AuthorizationPersistenceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-16 04:30:00.000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
    }

    public function testCatalogSynchronizesPermissionsWithoutChangingOwners(): void
    {
        $catalog = new ThinkPhpAuthorizationCatalogRepository();
        $permissionId = $catalog->syncPermission(new PermissionDefinition(
            'example.work-item.read',
            'example',
            'api',
            'Read work items',
            'normal',
            '1.0.0',
        ));
        self::assertSame($permissionId, $catalog->permissionId('example.work-item.read'));
        self::assertSame(64, strlen($catalog->registryRevision()));
        self::assertFalse($this->query("SHOW TABLES LIKE 'pa_resource_operation'")->fetchColumn());

        $this->expectException(DomainException::class);
        $catalog->syncPermission(new PermissionDefinition(
            'example.work-item.read',
            'other-module',
            'api',
            'Hijacked permission',
            'normal',
            '1.0.0',
        ));
    }

    public function testRevisionRepositoryMakesOldAuthorizationKeysUnreachable(): void
    {
        $account = $this->insert('pa_account', [
            'display_name' => 'Member',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $tenant = $this->insert('pa_tenant', [
            'code' => 'revision-authz',
            'name' => 'Revision Authz',
            'display_name' => 'Revision Authz',
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $member = $this->insert('pa_tenant_member', [
            'tenant_id' => $tenant,
            'account_id' => $account,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $role = $this->insert('pa_role', [
            'tenant_id' => $tenant,
            'key' => 'manager',
            'name' => 'Manager',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->licenseTenantModules($tenant, ['example'], 'active');

        $revisions = new ThinkPhpAuthorizationRevisionRepository();
        self::assertSame(2, $revisions->bumpTenant($tenant));
        self::assertSame(2, $revisions->bumpMember($tenant, $member));
        self::assertSame(2, $revisions->bumpRole($tenant, $role));
    }
}
