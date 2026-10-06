# 发行渠道与版本身份

当前源码身份由根目录 `release-versions.json` 的 `source_product_version` 定义。版本号本身不证明已公开发布；以对应固定 tag、Release、制品清单和摘要核对来源，取得源码分支不能替代取得固定发行包。

Peanut 上游应用、PHP Core 和 Web Core 六包使用同一个发行版本；破坏性变化需要升主版本时一并升版。下游 APP 保留自己的版本，上游升级不会重置 APP 身份。

## 三种升级与独立版本

`peanut upgrade` 在开发机上的 downstream APP 中吸收所选公开 Peanut/Scaffold 上游版本，调用经公开制品身份核验的固定 Code 来源原生 `scripts/scaffold-upgrade` 引擎，显式传入 APP project-root，保护 APP 身份及自有内容；它不是生产源码更新命令。`--check` 查询版本及来源，`--plan` 保存原生计划及同一引擎的仓库、tag、commit/tree、库存摘要绑定；resolve、apply 和 recover 重核该固定来源，不重新选择最新版。APP 只需已有 Composer 依赖，缺少独立入口的旧 APP 也使用这一标准路径；不依赖维护者工作树或私有 Project，也不向旧 APP 植入外部工具。明确审阅冲突后才应用。同版本不同 commit/tree 必须分别报告，不能仅凭版本相同宣称来源一致。

APP 继续开发并独立提升自己的版本，再从干净的 APP 提交制备 APP Release；APP 版本与 Peanut、Core、CLI 版本独立。生产服务器消费经清单和 SHA-256 核验的 APP Release，通过已安装的标准生产升级入口执行 plan、backup、apply、migration、health、verify 与受控 recovery，不能对生产目录执行 Peanut 上游吸收或 git pull。

人工生产升级是人工触发标准引擎；自动升级是 CI/Deployment Agent 触发同一引擎。两者使用相同的制品、清单、摘要、计划、迁移、备份、应用、核验和恢复合同，只有触发者不同。完整源码实例使用 `scripts/upgrade`，server-only 实例使用其已安装的 `server/docker/scripts/update.sh`；各入口须满足下文对应资格及保护前提。

`scripts/package-release.sh` 从已提交且干净的 APP Git 工作树默认装配完整开发源码包，保留 APP 自有登记、四端源码与 `.peanut/scaffold-baseline` 上游原始字节，供二开和现有源码升级入口使用。发行时生成的 `.peanut/application-release.json`、`release-manifest.txt` 和 `server/public/{admin,platform,pc,mobile}` 浏览器成品属于可再生发行输出，不成为下一次 APP 源码输入；原始 scaffold baseline 不因再次发行而改写。`--server-only` 另装配仅含 `server/` 的生产部署包。生产包 `server/.peanut/release-identity.json` 从同一次 APP 清单生成应用、上游来源、版本及实际 server 文件摘要；`server/plugins.lock` 是经原 APP 锁验证后生成的后端运行投影，原锁摘要与逐插件来源/派生摘要分别记录。APP 二开提交由其自己的 Git HEAD 表示。APP 的 `resources/project-resources.json` 是开发仓可编辑资源真值；打包到 server 后成为受发行摘要保护的只读投影。`build-edition-installers` 对同一初始模板生成开发源码和 server 两个包，明确标为 `generated-template`，没有独立 APP 提交。两类包都不含维护者登记、`.local`、Peanut scaffold 历史、实例安装记录、真实 Docker/MySQL 数据、secrets、updates/backups 或已安装依赖。

替换正式目录前，在隔离位置用可信渠道给出的归档摘要运行发行工具源码中的 `python3 scripts/package-release-files.py verify --archive=/absolute/server-package.tar.gz --expected-sha256=<trusted-64-hex>`。核验归档 SHA-256、仅含 server 的路径及包内逐文件清单；不能把同目录临时生成的摘要当作可信来源。候选依赖固定、制包、公开预发行和稳定生产资格分别记账，不能仅凭开发分支或制包成功宣称已有稳定生产包。

