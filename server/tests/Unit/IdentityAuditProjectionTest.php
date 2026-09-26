<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\audit\AuditActor;
use app\common\contract\audit\AuditEvent;
use app\common\contract\audit\AuditTrace;
use app\common\services\audit\AuditContractHost;
use DomainException;
use PDO;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** 在真实 ORM 中验证两种审计投影、JSON 保真与宿主脱敏，不修改已有审计数据。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IdentityAuditProjectionTest extends TestCase
{
    private PDO $database;
    private AuditService $audit;
    private AuditContractHost $host;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporaryRoot = $root . '/.local/tmp/identity-audit-projection-tests';
        if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
            throw new \RuntimeException('IDENTITY_AUDIT_TEST_DIRECTORY_UNAVAILABLE');
        }
        $temporary = realpath($temporaryRoot);
        self::assertIsString($temporary);
        self::assertStringStartsWith($root . '/.local/tmp/', $temporary);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($temporary . '/audit-projection-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2026-09-26 00:00:00.000', 1);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_platform_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT, action TEXT, outcome TEXT, reason_code TEXT, operator_id INTEGER, account_id INTEGER, target_type TEXT, target_id TEXT, request_id TEXT, operation_id TEXT, ip_address TEXT, user_agent_hash TEXT, before_json TEXT, after_json TEXT, metadata_json TEXT, occurred_at TEXT);
            CREATE TABLE pa_tenant_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, action TEXT, outcome TEXT, reason_code TEXT, actor_tenant_id INTEGER, actor_tenant_member_id INTEGER, actor_account_id INTEGER, actor_platform_operator_id INTEGER, actor_type TEXT, target_resource_type TEXT, target_resource_id TEXT, boundary_target_type TEXT, boundary_target_id TEXT, target_count INTEGER, target_set_digest TEXT, authorization_basis_json TEXT, request_id TEXT, operation_id TEXT, ip_address TEXT, user_agent_hash TEXT, before_json TEXT, after_json TEXT, metadata_json TEXT, occurred_at TEXT);
            SQL);
        $this->audit = new AuditService();
        $this->host = new AuditContractHost(null, $this->audit);
    }

    public function testHostTenantProjectionPreservesJsonStructureNullsAndRedactsNestedSecrets(): void
    {
        $this->host->recordTenantSystem(1, 'test.event', 'test.operation', 'request-one', [
            'safe' => ['ids' => [1, 2], 'label' => '中文'],
            'password' => 'synthetic-password',
            'nested' => ['token' => 'synthetic-token'],
        ]);
        $row = $this->database->query('SELECT * FROM pa_tenant_audit_event')->fetch(PDO::FETCH_ASSOC);
        $document = json_decode($row['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(['ids' => [1, 2], 'label' => '中文'], $document['safe']);
        self::assertStringNotContainsString('synthetic-password', $row['metadata_json']);
        self::assertStringNotContainsString('synthetic-token', $row['metadata_json']);
        self::assertNull($row['before_json']);
        self::assertNull($row['after_json']);
        self::assertNull($row['authorization_basis_json']);
        self::assertSame(1, $row['tenant_id']);
        self::assertSame(1, $row['actor_tenant_id']);
    }

    public function testNativeArrayAndHostEncodedPlatformPathsDoNotDoubleEncode(): void
    {
        $this->audit->platform(7, 101, 'native-request', 'native.event', 'native.operation', ['nested' => ['id' => 12]], before: ['status' => 'pending'], after: ['status' => 'active']);
        $this->host->recordPlatform('host.event', 'host.operation', 'host-request', null, null, ['safe' => true, 'secret' => 'synthetic-secret']);
        $rows = $this->database->query('SELECT * FROM pa_platform_audit_event ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['nested' => ['id' => 12]], json_decode($rows[0]['metadata_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['status' => 'pending'], json_decode($rows[0]['before_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['status' => 'active'], json_decode($rows[0]['after_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertTrue(json_decode($rows[1]['metadata_json'], true, 512, JSON_THROW_ON_ERROR)['safe']);
        self::assertStringNotContainsString('synthetic-secret', $rows[1]['metadata_json']);
        self::assertNull($rows[1]['operator_id']);
        self::assertNull($rows[1]['before_json']);
    }

    public function testCrossTenantActorCannotForgeATenantProjection(): void
    {
        $failure = null;
        try {
            $this->host->record(new AuditEvent(
                AuditEvent::TENANT,
                1,
                AuditActor::tenantSystem(2),
                'test.event',
                'test.operation',
                null,
                null,
                new AuditTrace('cross-tenant-test'),
                AuditOutcome::Success,
                null,
            ));
        } catch (DomainException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(DomainException::class, $failure);
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
    }

    public static function invalidPlatformProjection(): array
    {
        return [
            'invalid json' => [['metadataJson' => '{']],
            'scalar json' => [['metadataJson' => '"not-a-document"']],
            'missing request identity' => [['requestId' => '']],
            'unknown outcome' => [['outcome' => 'maybe']],
            'partial operator' => [['operatorId' => 7]],
            'invalid operator' => [['operatorId' => 0, 'accountId' => 101]],
        ];
    }

    #[DataProvider('invalidPlatformProjection')]
    public function testInvalidProjectionIsRejectedBeforePersistence(array $changes): void
    {
        $arguments = array_replace([
            'eventType' => 'test.event', 'action' => 'test.operation', 'outcome' => AuditOutcome::Success->value,
            'reasonCode' => null, 'operatorId' => null, 'accountId' => null,
            'targetType' => null, 'targetId' => null, 'requestId' => 'projection-test',
            'operationId' => null, 'ipAddress' => null, 'userAgentHash' => null,
            'beforeJson' => null, 'afterJson' => null, 'metadataJson' => '{"safe":true}',
        ], $changes);
        $failure = null;
        try {
            $this->audit->appendPlatformEvent(...$arguments);
        } catch (DomainException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(DomainException::class, $failure);
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }
}
