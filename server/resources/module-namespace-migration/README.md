# 模块命名空间迁移工具

权威入口为仓库根目录的 `scripts/migrate-module-namespaces`。它只处理当前登记的 18 个模块，先从 PHP 实际声明生成逐符号映射，再由同一份映射执行和复核迁移。

```bash
scripts/migrate-module-namespaces plan
scripts/migrate-module-namespaces apply
scripts/migrate-module-namespaces repair
scripts/migrate-module-namespaces verify
```

此脚本是针对既有基线的一次性迁移工具，执行模式仍固定核验原分支和起点；不要对已迁移的当前 dev 再次 apply。逐符号复核资料已版本化到 `server/tests/fixtures/module-namespace-migration-map.json`，当前组合可直接执行 `php server/tests/scripts/CheckModuleNamespaceMigration.php`。迁移只处理实际声明的类、接口、trait 和 enum，不允许对 `PeanutAdmin\\Kernel` 做全局前缀替换。

生产模块采用 `PeanutAdmin\\Modules\\<ModuleName>\\...`，Delivery Record fixture 采用 `PeanutAdmin\\Fixtures\\DeliveryRecord\\...`。类文件进入模块根的 `src/`；`composer.json`、`module.json`、`database/`、`resources/` 与 `route/` 保持在模块根。历史 `scaffold/releases/`、依赖、归档和锁文件不在替换范围。

此工具不会修改 Core/vendor、数据库、网络服务、发布摘要或 `plugins.lock`。当前组合使用真实 Core `98500ea` 的显式前缀支持，宿主将模块 Composer 声明送入同一加载器和边界检查；未知、重叠或保留前缀仍拒绝。人工架构与实施说明以 Project 仓的 `docs/development/controller-access-and-namespaces.md` 为准。
