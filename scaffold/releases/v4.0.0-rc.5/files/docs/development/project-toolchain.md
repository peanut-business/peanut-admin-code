# 项目工具登记与本机绑定

本入口只选择已经安装的工具，不安装 mise，不改全局 PATH，不复制 vendor/node_modules，也不自动下载、升级、测试、启动服务或发布。首批验证范围是现有 macOS 本机；不宣称完成跨平台验证。

## 唯一来源

- `tools/toolchain.json`：随项目提交的工具版本要求。pnpm 读取 `web/package.json` 的 `packageManager`，Composer 读取已有 `scripts/project-composer` 的版本和 SHA-256，不维护第二份手工版本。
- `.local/toolchain/host.json`：本机私有绝对路径、解释器与 CLI 文件绑定、缓存路径。由 `tools/toolchain.local.example.json` 提供格式，不提交真实桌面路径或凭据。
- `scripts/project-env`：统一入口；可由系统已安装 Python 3.9+ 启动。项目业务命令仍使用登记的 Python 版本。入口不会解决工具缺失，缺失或版本不符须先由授权的环境维护操作修正。

## 使用

先按示例登记本机已经安装的六类工具。npm/pnpm 必须绑定同一个登记 Node 与对应 CLI 文件，Composer 必须绑定登记 PHP 与固定 PHAR；原生程序可以使用系统安装，只要实际版本匹配。缓存目录须已经存在。

```sh
python3 scripts/project-env requirements
python3 scripts/project-env doctor
python3 scripts/project-env prepare
python3 scripts/project-env exec -- peanut status
python3 scripts/project-env exec -- bash scripts/package-release.sh /absolute/new-output --application-root=/absolute/application
```

`requirements` 不需要本机配置，可供 CI 读取。`doctor` 只读检查；`prepare` 只在本项目 `.local/toolchain/project-env/` 建立薄命令入口及本项目临时目录，不安装第三方包。`exec` 只使用已经准备好的登记，不自动 prepare；子进程继承同一工具 PATH 和缓存，避免 PHP→Python→pnpm 再次选错版本。缺失、不符或登记改变时退出 2；成功 exec 后保留实际子命令退出码及原始标准输入/输出。首次 `prepare` 先创建项目临时目录，再执行工具版本探测，避免 pnpm 在不存在的 TMPDIR 上失败。`doctor` 遇到缺失的项目临时目录只报告需要显式准备，不创建目录；版本命令非零时报告实际子命令退出码及错误片段，不把错误栈中的依赖版本误当成工具版本。

入口会创建工具入口，不是新的隔离系统或包管理器。命令本身仍具有调用者权限；选择已信任的配置和程序。不要用此入口绕过资源、测试、发布或部署授权。Peanut CLI 是独立安装的全局工具；这里的 project-env 只负责为当前项目选择登记工具环境，并在对应 Node bin 已安装 `peanut` 时调用它，不再在 Scaffold Product Token 内复制业务 CLI。

## 速度与缓存

同一登记、工具文件身份、关联配置和关键进程环境不变时，exec 复用 prepare 的版本检查结果，不在每个命令前扫描全机或安装依赖。修改项目要求、本机路径、被观察 PHP 配置等后，需要显式 prepare。`watch_paths` 可登记 PHP ini 文件和扫描目录；不读取或打印其内容。一次 prepare 不代替完整产品资格。

pnpm、npm、Composer 各用自己的共享缓存。Node/pnpm/Composer 工具本体使用登记的已有文件；不同项目可以绑定同一份匹配版本，也可以绑定各自版本。不同包管理器大版本由其自身划分存储格式，不复制或清空其他项目缓存。vendor/node_modules 仍按各工作树原生锁安装；缓存只改变速度，不能绕过 frozen lock 或来源校验。

CI 安装工具的步骤先从 `requirements` 输出读取版本，再绑定 CI 自己的路径并 prepare；不得复制本机 host.json 到 CI。此批不触发 CI，也不替已有 Recipe 偷改 baseline。新的 CI 配方接入必须更新其版本与摘要；固定历史发行候选保持原样。

## 下游应用与可选 CI

下游交付约定是通过同一可变模板 inventory 接收 `scripts/project-env`、`tools/toolchain.json`、本机配置示例和本指南；公开 AGENTS 引导使用该入口。维护者必须先用原生生成器更新 inventory 并核对来源，之后正常生成应用；仅修改生成器或入口文档不代表已经完成交付。生成应用不携带真实 `host.json`、维护者资源或凭据，也不自动安装 GitHub 工作流。工具要求是可定制的 managed 文件，升级遇到本地修改沿既有冲突流程处理；历史应用不因模板更新被直接改写。

`github-ci@1.1.0` 候选源码和 catalog 已归独立 `peanut-cli` 仓库管理；Scaffold Product Token 不再保存第二份 Recipe 源码。CLI `v0.1.0` 的 catalog 仍指向 `1.0.0`，`peanut recipe add github-ci` 不会自动升级既有 Recipe。激活新默认必须在 CLI 仓库完成相应回归及 CI 验证并发布新的 CLI/Recipe 版本；既有 Recipe 的 update/adoption 尚未实现，不能用 add、复制文件或更换 baseline 代替升级。

新候选由手动触发的 `ci.yml`、`release.yml` 复用 `toolchain.yml`。后者只接受 `workflow_call`，统一读取 `project-env requirements`，按项目版本准备一次 Node、pnpm、PHP、Python 和固定 Composer，再生成 CI 自己的私有路径绑定并执行 prepare。后续命令均经 project-env，不继承维护者电脑路径。固定版本不可取得、实际版本或 PHP 扩展不符时失败，不自动放宽为最新版本。CI 引入的下载、依赖安装、编译及工件上传只有在后续获得执行授权并启动工作流时才发生。

缓存只保存 pnpm/npm/Composer 包缓存，并按 runner 系统、架构、工具要求及依赖锁区分；不缓存 host.json、工具 shim、vendor、node_modules、凭据或数据库。CI 保留原有应用布局、后端语法与客户端构建步骤；server-only candidate 使用既有 package-release 入口，上传服务端归档和对应外部 manifest，不签名、不创建 Release、不部署。

待验证：工具精确版本在 Linux Actions 中的供应、实际安装路径、缓存恢复、生成 APP 的入口闭合，以及独立 CLI Recipe 在真实 Actions 环境中的执行。当前 Python 与 PHP 精确版本来自本机登记，不代表已经证明 hosted runner 可以取得同版本。Recipe 安装/重复/冲突回归归 `peanut-cli` 仓库；Scaffold Product Token 只保留自身 scaffold 的归属迁移保护。

## 固定发行候选

环境入口可以在维护工具 checkout 中执行已有的原生发行脚本，脚本通过 `--source-commit` 读取原本固定的源码。工具入口所在提交和实际发行 source commit 必须分别记录，不能把工具提交冒充制品来源。旧 source/tree、snapshot、成功制品与失败日志不可覆盖；失败后仅在确认旧进程终止、诊断已明确、授权允许时使用新的输出位置恢复未完成步骤。
