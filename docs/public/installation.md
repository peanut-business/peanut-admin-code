# 取得发行包并首次安装

本页适用于已公开发布、带固定依赖的完整产品发行包。当前 `4.0.0-dev.14` 是开发源码身份，尚无可按本页安装的正式 v4 GitHub Release；在对应正式包与 Core 依赖发布前，不要把开发 ZIP、CI 产物或维护者工作树当作正式交付。

## 取得与核对

从项目公布的 GitHub Release 页面选择同一产品版本、同一 Edition（`standalone` 或 `multi-tenant`）的 `peanut-admin-<version>-<edition>-server.tar.gz`、对应 Edition `.manifest.json`、`SHA256SUMS` 和 `RELEASE_MANIFEST.json`。以 Release 说明中公布的 SHA-256 核对 `RELEASE_MANIFEST.json`，再核对它列出的附件摘要、`SHA256SUMS` 和 Edition 清单 `server_archive` 中的版本、来源、文件名及归档摘要。解压或替换正式目录前，可在隔离位置用可信发行工具源码执行 `python3 scripts/package-release-files.py verify --archive=/absolute/server-package.tar.gz --expected-sha256=<可信渠道提供的SHA-256>`，再核对包内 server 文件清单。任一身份或摘要不符、依赖尚未公开发布时停止。安装包与 Edition 升级包均使用清单和 SHA-256 完整性校验；升级包还核对版本、来源与目标身份。不要从仓库源码 ZIP 自行拼装缺失的发行附件；本开发候选尚不能提供可执行的正式下载示例。

安全提取到独立的实例目录。生产包根目录仅有 `server/`；其中 `server/.peanut/release-identity.json` 记录本次发行的应用与文件身份，`server/plugins.lock` 是后端运行投影。先核对外部发行清单、归档摘要、包内身份和 `server/composer.lock`。

客户 APP 使用自己的 Git 来源、应用版本与 Release，Peanut 上游版本单独记录。部署消费该 APP 自行发行的 server 包，不把 Peanut Admin 仓库检出目录当作客户 APP。宿主持有 `server/` 源码、`vendor/`、配置、`public/storage/`、`runtime/`、`private/` 安装及私有存储身份；MySQL 数据保存在同实例的 `server/docker/mysql/`。包内 `server/docker/compose.yaml` 将这些目录挂载到固定 PHP、Nginx、MySQL 运行镜像，普通应用更新不构建应用镜像。只有运行环境变化才单独准备兼容运行镜像；当前标准部署范围为 PC SPA，SSR 服务仍需独立完成运行合同。

维护者明确登记的临时多租户源码实例仅允许首次部署：制品真实身份为 `generated-template`，来源 commit/tree 绑定 Peanut 上游，并登记 `deployment_kind=temporary-multi-tenant-source`、到期时间与替换 owner；不冒充独立 APP Release，也不进入 APP 自动升级任务。它仍使用独立目录、数据库、端口和同一原生 configure/start。已有目标目录一律保留并拒绝首次覆盖。

安装器默认只接受 Server 自带且可校验的发行身份；缺失或损坏时拒绝安装。源码工作树仅可在开发入口显式设置 `PEANUT_INSTALLATION_SOURCE_MODE=development` 后按源码身份运行，该设置不得带入生产实例。

按包内 `server/composer.lock` 及公开 registry 安装已发布的 Composer 依赖，保留锁定版本；不得以邻仓路径、内部 Core ZIP、未公开 `.tgz`、复制来的 `vendor/` 或无约束更新代替。发行包不附带已安装依赖。宿主需要 Docker Compose v2、Python 3、OpenSSL 和 POSIX shell。将 `server/docker/.env.example` 复制为 `server/docker/.env`，填入由包内 Dockerfile 预先构建并核验的 PHP 不可变镜像 ID；模板中的 Nginx/MySQL 已固定来源摘要，实际部署仍须核镜像 ID。PHP 镜像含 Composer 2.10.2 且构建时核对 PHAR SHA-256。运行 `server/docker/scripts/start.sh` 时，首次未安装实例的缺失或不完整 vendor 会在独立目录按锁准备、执行 ThinkPHP 原生 service discovery 和 vendor publish、检查平台要求并保存逐文件完整性回执；完整且锁未变时只读复用。已安装实例的 vendor 若缺失或损坏，普通 start 会拒绝，须在停写维护流程中修复，不能在线替换。准备失败不启动服务，也不改原 vendor。浏览器资产已在 `server/public/`，无需在生产实例安装客户端构建依赖。若该版本锁仍指向内部候选或本地 tarball，停止正式安装，等待固定依赖公开发布并由发行方重建完整包。

## 独立实例与首次安装

