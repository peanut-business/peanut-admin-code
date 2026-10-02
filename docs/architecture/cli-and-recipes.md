# Peanut CLI 与可选 Recipes

状态：已批准的架构；CLI MVP 0.1.0。初始官方 Recipe 为 `github-ci@1.0.0`。

工具登记集成候选：`github-ci@1.1.0` 已有本地源码，但 catalog 默认仍为 `1.0.0`，未运行新版本测试或 Actions。候选通过三个 workflow 复用一套项目工具要求，保留只读权限、手动启动与现有 baseline 保护；准备、缓存和激活 Gate 见[项目工具指南](../development/project-toolchain.md#下游应用与可选-ci)。本轮不修改 `1.0.0` 的模板、manifest 或已安装状态。

## 1. 职责

Core Scaffold 是平台无关的应用底座，交付后端、客户端、模块、原生依赖锁、安装、发行、升级及公开开发资料。新派生 APP 默认不生成 `.github/`，资源登记不预设 GitHub Actions 数据库。源码仓自身的 GitHub 工作流保持其维护者职责。

CLI 是统一 DX 入口，负责参数、诊断、状态与已有合同的编排。它归 Code 的产品工具，随 APP 源码交付，无需私有 Project、全局 npm 包或新增运行依赖。PHP/Web Core 不反向依赖 CLI。

Recipe 是可选的交付方案，拥有独立版本、manifest 和文件 baseline。`github-ci` 使用 GitHub Actions；后续 GitLab/Custom 沿用 Recipe 合同，并按实际需要增加路径范围与适配，不把平台能力放回 Core。当前只支持本地官方目录，不下载或执行远程 Recipe 代码。

| 层 | 入口 / 文件 | 归属 |
| --- | --- | --- |
| 应用创建 | `scripts/create-app`、application manifest、scaffold baseline | Core Scaffold 合同，由 Code 装配 |
| 开发入口 | `scripts/peanut`、`scripts/cli/` | CLI |
| Recipe 定义 | `recipes/catalog.json`、`recipes/<id>/<version>/manifest.json` | 独立 Recipe 版本 |
| Recipe 安装状态 | `.peanut/recipes/<id>/manifest.json`、`source-manifest.json`、`baseline/files/` | 对应 APP 的 Recipe baseline |
| Recipe 生成文件 | `.github/workflows/ci.yml`、`release.yml` | `recipe:github-ci` |
| 下游扩展与秘密 | APP 自有代码、资源、环境与 CI 定制 | APP；既有保护继续生效 |

## 2. MVP 使用

在 APP 根目录执行；`--path` 也可指定另一 APP，读取的 Recipe 来源始终是当前 CLI 自己的版本化目录。

```sh
./scripts/peanut doctor
./scripts/peanut status
./scripts/peanut recipe list
./scripts/peanut recipe status
./scripts/peanut recipe add github-ci
./scripts/peanut recipe status github-ci
./scripts/peanut status --path /absolute/application
```

入口有可执行权限，也可用 `php scripts/peanut ...`。把其绝对路径链接到自己的 PATH 后命令名就是 `peanut`；本批不安装全局链接或发布 CLI 包。输出为 JSON；退出码 0 表示命令完成，doctor 1 表示工具/输入不齐，2 表示输入、合同或冲突错误。帮助为 `--help`。

doctor 只检查本机 PHP 8.3/扩展、Node/npm/pnpm 可用性、原生锁与必要 JSON/文件，以及 Recipe 安装是否完整。它不会安装依赖、读取秘密、连接数据库、启动服务或证明运行健康。原有 `scaffold-doctor` 的资源绑定检查保持独立，本批没有把其运行资源守卫移到普通 doctor。

status 区分 APP、源码 checkout、APP 版本、scaffold 来源、Core/version contract、Recipe 版本及逐文件 unchanged/modified/missing。存在 `.peanut/upgrades` 只报告状态目录存在，不把它解释为升级失败或许可。

## 3. 安装与归属

`github-ci@1.0.0` 携带当前应用使用的锁定依赖 CI 模板（修正精确 Core 约束不应因 Composer 的一般 warning 被 `--strict` 阻断），以及调用现有 `scripts/package-release.sh` 的 Release candidate 工作流。两者只通过 `workflow_dispatch` 启动，同步提交不会启动构建；Release 工作流上传现有制包器生成的制品和 manifest，正式发布、签名资格与部署继续按应用自身门禁。

安装先验证应用身份、Recipe manifest、SemVer、全部模板摘要和目标路径，再整体检查文件占用与 application manifest 的 ownership。已存在的文件即使字节相同，也不能在首次安装中被静默收编；旧 scaffold 声明的 CI 文件必须通过原有显式 adoption/冲突机制另行处理，本 MVP 不修改旧 baseline。

所有写入复用 `ScaffoldPathGuard` 与 `ScaffoldManifest::path`；新增文件决策复用 `ScaffoldManifest::additionDecision`，scaffold upgrade runner 同样调用该方法。使用独占创建，拒绝符号链接、越界与目标占用。安装在 `.peanut/recipes/.<id>.installing` 准备 baseline，文件完成后原子提交状态目录。可捕获失败只回收本次创建且仍匹配摘要的文件；进程中断残留的 installing 目录报告 recovery_required，不自动重放或删除现场。失败可能保留空目录。

重复 add 返回已安装版本和定制状态，不覆盖、修复缺失文件或升级版本。模板库本身可随 scaffold 工具更新，已安装的版本和 baseline 独立保留。安装不会改 application manifest、scaffold baseline、资源、Git remote、GitHub 配置或凭据。提交 APP 时应同时提交生成工作流与 `.peanut/recipes/<id>/`。

## 4. 后续升级合同

Recipe manifest 使用 `peanut.recipe.v1`，逐文件记录 path、managed classification、`recipe:<id>` owner、mode 和 SHA-256；安装状态使用 `peanut.recipe-installation.v1`，保留原 manifest 字节摘要、独立版本和逐文件 baseline。产品、APP、scaffold、Core 与 Recipe 的版本互不代替。

未来 Recipe update 必须把旧 baseline、下游当前文件及新版本文件送入现有 scaffold 三方差异、冲突、计划确认、恢复合同；增加 Recipe owner/状态位置适配，禁止复制一套 upgrade/release engine，禁止用 add 覆盖升级。卸载、adoption、跨版本更新、下载、完整 create/upgrade、GitLab/Custom、远端 Variables/Secrets 配置尚未实现。当前文件范围只允许 `.github/workflows/*.yml`，其他 Recipe 需经明确实现后扩展。

现有 APP 和不可变 release/scaffold 候选不因本决定自动迁移。新的可变模板 inventory 采用平台无关默认；固定候选仍按其原 manifest 消费。scaffold 更新遇到旧 baseline 的 `.github/` 文件从目标删除时报告 `recipe_ownership_transition_required`，要求显式归属决定，避免把解耦解释为自动删 CI。未来公开发行需重新完成受影响资格，本批的本地测试不代表 Actions、正式发行或部署验收。
