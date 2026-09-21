<?php
declare(strict_types=1);

use app\common\execution\ExecutionContextStore;
use app\Modules\Official\Payment\Application\RefundEnum;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotencyResult;
use app\common\service\payment\contract\RefundGatewayInterface;
use app\modules\official\file\services\FileService;
use app\common\service\payment\PaymentRetryLock;
use app\common\service\payment\PaymentServiceFactory;
use app\common\services\XlsxExportService;
use app\Modules\Official\Member\Contracts\MemberBalanceCommands;
use app\Modules\Official\Payment\Application\RechargeAdministrationService;
use app\Modules\Official\Payment\Model\RechargeOrder;
use app\Modules\Official\Payment\Model\RefundLog;
use app\Modules\Official\Payment\Model\RefundRecord;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\facade\Console;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'app\\')) return;
    $path = dirname(__DIR__, 2) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require_once $path;
}, true, true);

function expectFinanceTenant(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function financeTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT03FINANCE' . str_pad((string)$memberId, 13, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), $requestId);
}

function financePdo(string $host, int $port, string $user, string $password, string $database): PDO
{
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
    );
}

function financeDatabase(PDO $admin, string $name): string
{
    if (preg_match('/^peanut_admin_development_p0e_[a-z0-9]{1,11}_consumer_module_cycle$/D', $name) !== 1) {
        throw new RuntimeException('refund test database is outside the registered P0-E namespace');
    }
    $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $name;
}

function financeRun(TenantContext $context, string $operation, callable $callback): mixed
{
    return app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, $operation),
        $callback,
    );
}

function financeReconcile(TenantContext $context): void
{
    $scope = TenantScope::fromTrustedContext($context->tenantId, 'refund-test:' . $context->requestId);
    financeRun($context, 'test.refund.reconcile', static fn() => ScheduledTenantContext::run(
        $scope,
        static fn() => Console::call('refund:reconcile'),
    ));
}

function financeRefundService(
    XlsxExportService $xlsx,
    IdempotentCommandExecutor $idempotency,
): RechargeAdministrationService {
    return new RechargeAdministrationService(
        $xlsx,
        $idempotency,
        app(PaymentRetryLock::class),
        app(PaymentServiceFactory::class),
        app(FileService::class),
        app(MemberBalanceCommands::class),
    );
}

final class RefundBehaviorGateway implements RefundGatewayInterface
{
    /** @var array<string,string> */
    private static array $modes = [];
    /** @var array<string,list<string>> */
    public static array $refundKeys = [];
    /** @var array<string,list<string>> */
    public static array $queryKeys = [];
    /** @var array<string,int> */
    public static array $effects = [];

    public static function mode(string $orderSn, string $mode): void
    {
        self::$modes[$orderSn] = $mode;
    }

    public function refund(array $order, string $refundSn, int $refundAmountCents): array
    {
        $orderSn = (string)($order['sn'] ?? '');
        self::$refundKeys[$orderSn][] = $refundSn;
        $attempt = count(self::$refundKeys[$orderSn]);
        $mode = self::$modes[$orderSn] ?? 'success';
        if ($mode === 'accepted_unknown') {
            self::$effects[$refundSn] = (self::$effects[$refundSn] ?? 0) + 1;
            throw new RuntimeException('provider accepted before transport failure', self::ERROR_RESULT_UNKNOWN);
        }
        if ($mode === 'known_failure_then_success' && $attempt === 1) {
            throw new RuntimeException('provider rejected refund');
        }
        self::$effects[$refundSn] = (self::$effects[$refundSn] ?? 0) + 1;
        return [
            'status' => self::STATUS_SUCCESS,
            'transaction_id' => 'provider-' . $refundSn,
            'receipt' => ['refund_sn' => $refundSn],
        ];
    }

    public function query(array $order, string $refundSn): array
    {
        $orderSn = (string)($order['sn'] ?? '');
        self::$queryKeys[$orderSn][] = $refundSn;
        return [
            'status' => self::STATUS_SUCCESS,
            'transaction_id' => 'provider-' . $refundSn,
            'receipt' => ['refund_sn' => $refundSn],
        ];
    }
}

