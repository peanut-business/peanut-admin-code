<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblem;
use app\common\http\middleware\MaintenanceWriteGateMiddleware;
use app\common\services\audit\AuditContractHost;
use app\common\services\readiness\FirstRunReadinessHost;
use PDO;
use PeanutAdmin\Modules\Ops\Contract\InstanceSafetyQueries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use think\App;
use think\Request;

/** Native query, middleware, audit and problem code in memory only; no real maintenance or backup. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstanceSafetyBoundaryTest extends TestCase
{
    private PDO $database;
    private InstanceSafetyQueries $queries;
    private MaintenanceWriteGateMiddleware $gate;
    private string $databaseClock = '2031-01-01 12:00:00.000';
    private bool $continued = false;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/instance-safety-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new \RuntimeException('INSTANCE_SAFETY_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($temporary . '/case-' . bin2hex(random_bytes(5)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->sqliteCreateFunction('UTC_TIMESTAMP', fn(int $precision): string => $this->databaseClock, 1);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_ops_maintenance_window (id INTEGER PRIMARY KEY, maintenance_key TEXT, reason_key TEXT, state TEXT, starts_at TEXT, ends_at TEXT, private_payload TEXT);
            CREATE TABLE pa_ops_backup_evidence (id INTEGER PRIMARY KEY, verified_at TEXT, secret_path TEXT);
            CREATE TABLE pa_platform_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT, action TEXT, outcome TEXT, reason_code TEXT, operator_id INTEGER, account_id INTEGER, target_type TEXT, target_id TEXT, request_id TEXT, operation_id TEXT, ip_address TEXT, user_agent_hash TEXT, before_json TEXT, after_json TEXT, metadata_json TEXT, occurred_at TEXT);
            SQL);
        $this->queries = $app->make(InstanceSafetyQueries::class);
        $current = new CurrentExecutionContext(new ExecutionContextStore());
        $this->gate = new MaintenanceWriteGateMiddleware(new AuditContractHost($current), $current, $this->queries);
    }

    private function window(int $id = 1, string $state = 'active', string $start = '2031-01-01 12:00:00.000', string $end = '2031-01-01 13:00:00.000'): void
    {
        $this->database->prepare('INSERT INTO pa_ops_maintenance_window VALUES (?,?,?,?,?,?,?)')->execute([
            $id, 'maintenance_' . str_pad((string) $id, 32, '0', STR_PAD_LEFT), 'planned-maintenance', $state, $start, $end, 'private-fixture-value',
        ]);
    }

    private function invoke(string $method = 'POST', string $path = 'api/example'): mixed
    {
        $request = (new Request())->setMethod($method)->setPathinfo($path)->withHeader(['X-Request-Id' => 'instance-safety-fixture']);
        return $this->gate->handle($request, function (): string {
            $this->continued = true;
            return 'continued';
        });
    }

    private function failure(string $code, string $method = 'POST', string $path = 'api/example'): void
    {
        try {
            $this->invoke($method, $path);
            self::fail('The mutation unexpectedly reached its handler.');
        } catch (ApiProblem $problem) {
            self::assertSame($code, $problem->errorCode);
            self::assertSame(503, $problem->httpStatus);
            self::assertSame('no-store', $problem->headers['Cache-Control']);
            self::assertSame('instance-safety-fixture', $problem->headers['X-Request-Id']);
        }
        self::assertFalse($this->continued);
    }

    public function testHostDependenciesUseOnlyThePublishedQuery(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/ops/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(InstanceSafetyQueries::class, $manifest['contracts']['exports']);
        foreach ([MaintenanceWriteGateMiddleware::class, FirstRunReadinessHost::class] as $type) {
            $source = file_get_contents((new ReflectionClass($type))->getFileName());
            self::assertStringContainsString('InstanceSafetyQueries $instanceSafety', $source);
            self::assertStringNotContainsString('Db::name(', $source);
        }
    }

    public function testOnlyCurrentWindowsAreReturnedUsingTheDatabaseClockAndLatestId(): void
    {
        self::assertNull($this->queries->blockingMaintenanceWindow());
        $this->window(1, 'scheduled');
        $this->window(2, 'active', '2031-01-01 11:00:00.000', '2031-01-01 12:00:00.000');
        $this->window(3, 'closed');
        $this->window(4, 'scheduled', '2031-01-01 12:00:00.001');
        $window = $this->queries->blockingMaintenanceWindow();
        self::assertSame(['maintenance_key', 'reason_key'], array_keys($window));
        self::assertSame('maintenance_' . str_pad('1', 32, '0', STR_PAD_LEFT), $window['maintenance_key']);
        $this->databaseClock = '2031-01-01 12:00:00.001';
        self::assertSame('maintenance_' . str_pad('4', 32, '0', STR_PAD_LEFT), $this->queries->blockingMaintenanceWindow()['maintenance_key']);
        $this->databaseClock = '2031-01-01 13:00:00.000';
        self::assertNull($this->queries->blockingMaintenanceWindow());
    }

    public static function mutations(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    #[DataProvider('mutations')]
    public function testEachMutationIsRejectedAndAudited(string $method): void
    {
        $this->window();
        $this->failure('MAINTENANCE_WRITE_BLOCKED', $method);
        $rows = $this->database->query('SELECT * FROM pa_platform_audit_event')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame('platform.maintenance.write-blocked', $rows[0]['event_type']);
        self::assertSame('MAINTENANCE_WRITE_BLOCKED', $rows[0]['reason_code']);
        $metadata = json_decode($rows[0]['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($method, $metadata['request_method']);
        self::assertStringNotContainsString('private-fixture-value', $rows[0]['metadata_json']);
    }

    public function testReadsAndExactControlRoutesDoNotDependOnTheMaintenanceStore(): void
    {
        $this->database->exec('DROP TABLE pa_ops_maintenance_window');
        foreach ([['GET', 'api/example'], ['HEAD', 'api/example'], ['PUT', 'v1/ops/maintenance'], ['POST', 'v1/ops/maintenance/maintenance_' . str_repeat('a', 32) . '/close']] as [$method, $path]) {
            self::assertSame('continued', $this->invoke($method, $path));
        }
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    public function testNearMatchIsNotAControlRouteAndStoreFailureRejectsTheWrite(): void
    {
        $this->database->exec('DROP TABLE pa_ops_maintenance_window');
        $this->failure('MAINTENANCE_GATE_UNAVAILABLE', 'POST', 'v1/ops/maintenance/maintenance_' . str_repeat('a', 32) . '/close/extra');
    }

    public function testAuditFailureDoesNotLetAWritingRequestThrough(): void
    {
        $this->window();
        $this->database->exec('DROP TABLE pa_platform_audit_event');
        $this->failure('MAINTENANCE_GATE_UNAVAILABLE');
    }

    public function testNoMaintenanceContinuesWithoutAudit(): void
    {
        self::assertSame('continued', $this->invoke());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    public function testBackupProjectionNeverClaimsRestoreOrProductionReadiness(): void
    {
        $host = (new ReflectionClass(FirstRunReadinessHost::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($host, 'instanceSafety'))->setValue($host, $this->queries);
        $backup = new ReflectionMethod($host, 'backup');
        $empty = $backup->invoke($host, 'saas');
        self::assertSame('action_required', $empty['status']);
        self::assertTrue($empty['production_blocking']);
        $this->database->exec("INSERT INTO pa_ops_backup_evidence VALUES (1,'2031-01-01 00:00:00.123','private-path'),(2,'2030-12-31 00:00:00.000','private-path')");
        self::assertSame('2031-01-01 00:00:00.123', $this->queries->lastVerifiedBackupAt());
        $present = $backup->invoke($host, 'saas');
        self::assertSame('unverified', $present['status']);
        self::assertTrue($present['production_blocking']);
        self::assertStringNotContainsString('private-path', json_encode($present, JSON_THROW_ON_ERROR));
        $this->database->exec('DROP TABLE pa_ops_backup_evidence');
        $missing = $backup->invoke($host, 'saas');
        self::assertSame('action_required', $missing['status']);
        self::assertTrue($missing['production_blocking']);
    }
}
