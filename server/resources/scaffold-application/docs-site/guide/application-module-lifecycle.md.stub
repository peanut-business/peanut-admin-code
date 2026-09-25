# 应用与模块生命周期

本文面向应用维护者和模块作者。完整产品包可直接按包内资料安装，不要求取得维护者的私有 Project 或先运行生成器。维护者制作应用、模块作者开发签名、消费者安装升级分别记录精确来源、原生锁、摘要及恢复证据。开发规范见包内 `docs/development/standard.md`。

## 1. 取得产品或制作开发应用

使用者取得完整产品包后，先按锁安装 PHP 依赖，再依公开资源和环境合同配置实例，最后执行包内首次安装入口。不能复制维护者的 vendor，不能把示例配置当作已授权数据库。安装不会隐式授予后续模块业务权限。

以下命令只用于有完整源码和制作工具的维护者准备开发应用，不是产品使用者的安装前置。源提交必须包含匹配 inventory；`4.0.0-dev` 是开发标识，不是正式发布：

```bash
php scripts/build-scaffold-release --version=<scaffold-semver> --source-commit=<40-hex> --output=<absolute-candidate-directory>
php scripts/create-app --name=<name> --slug=<slug> --package=<vendor/name> --target=<absolute-path> --edition=standalone|multi-tenant --profile=standard --scaffold-manifest=<absolute-candidate-directory>/scaffold-manifest.json
```

在干净的固定 Git checkout 中运行 `create-app`。显式 manifest 绑定模板身份，生成应用不需要维护者的私有 Project checkout，也不会复制源仓库的 `.git`、`vendor/` 或 `node_modules/`。应用自己的数据库、端口、域名、存储、队列和外部服务须另行登记，不能把 secret 写进产品 manifest。

先安装生成应用自己的锁定 PHP 依赖：

```bash
scripts/project-composer prepare
scripts/project-composer install --working-dir=server --no-interaction --no-scripts
```

完成实例资源登记、所需授权和环境配置后，由框架正式命令生成服务元数据，再使用包内安装入口；不手写 vendor/services.php。先在 server 目录执行 `php think service:discover`，随后从应用根执行 `php server/database/install.php`。以 `--status` 查看实际状态；普通重复安装应拒绝，`--skip-if-installed` 只安全跳过，不能清库或重置账号。实际入口参数与支持形态以取得的产品版本为准。

## 2. 创建并检查模块

在生成应用的 `server/` 中执行：

```bash
php think module:create <vendor.module> --vendor=<vendor>
# 需要管理端贡献时，创建阶段显式增加 --client=admin-web；默认仅后端。
php think module:check <vendor.module>
```

模块根必须有唯一 `module.json` 身份、Composer 声明、`src/`、迁移和资源。新骨架不发布空 Commands，也不凭生成目录宣称业务完成。实际公开服务、稳定结果、依赖和数据所有者随版本登记；跨模块使用明确导出的查询/业务操作，不导入其他模块 Model、Repository 或持久化实现。软删除、回收、恢复和永久删除必须有独立授权；付款、撤单、库存变更使用业务服务。

## 3. 签名打包

```bash
php think module:pack <vendor.module> --output=<absolute-path>/<module>-<version>.tar --signing-key-id=<key-id> --signing-secret-key-file=<permission-0600-file>
```

通过受信渠道传递 archive SHA-256、签名 key ID 和公钥配置，绝不提交 signing secret。验证器会拒绝未声明文件、链接、路径穿越、摘要/签名变化、依赖冲突、namespace 冲突和同版本身份变化；包不能执行任意安装脚本。

## 4. 安装与升级

开发/调试实例可在 Standalone 或 multi-tenant Edition 中安装已验证包：

```bash
php think module:install-package <archive> --sha256=<64-hex> --signature-key-id=<key-id>
php think module:update-package <archive> --sha256=<64-hex> --signature-key-id=<key-id> --dry-run
php think module:update-package <archive> --sha256=<64-hex> --signature-key-id=<key-id>
```

安装只改变 Package 和 ModuleInstallation 状态，不创建 Tenant、不启用 TenantModule、不创建人员、不授予 Tenant/RBAC 权限。升级必须是更高且不可变的身份；先执行零写入 dry-run。摘要/签名不匹配、降级、缺依赖、路径冲突、锁占用、未知迁移或缺少已验证备份都必须停止。源提升失败时先恢复，迁移开始后保存 recovery pointer；恢复源码不等于回滚数据库。

## 5. Tenant、权限和停用

用两个合成租户验证合法调用、跨租户拒绝、停用和撤权。模块安装不替代 Tenant 开通、RBAC 绑定或数据隔离。停用依赖模块后再停用 Package；retire 保留数据并复用未改变的 preview plan/digest；purge 还需要已验证的成对备份和显式确认。活跃 TenantModule、业务依赖、受保护模块和外部表引用都会阻断操作。

## 6. 所有权与脚手架升级

- `managed`：上游受管字节，只从上游修改并重新生成。
- `generated-managed`：确定性投影，只改唯一源文件。
- `app-owned`：应用、自有扩展和客户模块，升级保留并在冲突时要求 adoption。
- 实例状态：环境文件、凭据、上传、数据库行、备份和 recovery pointer，不能进入候选或包。

产品升级从已安装且可信的 `scripts/upgrade` 入口进行。`PEANUT_SERVER_ENV_FILE` 指向实例自己的后端环境文件；下面 `--env-file` 则指向权限为 0600、仅含升级信任公钥配置的文件，两者不能混用，私钥不进入消费端。

```bash
php scripts/upgrade plan --instance-root=<absolute-instance> --package=<absolute-extracted-package> --signature-key-id=<trusted-key-id> --env-file=<absolute-trust-file>
php scripts/upgrade apply --instance-root=<absolute-instance> --package=<absolute-extracted-package> --signature-key-id=<trusted-key-id> --env-file=<absolute-trust-file> --plan=<absolute-plan>
php scripts/upgrade verify --instance-root=<absolute-instance> --package=<absolute-extracted-package> --signature-key-id=<trusted-key-id> --env-file=<absolute-trust-file> --plan=<absolute-plan>
php scripts/upgrade recover --instance-root=<absolute-instance> --package=<absolute-extracted-package> --signature-key-id=<trusted-key-id> --env-file=<absolute-trust-file> --plan=<absolute-plan>
```

仅在计划指明可恢复且前置检查成立时执行 recover，不把四条命令当作无条件顺序脚本。先验签再使用目标包；冲突停在计划阶段。进入恢复后不能继续向前 apply；激活开始后禁止自动回灌旧备份。单纯受管文件差异工具不能代替产品依赖、数据库与文件卷的成对恢复。

既有实例保留原 baseline 和自有修改；明确 breaking 代码迁移不等于对客户数据的破坏性授权。安装、升级、数据恢复分别取得真实证据，不能通过删包文件、锁或迁移账本绕过完整性检查。
