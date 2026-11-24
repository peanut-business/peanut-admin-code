<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Owned tables for the isolated Workflow business fixture. */
final class CreateWorkflowBusinessFixtureSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_fixture_workflow_object' => <<<'SQL'
CREATE TABLE `pa_fixture_workflow_object` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `object_key` varchar(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scene` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_member_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `business_department_key` varchar(64) DEFAULT NULL,
  `title` varchar(120) NOT NULL,
  `amount_minor` int unsigned NOT NULL,
  `sku` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quantity` int unsigned NOT NULL,
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` bigint unsigned NOT NULL,
  `confirmed_revision_key` varchar(41) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_revision_key` varchar(41) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `artifact_revision` bigint unsigned DEFAULT NULL,
  `completion_ready` tinyint(1) NOT NULL,
  `completion_form_json` text NOT NULL,
  `result_code` varchar(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fixture_workflow_object` (`tenant_id`, `object_key`),
  KEY `idx_fixture_workflow_object_scene` (`tenant_id`, `scene`, `id`),
  CONSTRAINT `chk_fixture_workflow_object_completion_form_json` CHECK (JSON_VALID(`completion_form_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_fixture_workflow_payload' => <<<'SQL'
CREATE TABLE `pa_fixture_workflow_payload` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `object_key` varchar(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision_key` varchar(41) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` blob NOT NULL COMMENT 'Exact bytes used by payload_sha256',
  `payload_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fixture_workflow_payload` (`tenant_id`, `object_key`, `revision_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_fixture_workflow_effect' => <<<'SQL'
CREATE TABLE `pa_fixture_workflow_effect` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `effect_key` varbinary(160) NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `object_key` varchar(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `receipt_json` text NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fixture_workflow_effect` (`tenant_id`, `effect_key`),
  CONSTRAINT `chk_fixture_workflow_effect_receipt_json` CHECK (JSON_VALID(`receipt_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_fixture_workflow_stock_ledger' => <<<'SQL'
CREATE TABLE `pa_fixture_workflow_stock_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `effect_key` varbinary(160) NOT NULL,
  `object_key` varchar(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sku` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quantity` int unsigned NOT NULL,
  `actor_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fixture_workflow_stock_ledger` (`tenant_id`, `effect_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_fixture_workflow_stock_balance' => <<<'SQL'
CREATE TABLE `pa_fixture_workflow_stock_balance` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `sku` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quantity` bigint unsigned NOT NULL,
  `revision` bigint unsigned NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fixture_workflow_stock_balance` (`tenant_id`, `sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_fixture_workflow_task_actor' => <<<'SQL'
CREATE TABLE `pa_fixture_workflow_task_actor` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `reference_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `resource_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `object_key` varchar(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` varchar(64) NOT NULL,
  `session_key` char(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_security_revision` bigint unsigned NOT NULL,
  `tenant_security_revision` bigint unsigned NOT NULL,
  `member_security_revision` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fixture_workflow_task_actor` (`tenant_id`, `reference_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_fixture_workflow_collaboration' => <<<'SQL'
CREATE TABLE `pa_fixture_workflow_collaboration` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `object_key` varchar(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `instance_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `work_item_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `entry_key` varchar(46) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `note` varchar(1000) NOT NULL,
  `reference` varchar(128) NOT NULL,
  `actor_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fixture_workflow_collaboration` (`tenant_id`, `entry_key`),
  KEY `idx_fixture_workflow_collaboration_object` (`tenant_id`, `object_key`, `instance_key`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('The Workflow business fixture migration requires the native PDO adapter.');
        }
        foreach (self::CREATE_SQL as $table => $definition) {
            if (!$adapter->hasTable($table)) {
                $this->execute($definition);
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('The fixture tables are retired through the owned schema cleanup.');
    }
}
