<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Schema;

use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__, 4) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once __DIR__ . '/DataPermissionMigrationRunner.php';

final class DataPermissionSchemaTest extends DatabaseTestCase
{
    private const NOW = '2026-07-16 04:00:00.000';

    private DataPermissionMigrationRunner $dataRunner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        $this->dataRunner = new DataPermissionMigrationRunner($this->database);
        $this->dataRunner->migrate();
    }

    public function testDataPermissionMigrationsInstallRepeatAndRollback(): void
    {
        $this->dataRunner->migrate();
        self::assertSame(1, (int) $this->query(
            'SELECT COUNT(*) FROM pa_migrations WHERE version = 20261101010001',
        )->fetchColumn());

        try {
            $this->dataRunner->rollbackAll();
            self::fail('Identity initial schema rollback must retain data permission storage.');
        } catch (\Phinx\Migration\IrreversibleMigrationException $exception) {
            self::assertSame('Module data is retained. This initial schema migration cannot be rolled back.', $exception->getMessage());
        }
        self::assertTrue($this->tableExists('pa_data_permission'));
        self::assertFalse($this->tableExists('pa_data_permission_target'));
        self::assertTrue($this->tableExists('pa_tenant'));
    }

    public function testUniqueKeysAndTenantForeignKeysRejectAmbiguousPolicies(): void
    {
        [$alpha, $alphaMember, $alphaRole] = $this->tenantFixture('alpha');
        [$beta, $betaMember, $betaRole] = $this->tenantFixture('beta');
        $resource = 'example.work-item';

        $this->assertDatabaseRejects(fn() => $this->policy($alpha, $betaRole, $resource, $alphaMember));
        $this->assertDatabaseRejects(fn() => $this->policy($alpha, $alphaRole, $resource, $betaMember));
        $this->policy($alpha, $alphaRole, $resource, $alphaMember);
        $this->assertDatabaseRejects(fn() => $this->policy($alpha, $alphaRole, $resource, $alphaMember));
        $this->policy($beta, $betaRole, $resource, $betaMember);
        self::assertSame(2, (int) $this->query('SELECT COUNT(*) FROM pa_data_permission')->fetchColumn());

        $this->assertDatabaseRejects(fn() => $this->insert('pa_data_permission', [
            'tenant_id' => $beta,
            'role_id' => $betaRole,
            'resource_key' => 'example.invalid-validity',
            'valid_from' => '2026-08-01 00:00:00.000',
            'valid_until' => '2026-07-01 00:00:00.000',
            'created_by_member_id' => $betaMember,
            'updated_by_member_id' => $betaMember,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]));
    }

    public function testPolicyJsonAndRetiredRegistryStorageStaySeparated(): void
    {
        [$tenant, $member, $role] = $this->tenantFixture('canonical');
        $this->assertDatabaseRejects(fn() => $this->insert('pa_data_permission', [
            'tenant_id' => $tenant,
            'role_id' => $role,
            'resource_key' => 'example.work-item',
            'scope_type' => 'rules',
            'condition_groups_json' => 'invalid-json',
            'created_by_member_id' => $member,
            'updated_by_member_id' => $member,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]));
        self::assertFalse($this->tableExists('pa_resource_operation_target_type'));
        self::assertFalse($this->tableExists('pa_resource_operation_condition'));
    }

    /** @return array{int, int, int} */
    private function tenantFixture(string $code): array
    {
        $account = $this->insert('pa_account', [
            'display_name' => ucfirst($code),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $tenant = $this->insert('pa_tenant', [
            'code' => $code,
            'name' => ucfirst($code),
            'display_name' => ucfirst($code),
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

        return [$tenant, $member, $role];
    }

    private function policy(int $tenantId, int $roleId, string $resourceKey, int $memberId): int
    {
        return $this->insert('pa_data_permission', [
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'resource_key' => $resourceKey,
            'created_by_member_id' => $memberId,
            'updated_by_member_id' => $memberId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table
SQL);
        self::assertNotFalse($statement);
        $statement->execute(['schema' => $this->databaseName, 'table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }
}
