# 模块命名空间迁移工具

权威入口为仓库根目录的 `scripts/migrate-module-namespaces`。它只处理当前登记的 18 个模块，先从 PHP 实际声明生成逐符号映射，再由同一份映射执行和复核迁移。

```bash
scripts/migrate-module-namespaces plan
scripts/migrate-module-namespaces apply
scripts/migrate-module-namespaces repair
scripts/migrate-module-namespaces verify
```

默认映射写入 `.local/controller-namespace/namespace/mapping.json`。工具固定核验本切片的分支和起点，拒绝旧符号、新 FQCN、目标路径的大小写碰撞；只替换映射中真实声明过的类、接口、trait 和 enum，不允许对 `PeanutAdmin\\Kernel` 做全局前缀替换。

生产模块采用 `PeanutAdmin\\Modules\\<ModuleName>\\...`，Delivery Record fixture 采用 `PeanutAdmin\\Fixtures\\DeliveryRecord\\...`。类文件进入模块根的 `src/`；`composer.json`、`module.json`、`database/`、`resources/` 与 `route/` 保持在模块根。历史 `scaffold/releases/`、依赖、归档和锁文件不在替换范围。

此工具不会修改 Core/vendor、数据库、网络服务、发布摘要或 `plugins.lock`。真实 Core 对旧 Provider 前缀的硬校验需要由 Core owner 单独处理，不能通过修改 vendor 或保留业务兼容别名绕过。
