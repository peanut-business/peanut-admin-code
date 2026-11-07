<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use Phinx\Migration\IrreversibleMigrationException;

require_once __DIR__ . '/DatabaseTestCase.php';

final class KernelMigrationTest extends DatabaseTestCase
{
    private const TABLES = [
        'pa_account',
        'pa_credential',
        'pa_tenant',
        'pa_platform_operator',
        'pa_permission',
        'pa_platform_role',
        'pa_platform_role_permission',
        'pa_platform_operator_role',
        'pa_department',
        'pa_tenant_member',
        'pa_role',
        'pa_role_permission',
        'pa_member_role',
        'pa_platform_audit_event',
        'pa_tenant_audit_event',
        'pa_login_challenge',
        'pa_tenant_session',
        'pa_tenant_session_token',
        'pa_platform_session',
        'pa_platform_session_token',
        'pa_auth_security_event',
        'pa_protected_resource',
        'pa_target_type',
        'pa_resource_operation',
        'pa_resource_operation_target_type',
        'pa_resource_operation_permission',
        'pa_data_condition_definition',
        'pa_resource_operation_condition',
        'pa_module_installation',
        'pa_module_migration',
        'pa_menu_definition',
        'pa_tenant_idempotency_record',
        'pa_platform_idempotency_record',
        'pa_system_menu',
        'pa_jobs',
        'pa_member_job',
        'pa_source_read_grant',
        'pa_tenant_owner_invitation',
        'pa_tenant_entry_binding',
        'pa_data_permission_policy',
        'pa_data_permission_target_set',
        'pa_data_permission_group',
        'pa_data_permission_target',
        'pa_data_permission_condition',
        'pa_api_endpoint',
        'pa_permission_api_config',
        'pa_permission_api',
        'pa_plan',
        'pa_plan_module',
        'pa_plan_permission',
        'pa_tenant_license',
        'pa_migrations',
    ];

    public function testNativeFreshInstallAndRepeatPreserveData(): void
    {
        $this->runner->migrate();
        $accountId = $this->insert('pa_account', [
            'display_name' => 'Preserved native account',
            'created_at' => '2026-07-16 12:00:00.000',
            'updated_at' => '2026-07-16 12:00:00.000',
        ]);
        $this->runner->migrate();

        $account = $this->database->prepare('SELECT display_name FROM pa_account WHERE id = ?');
        $account->execute([$accountId]);
        self::assertSame('Preserved native account', $account->fetchColumn());
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM pa_account')->fetchColumn());

        foreach (self::TABLES as $table) {
            self::assertTrue($this->tableExists($table), "Missing migrated table {$table}");
        }
        self::assertFalse($this->tableExists('pa_tenant_module'));

        $challengeClient = $this
            ->query("SHOW COLUMNS FROM `pa_login_challenge` WHERE Field = 'client_key'")
            ->fetch();
        self::assertIsArray($challengeClient);
        self::assertSame('NO', $challengeClient['Null']);

        $authEventIndexes = $this
            ->query("SHOW INDEX FROM `pa_auth_security_event` WHERE Key_name = 'idx_auth_event_ip'")
            ->fetchAll();
        self::assertCount(2, $authEventIndexes);
        self::assertSame(
            ['ip_address', 'occurred_at'],
            array_column($authEventIndexes, 'Column_name'),
        );
    }

    public function testOwnerRollbackRefusesDataLossAndPreservesUnrelatedData(): void
    {
        $this->runner->migrate();
        $this->insert('pa_account', [
            'display_name' => 'Retained owner data',
            'created_at' => '2026-07-16 12:00:00.000',
            'updated_at' => '2026-07-16 12:00:00.000',
        ]);
        $this->database->exec('CREATE TABLE application_rollback_fixture (value VARCHAR(100) NOT NULL)');
        $this->insert('application_rollback_fixture', ['value' => 'Native application data']);
        try {
            $this->runner->rollbackAll();
            self::fail('The Identity owner must refuse destructive initial-schema rollback.');
        } catch (IrreversibleMigrationException $exception) {
            self::assertSame(
                'Module data is retained. This initial schema migration cannot be rolled back.',
                $exception->getMessage(),
            );
        }

        foreach (self::TABLES as $table) {
            self::assertTrue($this->tableExists($table), "Rollback lost table {$table}");
        }

        self::assertSame('Retained owner data', $this->query('SELECT display_name FROM pa_account')->fetchColumn());
        self::assertTrue($this->tableExists('application_rollback_fixture'));
        self::assertSame(
            'Native application data',
            $this->query('SELECT value FROM application_rollback_fixture')->fetchColumn(),
        );
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
SELECT COUNT(*)
FROM information_schema.tables
WHERE table_schema = :schema AND table_name = :table
SQL);
        $statement->execute([
            'schema' => $this->databaseName,
            'table' => $table,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }
}
