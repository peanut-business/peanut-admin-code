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
python3 scripts/project-env exec -- php scripts/peanut status
python3 scripts/project-env exec -- bash scripts/package-release.sh /absolute/new-output --application-root=/absolute/application
```

`requirements` 不需要本机配置，可供 CI 读取。`doctor` 只读检查；`prepare` 只在本项目 `.local/toolchain/project-env/` 建立薄命令入口及本项目临时目录，不安装第三方包。`exec` 只使用已经准备好的登记，不自动 prepare；子进程继承同一工具 PATH 和缓存，避免 PHP→Python→pnpm 再次选错版本。缺失、不符或登记改变时退出 2；成功 exec 后保留实际子命令退出码及原始标准输入/输出。首次 `prepare` 先创建项目临时目录，再执行工具版本探测，避免 pnpm 在不存在的 TMPDIR 上失败。`doctor` 遇到缺失的项目临时目录只报告需要显式准备，不创建目录；版本命令非零时报告实际子命令退出码及错误片段，不把错误栈中的依赖版本误当成工具版本。

入口会创建工具入口，不是新的隔离系统或包管理器。命令本身仍具有调用者权限；选择已信任的配置和程序。不要用此入口绕过资源、测试、发布或部署授权。Peanut CLI 与这里的工具环境入口分工：CLI 负责业务命令，project-env 负责先给这些命令选对工具；不复制一套业务 CLI。

## 速度与缓存

同一登记、工具文件身份、关联配置和关键进程环境不变时，exec 复用 prepare 的版本检查结果，不在每个命令前扫描全机或安装依赖。修改项目要求、本机路径、被观察 PHP 配置等后，需要显式 prepare。`watch_paths` 可登记 PHP ini 文件和扫描目录；不读取或打印其内容。一次 prepare 不代替完整产品资格。

pnpm、npm、Composer 各用自己的共享缓存。Node/pnpm/Composer 工具本体使用登记的已有文件；不同项目可以绑定同一份匹配版本，也可以绑定各自版本。不同包管理器大版本由其自身划分存储格式，不复制或清空其他项目缓存。vendor/node_modules 仍按各工作树原生锁安装；缓存只改变速度，不能绕过 frozen lock 或来源校验。

CI 安装工具的步骤先从 `requirements` 输出读取版本，再绑定 CI 自己的路径并 prepare；不得复制本机 host.json 到 CI。此批不触发 CI，也不替已有 Recipe 偷改 baseline。新的 CI 配方接入必须更新其版本与摘要；固定历史发行候选保持原样。

## 固定发行候选

环境入口可以在维护工具 checkout 中执行已有的原生发行脚本，脚本通过 `--source-commit` 读取原本固定的源码。工具入口所在提交和实际发行 source commit 必须分别记录，不能把工具提交冒充制品来源。旧 source/tree、snapshot、成功制品与失败日志不可覆盖；失败后仅在确认旧进程终止、诊断已明确、授权允许时使用新的输出位置恢复未完成步骤。
