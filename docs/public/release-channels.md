# 发行渠道与版本身份

当前源码身份由根目录 `release-versions.json` 的 `source_product_version` 定义。当前值 `4.0.0-dev.14` 是开发候选，不是正式 tag、Release 或已发布公共包；取得源码分支不能替代取得固定发行包。

`scripts/package-release.sh` 从已提交且干净的 APP Git 工作树默认装配完整开发源码包，保留 APP 自有登记、四端源码与 `.peanut/scaffold-baseline` 上游原始字节，供二开和现有源码升级入口使用。发行时生成的 `.peanut/application-release.json`、`release-manifest.txt` 和 `server/public/{admin,platform,pc,mobile}` 浏览器成品属于可再生发行输出，不成为下一次 APP 源码输入；原始 scaffold baseline 不因再次发行而改写。`--server-only` 另装配仅含 `server/` 的生产部署包。生产包 `server/.peanut/release-identity.json` 从同一次 APP 清单生成应用、上游来源、版本及实际 server 文件摘要；`server/plugins.lock` 是经原 APP 锁验证后生成的后端运行投影，原锁摘要与逐插件来源/派生摘要分别记录。APP 二开提交由其自己的 Git HEAD 表示。APP 的 `resources/project-resources.json` 是开发仓可编辑资源真值；打包到 server 后成为受发行摘要保护的只读投影。`build-edition-installers` 对同一初始模板生成开发源码和 server 两个包，明确标为 `generated-template`，没有独立 APP 提交。两类包都不含维护者登记、`.local`、Peanut scaffold 历史、实例安装记录、真实 Docker/MySQL 数据、secrets、updates/backups 或已安装依赖。

替换正式目录前，在隔离位置用可信渠道给出的归档摘要运行发行工具源码中的 `python3 scripts/package-release-files.py verify --archive=/absolute/server-package.tar.gz --expected-sha256=<trusted-64-hex>`。核验归档 SHA-256、仅含 server 的路径及包内逐文件清单；不能把同目录临时生成的摘要当作可信来源。当前开发候选的依赖仍未固定为正式发布版本，不能以此宣称已有正式生产包。

当前开发候选的 PHP Core 由 Composer 锁到 `peanut-admin/core` 的明确 Git 提交，Web Core 六包由候选本地 `.tgz` 和 SHA-256 固定；这不是公共发行包。第一阶段公开产品预发行可在严格核验 Action、Release、Packagist/npm 精确版本、源提交和六包完整性后使用相应的公开 Core 预发行精确版本。稳定正式产品仍要求正式稳定 Core 版本和独立资格。不要用无范围的 `composer update` 或 `npm install` 改写目标依赖。正式包的取得、完整性核对、依赖与首次安装步骤见[安装指南](installation.md)。

产品 GitHub Release 的发布入口是单一脚本 `scripts/publish-github-release`。默认稳定模式只接受不带 `v` 前缀的 `X.Y.Z`，要求存在注释式 tag `vX.Y.Z`，通过 candidate、tag、qualification、`main`、固定依赖和清单绑定检查后，创建正式 GitHub Release 并使用 `--latest`。公开预发行通道不是第二发布器，必须显式传入 `--prerelease`，版本只接受严格 `X.Y.Z-<prerelease>`（例如 `4.0.0-rc.1`），要求注释式 tag `vX.Y.Z-<prerelease>`，通过同一组 Gate 后创建 GitHub prerelease，且不得标记为 latest。稳定模式拒绝预发行版本；预发行模式拒绝稳定版本和非法 SemVer 预发行标识。没有明确授权时，不创建 `main`、tag、GitHub Release 或公开包。

本地非生产验证可以使用候选包内锁定的 Core ZIP。正式交付和生产安装必须从已发布渠道取得相应版本，并核对发行身份与依赖锁；不能把内部候选自带的 Core ZIP 当作正式发布版本。Composer 下载已发布版本时也可能使用 ZIP 分发格式，判定依据是发布来源与版本身份，不是文件扩展名。

正式使用路径是：

