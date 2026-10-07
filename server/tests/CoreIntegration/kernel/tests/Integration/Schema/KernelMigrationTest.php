<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

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
        'pa_tenant_module',
        'pa_platform_audit_event',
        'pa_tenant_audit_event',
        'pa_login_challenge',
        'pa_tenant_session',
        'pa_tenant_session_token',
        'pa_platform_session',
        'pa_platform_session_token',
        'pa_auth_security_event',
        'pa_ops_task',
        'pa_ops_maintenance_window',
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

    public function testControlledRollbackRemovesOnlyKernelSchema(): void
    {
        $this->runner->migrate();
        $applicationSchema = file_get_contents(dirname(__DIR__, 6) . '/database/init.sql');
        self::assertIsString($applicationSchema);
        self::assertSame(1, preg_match(
            '/CREATE TABLE `pa_config` \(.*?\) ENGINE=[^;]+;/s',
            $applicationSchema,
            $matches,
        ));
        $this->database->exec($matches[0]);
        $this->insert('pa_config', [
            'type' => 'website',
            'name' => 'rollback-preserved',
            'value' => 'Native application data',
            'create_time' => 1784203200,
        ]);
        $this->runner->rollbackAll();

        foreach (self::TABLES as $table) {
            self::assertFalse($this->tableExists($table), "Rollback retained table {$table}");
        }

        self::assertTrue($this->tableExists('pa_config'));
        self::assertSame(
            'Native application data',
            $this->query("SELECT value FROM pa_config WHERE type = 'website' AND name = 'rollback-preserved'")->fetchColumn(),
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
