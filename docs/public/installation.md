# 取得发行包并首次安装

本页适用于已公开发布、带固定依赖的完整产品发行包。当前 `4.0.0-dev.14` 是开发源码身份，尚无可按本页安装的正式 v4 GitHub Release；在对应正式包与 Core 依赖发布前，不要把开发 ZIP、CI 产物或维护者工作树当作正式交付。

## 取得与核对

从项目公布的 GitHub Release 页面选择同一产品版本、同一 Edition（`standalone` 或 `multi-tenant`）的 `peanut-admin-<version>-<edition>-server.tar.gz`、对应 Edition `.manifest.json`、`SHA256SUMS` 和 `RELEASE_MANIFEST.json`。以 Release 说明中公布的 SHA-256 核对 `RELEASE_MANIFEST.json`，再核对它列出的附件摘要、`SHA256SUMS` 和 Edition 清单 `server_archive` 中的版本、来源、文件名及归档摘要。解压或替换正式目录前，可在隔离位置用可信发行工具源码执行 `python3 scripts/package-release-files.py verify --archive=/absolute/server-package.tar.gz --expected-sha256=<可信渠道提供的SHA-256>`，再核对包内 server 文件清单。任一身份或摘要不符、依赖尚未公开发布时停止。安装包与 Edition 升级包均使用清单和 SHA-256 完整性校验；升级包还核对版本、来源与目标身份。不要从仓库源码 ZIP 自行拼装缺失的发行附件；本开发候选尚不能提供可执行的正式下载示例。

安全提取到独立的实例目录。生产包根目录仅有 `server/`；其中 `server/.peanut/release-identity.json` 记录本次发行的应用与文件身份，`server/plugins.lock` 是后端运行投影。先核对外部发行清单、归档摘要、包内身份和 `server/composer.lock`。

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

已安装 server-only 实例先用可信摘要执行 `server/docker/scripts/update.sh plan --archive=/absolute/new-server.tar.gz --expected-sha256=<64位SHA-256> --workspace=/absolute/empty-workspace`，再执行 `server/docker/scripts/update.sh apply --workspace=/absolute/empty-workspace`。目标依赖先在 workspace 内按锁准备，失败时旧服务仍运行。进入维护后停止 PHP/Nginx，保存同实例数据库恢复点及安装/配置身份，再替换程序与依赖，执行应用和模块原生迁移，私有健康核验成功后开放流量。激活前使用 `server/docker/scripts/recover.sh --workspace=/absolute/same-workspace` 成对恢复；激活后拒绝自动旧库回灌。运行配方变化时当前入口仍拒绝 apply，须先完成兼容镜像的独立准备与绑定。真实数据库/容器更新和恢复尚待登记的非生产实例串行验证。

`server/private/installation/installed.json` 同时是安装完成回执与物理防重装锁。只要该路径存在，即使数据库被清空、不可达或回执内容损坏，安装入口也拒绝再次初始化；先由资源 owner 核对并恢复原实例，不删除锁来重装。

首次安装的 `executing.json`、`baseline.json`、`installed.json` 和执行锁保存在实例自己的 `server/private/installation/` 持久目录，不属于发行包。旧版本若将这些记录放在 `server/runtime/installation/`，必须先停用旧应用写入者，并在同时挂载旧 runtime 卷与新 installation 卷的可信维护环境中，以拥有两个状态目录的应用进程 UID 显式使用可信维护工具 `php scripts/migrate-installation-state --server-root=/absolute/path/to/product-root/server`，核对输出的逐文件 SHA-256 和 `server/private/installation/migration.json`，再准备升级。该维护工具不随 server-only 包分发。迁移先写 `pending` 记录，在目标卷内逐文件核对原字节并原子发布，接着将旧目录改名为 `server/runtime/installation.migrated/` 留作恢复材料，最后把记录标为 `complete`；中断时可按同一记录重试。旧回执中记录的 `server/runtime/installation/` 路径是历史安装时的位置。两边记录不一致、旧执行锁被占用或迁移备份路径冲突时会拒绝，须先人工核对实例，不能重新安装来补回身份。普通安装状态查询不会自动迁移，`pending` 时也会阻断安装。

如果正式 Release 尚未给出该版本的完整附件清单与摘要、已公开固定依赖或明确的目标 Edition 配置，本页只能作为安装合同，不能宣称该版本可正式安装。
