# 上游发行与冻结候选生产者

仅供Peanut源码维护者使用。普通APP使用`docs/public/application-release.md`的server-only入口；不按本文件在日常开发中反复派生测试APP。内部完整候选仅保留现有冻结消费者的原合同，不等于公开发行、生产安装或新的默认流程。

候选首装与独立 APP 派生先用 `freeze-core-release --php-core-dir=<固定干净PHP仓> --web-core-dir=<固定干净Web仓> --version=<统一精确版本> --output=<不存在的绝对目录>` 生成一个 PHP ZIP、六个 Web TGZ 及实际 `release-core-inputs.json`，记录来源 commit/tree、版本与摘要；无需先公开发布 Core。随后固定同一 Code 源码，依次为 MT、ST 调用 `build-scaffold-release --version=<同一版本> --source-commit=<固定Code提交> --edition=multi-tenant|standalone --workflow=full --internal-core-inputs=<冻结回执绝对路径> --output=<各自不存在的绝对目录>`。该脚手架候选通过原生 Composer/pnpm/npm 投影双 Core 声明及锁，携带七个包，将真实投影文件与归档纳入受管清单及 baseline；非 Core 锁定版本与来源保持不变。

再从所选形态的真实 `scaffold-manifest.json` 生成 APP：现有 `create-app` 使用 `--source-channel=development --source-commit=<同一固定Code提交> --source-tree=<对应tree> --scaffold-manifest=<候选清单绝对路径> --profile=full --workflow=full` 及正常产品名称、slug、package、target、edition 参数。候选受管程序和固定归档直接取自该产物，复用安全路径读取、文件摘要、品牌 token 与 Composer content-hash 渲染；五个 app-owned 初始默认仍由同一固定生成器生成。严格核固定来源、inventory、形态、完整受管集合及七包摘要，不接受候选新增路径抢占 app-owned、excluded 或未知文件。品牌相关 Plugin/API 元数据按最终 APP 字节由既有生成器重建，记录真实 baseline 和所消费候选清单摘要。`generation_source` 保留 development/null，`scaffold_candidate.formal_release_eligible=false`，不把未公开候选标成已发布版本。APP 的 Composer/npm/pnpm 依赖通过自身携带的 packages 相对路径与原生锁安装；不得依赖维护者 Core checkout、私有 Project 或临时外部 file 路径。候选生成、APP 自有依赖安装与实际首装分别记录结果，生成源码不等于运行或资格通过。

正式交付在固定候选完成所需资格及人工 Gate 后，依次公开 Core、核公开渠道可取的精确版本与来源，再生成正式 APP 依赖、锁和发行包；main/tag、正式发布及生产授权保持独立，内部候选构建不代替这些 Gate。

维护者内部完整 APP 使用 `build-internal-edition-candidate --source-commit=<固定提交> --edition=multi-tenant --profile=full --workflow=full --core-inputs=<冻结回执绝对路径> --output=<不存在的绝对目录>`。edition、业务 profile 与 workflow 分别传入原生生成器；当前内部完整候选只接受 full/full，固定选择 Admin、Platform、PC SPA、UniApp H5。PHP ZIP 与六个 Web TGZ 原字节全部携带，绑定冻结回执、Git 来源、SHA-256；Web 原生锁使用从同一 TGZ 计算的 SHA-512 SRI。仅投影四端真实声明及 Core peer 闭包，不为 testing 等未消费包创造消费者或宣称运行覆盖。原生 Composer/pnpm/npm 生成锁后，非 Core 包的固定版本与来源身份必须保持不变；随后复用 `package-release.sh --internal-candidate` 在隔离目录 frozen install/ci、核实际安装位置及归档内容，再构建现有四端并组装完整 APP。安装阶段禁用生命周期脚本，Composer 使用 no-scripts；显式构建入口仍执行正常类型检查和构建，不运行治理测试、PHP 安装或 SQL。

内部投影保留原 `.peanut/application-manifest.json` 与 baseline 的字节和原摘要，以 `.peanut/internal-candidate.json` 单独记录依赖声明、锁和七包的投影树及新摘要；不把改写后的内容称为原始基线。封存源码校验与含四端浏览器产物的 APP release identity 分别核验，最终归档保留二开源码、原基线和全部七包，不携带 vendor/node_modules。回执只证明本次实际完成的输入、安装来源和四端构建；内部版本不等于公共发包，`formal_release.eligible=false`，不证明安装、升级或生产运行资格。源码实现与独立审查不代替构建；完整构建须在最终 dev 输入固定后单独授权，资源部署和测试仍遵守各自 Gate。