1. 取得带清单和摘要的固定发行包。
2. 核验发行身份、依赖锁和所选形态。
3. 部署到独立目录，在包内运行 `php server/database/install.php` 完成首次安装。
4. 完整开发源码实例的后续版本从实例中已安装的可信 `scripts/upgrade` 依次运行 `plan`、`apply` 和 `verify`；
   `recover` 只按已生成的计划和现场证据处理失败恢复。不要把首次安装入口当成升级命令。

server-only 生产包不含根目录 `scripts/upgrade`；下面的升级命令只适用于保留完整源码及该可信入口的既有实例。当前开发源码另有 `server/docker/scripts/update.sh` 和 `recover.sh`，但尚未构成已完成资格验证的正式 server-only 升级制品，不能用首次安装器或待验目标包中的脚本代替。

升级命令以 `--instance-root` 指定现有实例，以 `--package` 指定安全提取后的目标升级包。
由已安装入口验证目标包的 Ed25519 签名、完整文件清单和路径，再执行已验证的驱动；不要先运行待验签包中的程序。
`--signature-key-id` 选择可信密钥，权限为 0600 的 `--env-file` 只包含 `PEANUT_UPGRADE_TRUSTED_KEYS_JSON`。
该变量不得同时存在于进程环境。另以 `PEANUT_SERVER_ENV_FILE` 明确指定实例内权限 0600 的后端配置，实例根目录的 Compose `.env` 也必须存在。

```sh
unset PEANUT_UPGRADE_TRUSTED_KEYS_JSON
export PEANUT_SERVER_ENV_FILE=/srv/my-app/server/.env
php /srv/my-app/scripts/upgrade plan \
  --instance-root=/srv/my-app --package=/srv/upgrades/target \
  --signature-key-id=release-key --env-file=/srv/keys/upgrade-trust.env
```

确认 `plan` 退出成功并返回 `authenticated: true`；将返回的绝对 `plan_path` 作为 `--plan`，以相同参数调用 `apply`、`verify` 或 `recover`。
执行主机需要 PHP、jq、GNU 文件工具、curl、Docker 和 Compose，实例须能按 Compose 标签唯一识别；应用依赖和数据库操作在绑定容器内执行。

`apply` 准备目标依赖和前端，预检迁移，停写并备份数据库、公共文件卷、私有文件卷和安装身份卷，应用受管文件、应用及模块迁移，切换并在保持外部写入关闭时重载及验证业务，最后记录激活边界、恢复流量并提交完成状态。
完成必须同时具有健康和激活回执，进程退出成功不能替代这些绑定证据。

激活前可按同一计划恢复数据库、公共/私有文件、安装身份四项成对备份及受管代码，全部恢复后才启动旧运行时并验证。重复恢复只核验已恢复状态。
运维中心新建的成对备份清单与隔离恢复凭据使用 schema 3；备份集中包含 `database.sql.gz`、`php-storage.tar.gz`、`php-private-storage.tar.gz` 和 `php-installation.tar.gz`，四项均进入清单和摘要校验。旧 schema 2 备份保持原字节，但缺少安装身份归档，不能用于当前恢复验证或要求四项归档的 `--fresh` 部署；须重新创建并验证完整备份。
一旦开始开放目标写入，工具拒绝用旧备份自动回灌数据库，避免删除已经确认的新业务数据；此时先核现场，再制定数据恢复方案。
代码恢复与数据库恢复分别判断，不承诺所有 DDL 可逆；未知结果不能用简单重跑冒充幂等。

## server-only 更新入口的当前开发范围

当前开发实现先核可信归档摘要、实例安装身份、应用及文件归属，并在停旧服务前按目标锁准备 Composer 依赖：锁和现有 vendor 完整且一致时复用，否则在更新工作区准备目标 vendor，失败不破坏旧运行时。进入维护后保存同一实例的数据库恢复点及受限配置/安装身份摘要，按 journal 增改删程序并受控切换依赖，再执行应用迁移和 release-locked Module reconcile；私有业务健康与迁移回执通过后才激活。重复完成的计划不再次停服，已经应用或在激活临界点中断的计划按绑定状态继续核验/迁移而不重复文件更新。初始安装回执和基线保持原字节，后续部署身份另行记录。普通更新不复制 `public/storage` 或 `private/storage`，因为它们不属于程序写集；若某迁移需要改动上传，必须另列写集与恢复材料。运行配方或镜像输入发生变化时，现行入口仍在停服前明确拒绝，直到兼容镜像集的独立准备、绑定与切换合同完成，不能通过删除标志或手工覆盖继续。

