<?php
declare(strict_types=1);

namespace tests\Unit\CrudTraitInput;

use app\BaseController;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\traits\CrudTrait;
use app\common\validate\InputValidator;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\exception\ValidateException;
use think\Request;
use think\Validate;

final class CrudTraitInputContractTest extends TestCase
{
    private Container $previousContainer;
    private App $app;
    private RecordingCrudService $service;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        $this->previousContainer = Container::getInstance();
        $this->app = new App(sys_get_temp_dir() . '/peanut-crud-input-' . bin2hex(random_bytes(6)));
        $store = new ExecutionContextStore();
        $current = new CurrentExecutionContext($store);
        $this->app->instance(App::class, $this->app);
        $this->app->instance(CurrentExecutionContext::class, $current);
        $this->app->instance(InputValidator::class, new InputValidator($this->app, $current));
        $this->service = new RecordingCrudService();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
    }

    public function testWritableProjectionKeepsOnlyStorageFieldsAndExplicitControlKey(): void
    {
        $add = $this->controller((new Request())->withPost([
            'title' => 'hello',
            'workflow_note' => 'validated control only',
            'settings' => ['label' => 'public', 'secret' => 'accepted but not writable'],
        ]));
        self::assertSame(20000, $add->add()->getData()['code']);
        self::assertSame([
            'title' => 'hello',
            'settings' => ['label' => 'public'],
            'tenant_id' => 42,
        ], $this->service->lastAdd);

        $edit = $this->controller((new Request())->withPost([
            'uuid' => '018f0b4a-7b8c-7d6e-9f00-123456789abc',
            'title' => 'edited',
            'workflow_note' => 'trusted business control',
        ]));
        self::assertSame(20000, $edit->edit()->getData()['code']);
        self::assertSame([
            'title' => 'edited',
            'uuid' => '018f0b4a-7b8c-7d6e-9f00-123456789abc',
            'workflow_note' => 'trusted business control',
        ], $this->service->lastEdit);
    }

    public function testStringPrimaryKeyIsNotIntegerCoercedAcrossDetailStatusAndBatchRestore(): void
    {
        $detail = $this->controller((new Request())->withGet(['uuid' => 'note-A']))->detail();
        self::assertSame('note-A', $this->service->lastDetail);
        self::assertSame('note-A', $detail->getData()['data']['uuid']);

        $this->controller((new Request())->withPost(['uuid' => 'note-B', 'state' => 1, 'reason' => 'audit']))->updateStatus();
        self::assertSame(['note-B', 1], $this->service->lastStatus);

        $restore = $this->controller((new Request())->withPost(['ids' => ['note-A', 'note-B', 'note-A']]))->restore();
        self::assertSame(['note-A', 'note-B'], $this->service->lastRestore);
        self::assertSame(['restored' => ['note-A', 'note-B']], $restore->getData()['data']);
    }

    public function testUnknownNestedFieldIsRejectedBeforeTrustedHookAndService(): void
    {
        $controller = $this->controller((new Request())->withPost([
            'title' => 'hello',
            'workflow_note' => 'ok',
            'settings' => ['label' => 'ok', 'secret' => 'ok', 'undeclared' => true],
        ]));
        $this->expectException(ValidateException::class);
        $this->expectExceptionMessage('请求包含未声明字段：settings.undeclared');
        $controller->add();
    }

    private function controller(Request $request): ContractController
    {
        $this->app->instance(Request::class, $request);
        return new ContractController($this->app, $this->service);
    }
}

final class ContractValidate extends Validate
{
    protected $rule = [
        'uuid' => 'string',
        'ids' => 'array',
        'title' => 'require|string',
        'workflow_note' => 'string',
        'settings' => 'array',
        'state' => 'in:0,1',
        'reason' => 'string',
    ];

    protected $scene = [
        'add' => ['title', 'workflow_note', 'settings'],
        'edit' => ['uuid', 'title', 'workflow_note'],
        'detail' => ['uuid'],
        'delete' => ['uuid'],
        'status' => ['uuid', 'state', 'reason'],
        'recycle' => [],
        'recycleDetail' => ['uuid'],
        'restore' => ['uuid', 'ids'],
        'forceDelete' => ['uuid', 'ids'],
    ];
}

final class ContractController extends BaseController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = ContractValidate::class;
    protected const CRUD_PRIMARY_KEY = 'uuid';
    protected const CRUD_PRIMARY_KEY_TYPE = 'string';
    protected const CRUD_STATUS_FIELD = 'state';
    protected const CRUD_SOFT_DELETE = true;
    protected const CRUD_INPUT_FIELDS = [
        'detail' => ['uuid'],
        'add' => ['title', 'workflow_note', 'settings' => ['label', 'secret']],
        'edit' => ['uuid', 'title', 'workflow_note'],
        'delete' => ['uuid'],
        'status' => ['uuid', 'state', 'reason'],
        'recycle' => [],
        'recycleDetail' => ['uuid'],
        'restore' => ['uuid', 'ids'],
        'forceDelete' => ['uuid', 'ids'],
    ];
    protected const CRUD_WRITABLE_FIELDS = [
        'add' => ['title', 'settings' => ['label']],
        'edit' => ['title'],
        'status' => ['state'],
    ];
    protected const CRUD_CONTROL_FIELDS = [
        'edit' => ['workflow_note'],
    ];

    public function __construct(App $app, private readonly RecordingCrudService $service)
    {
        parent::__construct($app);
    }

    protected function resolveCrudContext(): mixed
    {
        return 'trusted-context';
    }

    protected function crudService(): object
    {
        return $this->service;
    }

    protected function beforeAdd(array &$params, mixed $context): void
    {
        if ($context !== 'trusted-context') {
            throw new \LogicException('trusted CRUD context was not preserved');
        }
        $params['tenant_id'] = 42;
    }
}

final class RecordingCrudService
{
    public array $lastAdd = [];
    public array $lastEdit = [];
    public int|string|null $lastDetail = null;
    public array $lastStatus = [];
    public array $lastRestore = [];

    public function add(mixed $context, array $params): bool
    {
        $this->lastAdd = $params;
        return true;
    }

    public function edit(mixed $context, array $params): bool
    {
        $this->lastEdit = $params;
        return true;
    }

    public function detail(mixed $context, int|string $id): array
    {
        $this->lastDetail = $id;
        return ['uuid' => $id];
    }

    public function updateStatus(mixed $context, int|string $id, int $state): bool
    {
        $this->lastStatus = [$id, $state];
        return true;
    }

    public function restore(mixed $context, array $ids): array
    {
        $this->lastRestore = $ids;
        return ['restored' => $ids];
    }
}
