# 模块开发与交付

模块是交付单元。后端实现、各客户端贡献、权限、事件、迁移和公开合同可以位于不同源码目录，但由同一个稳定模块 key 和版本汇集。模块之间只能使用公开 Query、Command、Event、Job 或 DTO 合同；不要读取别的模块私表、导入其 Model，或调用内部 Controller。

模块包至少应声明负责人、版本、依赖、兼容产品范围、迁移、权限、菜单与客户端贡献。安装前核验来源、许可、摘要和签名。PHP 模块在应用进程内运行，不是安全沙箱；普通租户不能上传任意 PHP、Shell、类名或路径来执行。

以下动作彼此独立：

1. 发现源码不代表已经安装。
2. 安装模块包不代表给所有租户开通。
3. 给租户开通不代表给所有人员授权。
4. 人员授权不能绕过模块状态、Tenant Scope 或对象状态。
5. 停用默认保留数据，并分别处理新入口、在途任务和客户端贡献。
6. 升级不得覆盖实例秘密、自有模块或应用自定义区。

对外模块包应带版本化说明，至少覆盖输入输出、权限、正常与失败例子、迁移、重试、停用和升级边界。API metadata 与实现同批刷新，并运行 `./scripts/check-openapi`，确保删除接口时不残留旧 SDK 或文档条目。

## 普通管理动作的写法

简单列表、详情和增删改可参考 `ArticleCateController`：声明 `$crudClass` 指向当前 App 已绑定的业务用例，`CRUD_VALIDATE` 指向校验器，再按动作声明 `CRUD_INPUT_FIELDS`。新增、修改和状态变更还必须分别声明 `CRUD_WRITABLE_FIELDS`；`id` 等定位字段是控制参数，不应作为可持久化字段。漏写可写声明会报配置错误，额外请求字段会被拒绝。服务仍负责 Tenant Scope、对象归属、业务不变量和事务，不能只靠 Controller 字段投影保护内部调用。

分类模块的最小声明形态如下；实际字段、校验规则和授权由各模块决定：

```php
protected string $crudClass = ArticleCategoryAdministration::class;
protected const CRUD_VALIDATE = ArticleCateValidate::class;
protected const CRUD_INPUT_FIELDS = [
    'add' => ['name', 'is_show', 'sort'],
    'edit' => ['id', 'name', 'is_show', 'sort'],
];
protected const CRUD_WRITABLE_FIELDS = [
    'add' => ['name', 'is_show', 'sort'],
    'edit' => ['name', 'is_show', 'sort'],
];
```

需要软删除时，只有模块明确启用并登记回收、恢复、永久删除路由和权限后才开放相应动作；恢复要先检查冲突及关联，普通查询和导出仍保持租户过滤。订单、账本、支付等具有独立状态和补偿规则的操作应写明确业务用例，不套普通删除。