为本实例单独登记数据库、缓存、队列、存储、外部服务、域名和端口的身份、负责人、地址、凭据引用与健康状态。确认目标数据库为空、资源归本实例使用，并核验实际连接；不能套用别的项目、示例或旧实例资源。当前第一阶段的 server 包把 APP `resources/project-resources.json` 作为不可变发行投影并纳入发行摘要，因此独立 APP 必须先在自己的开发仓登记目标资源、提交，再生成 server 包；部署后不得直接修改包内登记来绕过身份校验。通用 Peanut 模板包的现场资源分配层尚未交付，不能把未登记模板 server 包描述为可直接安装。根据该包的 `server/.env.example` 配置本实例 `server/.env`，尤其是 `DEPLOYMENT_MODE`、数据库和应用密钥；该文件必须是普通单链接文件且权限为 `0600`。`server/docker/.env` 只负责编排，和后端配置用途不同。环境文件仅保存运行配置，**不得写入初始管理员邮箱或密码**。

自动首次安装所需 `ADMIN_INITIAL_EMAIL` 和强密码 `ADMIN_INITIAL_PASSWORD` 写入单独的临时文件；多租户模式还需不同的 `PLATFORM_INITIAL_EMAIL` 与 `PLATFORM_INITIAL_PASSWORD`。该文件必须是绝对路径、普通单链接文件、权限 `0600`，仅由安装命令的 `PEANUT_INSTALLATION_ENV_FILE` 指定，不复制到 `server/.env` 或产品包。按包内实际配置完成数据库连接和服务发现后，从产品根目录执行：

```sh
cd /absolute/path/to/product-root/server
php think service:discover
cd ..
php server/database/install.php --preflight
PEANUT_INSTALLATION_ENV_FILE=/absolute/private/installation.env php server/database/install.php
```

仅当预检返回 `ready` 才执行下一行安装命令。安装结束后移除这份一次性凭据文件，并按包内入口检查安装状态和运行环境。`php server/database/install.php --status` 可查询状态；普通重复安装会拒绝，`--skip-if-installed` 只跳过已完成的安装，不清库或重置管理员。不要对现有客户库使用首次安装或 `--fresh`。

已安装 server-only 实例先用可信摘要执行 `server/docker/scripts/update.sh plan --archive=/absolute/new-server.tar.gz --expected-sha256=<64位SHA-256> --workspace=/absolute/empty-workspace`，再执行 `server/docker/scripts/update.sh apply --workspace=/absolute/empty-workspace`。目标依赖先在 workspace 内按锁准备，失败时旧服务仍运行。进入维护后停止 PHP/Nginx，保存同实例数据库、公共 storage/uploads、私有 storage、安装身份及配置的配对恢复材料，再替换程序与依赖，执行应用和模块原生迁移，私有健康核验成功后开放流量。激活前使用 `server/docker/scripts/update.sh recover --workspace=/absolute/same-workspace` 成对恢复；激活后拒绝自动旧库回灌。运行配方变化时当前入口仍拒绝 apply，须先完成兼容镜像的独立准备与绑定。真实数据库/容器更新和恢复尚待登记的非生产实例串行验证。

`server/private/installation/installed.json` 同时是安装完成回执与物理防重装锁。只要该路径存在，即使数据库被清空、不可达或回执内容损坏，安装入口也拒绝再次初始化。安装完成后 `/install`、`/install/`、`/install/index.html` 及管理端安装页面由服务器返回真实 HTTP 404；执行 API 仍明确拒绝重复安装。只有用户手动删除该指定锁才会重新开放入口；删锁不授权清库、覆盖配置或重新生成安装身份。已有数据库、`baseline.json`、执行记录或部分完成残留仍会阻止首次安装，须保留事实交由资源 owner 核对。正常启动和数据库故障不会自动删除锁。

安装向导依次完成协议确认、环境探针、实例配置、数据库连接与权限复查、执行、完成登录。环境检查在配置之前进行；配置发布待完成时只重新读取状态，不重复提交。数据库检查不会授予权限或写入业务数据，不能确认的有效权限按 unknown 阻断；执行前重新核对。状态响应区分前置条件未满足、部分执行、已安装运行故障与无法确认，并返回 `stage`、`reason`、`remedy`、`writes`、`safe_retry`。请求失败不代表未安装，不能据此重放执行。外部页面及前端源码入口使用 `install`，内部安装 Host 与持久身份目录继续按其真实职责保留 `installation` 命名。