内部完整候选成功后正常清理临时现场；失败时 PHP builder 保留本次 0700 私有临时根，嵌套 `package-release.sh --internal-candidate` 同时保留实际 snapshot/build、原生 PM 锁、已安装状态及安装来源证明的实际输出（若已产生），不在内层 EXIT trap 提前删除。两层输出保留路径、调用 UID/PID 与清理责任；上下文文件使用 0600，不转储环境或凭据，不把失败材料装入交付归档，也不把部分证明称为通过。生命周期 owner 是该次真实 Build Session owner，诊断完成后按明确范围清理；超时或强制终止未执行 finally/trap 时，先对账同一 Job/子进程和已登记 staging 根，不启动重跑来重新取得现场。调用者的持久 Job 日志与输入回执位于临时清理范围外。

该 builder 的无依赖源码快照通过原生 `ApplicationCreator::apiGeneratorPath` 使用调用工程已准备的 `scripts/generate-api-contracts.php`。原生生成器必须先核工具与快照的 generator、Web manifest/lock、toolchain 字节相等，并核已安装 pnpm 锁、openapi-typescript 精确版本和 CLI 位置；APP 输入及输出仍取快照投影，不复制 node_modules、不临时下载，也不放宽身份检查。内部制包回执另记实际 generator 源码摘要、依赖锁摘要和经原生核对的工具版本；这不代替候选随后自身的隔离安装。

## 唯一发布器及main绑定

产品 GitHub Release 的发布入口是单一脚本 `scripts/publish-github-release`。默认稳定模式只接受不带 `v` 前缀的 `X.Y.Z`，要求存在注释式 tag `vX.Y.Z`，通过 candidate、tag、qualification、`main`、固定依赖和清单绑定检查后，创建正式 GitHub Release 并使用 `--latest`。公开预发行通道不是第二发布器，必须显式传入 `--prerelease`，版本只接受严格 `X.Y.Z-<prerelease>`（例如 `4.0.0-rc.1`），要求注释式 tag `vX.Y.Z-<prerelease>`，通过同一组 Gate 后创建 GitHub prerelease，且不得标记为 latest。稳定模式拒绝预发行版本；预发行模式拒绝稳定版本和非法 SemVer 预发行标识。没有明确授权时，不创建 `main`、tag、GitHub Release 或公开包。

产品 tag、qualification、候选锁、归档及 Edition 制品始终精确绑定已资格确认的源码提交 S 和源码树。实际发布要求实时远端 `main` 与本地 `origin/main` 一致，且等于 S；或等于同仓真实已合并 `dev` → `main` PR 的正常合并提交 M。后一种情况只接受精确父序列 `[B,S]`、B 为 S 祖先、M 与 S 同树，以及 GitHub API 的唯一 PR、merged 状态、提交、仓库和分支身份全部一致。开放 PR 的测试合并、squash/rebase、后续同树提交、未知或歧义 API 证据均拒绝，M 不被重新标为已资格确认的源码。

M 不等于 S 时，发布命令还须传入 `--main-integration=/absolute/premerge.json`。该外部回执只有七个字段：`schema_version=1`、`repository="peanut-business/peanut-admin-code"`、`source_commit=S`、`base_commit=B`、`ready_for_stable=true`、UTC ISO8601 `readiness_at` 与 `premerge_observed_at`；要求资格准备完成时间不晚于合并前观察时间，且观察时间严格早于真实 PR 的 `merged_at`。回执不改写原资格字节，也不代替原生资格检查或发布授权。发布器在输出目录保存独立 `MAIN_INTEGRATION_PROOF.json`，记录实时 main/tag、图、PR 及输入/API 摘要，供外部发布回执使用；它不改变候选锁或发布源码身份。创建 Release 前再次核 live main/tag 与 PR，任何漂移即停止。`--prepare-only` 仍只准备制品；Core 的当前 main 发布规则和候选检查器的 `--require-main` 精确合同保持原状。

## 固定公开依赖准备


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