final class RefundBehaviorPaymentFactory
{
    public static function forTenant(object $context, string $channel, mixed $transport = null): self
    {
        return new self();
    }

    public function refund(string $channel): RefundGatewayInterface
    {
        return new RefundBehaviorGateway();
    }
}

final readonly class FailingRefundIdempotency implements IdempotentCommandExecutor
{
    public function __construct(private IdempotentCommandExecutor $delegate) {}

    public function begin(IdempotencyCommand $command): IdempotencyResult
    {
        return $this->delegate->begin($command);
    }

    public function complete(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
    {
        throw new RuntimeException('fixture receipt finalize failure');
    }

    public function fail(IdempotencyResult $execution, IdempotencyReceipt $receipt): void
    {
        throw new RuntimeException('fixture receipt finalize failure');
    }
}

if (!class_alias(RefundBehaviorPaymentFactory::class, 'app\\common\\service\\payment\\PaymentServiceFactory')) {
    throw new RuntimeException('cannot install fake refund Provider factory');
}

function createFinanceTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL, code VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
CREATE TABLE pa_member (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, sn VARCHAR(20) NOT NULL DEFAULT '',
  account VARCHAR(50) NOT NULL DEFAULT '', account_unique VARCHAR(50) GENERATED ALWAYS AS (NULLIF(account,'')) STORED,
  password VARCHAR(100) NOT NULL DEFAULT '', nickname VARCHAR(50) NOT NULL DEFAULT '',
  avatar VARCHAR(255) NOT NULL DEFAULT '', real_name VARCHAR(32) NOT NULL DEFAULT '',
  mobile VARCHAR(20) NOT NULL DEFAULT '', mobile_unique VARCHAR(20) GENERATED ALWAYS AS (NULLIF(mobile,'')) STORED,
  channel TINYINT UNSIGNED NOT NULL DEFAULT 0, email VARCHAR(100) NOT NULL DEFAULT '', sex TINYINT NOT NULL DEFAULT 0,
  birthday DATE NULL, status TINYINT NOT NULL DEFAULT 1, login_time INT UNSIGNED NOT NULL DEFAULT 0,
  login_ip VARCHAR(45) NOT NULL DEFAULT '', is_new_user TINYINT NOT NULL DEFAULT 0,
  user_money DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
  total_recharge_amount DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0, points INT UNSIGNED NOT NULL DEFAULT 0,
  create_time INT UNSIGNED NOT NULL DEFAULT 0, update_time INT UNSIGNED NOT NULL DEFAULT 0, delete_time INT UNSIGNED NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uk_member_tenant_id (tenant_id, id),
  UNIQUE KEY uk_member_tenant_sn (tenant_id, sn), UNIQUE KEY uk_member_tenant_account (tenant_id, account_unique),
  UNIQUE KEY uk_member_tenant_mobile (tenant_id, mobile_unique),
  CONSTRAINT fk_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_member_balance_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, sn VARCHAR(32) NOT NULL DEFAULT '', member_id INT UNSIGNED NOT NULL DEFAULT 0,
  change_object TINYINT UNSIGNED NOT NULL DEFAULT 1, change_type SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  action TINYINT UNSIGNED NOT NULL DEFAULT 1, left_amount DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
  source_type TINYINT NOT NULL DEFAULT 0, extra TEXT NULL, admin_id INT UNSIGNED NOT NULL DEFAULT 0,
  create_time INT UNSIGNED NOT NULL DEFAULT 0, update_time INT UNSIGNED NULL, delete_time INT UNSIGNED NULL,
  change_amount DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0, source_sn VARCHAR(255) NULL,
  source_sn_unique VARCHAR(255) GENERATED ALWAYS AS (NULLIF(source_sn,'')) STORED,
  remark VARCHAR(255) NULL DEFAULT '', tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uk_member_balance_log_tenant_id (tenant_id, id),
  UNIQUE KEY uk_member_balance_log_tenant_sn (tenant_id, sn),
  UNIQUE KEY uk_member_balance_log_tenant_source (tenant_id, source_sn_unique),
  KEY idx_member_balance_log_tenant_member_time (tenant_id, member_id, create_time, id),
  CONSTRAINT fk_member_balance_log_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT,
  CONSTRAINT fk_member_balance_log_member FOREIGN KEY (tenant_id, member_id) REFERENCES pa_member (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_recharge_order (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED NOT NULL DEFAULT 0,
  pay_status TINYINT NOT NULL DEFAULT 0, order_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  order_terminal TINYINT NULL DEFAULT 1, refund_status TINYINT NOT NULL DEFAULT 0,
  refund_transaction_id VARCHAR(255) NULL, delete_time INT UNSIGNED NULL,
  sn VARCHAR(64) NOT NULL, pay_way TINYINT NOT NULL DEFAULT 2, pay_time INT UNSIGNED NULL,
  create_time INT UNSIGNED NULL, update_time INT UNSIGNED NULL, pay_sn VARCHAR(255) NULL,
  transaction_id VARCHAR(128) NULL, tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uk_sn (sn), UNIQUE KEY uk_pay_sn (pay_sn),
  UNIQUE KEY uk_transaction_id (transaction_id), UNIQUE KEY uk_recharge_order_tenant_id (tenant_id, id),
  KEY idx_recharge_order_tenant_member_time (tenant_id, user_id, create_time, id),
  CONSTRAINT fk_recharge_order_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT,
  CONSTRAINT fk_recharge_order_member FOREIGN KEY (tenant_id, user_id) REFERENCES pa_member (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_refund_record (
  id INT NOT NULL AUTO_INCREMENT, order_type VARCHAR(255) NULL DEFAULT 'order',
  order_amount DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0, refund_amount DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
  transaction_id VARCHAR(255) NULL, refund_way TINYINT NOT NULL DEFAULT 1, refund_type TINYINT NOT NULL DEFAULT 1,
  refund_status TINYINT UNSIGNED NOT NULL DEFAULT 0, refund_msg TEXT NULL,
  create_time INT UNSIGNED NULL DEFAULT 0, update_time INT NULL, sn VARCHAR(32) NOT NULL DEFAULT '',
  order_sn VARCHAR(64) NOT NULL, tenant_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0, order_id INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id), UNIQUE KEY uk_sn (sn), UNIQUE KEY uk_refund_record_tenant_id (tenant_id, id),
  UNIQUE KEY uk_refund_record_tenant_order (tenant_id, order_type, order_id),
  UNIQUE KEY uk_refund_record_order_global (order_type, order_id),
  KEY idx_refund_record_tenant_status_time (tenant_id, refund_status, create_time, id),
  CONSTRAINT fk_refund_record_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_record_member FOREIGN KEY (tenant_id, user_id) REFERENCES pa_member (tenant_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_record_order FOREIGN KEY (tenant_id, order_id) REFERENCES pa_recharge_order (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_refund_log (
  id INT NOT NULL AUTO_INCREMENT, sn VARCHAR(32) NULL, record_id INT NOT NULL,
  handle_id INT NOT NULL DEFAULT 0, order_amount DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
  refund_amount DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0, refund_status TINYINT UNSIGNED NOT NULL DEFAULT 0,
  refund_msg TEXT NULL, create_time INT UNSIGNED NULL DEFAULT 0, update_time INT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id), UNIQUE KEY uk_sn (sn), UNIQUE KEY uk_refund_log_tenant_id (tenant_id, id),
  KEY idx_refund_log_tenant_record_time (tenant_id, record_id, create_time, id),
  CONSTRAINT fk_refund_log_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_log_member FOREIGN KEY (tenant_id, user_id) REFERENCES pa_member (tenant_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_log_record FOREIGN KEY (tenant_id, record_id) REFERENCES pa_refund_record (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_tenant_idempotency_record (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
  tenant_member_id BIGINT UNSIGNED NOT NULL, operation_key VARCHAR(160) NOT NULL,
  idempotency_key_hash CHAR(64) NOT NULL, request_hash CHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL, response_status SMALLINT UNSIGNED NULL,
  response_body_json JSON NULL, resource_type VARCHAR(160) NULL, resource_id VARCHAR(128) NULL,
  expires_at DATETIME(3) NOT NULL, created_at DATETIME(3) NOT NULL, updated_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tenant_idempotency (tenant_id, tenant_member_id, operation_key, idempotency_key_hash),
  KEY idx_tenant_idempotency_expiry (expires_at, status, id),
  CONSTRAINT chk_tenant_idempotency_status CHECK (status IN ('processing','completed','failed'))
) ENGINE=InnoDB;
SQL);
}

function seedFinanceTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant (id,code,status) VALUES (101,'alpha','active'),(202,'beta','active');
INSERT INTO pa_member (id,tenant_id,sn,account,nickname,user_money,total_recharge_amount)
VALUES (11,101,'M-ALPHA','alpha','Alpha',100.00,30.00),(22,202,'M-BETA','beta','Beta',20.00,0);
INSERT INTO pa_recharge_order
  (id,tenant_id,sn,user_id,pay_sn,pay_way,pay_status,pay_time,order_amount,order_terminal,transaction_id,refund_status,create_time)
VALUES (21,101,'RC-ALPHA',11,'PY-ALPHA',2,1,1700000000,10.00,3,'TX-ALPHA',1,1700000000),
       (23,101,'RC-UNKNOWN',11,'PY-UNKNOWN',2,1,1700000000,10.00,3,'TX-UNKNOWN',0,1700000000),
       (24,101,'RC-RETRY',11,'PY-RETRY',2,1,1700000000,10.00,3,'TX-RETRY',0,1700000000),
       (25,101,'RC-FINALIZE',11,'PY-FINALIZE',2,1,1700000000,10.00,3,'TX-FINALIZE',0,1700000000);
INSERT INTO pa_refund_record
  (id,tenant_id,sn,user_id,order_id,order_sn,order_type,order_amount,refund_amount,transaction_id,refund_way,refund_type,refund_status,refund_msg)
VALUES (31,101,'RF-ALPHA',11,21,'RC-ALPHA','recharge',10.00,10.00,'TX-ALPHA',1,1,2,'');
INSERT INTO pa_refund_log
  (id,tenant_id,sn,record_id,user_id,handle_id,order_amount,refund_amount,refund_status,refund_msg)
VALUES (41,101,'RL-ALPHA',31,11,1,10.00,10.00,2,'');
SQL);
}

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int)IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = financeDatabase($admin, IsolatedBackendEnvironment::required('DB_NAME'));

try {
    $pdo = financePdo($host, $port, $user, $password, $database);
    createFinanceTenantSchema($pdo);
    seedFinanceTenantSchema($pdo);
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App(); $app->initialize();
    $alpha = financeTenantContext(101, 501, 'mt03-finance-alpha');
    $beta = financeTenantContext(202, 502, 'mt03-finance-beta');
    financeRun($alpha, 'test.refund.seed.alpha', static function (): void {
        expectFinanceTenant(!RefundRecord::where([])->where('id', 31)->findOrEmpty()->isEmpty(), 'Alpha refund record disappeared');
        expectFinanceTenant(!RefundLog::where([])->where('id', 41)->findOrEmpty()->isEmpty(), 'Alpha refund log disappeared');
    });
    financeRun($beta, 'test.refund.seed.beta', static function (): void {
        expectFinanceTenant(RefundRecord::where([])->where('id', 31)->findOrEmpty()->isEmpty(), 'Beta read Alpha refund record');
        expectFinanceTenant(RefundLog::where([])->where('id', 41)->findOrEmpty()->isEmpty(), 'Beta read Alpha refund log');
    });

    $betaOrder = financeRun($beta, 'test.refund.beta-order', static fn() => RechargeOrder::create([
        'tenant_id' => 101,
        'sn' => 'RC-BETA-22', 'user_id' => 22, 'pay_sn' => null, 'pay_way' => 2,
        'pay_status' => RechargeOrder::PAY_STATUS_UNPAID, 'order_amount' => '5.00',
        'order_terminal' => 3, 'transaction_id' => null, 'refund_status' => 0,
    ]));
    expectFinanceTenant((int)$betaOrder->tenant_id === 202, 'payload forged order Tenant ownership');
    financeRun($alpha, 'test.refund.beta-order-isolation', static function () use ($betaOrder): void {
        expectFinanceTenant(RechargeOrder::where([])->where('id', (int)$betaOrder->id)->findOrEmpty()->isEmpty(), 'Alpha read Beta order');
    });

    $idempotency = $app->make(IdempotentCommandExecutor::class);
    $xlsx = (new ReflectionClass(XlsxExportService::class))->newInstanceWithoutConstructor();
    $refunds = financeRefundService($xlsx, $idempotency);

    RefundBehaviorGateway::mode('RC-UNKNOWN', 'accepted_unknown');
    $unknown = financeRun($alpha, 'test.refund.accepted-unknown', static fn() => $refunds->refund(
        $alpha,
        ['recharge_id' => 23],
        1,
        'refund-unknown-001',
    ));
    expectFinanceTenant($unknown === [true, '操作成功'], 'accepted Provider result was not kept pending');
    $unknownRecord = financeRun($alpha, 'test.refund.accepted-unknown-record', static fn() => RefundRecord::where([])
        ->where('order_id', 23)->findOrEmpty());
    expectFinanceTenant(!$unknownRecord->isEmpty() && (int)$unknownRecord->refund_status === RefundEnum::REFUND_ING, 'accepted unknown result did not stay ING');
    expectFinanceTenant(
        (string)$pdo->query("SELECT status FROM pa_tenant_idempotency_record WHERE operation_key='recharge.refund.create' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'completed',
        'pending business result and idempotency receipt did not commit together'
    );
    $unknownReplay = financeRun($alpha, 'test.refund.accepted-unknown-replay', static fn() => $refunds->refund(
        $alpha,
        ['recharge_id' => 23],
        1,
        'refund-unknown-001',
    ));
    expectFinanceTenant($unknownReplay === $unknown, 'duplicate refund request did not replay its receipt');
    expectFinanceTenant(
        (RefundBehaviorGateway::$refundKeys['RC-UNKNOWN'] ?? []) === [(string)$unknownRecord->sn]
            && (RefundBehaviorGateway::$effects[(string)$unknownRecord->sn] ?? 0) === 1,
        'accepted unknown refund repeated the external effect or changed its Provider key'
    );
    financeReconcile($alpha);
    $unknownRecord = financeRun($alpha, 'test.refund.accepted-unknown-settled', static fn() => RefundRecord::where([])
        ->where('id', (int)$unknownRecord->id)->findOrEmpty());
    expectFinanceTenant((int)$unknownRecord->refund_status === RefundEnum::REFUND_SUCCESS, 'reconcile did not settle accepted refund');
    expectFinanceTenant(
        (RefundBehaviorGateway::$queryKeys['RC-UNKNOWN'] ?? []) === [(string)$unknownRecord->sn],
        'reconcile did not query with the original RefundRecord SN'
    );

    RefundBehaviorGateway::mode('RC-RETRY', 'known_failure_then_success');
    $failed = financeRun($alpha, 'test.refund.known-failure', static fn() => $refunds->refund(
        $alpha,
        ['recharge_id' => 24],
        1,
        'refund-retry-001',
    ));
    expectFinanceTenant($failed === [false, 'provider rejected refund'], 'known Provider failure result changed');
    $retryRecord = financeRun($alpha, 'test.refund.known-failure-record', static fn() => RefundRecord::where([])
        ->where('order_id', 24)->findOrEmpty());
    expectFinanceTenant((int)$retryRecord->refund_status === RefundEnum::REFUND_ERROR, 'known Provider failure did not settle ERROR');
    $failedReplay = financeRun($alpha, 'test.refund.known-failure-replay', static fn() => $refunds->refund(
        $alpha,
        ['recharge_id' => 24],
        1,
        'refund-retry-001',
    ));
    expectFinanceTenant($failedReplay === $failed, 'failed refund receipt was not replayed');
    $retried = financeRun($alpha, 'test.refund.retry', static fn() => $refunds->refundAgain(
        $alpha,
        ['record_id' => (int)$retryRecord->id],
        1,
    ));
    expectFinanceTenant($retried === [true, '操作成功'], 'failed refund retry did not succeed');
    expectFinanceTenant(
        (RefundBehaviorGateway::$refundKeys['RC-RETRY'] ?? []) === [(string)$retryRecord->sn, (string)$retryRecord->sn]
            && (RefundBehaviorGateway::$effects[(string)$retryRecord->sn] ?? 0) === 1,
        'refund retry changed the Provider key or duplicated the external effect'
    );
    expectFinanceTenant(
        financeRun($alpha, 'test.refund.retry-logs', static fn() => (int)RefundLog::where([])
            ->where('record_id', (int)$retryRecord->id)->count()) === 2,
        'refund retry did not append exactly one local attempt log'
    );

    RefundBehaviorGateway::mode('RC-FINALIZE', 'success');
    $failingRefunds = financeRefundService(
        $xlsx,
        new FailingRefundIdempotency($idempotency),
    );
    $finalizeFailure = financeRun($alpha, 'test.refund.finalize-failure', static fn() => $failingRefunds->refund(
        $alpha,
        ['recharge_id' => 25],
        1,
        'refund-finalize-001',
    ));
    expectFinanceTenant($finalizeFailure[0] === false, 'fixture receipt finalize failure was hidden');
    $finalizeRecord = financeRun($alpha, 'test.refund.finalize-failure-record', static fn() => RefundRecord::where([])
        ->where('order_id', 25)->findOrEmpty());
    $finalizeLog = financeRun($alpha, 'test.refund.finalize-failure-log', static fn() => RefundLog::where([])
        ->where('record_id', (int)$finalizeRecord->id)->order('id', 'desc')->findOrEmpty());
    $finalizeOrder = financeRun($alpha, 'test.refund.finalize-failure-order', static fn() => RechargeOrder::where([])
        ->where('id', 25)->findOrEmpty());
    expectFinanceTenant(
        (int)$finalizeRecord->refund_status === RefundEnum::REFUND_ING
            && (int)$finalizeLog->refund_status === RefundEnum::REFUND_ING
            && trim((string)$finalizeOrder->refund_transaction_id) === ''
            && (string)$pdo->query("SELECT status FROM pa_tenant_idempotency_record WHERE operation_key='recharge.refund.create' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'processing',
        'business result committed without its idempotency receipt'
    );
    expectFinanceTenant(
        (RefundBehaviorGateway::$refundKeys['RC-FINALIZE'] ?? []) === [(string)$finalizeRecord->sn]
            && (RefundBehaviorGateway::$effects[(string)$finalizeRecord->sn] ?? 0) === 1,
        'finalize failure changed the Provider key or repeated the external effect'
    );
    financeReconcile($alpha);
    $finalizeRecord = financeRun($alpha, 'test.refund.finalize-failure-settled', static fn() => RefundRecord::where([])
        ->where('id', (int)$finalizeRecord->id)->findOrEmpty());
    expectFinanceTenant(
        (int)$finalizeRecord->refund_status === RefundEnum::REFUND_SUCCESS
            && (RefundBehaviorGateway::$queryKeys['RC-FINALIZE'] ?? []) === [(string)$finalizeRecord->sn],
        'reconcile did not recover the rolled-back local Provider result with the stable key'
    );

    $alphaRecordIds = [(int)$unknownRecord->id, (int)$retryRecord->id, (int)$finalizeRecord->id];
    expectFinanceTenant(
        financeRun($beta, 'test.refund.behavior-isolation', static fn() => (int)RefundRecord::where([])
            ->whereIn('id', $alphaRecordIds)->count()) === 0,
        'Beta read Alpha refund behavior records'
    );
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}

echo "MT03-RECHARGE-REFUND-TENANT-001 passed\n";
