<?php

declare(strict_types=1);

use app\adminapi\services\log\OperationLogApplicationService;
use app\adminapi\services\OperationLogService;
use app\common\contract\audit\AuditEvent;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\audit\OperationLogProjection;
use app\common\model\log\OperationLog;
use app\common\model\TenantOwnedModel;
use app\common\policy\audit\RedactionPolicy;
use app\common\services\XlsxExportService;
use app\common\tenancy\MultiTenantDataScopePolicy;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\Model;

/** Actual write projection and query/export consumers on memory-only SQLite. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OperationLogRedactionBoundaryTest extends TestCase
{
    private PDO $pdo;
    private ExecutionContextStore $contexts;
    private CurrentExecutionContext $current;
    private TenantContext $actor;

    protected function setUp(): void
    {
        $root = dirname(__DIR__,3);
        require_once $root.'/server/vendor/topthink/framework/src/helper.php';
        new think\App($root.'/.local/tmp/operation-log-redaction');
        $this->pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->pdo->exec('CREATE TABLE pa_operation_log(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,admin_id INTEGER,username TEXT,ip TEXT,uri TEXT,method TEXT,request_id TEXT,params TEXT,create_time INTEGER)');
        $this->contexts = new ExecutionContextStore();
        $this->current = new CurrentExecutionContext($this->contexts);
        $scope = new MultiTenantDataScopePolicy($this->current);
        Model::maker(static function(Model $model) use($scope): void {
            if ($model instanceof TenantOwnedModel) { $model->setDataScopePolicy($scope); }
        });
        $this->actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(1,'log-redaction',101,501,601,'admin-web',new DateTimeImmutable('2035-01-01'),1),'log-redaction-request');
    }

    private function params(): array
    {
        return ['password'=>'synthetic-password','password_confirm'=>'synthetic-confirm','jobs_code'=>'visible-job',
            'public_key'=>'visible-public-key','nested'=>[['token'=>'synthetic-token','signature'=>'synthetic-signature'],
            ['X-Peanut-Signature'=>'synthetic-webhook-signature']]];
    }

    private function assertSafe(string $encoded): void
    {
        foreach (['synthetic-password','synthetic-confirm','synthetic-token','synthetic-signature','synthetic-webhook-signature'] as $secret) {
            self::assertStringNotContainsString($secret,$encoded);
        }
        $params = json_decode($encoded,true,512,JSON_THROW_ON_ERROR);
        self::assertSame('visible-job',$params['jobs_code']);
        self::assertSame('visible-public-key',$params['public_key']);
        self::assertSame('******',$params['nested'][0]['signature']);
    }

    private function runAs(callable $operation): mixed
    {
        return $this->contexts->run(new AdminExecutionContext($this->actor,'log.redaction.test',[
            'id'=>601,'tenant_id'=>101,'account_id'=>501,'authorization_revision'=>1,
        ]),$operation);
    }

    public function testTheExistingWriteProjectionRedactsNestedSecretsBeforePersistence(): void
    {
        $this->runAs(fn()=>(new OperationLogProjection($this->current))->append(AuditEvent::operationLog(
            $this->actor,601,'Actor','192.0.2.1','api/v1/files/visibility/batches','POST',$this->params(),
        )));
        $this->assertSafe($this->pdo->query('SELECT params FROM pa_operation_log')->fetchColumn());
        $this->assertSafe(OperationLogService::serializeParams($this->params()));
    }

    public function testListsDetailsAndExportMaskHistoricalJsonWithoutRewritingIt(): void
    {
        $stored = json_encode($this->params(),JSON_THROW_ON_ERROR);
        $insert = $this->pdo->prepare("INSERT INTO pa_operation_log(tenant_id,admin_id,username,ip,uri,method,request_id,params,create_time) VALUES(?,601,'Actor','192.0.2.1','visibility','POST','historic',?,1)");
        $insert->execute([101,$stored]);
        $insert->execute([202,json_encode(['foreign'=>'foreign-tenant-secret'],JSON_THROW_ON_ERROR)]);
        $exports = $this->createMock(XlsxExportService::class);
        $exports->expects(self::once())->method('create')->willReturnCallback(function(string $name,array $headers,array $rows): array {
            self::assertCount(1,$rows);
            $this->assertSafe($rows[0][5]);
            return ['url'=>'fixture-export','original_name'=>'audit.xlsx'];
        });
        $service = new OperationLogApplicationService($exports,(new ReflectionClass(OperationLogService::class))->newInstanceWithoutConstructor());
        $this->runAs(function() use($service): void {
            $list = $service->lists($this->actor,['page_no'=>1,'page_size'=>15]);
            self::assertSame(1,$list->total);
            $row = $list->items[0];
            $this->assertSafe($row instanceof Model ? $row->getAttr('params') : $row['params']);
            $this->assertSafe($service->detail($this->actor,1)['params']);
            self::assertSame('fixture-export',$service->lists($this->actor,['export'=>2])['url']);
        });
        self::assertSame($stored,$this->pdo->query('SELECT params FROM pa_operation_log WHERE id=1')->fetchColumn());
    }

    public function testTheReadAttributePreservesTheExistingStringAndArrayContracts(): void
    {
        $model = new OperationLog();
        $array = $model->getParamsAttr($this->params());
        self::assertIsArray($array);
        self::assertSame(RedactionPolicy::sanitize($this->params()),$array);
        self::assertIsString($model->getParamsAttr(json_encode($this->params(),JSON_THROW_ON_ERROR)));
        self::assertSame(' {"safe":{},"items":[]} ',$model->getParamsAttr(' {"safe":{},"items":[]} '));
        self::assertSame('legacy text',$model->getParamsAttr('legacy text'));
    }
}