公开产品候选的 PHP Core 由 Composer 锁定已发布的精确版本、源码提交与分发身份；Web Core 六包由 npm registry 的精确版本、tarball 和 sha512 integrity 固定。来源核验同时绑定官方仓库、固定提交/tag、成功 Action 和 Release；npm 未提供 gitHead 时使用经验证的公共 provenance，详见 [Core 发行](https://github.com/peanut-business/peanut-admin-code/blob/main/docs/development/core-release.md)。产品预发行可使用相应公开 Core 预发行；稳定正式产品仍要求正式稳定 Core 版本和独立资格。不要用无范围的 `composer update` 或 `npm install` 改写目标依赖。正式包的取得、完整性核对、依赖与首次安装步骤见[安装指南](installation.md)。

产品 GitHub Release 的发布入口是单一脚本 `scripts/publish-github-release`。默认稳定模式只接受不带 `v` 前缀的 `X.Y.Z`，要求存在注释式 tag `vX.Y.Z`，通过 candidate、tag、qualification、`main`、固定依赖和清单绑定检查后，创建正式 GitHub Release 并使用 `--latest`。公开预发行通道不是第二发布器，必须显式传入 `--prerelease`，版本只接受严格 `X.Y.Z-<prerelease>`（例如 `4.0.0-rc.1`），要求注释式 tag `vX.Y.Z-<prerelease>`，通过同一组 Gate 后创建 GitHub prerelease，且不得标记为 latest。稳定模式拒绝预发行版本；预发行模式拒绝稳定版本和非法 SemVer 预发行标识。没有明确授权时，不创建 `main`、tag、GitHub Release 或公开包。

产品 tag、qualification、候选锁、归档及 Edition 制品始终精确绑定已资格确认的源码提交 S 和源码树。实际发布要求实时远端 `main` 与本地 `origin/main` 一致，且等于 S；或等于同仓真实已合并 `dev` → `main` PR 的正常合并提交 M。后一种情况只接受精确父序列 `[B,S]`、B 为 S 祖先、M 与 S 同树，以及 GitHub API 的唯一 PR、merged 状态、提交、仓库和分支身份全部一致。开放 PR 的测试合并、squash/rebase、后续同树提交、未知或歧义 API 证据均拒绝，M 不被重新标为已资格确认的源码。

M 不等于 S 时，发布命令还须传入 `--main-integration=/absolute/premerge.json`。该外部回执只有七个字段：`schema_version=1`、`repository="peanut-business/peanut-admin-code"`、`source_commit=S`、`base_commit=B`、`ready_for_stable=true`、UTC ISO8601 `readiness_at` 与 `premerge_observed_at`；要求资格准备完成时间不晚于合并前观察时间，且观察时间严格早于真实 PR 的 `merged_at`。回执不改写原资格字节，也不代替原生资格检查或发布授权。发布器在输出目录保存独立 `MAIN_INTEGRATION_PROOF.json`，记录实时 main/tag、图、PR 及输入/API 摘要，供外部发布回执使用；它不改变候选锁或发布源码身份。创建 Release 前再次核 live main/tag 与 PR，任何漂移即停止。`--prepare-only` 仍只准备制品；Core 的当前 main 发布规则和候选检查器的 `--require-main` 精确合同保持原状。

本地非生产验证可以使用候选包内锁定的 Core ZIP。正式交付和生产安装必须从已发布渠道取得相应版本，并核对发行身份与依赖锁；不能把内部候选自带的 Core ZIP 当作正式发布版本。Composer 下载已发布版本时也可能使用 ZIP 分发格式，判定依据是发布来源与版本身份，不是文件扩展名。

正式安装消费 APP 自己发行的 server 包；来源、应用版本、commit/tree、文件清单和外部可信 SHA-256 均须匹配。首次安装见[安装说明](installation.md)，已有实例不运行首次安装器。

APP 开发源码吸收上游时，使用已安装的可信入口及完整升级包：

```sh
php /srv/my-app/scripts/upgrade plan --scope=source \
  --instance-root=/srv/my-app --package=/srv/upgrades/extracted-upstream
```

返回的绝对 `plan_path` 用于同一 `--scope=source`、`--instance-root`、`--package` 下的 `apply`、`verify` 或 `recover --plan=<plan_path>`。此范围沿用 Scaffold 的归属、三方差异、冲突及逐文件恢复合同，只吸收开发源码；APP 再从自己的固定提交发行部署制品。

## server-only 更新入口的当前开发范围

宿主只运行已安装的可信 `server/docker/scripts/update.sh`。server 包随带的维护工具位于 `server/docker/scripts/`；目标归档中的未认证代码不能作为更新入口。宿主维护 PHP 入口由 `PEANUT_UPGRADE_PHP` 指定已登记的绝对可执行路径，版本须满足 PHP 8.3 及实际依赖锁。

```sh
/srv/my-app/server/docker/scripts/update.sh plan \
  --archive=/srv/upgrades/app-server.tar.gz --expected-sha256=<可信SHA-256> \
  --workspace=/srv/my-app/server/private/updates/target
/srv/my-app/server/docker/scripts/update.sh apply --workspace=/srv/my-app/server/private/updates/target
/srv/my-app/server/docker/scripts/update.sh verify --workspace=/srv/my-app/server/private/updates/target
```

该入口映射到同一个已安装协调器的 `--scope=server`。workspace 的 `product-binding.json` 绑定唯一 product plan 路径和摘要；程序文件 journal、依赖和迁移回执从属于该计划，不另拥有升级生命周期。PHP、Nginx、MySQL 为固定运行镜像，应用源码、vendor、配置、上传、runtime、private 和 `server/docker/mysql` 数据位于本实例宿主目录。普通更新不构建或切换应用镜像；运行环境变化须另行准备、核验并绑定兼容镜像。

更新先认证归档、APP 自有发行身份、受管源内容及目标文件清单，准备锁定依赖并预检迁移；停写后保存数据库、公共 storage/uploads、私有 storage、安装身份及配置的配对恢复材料，再替换程序、依赖并执行原生应用和模块迁移。保持外部写入关闭时核验真实业务和迁移，持久记录 `activation_started` 后才开放目标流量。退出成功不能替代绑定的健康、迁移与激活回执。

激活前可执行 `update.sh recover --workspace=<同一workspace>`；按同一计划恢复数据库、公共/私有文件、安装身份、程序及依赖，验证旧运行时后才恢复写入。激活开始后拒绝自动回灌旧数据库；未知 DDL 结果不能靠重复执行冒充幂等。公共访问由 `runtime/upgrade/.traffic-ready` 正向许可控制；维护、活动或异常状态不能靠重启 PHP、删除标记或手改回执解除。

运维中心独立的备份与隔离恢复业务 job 保留自身状态。新成对备份清单和隔离恢复凭据使用 schema 4，资源关系绑定已登记 APP 目录、数据库及固定环境镜像；四项制品为 `database.sql.gz`、`php-storage.tar.gz`、`php-private-storage.tar.gz`、`php-installation.tar.gz`，均校验摘要。旧 schema 2/3 保持历史原字节，不用于当前目录合同的恢复验证，需重新创建并验证备份。

本批源码和语法检查不证明真实 MySQL 备份、迁移、恢复、断电接续或正式资格。运行合同仍须在登记的非生产实例中按固定制品串行验证；本页不宣称该验证已经完成。

## 维护者：准备固定公开依赖的产品候选

`scripts/prepare-product-release-candidate` 只用于 Peanut 上游源码仓的隔离发行工作树，不随生成 APP 交付，也不是 APP 自己制包、首次安装或线上更新的入口。APP 继续使用前述 `scripts/package-release.sh`。

跨仓第一阶段可按[Core 续作入口](https://github.com/peanut-business/peanut-admin-code/blob/main/docs/development/core-release.md#第一阶段跨仓续作入口)使用 `scripts/continue-product-release`：默认只读计划；`--apply` 才建立任务输出根的阶段状态并调用现有工具。候选依次完成公开 Core 身份核验、原生锁和候选工具依赖准备、库存生成；维护者审核并提交源码封存，工具从该真实提交生成脚手架/P0-E 来源投影，再审核作最终产品提交。双 Edition 制品与资格都绑定最终 commit/tree，发布仍调用唯一原发布器及原资格门禁。执行面不能直接查询 npm 时，可提供 `--core-web-package-evidence` 的绝对只读证据文件，仅供六包元数据读取；协调器仍实时核注释 tag、固定提交、成功 Action 和 GitHub Release，证据路径/摘要进入固定输入且变化即拒绝，不因此获得发布授权。外部发布结果不明时，只对原意图与本地完整附件逐件匹配远端附件 SHA-256 后接受；缺摘要或身份不符即停止人工核对，不重发。APP 消费已公开 P1 包，不依赖此维护者编排或私有 Project。

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