公共访问由 `runtime/upgrade/.traffic-ready` 正向许可控制，维护和活动更新指针同时阻断静态页面、API 与 healthz。进入更新撤销许可，普通 PHP 重启不能将活动或异常状态重新开放；只有绑定同一计划、实例、程序清单且未过期的私有核验通过，激活或恢复终态才恢复许可。静态 healthz 的 200 不代替应用/数据库健康核验。`runtime/upgrade` 含更新互斥、维护及许可状态，不是可以随普通缓存一起删除的目录；不得靠删除标记或修改回执解除维护。

上述程序文件、依赖准备、数据库恢复/迁移状态机回归，以及隔离环境的 Nginx 语法/HTTP 检查，只证明源码合同和合成夹具行为；它们不证明正式产品安装、真实 MySQL 备份/迁移/恢复、生产镜像切换或断电恢复。server-only 升级仍须完成兼容运行镜像准备，并在登记的非生产实例上以固定制品串行验证真实依赖、数据库恢复点、迁移、私有健康、激活前恢复和中断接续；开放目标写入后不得自动回灌旧数据库。

## 维护者：准备固定公开依赖的产品候选

`scripts/prepare-product-release-candidate` 只用于 Peanut 上游源码仓的隔离发行工作树，不随生成 APP 交付，也不是 APP 自己制包、首次安装或线上更新的入口。APP 继续使用前述 `scripts/package-release.sh`。

跨仓第一阶段可按[Core 续作入口](../development/core-release.md#第一阶段跨仓续作入口)使用 `scripts/continue-product-release`：默认只读计划；`--apply` 才建立任务输出根的阶段状态并调用现有工具。候选依次完成公开 Core 身份核验、原生锁和候选工具依赖准备、库存生成；维护者审核并提交源码封存，工具从该真实提交生成脚手架/P0-E 来源投影，再审核作最终产品提交。双 Edition 制品与资格都绑定最终 commit/tree，发布仍调用唯一原发布器及原资格门禁。外部发布结果不明时，只对原意图与本地完整附件逐件匹配远端附件 SHA-256 后接受；缺摘要或身份不符即停止人工核对，不重发。APP 消费已公开 P1 包，不依赖此维护者编排或私有 Project。

执行前固定一个尚未使用的产品版本、PHP Core 和 Web Core 的公开精确版本及各自完整 Git 提交。先运行 `--dry-run` 核对输入与将调用的原生命令；移除该选项才会准备候选：

```sh
python3 scripts/prepare-product-release-candidate \
  --version="${PRODUCT_VERSION:?set an unused product prerelease version}" \
  --core-php-version="${CORE_PHP_VERSION:?set a published exact version}" \
  --core-php-reference="${CORE_PHP_COMMIT:?set the verified full commit}" \
  --core-web-version="${CORE_WEB_VERSION:?set a published exact version}" \
  --core-web-reference="${CORE_WEB_COMMIT:?set the verified full commit}" \
  --dry-run
```

实际准备使用原生 Composer、npm 和 pnpm 更新声明及锁，随后核对 PHP 来源、六个 npm 包的公开仓库/`gitHead`/完整性及原生锁。pnpm 未记录 tarball URL 时，不自行拼接下载地址；发行身份使用官方 registry 实际返回的地址。缺来源字段、锁不一致、仍含本地依赖或命令失败时停止；正常可捕获的执行失败会恢复该工具负责的版本文件和锁文件原字节。异常终止或并发修改仍须保留现场并检查，不能把这一恢复视为运行实例的灾备机制。

产品候选版本与生成 APP 的初始版本分别记录，不改写 APP 上游基线。准备结果保留 `technical_qualification.result=pending`；原生冻结安装、四端构建、真实完整包、安装/更新及对应发行资格仍须分别验证。`--dry-run` 不联网验证版本存在；候选准备成功也不创建 tag、发布 Release 或取得部署授权。

具体可用渠道以该发行包的清单为准。开发分支、维护者工作树、私有 Project、临时 Core 压缩包和 CI 产物都不自动成为正式发布渠道。
