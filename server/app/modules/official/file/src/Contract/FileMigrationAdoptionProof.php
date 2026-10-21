<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstallationExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\services\installation\InstallationExecutionHost;
use PDO;
use think\facade\Db;

/** Read-only admission for the four immutable File migrations; never executes migration SQL. */
final readonly class FileMigrationAdoptionProof
{
    public const PROOF_CONTRACT = 'peanut.file-migration-adoption.v1';
    public const MIGRATION_KEYS = [
        'official.file:20260823-unify-storage-service',
        'official.file:20260921020000_add_file_image_assets',
        'official.file:20261012030000_add_controlled_file_references',
        'official.file:20261012051000_add_immutable_document_snapshots',
    ];

    // Frozen semantic declarations from d0f01eb's init.sql and the four original SQL files.
    private const SCHEMAS = <<<'JSON'
{"post":{"file":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"id":["int unsigned",false,null,null,null,"auto_increment"],"cid":["int unsigned",false,"0",null,null,""],"source_id":["int unsigned",false,"0",null,null,""],"source":["tinyint",false,"0",null,null,""],"type":["tinyint",false,"10",null,null,""],"name":["varchar(255)",false,"","utf8mb4","utf8mb4_0900_ai_ci",""],"create_time":["int unsigned",false,"0",null,null,""],"update_time":["int unsigned",false,"0",null,null,""],"delete_time":["int unsigned",true,null,null,null,""],"tenant_id":["bigint unsigned",false,null,null,null,""],"file_key":["varchar(64)",false,null,"ascii","ascii_bin",""]},"indexes":{"PRIMARY":{"unique":true,"columns":["id"]},"idx_cid":{"unique":false,"columns":["cid"]},"idx_type":{"unique":false,"columns":["type"]},"idx_type_cid_source":{"unique":false,"columns":["type","cid","source"]},"uk_file_tenant_id":{"unique":true,"columns":["tenant_id","id"]},"idx_file_tenant_type_cid_source":{"unique":false,"columns":["tenant_id","type","cid","source","id"]},"uk_file_object_reference":{"unique":true,"columns":["file_key"]}},"foreign_keys":{"fk_file_tenant":{"columns":["tenant_id"],"table":"tenant","references":["id"],"delete":"RESTRICT","update":"RESTRICT"},"fk_file_object_reference":{"columns":["file_key"],"table":"file_object","references":["file_key"],"delete":"RESTRICT","update":"RESTRICT"}},"checks":{}},"file_object":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"id":["bigint unsigned",false,null,null,null,"auto_increment"],"file_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"tenant_id":["bigint unsigned",false,null,null,null,""],"original_name":["varchar(255)",false,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"media_type":["varchar(127)",false,null,"ascii","ascii_bin",""],"size_bytes":["bigint unsigned",false,null,null,null,""],"sha256":["char(64)",true,null,"ascii","ascii_bin",""],"status":["varchar(16)",false,"ready","ascii","ascii_bin",""],"created_by_member_id":["bigint unsigned",true,null,null,null,""],"revision":["bigint unsigned",false,"1",null,null,""],"created_at":["datetime(3)",false,null,null,null,""],"updated_at":["datetime(3)",false,null,null,null,""],"archived_at":["datetime(3)",true,null,null,null,""],"purpose":["varchar(96)",false,"export.csv","ascii","ascii_bin",""],"access_type":["varchar(8)",false,"private","ascii","ascii_bin",""],"storage_space_id":["bigint unsigned",false,null,null,null,""],"disposition":["varchar(16)",false,"attachment","ascii","ascii_bin",""],"object_key":["varchar(255)",false,null,"ascii","ascii_bin",""]},"indexes":{"PRIMARY":{"unique":true,"columns":["id"]},"uk_file_object_key":{"unique":true,"columns":["file_key"]},"uk_file_object_storage":{"unique":true,"columns":["storage_space_id","object_key"]},"idx_file_object_status":{"unique":false,"columns":["tenant_id","status","id"]},"idx_file_object_sha256":{"unique":false,"columns":["tenant_id","sha256"]},"idx_file_object_purpose":{"unique":false,"columns":["tenant_id","purpose","status","id"]},"uk_file_object_tenant_key":{"unique":true,"columns":["tenant_id","file_key"]},"fk_file_object_member":{"unique":false,"columns":["tenant_id","created_by_member_id"]}},"foreign_keys":{"fk_file_object_tenant":{"columns":["tenant_id"],"table":"tenant","references":["id"],"delete":"RESTRICT","update":"RESTRICT"},"fk_file_object_member":{"columns":["tenant_id","created_by_member_id"],"table":"tenant_member","references":["tenant_id","id"],"delete":"RESTRICT","update":"RESTRICT"},"fk_file_object_space":{"columns":["storage_space_id"],"table":"storage_space","references":["id"],"delete":"RESTRICT","update":"RESTRICT"}},"checks":{"chk_file_object_key":"`file_key` REGEXP '^file_[0-9a-f]{32}$'","chk_file_object_sha256":"`sha256` REGEXP '^[0-9a-f]{64}$'","chk_file_object_status":"`status` IN ('pending_write','write_failed','ready','archived')","chk_file_object_revision":"`revision` >= 1","chk_file_object_archive_shape":"((`status` IN ('pending_write','write_failed','ready')) AND `archived_at` IS NULL) OR (`status`='archived' AND `archived_at` IS NOT NULL)","chk_file_object_purpose":"`purpose` REGEXP '^[a-z][a-z0-9._-]{2,95}$'","chk_file_object_access":"`access_type` IN ('public','private')","chk_file_object_disposition":"`disposition` IN ('inline','attachment')"}},"storage_account":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"id":["bigint unsigned",false,null,null,null,"auto_increment"],"account_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"driver":["varchar(16)",false,null,"ascii","ascii_bin",""],"name":["varchar(128)",false,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"credential_ciphertext":["mediumtext",true,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"credential_key_version":["varchar(32)",true,null,"ascii","ascii_bin",""],"credential_rotated_at":["datetime(3)",true,null,null,null,""],"status":["varchar(16)",false,"active","ascii","ascii_bin",""],"created_at":["datetime(3)",false,null,null,null,""],"updated_at":["datetime(3)",false,null,null,null,""]},"indexes":{"PRIMARY":{"unique":true,"columns":["id"]},"uk_storage_account_key":{"unique":true,"columns":["account_key"]}},"foreign_keys":{},"checks":{"chk_storage_account_driver":"`driver` IN ('local','qiniu','aliyun','qcloud')","chk_storage_account_status":"`status` IN ('active','disabled')","chk_storage_account_credentials":"(`driver`='local' AND `credential_ciphertext` IS NULL AND `credential_key_version` IS NULL AND `credential_rotated_at` IS NULL) OR (`driver`<>'local' AND `credential_ciphertext` IS NOT NULL AND `credential_key_version` IS NOT NULL AND `credential_rotated_at` IS NOT NULL)"}},"storage_space":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"id":["bigint unsigned",false,null,null,null,"auto_increment"],"space_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"account_id":["bigint unsigned",false,null,null,null,""],"name":["varchar(128)",false,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"access_type":["varchar(8)",false,null,"ascii","ascii_bin",""],"bucket":["varchar(255)",true,null,"ascii","ascii_bin",""],"region":["varchar(100)",true,null,"ascii","ascii_bin",""],"endpoint":["varchar(500)",true,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"access_domain":["varchar(500)",true,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"local_path":["varchar(64)",true,null,"ascii","ascii_bin",""],"status":["varchar(16)",false,"active","ascii","ascii_bin",""],"created_at":["datetime(3)",false,null,null,null,""],"updated_at":["datetime(3)",false,null,null,null,""]},"indexes":{"PRIMARY":{"unique":true,"columns":["id"]},"uk_storage_space_key":{"unique":true,"columns":["space_key"]},"uk_storage_space_location":{"unique":true,"columns":["account_id","bucket","region"]}},"foreign_keys":{"fk_storage_space_account":{"columns":["account_id"],"table":"storage_account","references":["id"],"delete":"RESTRICT","update":"RESTRICT"}},"checks":{"chk_storage_space_access":"`access_type` IN ('public','private')","chk_storage_space_status":"`status` IN ('active','read_only','disabled')","chk_storage_space_shape":"(`local_path` IS NOT NULL AND `bucket` IS NULL AND `region` IS NULL AND `endpoint` IS NULL) OR (`local_path` IS NULL AND `bucket` IS NOT NULL)","chk_storage_space_local_path":"`local_path` IS NULL OR `local_path` IN ('public/storage','private/storage')"}},"storage_route":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"route_key":["varchar(96)",false,null,"ascii","ascii_bin",""],"access_type":["varchar(8)",false,null,"ascii","ascii_bin",""],"space_id":["bigint unsigned",false,null,null,null,""],"updated_at":["datetime(3)",false,null,null,null,""]},"indexes":{"PRIMARY":{"unique":true,"columns":["route_key"]},"idx_storage_route_space":{"unique":false,"columns":["space_id"]}},"foreign_keys":{"fk_storage_route_space":{"columns":["space_id"],"table":"storage_space","references":["id"],"delete":"RESTRICT","update":"RESTRICT"}},"checks":{"chk_storage_route_access":"`access_type` IN ('public','private')","chk_storage_route_key":"`route_key` REGEXP '^[a-z][a-z0-9._-]{2,95}$'"}},"file_image_asset":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"tenant_id":["bigint unsigned",false,null,null,null,""],"file_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"width":["int unsigned",false,null,null,null,""],"height":["int unsigned",false,null,null,null,""],"media_type":["varchar(127)",false,null,"ascii","ascii_bin",""],"created_at":["datetime(3)",false,null,null,null,""],"updated_at":["datetime(3)",false,null,null,null,""]},"indexes":{"PRIMARY":{"unique":true,"columns":["tenant_id","file_key"]}},"foreign_keys":{"fk_file_image_asset_object":{"columns":["tenant_id","file_key"],"table":"file_object","references":["tenant_id","file_key"],"delete":"RESTRICT","update":"RESTRICT"}},"checks":{"chk_file_image_asset_dimensions":"`width` BETWEEN 1 AND 50000 AND `height` BETWEEN 1 AND 50000 AND `width` * `height` <= 100000000","chk_file_image_asset_media_type":"`media_type` IN ('image/jpeg','image/png')"}},"file_derivative":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"tenant_id":["bigint unsigned",false,null,null,null,""],"source_file_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"variant_key":["varchar(32)",false,null,"ascii","ascii_bin",""],"derivative_file_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"width":["int unsigned",false,null,null,null,""],"height":["int unsigned",false,null,null,null,""],"media_type":["varchar(127)",false,null,"ascii","ascii_bin",""],"created_at":["datetime(3)",false,null,null,null,""],"updated_at":["datetime(3)",false,null,null,null,""]},"indexes":{"PRIMARY":{"unique":true,"columns":["tenant_id","source_file_key","variant_key"]},"uk_file_derivative_object":{"unique":true,"columns":["tenant_id","derivative_file_key"]}},"foreign_keys":{"fk_file_derivative_source":{"columns":["tenant_id","source_file_key"],"table":"file_object","references":["tenant_id","file_key"],"delete":"RESTRICT","update":"RESTRICT"},"fk_file_derivative_object":{"columns":["tenant_id","derivative_file_key"],"table":"file_object","references":["tenant_id","file_key"],"delete":"RESTRICT","update":"RESTRICT"}},"checks":{"chk_file_derivative_key":"`variant_key` REGEXP '^[a-z][a-z0-9-]{0,31}$'","chk_file_derivative_dimensions":"`width` BETWEEN 1 AND 4096 AND `height` BETWEEN 1 AND 4096","chk_file_derivative_media_type":"`media_type` IN ('image/jpeg','image/png')","chk_file_derivative_distinct":"`source_file_key` <> `derivative_file_key`"}},"file_delivery_nonce":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"token_id_hash":["char(64)",false,null,"ascii","ascii_bin",""],"expires_at":["datetime(3)",false,null,null,null,""],"consumed_at":["datetime(3)",false,null,null,null,""]},"indexes":{"PRIMARY":{"unique":true,"columns":["token_id_hash"]},"idx_file_delivery_nonce_expiry":{"unique":false,"columns":["expires_at"]}},"foreign_keys":{},"checks":{"chk_file_delivery_nonce_hash":"`token_id_hash` REGEXP '^[0-9a-f]{64}$'"}},"file_delivery_grant":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"token_id_hash":["char(64)",false,null,"ascii","ascii_bin",""],"subject_tenant_id":["bigint unsigned",false,null,null,null,""],"file_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"file_revision":["bigint unsigned",false,null,null,null,""],"visibility":["varchar(8)",false,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"operation":["varchar(32)",false,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"subject_json":["json",true,null,null,null,""],"authorization_revision":["char(64)",true,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"document_id":["bigint unsigned",true,null,null,null,""],"reference_digest":["varchar(128)",true,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"source_file_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"source_revision":["bigint unsigned",false,null,null,null,""],"source_visibility":["varchar(8)",false,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"expires_at":["datetime(3)",false,null,null,null,""]},"indexes":{"PRIMARY":{"unique":true,"columns":["token_id_hash"]},"idx_file_grant_expiry":{"unique":false,"columns":["expires_at"]}},"foreign_keys":{},"checks":{"chk_file_grant_operation":"`operation` IN ('public','preview','download_original')","chk_file_grant_visibility":"`visibility` IN ('public','private')","chk_file_grant_source_visibility":"`source_visibility` IN ('public','private')"}},"file_reference":{"engine":"InnoDB","collation":"utf8mb4_0900_ai_ci","columns":{"id":["bigint unsigned",false,null,null,null,"auto_increment"],"tenant_id":["bigint unsigned",false,null,null,null,""],"file_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"owner_module_key":["varchar(128)",false,null,"ascii","ascii_bin",""],"owner_type":["varchar(64)",false,null,"ascii","ascii_bin",""],"owner_id":["varchar(128)",false,null,"ascii","ascii_bin",""],"reference_digest":["varchar(128)",false,null,"utf8mb4","utf8mb4_0900_ai_ci",""],"updated_at":["datetime(3)",false,null,null,null,""]},"indexes":{"PRIMARY":{"unique":true,"columns":["id"]},"uk_file_reference_owner":{"unique":true,"columns":["tenant_id","file_key","owner_module_key","owner_type","owner_id"]},"idx_file_reference_owner":{"unique":false,"columns":["tenant_id","owner_module_key","owner_type","owner_id"]},"fk_file_reference_object":{"unique":false,"columns":["file_key"]}},"foreign_keys":{"fk_file_reference_object":{"columns":["file_key"],"table":"file_object","references":["file_key"],"delete":"RESTRICT","update":"RESTRICT"}},"checks":{}},"file_immutable_snapshot":{"engine":"InnoDB","collation":"utf8mb4_unicode_ci","columns":{"file_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"tenant_id":["bigint unsigned",false,null,null,null,""],"capture_id":["char(64)",false,null,"ascii","ascii_bin",""],"source_file_key":["varchar(64)",false,null,"ascii","ascii_bin",""],"source_revision":["bigint unsigned",false,null,null,null,""],"source_sha256":["char(64)",false,null,"ascii","ascii_bin",""],"verified_mime_type":["varchar(127)",false,null,"ascii","ascii_general_ci",""],"created_by_member_id":["bigint unsigned",false,null,null,null,""],"created_at":["datetime(3)",false,null,null,null,""],"status":["varchar(32)",false,"captured","utf8mb4","utf8mb4_unicode_ci",""],"claimed_document_id":["bigint unsigned",true,null,null,null,""],"claimed_at":["datetime(3)",true,null,null,null,""]},"indexes":{"PRIMARY":{"unique":true,"columns":["file_key"]},"uk_file_document_capture":{"unique":true,"columns":["tenant_id","capture_id"]}},"foreign_keys":{"fk_file_immutable_target":{"columns":["file_key"],"table":"file_object","references":["file_key"],"delete":"RESTRICT","update":"RESTRICT"},"fk_file_immutable_tenant":{"columns":["tenant_id"],"table":"tenant","references":["id"],"delete":"RESTRICT","update":"RESTRICT"}},"checks":{"chk_file_immutable_status":"`status` IN ('captured','claimed','cleanup_pending','abandoned')"}}}}
JSON;

    public function __construct(private CurrentExecutionContext $contexts) {}

    /** The application owner supplies this source-bound reader; it runs inside this exact PDO snapshot. */
    public function report(string $oldMigrationKey, \Closure $applicationHistory, ?PDO $expectedPdo = null, ?\Closure $visibilityHistory = null): array
    {
        $checks = [];
        $pdo = null;
        $started = false;
        try {
            $context = $this->contexts->current();
            if (!$context instanceof InstanceExecutionContext && !$context instanceof InstallationExecutionContext) {
                return $this->result($oldMigrationKey, 'reject', $checks, 'EXECUTION_CONTEXT_UNAVAILABLE');
            }
            $position = array_search($oldMigrationKey, self::MIGRATION_KEYS, true);
            if ($position === false) {
                return $this->result($oldMigrationKey, 'reject', $checks, 'MIGRATION_UNSUPPORTED');
            }
            $connection = Db::connect();
            if ($connection->getPdo() === false && $expectedPdo === null) {
                $connection->query('SELECT 1', [], true);
            }
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO || $expectedPdo !== null && ($pdo !== $expectedPdo || !$pdo->inTransaction())) {
                return $this->result($oldMigrationKey, 'reject', $checks, 'PDO_IDENTITY_UNKNOWN');
            }
            if ($expectedPdo === null) {
                if ($pdo->inTransaction()) {
                    return $this->result($oldMigrationKey, 'reject', $checks, 'TRANSACTION_ALREADY_ACTIVE');
                }
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('SET TRANSACTION READ ONLY');
                $pdo->beginTransaction();
                $started = true;
            }
            $checks['database'] = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            if ($checks['database'] === '') { throw new \RuntimeException('DATABASE_UNKNOWN'); }
            $history = $applicationHistory($pdo, $oldMigrationKey);
            if (!is_array($history) || array_keys($history) !== ['historical_application_migration', 'historical_application_migration_observation']
                || !in_array($history['historical_application_migration_observation'], ['unknown_ledger_absent', 'unknown_record_absent', 'recorded_applied'], true)) {
                throw new \RuntimeException('APPLICATION_MIGRATION_LEDGER_INVALID');
            }
            $checks += $history;
            $schemas = json_decode(self::SCHEMAS, true, 512, JSON_THROW_ON_ERROR);
            $names = [];
            foreach (array_keys($schemas['post']) as $name) {
                $physical = Db::name($name)->getTable();
                if (!is_string($physical) || preg_match('/^[a-zA-Z0-9_]+$/D', $physical) !== 1) {
                    throw new \RuntimeException('TABLE_IDENTITY_UNKNOWN');
                }
                $names[$name] = $physical;
            }
            foreach (['tenant', 'tenant_member', 'config'] as $name) {
                $physical = Db::name($name)->getTable();
                if (!is_string($physical) || preg_match('/^[a-zA-Z0-9_]+$/D', $physical) !== 1) {
                    throw new \RuntimeException('TABLE_IDENTITY_UNKNOWN');
                }
                $names[$name] = $physical;
            }
            $this->referencedKeys($pdo, $names, $checks);
            $actual = [];
            foreach ($schemas['post'] as $name => $_) { $actual[$name] = $this->schema($pdo, $names[$name]); }
            $checks['schema_sha256'] = hash('sha256', self::json($actual));
            $checks['tables'] = array_map(static fn($schema): string => $schema === null ? 'absent' : 'present', $actual);
            // Exactly one later File migration adds this index. No other unregistered additions are admitted.
            $basePost = $schemas['post'];
            $object = $actual['file_object'];
            if (isset($object['columns']['visibility_batch_key'])) {
                if ($visibilityHistory === null || $visibilityHistory($pdo) !== true) {
                    throw new \RuntimeException('VISIBILITY_NATIVE_HISTORY_REQUIRED');
                }
                $key = array_key_last(FileMigrationPreservation::VISIBILITY_STAGES);
                $path = dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('official.file:')) . '.sql';
                if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== FileMigrationPreservation::VISIBILITY_STAGES[$key]) {
                    throw new \RuntimeException('VISIBILITY_SOURCE_CHANGED');
                }
                $sql = (string) file_get_contents($path);
                $basePost['file_object']['columns']['status'][0] = 'varchar(32)';
                $basePost['file_object']['columns']['visibility_batch_key'] = ['varchar(64)', true, null, 'ascii', 'ascii_bin', ''];
                foreach (['chk_file_object_status','chk_file_object_archive_shape','chk_file_object_visibility_operation'] as $name) {
                    if (preg_match('/ADD CONSTRAINT `' . $name . '` CHECK \(([^\r\n]+)\)(?:,|;)/', $sql, $match) !== 1) {
                        throw new \RuntimeException('VISIBILITY_SOURCE_CHANGED');
                    }
                    $basePost['file_object']['checks'][$name] = $match[1];
                }
                foreach (['file_visibility_batch','file_visibility_item'] as $name) {
                    $table = Db::name($name)->getTable();
                    if ($table !== 'pa_' . $name) {
                        throw new \RuntimeException('VISIBILITY_NAMESPACE_UNKNOWN');
                    }
                    $image = \app\common\services\upgrade\MySqlMetadataObservation::tableSchema($pdo, $table);
                    if ($image === null) {
                        throw new \RuntimeException('VISIBILITY_SCHEMA_UNKNOWN');
                    }
                    app()->make(FileMigrationPreservation::class)->visibilityTable($pdo, $name, $image);
                }
                $bad = $this->count($pdo, 'SELECT COUNT(*) FROM pa_file_visibility_item i LEFT JOIN pa_file_visibility_batch b ON b.batch_key=i.batch_key'
                    . ' LEFT JOIN pa_file_object o ON o.file_key=i.file_key WHERE b.batch_key IS NULL OR o.file_key IS NULL OR i.tenant_id<>b.tenant_id OR i.tenant_id<>o.tenant_id');
                if ($bad !== 0) {
                    throw new \RuntimeException('FILE_RELATIONSHIP_MISMATCH');
                }
                $checks['visibility_native_profile'] = 'complete_schema_with_native_completion_or_held_continuation';
            }
            $hasImageIndex = isset($object['indexes']['uk_file_object_tenant_key']);
            if (!$hasImageIndex) { unset($basePost['file_object']['indexes']['uk_file_object_tenant_key']); }
            $baseNames = ['file', 'file_object', 'storage_account', 'storage_space', 'storage_route'];
            $baseReady = true;
            foreach ($baseNames as $name) {
                $ok = $this->matches($actual[$name], $basePost[$name], $names);
                $checks['schema_matches'][$name] = $ok;
                $baseReady = $baseReady && $ok;
            }
            if (!$baseReady) {
                if ($position === 0 && $this->emptyLegacyUnify($pdo, $actual, $schemas['post'], $names, $checks)) {
                    if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) { throw new \RuntimeException('PDO_IDENTITY_CHANGED'); }
                    return $this->result($oldMigrationKey, 'execute', $checks, null);
                }
                // Historical unify includes destructive mapping and removed source columns. Never auto-replay it.
                return $this->result($oldMigrationKey, 'reject', $checks, 'FILE_BASE_POSTSHAPE_UNKNOWN');
            }
            $this->data($pdo, $baseNames, $basePost, $names, $checks);
            $families = [[], ['file_image_asset', 'file_derivative', 'file_delivery_nonce'],
                ['file_delivery_grant', 'file_reference'], ['file_immutable_snapshot']];
            // Validate every already-present later family; a partial later migration is never a legal increment.
            foreach ([1, 2, 3] as $i) {
                $present = 0;
                foreach ($families[$i] as $name) { $present += $actual[$name] !== null ? 1 : 0; }
                $checks['families'][(string) $i] = $present === 0 ? 'absent' : 'present';
                if ($present === 0) { continue; }
                if ($present !== count($families[$i]) || $i === 1 && !$hasImageIndex) {
                    return $this->result($oldMigrationKey, 'reject', $checks, 'PARTIAL_LATER_SCHEMA');
                }
                foreach ($families[$i] as $name) {
                    if (!$this->matches($actual[$name], $schemas['post'][$name], $names)) {
                        return $this->result($oldMigrationKey, 'reject', $checks, 'LATER_SCHEMA_MISMATCH');
                    }
                }
                $this->data($pdo, $families[$i], $schemas['post'], $names, $checks);
            }
            if ($hasImageIndex && $actual['file_image_asset'] === null) {
                return $this->result($oldMigrationKey, 'reject', $checks, 'PARTIAL_IMAGE_SCHEMA');
            }
            if ($position === 0) {
                // Unknown history still needs the narrow reconstructable legacy mapping.
                // A genuine completed application migration can subsequently receive normal
                // File business writes; those rows must satisfy the full current poststate,
                // not be misidentified as output of the old export.csv conversion.
                $recorded = $history['historical_application_migration_observation'] === 'recorded_applied';
                if ($recorded && $history['historical_application_migration'] !== [
                    'source' => 'application-migration-ledger', 'migration_id' => '20260823-unify-storage-service',
                    'release_version' => '3.0.6', 'checksum' => 'f79c35b2fe8414dc172a81a45183d2a52eadb05833a704149780b36e400ca2d4',
                    'status' => 'applied', 'claim' => 'recorded_applied',
                ]) { throw new \RuntimeException('APPLICATION_MIGRATION_LEDGER_INVALID'); }
                $checks['unify']['unverifiable_material_rows'] = $this->count($pdo, 'SELECT COUNT(*) FROM ' . self::q($names['file']));
                $checks['unify']['legacy_storage_config_rows'] = $this->count($pdo, 'SELECT COUNT(*) FROM ' . self::q($names['config']) . " WHERE `type`='storage'");
                $checks['unify']['default_route_mismatches'] = $this->count($pdo,
                    "SELECT COUNT(*) FROM (SELECT 'public' AS access_type UNION ALL SELECT 'private') a WHERE NOT EXISTS (SELECT 1 FROM " . self::q($names['storage_route']) . ' r JOIN ' . self::q($names['storage_space']) . ' s ON s.id=r.space_id JOIN ' . self::q($names['storage_account']) . " c ON c.id=s.account_id WHERE r.route_key=CONCAT('default.',a.access_type) AND r.access_type=a.access_type AND s.space_key=CONCAT('local-',a.access_type) AND s.access_type=a.access_type AND s.local_path=CONCAT(a.access_type,'/storage') AND c.account_key='local' AND c.driver='local')",
                );
                $checks['unify']['unverifiable_object_mapping_rows'] = $this->count($pdo,
                    'SELECT COUNT(*) FROM ' . self::q($names['file_object']) . ' o JOIN ' . self::q($names['storage_space']) . " s ON s.id=o.storage_space_id WHERE o.purpose<>'export.csv' OR o.access_type<>'private' OR o.disposition<>'attachment' OR s.space_key<>'local-private'",
                );
                $mapping = $recorded ? array_diff_key($checks['unify'], array_flip([
                    'unverifiable_material_rows', 'unverifiable_object_mapping_rows',
                ])) : $checks['unify'];
                $checks['adoption_basis'] = $recorded ? 'recorded_application_execution_and_current_poststate' : 'reconstructed_legacy_mapping_and_current_poststate';
                if (array_sum($mapping) !== 0) {
                    return $this->result($oldMigrationKey, 'reject', $checks, 'UNIFY_MAPPING_PROOF_UNAVAILABLE');
                }
                $disposition = 'adopt';
            } else {
                $present = $actual[$families[$position][0]] !== null;
                // Image's prestate must have no unique-key addition; its poststate must have the exact key.
                if ($position === 1 && $hasImageIndex !== $present) {
                    return $this->result($oldMigrationKey, 'reject', $checks, 'IMAGE_PRECONDITION_MISMATCH');
                }
                $disposition = $present ? 'adopt' : 'execute';
            }
            if ($disposition === 'execute') {
                $this->assertConstraintNamesAvailable($pdo, $families[$position], $schemas['post'], $names, $checks);
                // Original immutable SQL explicitly names pa_*; never execute it against another namespace.
                foreach ($names as $name => $physical) {
                    if ($physical !== 'pa_' . $name) {
                        return $this->result($oldMigrationKey, 'reject', $checks, 'MIGRATION_SQL_PREFIX_UNSUPPORTED');
                    }
                }
            }
            if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) {
                return $this->result($oldMigrationKey, 'reject', $checks, 'PDO_IDENTITY_CHANGED');
            }
            // Detect observed DDL drift. This cannot replace the caller's authorized DDL exclusion window.
            foreach ($actual as $name => $schema) {
                if (self::json($this->schema($pdo, $names[$name])) !== self::json($schema)) {
                    return $this->result($oldMigrationKey, 'reject', $checks, 'SCHEMA_CHANGED_DURING_PROOF');
                }
            }
            $checks['proof_no_business_data_write'] = true;
            $checks['proof_no_ddl'] = true;
            $checks['adoption_no_business_data_write'] = $disposition === 'adopt';
            $checks['adoption_no_ddl'] = $disposition === 'adopt';
            $checks['ddl_exclusion_window_required'] = true;
            $checks['data_snapshot_scope'] = 'consistent_read_not_external_writer_exclusion';
            $checks['external_writer_exclusion'] = 'ops_protocol_gate_required_not_attested';
            return $this->result($oldMigrationKey, $disposition, $checks, null);
        } catch (\Throwable $exception) {
            $known = ['CHECK_DATA_MISMATCH', 'FOREIGN_DATA_MISMATCH', 'UNIQUE_DATA_MISMATCH',
                'FILE_RELATIONSHIP_MISMATCH', 'REFERENCED_KEY_UNKNOWN', 'CHECK_NOT_ENFORCED',
                'CHECK_SYNTAX_UNKNOWN', 'INDEX_UNKNOWN', 'TABLE_UNKNOWN', 'FOREIGN_SCHEMA_UNKNOWN',
                'APPLICATION_MIGRATION_LEDGER_INVALID', 'APPLICATION_MIGRATION_RECORD_CONFLICT', 'APPLICATION_MIGRATION_HISTORY_PDO_MISMATCH',
                'MIGRATION_CONSTRAINT_NAME_CONFLICT', 'MIGRATION_CONSTRAINT_NAMES_UNKNOWN'];
            $reason = in_array($exception->getMessage(), $known, true) ? $exception->getMessage() : 'FILE_ADOPTION_PROOF_UNKNOWN';
            return $this->result($oldMigrationKey, 'reject', $checks, $reason);
        } finally {
            try {
                if ($started && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
            } catch (\Throwable) {
                return $this->result($oldMigrationKey, 'reject', $checks, 'READ_SNAPSHOT_CLOSE_FAILED');
            }
        }
    }

    /** MySQL FK/CHECK names are schema-scoped: an absent target family alone is not a safe prestate. */
    /** One exact empty r1 prestate, authorized only by the actual native partial installation host. */
    private function emptyLegacyUnify(PDO $pdo, array $actual, array $post, array $names, array &$checks): bool
    {
        $legacy = $post;
        unset($legacy['file']['columns']['file_key'], $legacy['file']['indexes']['uk_file_object_reference'],
            $legacy['file']['foreign_keys']['fk_file_object_reference']);
        $legacy['file']['columns']['uri'] = ['varchar(255)', false, '', 'utf8mb4', 'utf8mb4_0900_ai_ci', ''];
        $legacy['file']['columns']['storage'] = ['varchar(20)', false, 'local', 'utf8mb4', 'utf8mb4_0900_ai_ci', ''];
        foreach (['purpose', 'access_type', 'storage_space_id', 'disposition', 'object_key'] as $column) {
            unset($legacy['file_object']['columns'][$column]);
        }
        $legacy['file_object']['columns']['storage_provider_key'] = ['varchar(64)', false, null, 'ascii', 'ascii_bin', ''];
        $legacy['file_object']['columns']['storage_key'] = ['varchar(255)', false, null, 'ascii', 'ascii_bin', ''];
        $legacy['file_object']['columns']['sha256'][1] = false;
        $legacy['file_object']['columns']['created_by_member_id'][1] = false;
        $legacy['file_object']['indexes']['uk_file_object_storage']['columns'] = ['tenant_id', 'storage_provider_key', 'storage_key'];
        unset($legacy['file_object']['indexes']['idx_file_object_purpose'], $legacy['file_object']['indexes']['uk_file_object_tenant_key'],
            $legacy['file_object']['foreign_keys']['fk_file_object_space']);
        foreach (['chk_file_object_purpose', 'chk_file_object_access', 'chk_file_object_disposition'] as $check) {
            unset($legacy['file_object']['checks'][$check]);
        }
        $legacy['file_object']['checks']['chk_file_object_status'] = "`status` IN ('ready', 'archived')";
        $legacy['file_object']['checks']['chk_file_object_archive_shape'] = "(`status` = 'ready' AND `archived_at` IS NULL) OR (`status` = 'archived' AND `archived_at` IS NOT NULL)";
        foreach ($actual as $name => $schema) {
            if (in_array($name, ['file', 'file_object'], true)) {
                if (!$this->matches($schema, $legacy[$name], $names)) { return false; }
            } elseif ($schema !== null) { return false; }
        }
        foreach ($names as $name => $physical) {
            if ($physical !== 'pa_' . $name) { throw new \RuntimeException('MIGRATION_SQL_PREFIX_UNSUPPORTED'); }
        }
        $counts = ['material_rows' => $this->count($pdo, 'SELECT COUNT(*) FROM ' . self::q($names['file'])),
            'object_rows' => $this->count($pdo, 'SELECT COUNT(*) FROM ' . self::q($names['file_object'])),
            'storage_config_rows' => $this->count($pdo, 'SELECT COUNT(*) FROM ' . self::q($names['config']) . " WHERE `type`='storage'")];
        if (array_sum($counts) !== 0) { return false; }
        $sequences = $this->rows($pdo, "SELECT TABLE_NAME,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('pa_file','pa_file_object') ORDER BY TABLE_NAME", []);
        if (count($sequences) !== 2 || array_column($sequences, 'TABLE_NAME') !== ['pa_file', 'pa_file_object']
            || array_map('strval', array_column($sequences, 'AUTO_INCREMENT')) !== ['1', '1']
            || (int) $pdo->query('SELECT @@auto_increment_increment')->fetchColumn() !== 1
            || (int) $pdo->query('SELECT @@auto_increment_offset')->fetchColumn() !== 1) { return false; }
        $authority = app()->make(InstallationExecutionHost::class)->fileUnifyContinuationAdmission($pdo, self::MIGRATION_KEYS[0]);
        $this->assertConstraintNamesAvailable($pdo, ['storage_account', 'storage_space', 'storage_route'], $post, $names, $checks);
        if (app()->make(InstallationExecutionHost::class)->fileUnifyContinuationAdmission($pdo, self::MIGRATION_KEYS[0]) !== $authority) {
            throw new \RuntimeException('CONTINUATION_AUTHORITY_CHANGED');
        }
        $checks['empty_legacy_unify'] = ['counts' => $counts, 'exact_original_r1_schema' => true,
            'native_authority_sha256' => $authority['authority_sha256']];
        return true;
    }

    private function assertConstraintNamesAvailable(PDO $pdo, array $tables, array $schemas, array $names, array &$checks): void
    {
        $expected = [];
        foreach ($tables as $table) {
            foreach (['foreign_keys' => 'FOREIGN KEY', 'checks' => 'CHECK'] as $group => $type) {
                foreach (array_keys($schemas[$table][$group]) as $name) {
                    if (isset($expected[$name])) { throw new \RuntimeException('MIGRATION_CONSTRAINT_NAMES_UNKNOWN'); }
                    $expected[$name] = ['table' => $names[$table], 'type' => $type];
                }
            }
        }
        if ($expected === []) { throw new \RuntimeException('MIGRATION_CONSTRAINT_NAMES_UNKNOWN'); }
        ksort($expected, SORT_STRING);
        // INFORMATION_SCHEMA hides tables without privileges. Empty results are not absence proof
        // unless direct SELECT/ALL grants establish visibility of this whole schema. Role-only or
        // unrecognized grants remain unknown; never expose grant text in the proof report.
        $visible = false; $restricted = false;
        $databaseScope = self::q($checks['database']) . '.*';
        $mode = $this->rows($pdo, 'SELECT @@GLOBAL.partial_revokes AS partial_revokes', [])[0]['partial_revokes'] ?? null;
        if (!in_array($mode, [0, 1, '0', '1'], true)) { throw new \RuntimeException('MIGRATION_CONSTRAINT_NAMES_UNKNOWN'); }
        $scopes = ['*.*', $databaseScope];
        if ((string) $mode === '0') { $scopes[] = str_replace('_', '\\_', $databaseScope); }
        foreach ($this->rows($pdo, 'SHOW GRANTS', []) as $grantRow) {
            $grant = count($grantRow) === 1 ? reset($grantRow) : null;
            if (is_string($grant) && str_starts_with($grant, 'REVOKE ')) { $restricted = true; }
            if (!is_string($grant) || preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match) !== 1
                || !in_array($match[2], $scopes, true)) { continue; }
            $privileges = array_map('trim', explode(',', $match[1]));
            $visible = $visible || in_array('SELECT', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
        }
        if (!$visible || $restricted) { throw new \RuntimeException('MIGRATION_CONSTRAINT_NAMES_UNKNOWN'); }
        $owners = $this->rows($pdo,
            'SELECT TABLE_SCHEMA,TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE IN (\'FOREIGN KEY\',\'CHECK\')'
            . ' AND CONSTRAINT_NAME IN (' . implode(',', array_fill(0, count($expected), '?')) . ')'
            . ' ORDER BY CONSTRAINT_NAME,TABLE_NAME,CONSTRAINT_TYPE', array_keys($expected),
        );
        $checks['execute_constraint_names'] = ['whole_schema_visibility' => true, 'expected_owners' => $expected, 'observed_owners' => $owners];
        // Execute requires every target table to be absent, so even an expected owner here is inconsistent.
        if ($owners !== []) { throw new \RuntimeException('MIGRATION_CONSTRAINT_NAME_CONFLICT'); }
        $checks['execute_constraint_names']['all_available'] = true;
    }

    private function schema(PDO $pdo, string $table): ?array
    {
        $rows = $this->rows($pdo, 'SELECT ENGINE,TABLE_COLLATION,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if ($rows === []) { return null; }
        if (count($rows) !== 1 || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE') { throw new \RuntimeException('TABLE_UNKNOWN'); }
        $schema = ['engine' => $rows[0]['ENGINE'], 'collation' => $rows[0]['TABLE_COLLATION'], 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => []];
        foreach ($this->rows($pdo, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]) as $row) {
            if ($row['GENERATION_EXPRESSION'] !== '') { throw new \RuntimeException('GENERATED_COLUMN_UNKNOWN'); }
            $schema['columns'][$row['COLUMN_NAME']] = [strtolower(preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/i', '$1', $row['COLUMN_TYPE'])), $row['IS_NULLABLE'] === 'YES', $row['COLUMN_DEFAULT'], $row['CHARACTER_SET_NAME'], $row['COLLATION_NAME'], strtolower($row['EXTRA'])];
        }
        foreach ($this->rows($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART,COLLATION,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]) as $row) {
            if ($row['SUB_PART'] !== null || $row['COLLATION'] !== 'A' || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES' || $row['EXPRESSION'] !== null) { throw new \RuntimeException('INDEX_UNKNOWN'); }
            $schema['indexes'][$row['INDEX_NAME']]['unique'] = (int) $row['NON_UNIQUE'] === 0;
            $schema['indexes'][$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
        }
        foreach ($this->rows($pdo, 'SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$table]) as $row) {
            if ($row['REFERENCED_TABLE_SCHEMA'] !== (string) $pdo->query('SELECT DATABASE()')->fetchColumn()) { throw new \RuntimeException('FOREIGN_SCHEMA_UNKNOWN'); }
            $key = $row['CONSTRAINT_NAME'];
            $schema['foreign_keys'][$key]['columns'][] = $row['COLUMN_NAME'];
            $schema['foreign_keys'][$key]['table'] = $row['REFERENCED_TABLE_NAME'];
            $schema['foreign_keys'][$key]['references'][] = $row['REFERENCED_COLUMN_NAME'];
            $schema['foreign_keys'][$key]['delete'] = $row['DELETE_RULE'] === 'NO ACTION' ? 'RESTRICT' : $row['DELETE_RULE'];
            $schema['foreign_keys'][$key]['update'] = $row['UPDATE_RULE'] === 'NO ACTION' ? 'RESTRICT' : $row['UPDATE_RULE'];
        }
        foreach ($this->rows($pdo, "SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK'", [$table]) as $row) {
            if ($row['ENFORCED'] !== 'YES') { throw new \RuntimeException('CHECK_NOT_ENFORCED'); }
            $schema['checks'][$row['CONSTRAINT_NAME']] = self::expression($row['CHECK_CLAUSE']);
        }
        return $schema;
    }

    private function matches(?array $actual, array $expected, array $names): bool
    {
        if ($actual === null) { return false; }
        foreach ($expected['foreign_keys'] as &$foreign) { $foreign['table'] = $names[$foreign['table']]; }
        unset($foreign);
        foreach ($expected['checks'] as &$check) { $check = self::expression($check); }
        unset($check);
        return self::json($actual) === self::json($expected);
    }

    /** Every declared CHECK and FK is re-evaluated, and row bytes are hashed without exposing values. */
    private function data(PDO $pdo, array $tables, array $schemas, array $names, array &$checks): void
    {
        foreach ($tables as $name) {
            $table = self::q($names[$name]);
            foreach ($schemas[$name]['checks'] as $key => $expression) {
                $n = $this->count($pdo, 'SELECT COUNT(*) FROM ' . $table . ' WHERE (' . $expression . ') IS FALSE');
                $checks['violations'][$name][$key] = $n;
                if ($n !== 0) { throw new \RuntimeException('CHECK_DATA_MISMATCH'); }
            }
            foreach ($schemas[$name]['foreign_keys'] as $key => $foreign) {
                $join = []; $nonnull = [];
                foreach ($foreign['columns'] as $i => $column) {
                    $join[] = 'p.' . self::q($foreign['references'][$i]) . '=c.' . self::q($column);
                    $nonnull[] = 'c.' . self::q($column) . ' IS NOT NULL';
                }
                $n = $this->count($pdo, 'SELECT COUNT(*) FROM ' . $table . ' c WHERE ' . implode(' AND ', $nonnull) . ' AND NOT EXISTS (SELECT 1 FROM ' . self::q($names[$foreign['table']]) . ' p WHERE ' . implode(' AND ', $join) . ')');
                $checks['violations'][$name][$key] = $n;
                if ($n !== 0) { throw new \RuntimeException('FOREIGN_DATA_MISMATCH'); }
            }
            foreach ($schemas[$name]['indexes'] as $key => $index) {
                if (!$index['unique']) { continue; }
                $columns = array_map(self::q(...), $index['columns']);
                $nonnull = array_map(static fn($column): string => $column . ' IS NOT NULL', $columns);
                $n = $this->count($pdo, 'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $table . ' WHERE ' . implode(' AND ', $nonnull) . ' GROUP BY ' . implode(',', $columns) . ' HAVING COUNT(*)>1) duplicates');
                $checks['violations'][$name][$key . ':duplicates'] = $n;
                if ($n !== 0) { throw new \RuntimeException('UNIQUE_DATA_MISMATCH'); }
            }
            $primary = array_map(self::q(...), $schemas[$name]['indexes']['PRIMARY']['columns']);
            $statement = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . implode(',', $primary));
            $hash = hash_init('sha256'); $count = 0;
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                foreach ($row as $value) {
                    hash_update($hash, $value === null ? 'N;' : 'S' . strlen((string) $value) . ':' . (string) $value . ';');
                }
                hash_update($hash, "\n"); ++$count;
            }
            $statement->closeCursor();
            $checks['protected_rows'][$name] = ['count' => $count, 'sha256' => hash_final($hash)];
        }
        $relationships = [
            'file' => 'SELECT COUNT(*) FROM ' . self::q($names['file']) . ' f JOIN ' . self::q($names['file_object']) . ' o ON o.file_key=f.file_key WHERE o.tenant_id<>f.tenant_id',
            'storage_space' => 'SELECT COUNT(*) FROM ' . self::q($names['storage_space']) . ' s JOIN ' . self::q($names['storage_account']) . " a ON a.id=s.account_id WHERE (a.driver='local' AND s.local_path IS NULL) OR (a.driver<>'local' AND s.local_path IS NOT NULL)",
            'storage_route' => 'SELECT COUNT(*) FROM ' . self::q($names['storage_route']) . ' r JOIN ' . self::q($names['storage_space']) . ' s ON s.id=r.space_id WHERE r.access_type<>s.access_type',
            'file_object' => 'SELECT COUNT(*) FROM ' . self::q($names['file_object']) . ' o JOIN ' . self::q($names['storage_space']) . ' s ON s.id=o.storage_space_id WHERE o.access_type<>s.access_type',
            'file_reference' => 'SELECT COUNT(*) FROM ' . self::q($names['file_reference']) . ' r JOIN ' . self::q($names['file_object']) . ' o ON o.file_key=r.file_key WHERE r.tenant_id<>o.tenant_id',
            'file_immutable_snapshot' => 'SELECT COUNT(*) FROM ' . self::q($names['file_immutable_snapshot']) . ' s JOIN ' . self::q($names['file_object']) . ' o ON o.file_key=s.file_key WHERE s.tenant_id<>o.tenant_id',
        ];
        foreach ($tables as $name) {
            if (!isset($relationships[$name])) { continue; }
            $n = $this->count($pdo, $relationships[$name]);
            $checks['violations'][$name]['business_relationships'] = $n;
            if ($n !== 0) { throw new \RuntimeException('FILE_RELATIONSHIP_MISMATCH'); }
        }
    }

    /** Only the Identity key surface referenced by these File migrations is part of this proof. */
    private function referencedKeys(PDO $pdo, array $names, array &$checks): void
    {
        foreach (['tenant' => ['id'], 'tenant_member' => ['tenant_id', 'id']] as $name => $columns) {
            $table = $names[$name];
            $rows = $this->rows($pdo, 'SELECT ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE') { throw new \RuntimeException('REFERENCED_KEY_UNKNOWN'); }
            foreach ($columns as $column) {
                $rows = $this->rows($pdo, 'SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column]);
                if (count($rows) !== 1 || strtolower($rows[0]['COLUMN_TYPE']) !== 'bigint unsigned' || $rows[0]['IS_NULLABLE'] !== 'NO') { throw new \RuntimeException('REFERENCED_KEY_UNKNOWN'); }
            }
            $indexes = [];
            foreach ($this->rows($pdo, 'SELECT INDEX_NAME,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]) as $row) {
                if ($row['SUB_PART'] === null && $row['INDEX_TYPE'] === 'BTREE' && $row['IS_VISIBLE'] === 'YES') { $indexes[$row['INDEX_NAME']][] = $row['COLUMN_NAME']; }
            }
            if (!in_array($columns, $indexes, true)) { throw new \RuntimeException('REFERENCED_KEY_UNKNOWN'); }
            $checks['referenced_keys'][$name] = $columns;
        }
    }

    private function rows(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql); $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC); $statement->closeCursor(); return $rows;
    }

    private function count(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql); $count = (int) $statement->fetchColumn(); $statement->closeCursor(); return $count;
    }

    private static function q(string $name): string
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/D', $name) !== 1) { throw new \RuntimeException('IDENTIFIER_UNKNOWN'); }
        return '`' . $name . '`';
    }

    private function result(string $key, string $disposition, array $checks, ?string $reason): array
    {
        $report = ['proof_contract' => self::PROOF_CONTRACT, 'migration_key' => $key,
            'disposition' => $disposition, 'may_apply' => $disposition !== 'reject',
            'checks' => self::canonical($checks), 'reason' => $reason,
            'code' => $reason === null ? null : 'FILE_MIGRATION_ADOPTION_REJECTED'];
        $report['proof_sha256'] = hash('sha256', self::json($report)); return $report;
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as &$item) { $item = self::canonical($item); }
        }
        return $value;
    }

    private static function json(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Narrow CHECK syntax shared by these fixed File SQL files; unknown syntax is rejected. */
    private static function expression(string $sql): array
    {
        $tokens = []; $offset = 0; $escapedQuote = "\\'";
        while ($offset < strlen($sql)) {
            if (preg_match('/\G\s+/A', $sql, $m, 0, $offset)) { $offset += strlen($m[0]); continue; }
            // MySQL CHECK_CLAUSE stores these fixed literals with escaped delimiters.
            // Decode only that token form; escapes inside its content remain unsupported.
            if (preg_match('/\G_[a-zA-Z0-9]+/A', $sql, $m, 0, $offset)
                && substr($sql, $offset + strlen($m[0]), 2) === $escapedQuote) {
                $offset += strlen($m[0]);
            }
            if (substr($sql, $offset, 2) === $escapedQuote) {
                $end = strpos($sql, $escapedQuote, $offset + 2);
                if ($end === false) { throw new \RuntimeException('CHECK_SYNTAX_UNKNOWN'); }
                $literal = substr($sql, $offset + 2, $end - $offset - 2);
                if (strpbrk($literal, "\\'") !== false) { throw new \RuntimeException('CHECK_SYNTAX_UNKNOWN'); }
                $tokens[] = "'" . $literal . "'"; $offset = $end + 2; continue;
            }
            if (!preg_match('/\G(?:_[a-zA-Z0-9]+(?=\x27)|`[^`]+`|\x27(?:\x27\x27|[^\x27])*\x27|[a-zA-Z_][a-zA-Z0-9_]*|[0-9]+|<>|!=|>=|<=|[(),*=<>])/A', $sql, $m, 0, $offset)) { throw new \RuntimeException('CHECK_SYNTAX_UNKNOWN'); }
            $offset += strlen($m[0]); $token = $m[0];
            if ($token[0] === '_' && $offset < strlen($sql) && $sql[$offset] === "'") { continue; }
            $tokens[] = $token[0] === "'" ? $token : strtolower(trim($token, '`'));
        }
        $at = 0;
        $parse = static function (int $minimum = 0) use (&$parse, &$at, $tokens): array {
            $token = $tokens[$at++] ?? null;
            if ($token === '(') {
                $left = $parse(); if (($tokens[$at++] ?? null) !== ')') { throw new \RuntimeException('CHECK_GROUP_UNKNOWN'); }
            } elseif ($token === 'regexp_like') {
                if (($tokens[$at++] ?? null) !== '(') { throw new \RuntimeException('CHECK_FUNCTION_UNKNOWN'); }
                $one = $parse(); if (($tokens[$at++] ?? null) !== ',') { throw new \RuntimeException('CHECK_FUNCTION_UNKNOWN'); }
                $two = $parse(); if (($tokens[$at++] ?? null) !== ')') { throw new \RuntimeException('CHECK_FUNCTION_UNKNOWN'); }
                $left = ['regexp', $one, $two];
            } elseif ($token !== null && preg_match('/^(?:[a-z_][a-z0-9_]*|[0-9]+|\x27.*\x27)$/sD', $token)) {
                $left = ['value', $token];
            } else { throw new \RuntimeException('CHECK_VALUE_UNKNOWN'); }
            $precedence = ['or' => 1, 'and' => 2, '=' => 3, '<>' => 3, '!=' => 3, '<' => 3, '>' => 3, '>=' => 3, '<=' => 3, 'is' => 3, 'in' => 3, 'between' => 3, 'regexp' => 3, '*' => 4];
            while (isset($tokens[$at], $precedence[$tokens[$at]]) && $precedence[$tokens[$at]] >= $minimum) {
                $operator = $tokens[$at++]; $level = $precedence[$operator];
                if ($operator === 'between') {
                    $low = $parse(4); if (($tokens[$at++] ?? null) !== 'and') { throw new \RuntimeException('CHECK_BETWEEN_UNKNOWN'); }
                    $left = ['between', $left, $low, $parse(4)];
                } elseif ($operator === 'in') {
                    if (($tokens[$at++] ?? null) !== '(') { throw new \RuntimeException('CHECK_LIST_UNKNOWN'); }
                    $list = []; do { $list[] = $parse(4); $end = $tokens[$at++] ?? null; } while ($end === ',');
                    if ($end !== ')') { throw new \RuntimeException('CHECK_LIST_UNKNOWN'); }
                    $left = ['in', $left, $list];
                } elseif ($operator === 'is') {
                    $not = ($tokens[$at] ?? null) === 'not'; if ($not) { ++$at; }
                    if (($tokens[$at++] ?? null) !== 'null') { throw new \RuntimeException('CHECK_NULL_UNKNOWN'); }
                    $left = [$not ? 'is_not_null' : 'is_null', $left];
                } else { $left = [$operator === '!=' ? '<>' : $operator, $left, $parse($level + 1)]; }
            }
            return $left;
        };
        $tree = $parse(); if ($at !== count($tokens)) { throw new \RuntimeException('CHECK_TRAILING_UNKNOWN'); } return $tree;
    }
}
