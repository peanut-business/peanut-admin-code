<?php
declare (strict_types = 1);

namespace app;

use app\common\execution\CurrentExecutionContext;
use app\common\validate\InputValidator;
use app\common\validate\ValidatedInput;
use LogicException;
use think\App;
use think\exception\ValidateException;
use think\Request;

/**
 * 控制器基础类
 *
 * @property-read CurrentExecutionContext $context 当前 App 已注册的执行上下文读取器
 */
abstract class BaseController
{
    /**
     * Request实例
     */
    protected Request $request;

    /**
     * 应用实例
     */
    protected App $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {}

    /**
     * 始终从构造当前 Controller 的 App 读取已注册 reader；不创建空上下文。
     */
    final protected function executionContext(): CurrentExecutionContext
    {
        return $this->app->get(CurrentExecutionContext::class);
    }

    final public function __get(string $name): mixed
    {
        return match ($name) {
            'context' => $this->executionContext(),
            default => throw new LogicException(sprintf(
                'Undefined readonly controller property: %s::$%s',
                static::class,
                $name,
            )),
        };
    }

    final public function __isset(string $name): bool
    {
        return $name === 'context' && $this->app->has(CurrentExecutionContext::class);
    }

    final public function __set(string $name, mixed $value): never
    {
        throw new LogicException(sprintf(
            'Readonly controller property cannot be written: %s::$%s',
            static::class,
            $name,
        ));
    }

    final public function __unset(string $name): never
    {
        throw new LogicException(sprintf(
            'Readonly controller property cannot be unset: %s::$%s',
            static::class,
            $name,
        ));
    }

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return ValidatedInput
     * @throws ValidateException
     */
    protected function validate(
        array $data,
        string|array $validate,
        array $message = [],
        bool $batch = false,
    ): ValidatedInput
    {
        return $this->app->make(InputValidator::class)->validate(
            $data,
            $validate,
            $message,
            $batch || $this->batchValidate,
        );
    }

}