首次安装的 `executing.json`、`baseline.json`、`installed.json` 和执行锁保存在实例自己的 `server/private/installation/` 持久目录，不属于发行包。旧版本若将这些记录放在 `server/runtime/installation/`，必须先停用旧应用写入者，并在同时挂载旧 runtime 卷与新 installation 卷的可信维护环境中，以拥有两个状态目录的应用进程 UID 显式使用可信维护工具 `php scripts/migrate-installation-state --server-root=/absolute/path/to/product-root/server`，核对输出的逐文件 SHA-256 和 `server/private/installation/migration.json`，再准备升级。该维护工具不随 server-only 包分发。迁移先写 `pending` 记录，在目标卷内逐文件核对原字节并原子发布，接着将旧目录改名为 `server/runtime/installation.migrated/` 留作恢复材料，最后把记录标为 `complete`；中断时可按同一记录重试。旧回执中记录的 `server/runtime/installation/` 路径是历史安装时的位置。两边记录不一致、旧执行锁被占用或迁移备份路径冲突时会拒绝，须先人工核对实例，不能重新安装来补回身份。普通安装状态查询不会自动迁移，`pending` 时也会阻断安装。

如果正式 Release 尚未给出该版本的完整附件清单与摘要、已公开固定依赖或明确的目标 Edition 配置，本页只能作为安装合同，不能宣称该版本可正式安装。

## 开发源码实例的单条应用迁移

`php server/think schema:application ... --scope=source-development` 是显式的 Code 开发源码维护入口，协议版本为 `peanut.source-development-schema-plan.v1` / `peanut.source-development-schema-result.v1`。它要求真实 Console Instance 上下文、development/debug、`PEANUT_INSTALLATION_SOURCE_MODE=development`、干净且固定的 Git 来源、已准备的原生依赖，以及明确登记的非生产数据库。生成 APP 的 application-manifest、server 发行和 development-runtime 标记（包括符号链接）均拒绝；普通 `schema:application` 和 server-update workspace 仍使用各自原入口。

此入口目前只处理唯一待执行项 `20261016-module-migration-provenance`，SQL SHA-256 必须为 `d27a6f52dd3e02b9c0c45a804c059f54062a732c9e1a742dfe12e78b80d96cce`。其他应用迁移必须已有 checksum 相同的 applied 记录。预检保留原 application/module 两张账本的完整记录与结构摘要；执行只增加该迁移对应的应用账本行及两个 provenance 列、两个 CHECK，历史模块行的来源保持 NULL，历史 release_version 不重写。已有 partial 结构、缺账的完整后态、failed/applying、checksum 冲突均停止，不重放或补账。缺失的 installed.json / baseline.json 保持缺失，已有文件保持原字节；该入口不建立首次安装身份。

资源 owner 必须通过正常资源授权机制取得绑定本次 owner、thread、Workflow Session、gate、源码 candidate/worktree、登记摘要、endpoint/schema 和 TTL 的原生租约。命令的 `--owner`、`--thread`、`--session`、`--gate` 必须与它一致；`PEANUT_RESOURCE_LEASE_PROOF` 指向原生证明。CLI 在初始化 master 连接前先核源码、登记和原生租约；apply 要求完整 DDL/exclusive 范围并再次执行原生 verify-locks。登记采用 schema 模式、且没有 shared_access 和 mysql-access 声明的既有租约按原生整 schema 独占合同处理；显式 read 声明不能升级为 DDL。计划、回执或文件摘要都不是授权。

执行者另行完成 HTTP、worker 及其他写入者的实际停写、在途操作排空、两张账本的成对备份和可执行恢复准备，再形成下列受保护事实文件。schema named lock 只串行参与该锁的迁移，不能阻止任意业务写入者。工具不会停服务、执行备份/恢复工具、创建租约或续租。

DB2 先行验证及原库 apply 是**外部执行与独立验收门禁**。执行者先在登记的 `lease_gate=migration-validation` 数据库，以同一源码、实际加载的 Core、固定 SQL 和原库真实两表基线执行唯一迁移，再实际恢复两表并读取核对。协调者必须读取原生执行 Job 的终态、退出码、真实目标 UUID/schema、迁移前后及恢复后摘要，独立验收后才明确派发原库本次 apply；执行者再取得原库自己的停写窗口和授权。两个 schema 可在同一 MySQL 服务上。首次 DB2 操作不循环要求自己先完成资格；`validation_only` 仅描述当前可信资源登记及同名原生 gate。CLI 不认证历史 Job、不自动认定 DB2/恢复通过，也不代替协调者授权。现有执行任务及原生 Job 保存此门禁记录，无需新增签名或调度系统。

所有输入 JSON、引用的原始回执及备份载荷使用 canonical 绝对路径、普通单链接文件、权限 `0600`，owner 为源码目录 owner 或 root；JSON/回执及此两表载荷限 4 MiB。恢复工具本身使用固定普通文件和 SHA-256。事实文件采用以下字段（对象键顺序不影响比较，数组顺序须一致）：

