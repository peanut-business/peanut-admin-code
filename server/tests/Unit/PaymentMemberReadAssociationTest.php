<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\model\TenantOwnedModel;
use app\common\services\XlsxExportService;
use app\common\tenancy\MultiTenantDataScopePolicy;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Member\Contract\MemberBalanceCommands;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use PeanutAdmin\Modules\Payment\Service\RechargeAdministrationService;
use PeanutAdmin\Modules\Payment\Service\RefundApplicationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\App;
use think\DbManager;
use think\Model;

/** 实际列表/计数/统计/导出选行用ORM与SQLite；文件存储为明确替身，不测试真实退款或文件写入。 */
final class PaymentMemberReadAssociationTest extends TestCase
{
    private PDO $pdo;
    private PaymentReadConnection $connection;
    private ExecutionContextStore $contexts;
    private RechargeAdministrationService $recharge;
    private RefundApplicationService $refund;
    private array $exportRows = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($root . '/.local/tmp/payment-read-association');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('if', static fn(mixed $value, mixed $yes, mixed $no): mixed => $value ? $yes : $no, 3);
        $this->connection = new PaymentReadConnection($this->pdo);
        $manager = new \SharedPdoDbManager($this->connection);
        $this->connection->setDb($manager);
        $app->instance(DbManager::class, $manager);
        $this->contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($this->contexts);
        $policy = new MultiTenantDataScopePolicy($current);
        Model::maker(static function (Model $model) use ($policy): void {
            if ($model instanceof TenantOwnedModel) {
                $model->setDataScopePolicy($policy);
            }
        });
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pa_member (id INTEGER PRIMARY KEY,tenant_id INTEGER,sn TEXT,nickname TEXT,mobile TEXT,account TEXT,avatar TEXT,password TEXT,secret_key TEXT,delete_time INTEGER);
            INSERT INTO pa_member VALUES (11,101,'U11','Alice','111','alice','a.png','private-password-a','private-key-a',NULL),(12,101,'U12','Bob','222','bob','b.png','private-password-b','private-key-b',100),(21,202,'U21','Other','999','other','o.png','other-secret','other-key',NULL);
            CREATE TABLE pa_recharge_order (id INTEGER PRIMARY KEY,tenant_id INTEGER,user_id INTEGER,sn TEXT,order_amount TEXT,pay_way INTEGER,pay_time INTEGER,pay_status INTEGER,create_time INTEGER,refund_status INTEGER,delete_time INTEGER);
            INSERT INTO pa_recharge_order VALUES (1,101,11,'R1','10.00',2,100,1,100,0,NULL),(2,101,12,'R2','20.00',2,101,1,101,0,NULL),(3,101,11,'R3','30.00',3,0,0,102,0,NULL),(4,101,21,'R4','90.00',2,103,1,103,0,NULL),(5,202,21,'R5','50.00',2,104,1,104,0,NULL),(6,101,99,'R6','60.00',2,105,1,105,0,NULL),(7,101,11,'R7','70.00',2,106,1,106,0,100);
            CREATE TABLE pa_refund_record (id INTEGER PRIMARY KEY,tenant_id INTEGER,user_id INTEGER,order_id INTEGER,order_type TEXT,sn TEXT,order_sn TEXT,refund_amount TEXT,refund_way INTEGER,refund_type INTEGER,refund_status INTEGER,create_time INTEGER,refund_msg TEXT,delete_time INTEGER);
            INSERT INTO pa_refund_record VALUES (1,101,11,1,'recharge','F1','R1','2.00',1,1,1,100,'private-channel',NULL),(2,101,12,2,'recharge','F2','R2','3.00',1,1,0,101,'private-channel',NULL),(3,101,11,3,'recharge','F3','R3','4.00',1,1,2,102,'private-channel',NULL),(4,101,21,99,'recharge','F4','R4','9.00',1,1,1,103,'private-channel',NULL),(5,202,21,5,'recharge','F5','R5','50.00',1,1,1,104,'other-channel',NULL),(6,101,99,99,'recharge','F6','R6','9.00',1,1,0,105,'private-channel',NULL);
            SQL);
        $files = $this->createStub(FileReferences::class);
        $files->method('getFileUrl')->willReturnCallback(static fn(string $value): string => $value);
        $xlsx = $this->createStub(XlsxExportService::class);
        $xlsx->method('create')->willReturnCallback(function (string $name, array $headings, array $rows): array {
            $this->exportRows = $rows;
            return ['url' => 'fixture://export', 'original_name' => $name];
        });
        $this->recharge = new RechargeAdministrationService(
            $xlsx,
            $this->createStub(IdempotentCommandExecutor::class),
            (new ReflectionClass(\app\common\infrastructure\payment\PaymentRetryLock::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(\app\common\composition\payment\PaymentServiceFactory::class))->newInstanceWithoutConstructor(),
            $files,
            $this->createStub(MemberBalanceCommands::class),
            $this->createStub(MemberQueries::class),
        );
        $this->refund = new RefundApplicationService($files, new AdminDirectoryQuery($current));
    }

    private function context(int $tenant = 101): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'payment-read-test', $tenant, 501, 601, 'admin-web', new DateTimeImmutable('2035-01-01'), 1), 'payment-read-request');
    }

    private function runFor(int $tenant, callable $callback): mixed
    {
        $context = $this->context($tenant);
        return $this->contexts->run(new AdminExecutionContext($context, 'payment.read'), fn() => $callback($context));
    }

    public function testRechargePreservesScopedHistoryStablePagingAndMinimalFields(): void
    {
        $first = $this->runFor(101, fn($context) => $this->recharge->lists($context, ['page_no' => 1, 'page_size' => 2]));
        self::assertSame(3, $first->total);
        self::assertSame([3, 2], array_column($first->items, 'id'));
        self::assertSame(['Alice', 'Bob'], array_column($first->items, 'nickname'));
        $second = $this->runFor(101, fn($context) => $this->recharge->lists($context, ['page_no' => 2, 'page_size' => 2]));
        self::assertSame([1], array_column($second->items, 'id'));
        self::assertSame('8.00', $second->items[0]['refundable_amount']);
        $other = $this->runFor(202, fn($context) => $this->recharge->lists($context, []));
        self::assertSame([5], array_column($other->items, 'id'));
        $json = json_encode($first->items, JSON_THROW_ON_ERROR);
        foreach (['password', 'secret_key', 'mobile', 'private-', 'Other'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
    }

    public function testMemberFiltersAndExportUseTheSameDatabaseScopeWithoutMemberWrites(): void
    {
        $before = $this->pdo->query('SELECT * FROM pa_member ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $selected = $this->runFor(101, fn($context) => $this->recharge->lists($context, ['user_info' => '222', 'page_no' => 1, 'page_size' => 1]));
        self::assertSame(1, $selected->total);
        self::assertSame([2], array_column($selected->items, 'id'));
        $export = $this->runFor(101, fn($context) => $this->recharge->lists($context, ['export' => 2, 'page_type' => 1, 'page_size' => 1, 'page_start' => 2, 'page_end' => 2]));
        self::assertSame('fixture://export', $export['url']);
        self::assertCount(1, $this->exportRows);
        self::assertSame('R2', $this->exportRows[0][0]);
        self::assertSame('Bob', $this->exportRows[0][1]);
        self::assertSame($before, $this->pdo->query('SELECT * FROM pa_member ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $sql = implode("\n", $this->connection->sql);
        self::assertDoesNotMatchRegularExpression('/(?:UPDATE|DELETE FROM|INSERT INTO)\s+[`"]?pa_member/i', $sql);
        self::assertDoesNotMatchRegularExpression('/SELECT\s+[^\n]*(?:u\.\*|u\.(?:password|secret_key))/i', $sql);
    }

    public function testRefundFilteredTotalAndAllStatusCountersRemainDistinct(): void
    {
        $page = $this->runFor(101, fn($context) => $this->refund->lists($context, ['refund_status' => 1, 'page_size' => 1]));
        self::assertSame(1, $page->total);
        self::assertSame([1], array_column($page->items, 'id'));
        $body = $page->responseData();
        self::assertSame(['total' => 3, 'ing' => 1, 'success' => 1, 'error' => 1], $body['extend']);
        self::assertArrayNotHasKey('refund_msg', $page->items[0]);
        $bob = $this->runFor(101, fn($context) => $this->refund->lists($context, ['user_info' => 'bob']));
        self::assertSame([2], array_column($bob->items, 'id'));
        $stat = $this->runFor(101, fn($context) => $this->refund->stat($context));
        self::assertSame(['total' => 27.0, 'ing' => 12.0, 'success' => 11.0, 'error' => 4.0], $stat);
    }

    public function testMoreRowsDoNotAddPerMemberQueries(): void
    {
        $count = function (int $pageSize): int {
            $this->connection->sql = [];
            $this->runFor(101, fn($context) => $this->recharge->lists($context, ['page_size' => $pageSize]));
            return count(array_filter($this->connection->sql, static fn(string $sql): bool => str_starts_with(strtoupper(ltrim($sql)), 'SELECT ') && str_contains($sql, 'pa_member')));
        };
        $count(1); // Warm only schema metadata, not application data.
        $one = $count(1);
        self::assertGreaterThan(0, $one);
        self::assertSame($one, $count(3));
        foreach ($this->connection->sql as $sql) {
            if (str_starts_with(strtoupper(ltrim($sql)), 'SELECT ') && str_contains($sql, 'pa_member')) {
                self::assertStringContainsString(' JOIN ', $sql);
            }
        }
    }

    public function testMissingExecutionContextAndUnsupportedRefundExportRemainFailures(): void
    {
        try {
            $this->recharge->lists($this->context(), []);
            self::fail('Read without trusted execution context accepted.');
        } catch (\DomainException|\LogicException) {
            self::assertTrue(true);
        }
        $this->expectException(\app\common\exception\BusinessException::class);
        $this->runFor(101, fn($context) => $this->refund->lists($context, ['export' => 2]));
    }
}

final class PaymentReadConnection extends \think\db\connector\Sqlite
{
    public array $sql = [];
    public function __construct(private readonly PDO $fixture)
    {
        parent::__construct(['type' => 'sqlite', 'builder' => \think\db\builder\Sqlite::class, 'prefix' => 'pa_']);
    }
    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return $this->fixture;
    }
    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): PDOStatement
    {
        $this->sql[] = $sql;
        return parent::getPDOStatement($sql, $bind, $master, $procedure);
    }
}
