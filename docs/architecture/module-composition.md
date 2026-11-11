# 应用默认模块组合

`server/config/module_composition.json` 是宿主默认组合的唯一版本化清单。协议为 `peanut.application-module-composition.v1`，`schema_version` 为 1，`version` 标识组合契约版本，独立于模块版本。`mandatory` 是运行必需的根模块，`default` 是默认推荐的额外根模块；两者必须是无重复、互不重叠的模块 key 数组。其他模块从用户明确选择的发行索引获取，默认不安装 Demo 或整个目录。

MT 当前必选根来自宿主消费者：Identity 提供身份、权限和租户生命周期；File 提供公共资源和存储；Settings、ReferenceCodes 提供配置和目录同步；Integration 参与租户初始化；Ops 提供维护门禁。Task 和 ContentTools 通过模块依赖进入闭包，不在宿主再列一份。当前没有额外推荐根，因此 minimal 和 standard 的模块闭包相同；客户端交付 profile 仍由原有生成器决定。

`InitialInstallationSource::modules($serverRoot, $includeDefaults)` 按 Module manifest 递归收集闭包，然后调用现有 `PackageCheck::dependencyOrder()` 校验版本、循环及依赖顺序。生成器、首装默认/必选选择、初始 SQL 投影、无实例快照时的安装输入和默认模块 reconcile 共用此入口。缺少源码时返回 `INSTALL_MODULE_SOURCE_MISSING: <key>`，不静默退回固定名单。安装器仍以已有实例的 `official_modules` 快照维护其安装历史，后续组合变化不改写旧快照。

清单只声明产品组合，既不授予任何租户权限，也不把已停用模块重新启用。所有源码、Edition、公开导出、Composer、迁移、安装登记和数据保护继续由现有原生入口校验。宿主组合根现有工厂和安全适配仍须保留；某个符号未列入 exports 时不能通过删除消费者或把模块内部类型搬入 Core 来消除依赖。

## CLI 创建交接接口

下述是薄模板的接入合同；当前 CLI create 尚未实现自动消费，不应据此宣称薄模板首装完成。

1. CLI 从已验证的模板 inventory 中读取 `server/config/module_composition.json`；不在 CLI 保存第二份模块名单。minimal 选择 `mandatory`，standard/full 选择 `mandatory + default`。记录实际选择与清单摘要，模板组合版本不是模块版本 pin。
2. 用户选择官方发行渠道和固定索引，例如预发行 `v1.0.0-rc.1`；CLI 沿已有 `peanut.module-release-index.v1` 校验 Release、索引 digest、固定 source commit、模块版本、Edition、依赖范围、撤回状态及每个 TAR 的独立 SHA。稳定渠道缺完整闭包时明确拒绝，不能隐式改用 RC。按索引及包内同一依赖声明求完整闭包，拒绝版本冲突和循环，依赖先于消费者准备。
3. 在未安装应用中只接收源码与锁，复用 `server/module` 的 `adopt` 操作：`{"operation":"adopt","arguments":["/absolute/module.tar","--sha256","<reviewed-sha256>","--official-key","peanut.<name>"]}`。此入口仅加载当前应用的安全环境文件、`peanut`、`module_packages` 和 `deployment` 配置，建立原有 CLI 上下文，不提前启动依赖模块的宿主。环境文件必须是普通文件且其他用户无读写权限，显式设 `APP_ENV=development` 与 `DEPLOYMENT_MODE=multi-tenant`；来源 SHA、官方 key、同版/降级/归属校验仍由原采用服务执行。恢复使用同一入口的 `--recover`。这个操作不替代首次安装，不调用业务数据库，不授予 RBAC。安装、升级、编译等运行操作仍走普通宿主启动。
4. 整个最终闭包准备齐全后，只调用一次现有 `Dependencies::prepareRelease()` 的原生依赖准备能力，客户端集合取各 Module manifest。Composer path 包、开发 metapackage、各端 workspace 与原生锁继续由现有机制处理；不能剪裁锁 JSON、伪造 content-hash 或复制其他应用 vendor。该能力当前没有独立的未安装应用 CLI 编排接口，须由后续 Code/CLI 接入共同闭合。
5. 依赖准备、API 生成和 registry 编译依据实际最终输入执行；数据库就绪后交给现有安装器完成首次迁移、身份初始化、模块登记及目录同步。源码创建、来源采用、依赖准备、数据库安装和运行验收分别报告。

## 模板退出与后续升级

当前生成器和清单仍保留启动所需的 8 模块源码、Composer path 节点与锁输入。本次先统一组合选择并接通源码采用的最小启动；CLI 创建闭包及原生依赖准备未接通前，不删除这些源码，不把当前生成应用标成 thin scaffold。

后续退出需在同一可运行变更中完成：inventory 排除官方后端和各端作者源码；模板的 Composer/客户端默认作者输入退出且通过原生工具生成对应锁；`modules.lock` 只记录实际接收的固定发行来源；源码归属使用现有模块采用及升级机制，不把独立模块再次登记为 scaffold-managed。未准备必选模块的应用由安装入口返回明确未就绪状态，不能 fatal 或放行管理 API。

既有应用仍通过 `scripts/scaffold-upgrade` 的三方计划升级宿主；已安装且兼容的较新模块、同版摘要、应用定制、disabled 状态与恢复合同继续保留。宿主最低要求上升时列出联动模块计划，不静默降级或替换。composition 改动不授权数据库重置、现有应用写入或正式发布。