| 文件 | 必需字段 |
| --- | --- |
| 两者共有 | `schema_version: 1`；`source`、`database`、`authority`、`registry_sha256` 原样取本次 preflight 的 `binding`；固定 `migration_sha256`；整数 `observed_at` / `expires_at`（当前有效且不超过原生租约）；非空 `receipts: [{path, sha256}]`，引用实际操作原始回执 |
| maintenance | `protocol: peanut.source-development-maintenance.v1`；`state: active`；`excluded_writers: [http, workers, other-writers]`，由本次实际停写操作支持 |
| recovery | `protocol: peanut.source-development-recovery.v1`；`scope: [pa_schema_migration, pa_module_migration]`；`baseline_sha256` 为计划 baseline 的 canonical JSON SHA-256；`source_database`；`backup_payload: {path, sha256}`；`restore_tool: {path, sha256}`；`restore_contract_sha256` 绑定实际两表恢复合同 |

`source_database` 记录原库，使用与 binding.database 相同的原生身份结构：`server_uuid`、`server_hostname`、整数 `server_port`、`database`、`transport`。canonical JSON 将对象键递归按字节排序、保持数组顺序，不转义 Unicode 和斜线；摘要不含末尾换行。入口仅校验当前事实的绑定、有效期及引用文件字节；它不解析载荷来证明内容、不执行恢复工具，也不消费 `qualification_result`。摘要及文件权限不能认证 producer 或证明操作实际完成；受授权的执行者负责真实停写、载荷内容和可恢复性，协调者通过原生 Job/实际读回独立验收，不能用自填 JSON 或自行重算摘要代替。

在已完成环境准备的源码根目录，经登记的项目执行环境运行下列原生命令；先设定本次真实身份变量及绝对私有文件路径，勿直接复制占位值。预检没有数据库持久写入，会取得既有 source product 文件锁和 schema named lock；计划文件由调用者以 `0600` 保存。以下 apply 仅在协调者已经明确派发的本次范围内执行；原库命令必须等待上述 DB2 独立验收。

```sh
umask 077
php server/think schema:application preflight --scope=source-development \
  --owner="$OWNER" --thread="$THREAD" --session="$SESSION" --gate="$GATE" > "$OBSERVATION"
# 从观察结果绑定实际停写与恢复材料后，生成可供 apply 核对的计划。
php server/think schema:application preflight --scope=source-development \
  --owner="$OWNER" --thread="$THREAD" --session="$SESSION" --gate="$GATE" \
  --maintenance="$MAINTENANCE" --recovery="$RECOVERY" > "$PLAN"
php server/think schema:application apply --scope=source-development \
  --owner="$OWNER" --thread="$THREAD" --session="$SESSION" --gate="$GATE" \
  --plan="$PLAN" --maintenance="$MAINTENANCE" --recovery="$RECOVERY"
php server/think schema:application verify --scope=source-development \
  --owner="$OWNER" --thread="$THREAD" --session="$SESSION" --gate="$GATE" \
  --result="$RESULT_PATH"
```

每步先核退出码及 JSON；计划不输出 `may_apply`，`execution_admitted` 始终 false，apply 会重新核实当前原生权限、字节和数据库。apply 在外层既有 source product 锁、内层既有 schema 锁内使用同一 ThinkORM 默认 master PDO，SQL 后排空结果、确认无事务并核真实后态，再标 applied。原生 beforeUnlock 最终核验后、仍持同一 PDO 的 schema 锁时，结果文件原子封存于已有忽略规则覆盖的持久目录 `server/private/installation/application-schema/source-development/`，完成后输出 `result_path`；该子目录不创建或替代 installed.json / baseline.json。verify 读取该目录符合协议与摘要的结果，重新核实当前真实后态和旧记录，允许使用当前有效的只读租约；它不执行迁移，也不认证结果文件的历史 producer。所有结果只覆盖本次运行的 application-schema，明确标记 `external_gates=not_evaluated`、`plugins=not_run`、`product_database=not_verified`，不能用作自动 DB2 资格或原库授权。

典型拒绝包括 `APPLICATION_SCHEMA_SOURCE_PENDING_SCOPE_REQUIRED`（待执行集合不符）、`APPLICATION_SCHEMA_PLAN_STALE`（来源/计划绑定变化）、`APPLICATION_SCHEMA_DDL_LEASE_REQUIRED`（无完整 DDL 范围）、`APPLICATION_SCHEMA_SOURCE_RECOVERY_REFERENCES_REQUIRED`（两表备份引用格式不符或携带已停用的 qualification_result）。失败保留原始输出、账本和恢复材料；DDL 可能已提交，不能自动重试或删除 failed/applying 行。成功后再次 apply 也会拒绝，应使用结果路径 verify。该源码入口尚未以真实数据库完成运行资格，不能据此宣布可生产使用。
