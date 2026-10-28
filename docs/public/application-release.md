# 产品 Git 交付与生产更新

本页是产品定版、首次部署和原目录更新的现行合同。产品源码归自己的 Git：`dev` 开发，经人工审核后合入 `main` 并打对应 tag，生产采用已核定的 `main` 提交。脚手架模板仍用于 CLI `create` / `upgrade`；B 部署包、`peanut package` 和 `release/apps` 已退出日常生产流程。

现行更新入口是产品自己的 `scripts/deploy-update.sh --target=<完整commit>`，按下文选择后台和执行环境。入口在原 Git main 目录 ff-only 更新、按变化构建后台、执行原生迁移及清缓存；真实环境的采用、访问和业务验收仍须分别确认。
## 1. 定版和来源

产品保留独立版本、Edition、上游模板 baseline 和自己的业务定制。吸收脚手架变化使用 CLI 的三方升级，不以 Git 合并上游或重新创建产品代替。完成产品开发和相应验证后，再按授权审核 `dev` → `main` 及 tag；提交、定版、正式发行和生产采用分别记录。

生产记录产品仓库、精确 commit/tag、版本、Edition 和依赖锁。只读仓库授权由主机秘密机制提供，不进入仓库、URL 或日志。不能从开发分支日期、tag 存在或 CI 构建成功推断该组合已通过发行资格。

## 2. 程序与实例数据边界

| 对象 | 管理方式 |
| --- | --- |
| 后端、前端源码、默认配置、迁移、脚本及依赖 manifest/lock | 产品 Git 跟踪，按已审核提交更新 |
| vendor、node_modules、构建产物 | 按实际锁和原生构建入口准备，遵守产品忽略规则，不擅自提交产物 |
| `.env`、凭据、证书、仓库授权 | 未跟踪且忽略，由实例 owner 安全提供 |
| uploads、DB/卷、安装身份及防重装标记 | 未跟踪且忽略，普通程序更新保留 |
| runtime、日志、缓存和队列运行数据 | 未跟踪且忽略，只执行本次变化必要的缓存/进程操作 |

添加 `.gitignore` 不会取消已跟踪文件。实施前必须核真实追踪状态与差异；不能用 `reset --hard`、`clean`、无边界目录覆盖或删安装锁消除冲突。退役程序由明确受管差异处理，不删除上传和未知文件。

## 3. 生产首次部署

1. 核产品、固定目录、独立生产库、定版提交和有效恢复条件；首次安装要求全新空目录、空库。既有或 partial 实例不能按新建处理。
2. 取得产品 `main` 的已审核提交，记录实际来源；不在生产隐式吸收脚手架升级。
3. 按实际原生锁准备 PHP/客户端依赖和必要前端构建，配置生产环境及登记资源。沿用已有包管理器，不用无锁的安装替换固定依赖。
4. 通过产品原生服务入口启动并走[安装页面向导](installation.md)，完成安装、访问和本次业务确认，保留安装身份及防重装保护。

生产可能需要前端构建，不能沿用旧 server-only 包“服务器无需构建”的假设。依赖、静态资源、程序和 schema 必须对应同一采用版本。

## 4. 已有生产原目录更新

固定当前实例和目标 `main` 提交 → 审阅受管差异并准备真实有效恢复材料 → 按实际影响停写、排空 → 原目录更新程序 → 锁变化时更新对应依赖 → 前端变化时构建受影响客户端 → 执行尚未应用的迁移 → 必要缓存/进程更新 → 确认访问与本次业务。

无变化的层不重复处理。首次安装、迁移和上游源码升级是不同动作；不重新初始化数据库、改写已应用迁移历史或重置管理员。未知结果先对账，不重复副作用。

代码回退不等于数据恢复；旧代码必须与当前 schema 兼容。开放写入后不能自动回灌旧数据库覆盖新业务数据。失败时保留实际效果、原计划和 journal，按明确恢复边界处理。

## 5. 执行入口与失败边界

完成前述目标及恢复准备后，在产品根运行：

```sh
scripts/deploy-update.sh --target=<已审核的完整main提交>
```

宿主 PHP 部署在调用前自行完成停写和进程管理。挂载式 Docker 实例显式选择其原 Compose 项目：

```sh
scripts/deploy-update.sh --target=<已审核的完整main提交> --compose-project=<该实例原项目名>
```

脚本要求实际根、main 和无已跟踪修改；fetch 后目标必须仍等于 origin/main，漂移即拒绝；只做 ff-only，不允许改变实例 Edition。固定 pending 同时保存旧/新提交、Edition、后台选择和 Compose 项目，失败后须以相同参数接续并先核实际效果。

默认后台选择取 APP Edition：standalone 为 admin，multi-tenant 为 admin,platform；可显式传 `--clients=admin` 或 `--clients=admin,platform`，standalone 不允许平台后台。仅构建受影响的已选后台，插件装配或 Core Web 变化也只影响所选范围。PC/H5 源码和已有产物保留，本入口不更新它们；需要更新时另按该产品已审核的客户端范围安排。

Admin 沿现有 `pnpm run build` 并显式指定 `--mode standalone` 或 `--mode multi-tenant`，平台沿现有 `npm run build`。输出直接采用到该 APP `server/public/admin`、`server/public/platform`，与挂载式 Nginx 的 HTTP 根一致。脚本拒绝链接或已跟踪的静态目标，Vite 只清空对应后台产物目录。依赖缺失或相关 manifest/lock/Core Web 变化时，才按各工程原有锁安装。构建包含工程既有类型检查，不额外运行测试。

Docker 模式复用该实例 `configure-collaboration.py` 的原生 Compose 参数（包括适用 overlay），核配置及原有 vendor receipt，停止其 nginx/php 后更新程序。ThinkPHP 命令通过同一 php 服务的 owner 工作目录和 Compose 网络执行，不用宿主 PHP 猜测容器数据库地址；完成后复用 `start.sh -p <原项目名>` 恢复 nginx/php，重新建立只读 HTTP 上下文。其他应用写者须由部署 owner 在调用前排空，MySQL 数据卷保持原位。脚本不执行安装或 seed。

当前 Docker 模式尚不支持已安装 source APP 的锁依赖更新：检测到 `server/composer.json` 或 `server/composer.lock` 差异即在 Git 采用前拒绝。原生 `prepare-vendor.sh start` 拒绝修复 installed vendor，`prepared` 要求其正式暂存身份；不得伪造身份、借该模式或手工 seal 绕过。仅在锁无变化且原 vendor receipt 有效时复用依赖；无效时停止相关更新，由对应原生维护能力闭合后再采用。宿主模式仍按锁以 no-scripts/no-plugins 准备 Composer，并执行现有 service discovery/vendor publish 入口。

脚本失败保留 pending 和已发生的效果，Docker 流量可能仍处于停止状态。禁止盲重发、reset 或自动恢复旧库；下一次处理先核当前程序、依赖、静态构建、迁移和进程状态。恢复后仍需确认真实后台访问和本次业务，脚本成功不代表该验收完成。

旧 package-release、deploy-release、server/docker/scripts/update.sh 及其归档更新链已经退役。独立模板生产和源码三方升级继续存在，生产更新不重新生成产品、安装账号或初始化数据。进程更新、维护窗口、真实备份/恢复及业务确认按本次实际环境执行。本文不授予生产、main/tag、资金或破坏性数据操作权限。
