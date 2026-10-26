# Peanut Admin 产品使用资料

面向APP开发者、部署者、模块开发者及API使用者。本目录随脚手架模板和APP开发源码版本化交付，不依赖维护者私有Project或本机绝对路径。

| 目标 | 唯一说明 |
| --- | --- |
| 选择来源、理解版本与升级对象 | [发行渠道与版本身份](release-channels.md) |
| 首次配置并安装新实例 | [安装指南](installation.md) |
| APP制包、部署更新与恢复 | [APP发行与server-only](application-release.md) |
| 查接口、刷新SDK | [API合同与SDK](api-and-sdk.md) |
| 编写和交付业务模块 | [模块开发](module-development.md) |
| 产品首次开发与定版流程 | [产品开发流程](#产品开发与定版) |

## 产品开发与定版

脚手架模板的 ST、MT 是两个独立产品包；模板创建和升级继续使用 Peanut CLI。创建产品时先准备全新的空目录和空数据库，再由 CLI `create` 生成源码；`create` 只写入源码，不安装依赖、不建库、不启动服务，也不初始化产品 Git 仓库。之后按生成项目的锁文件安装依赖，通过安装向导完成首次安装，再在该产品自己的 Git 仓库 `dev` 分支持续开发。CLI `upgrade` 是在已有产品源码中按模板 baseline 做三方升级，不是 Git 分支合并，也不是生产更新。不要把创建工程、安装数据库和生产部署混成一个动作。

产品定版由维护者审核后合入产品仓库 `main` 并创建对应 tag。生产目标流程是首次从产品 `main` 在全新空目录部署，后续在原目录更新 `main`，按变更准备锁定依赖、必要构建并执行尚未应用的迁移，再核对访问和运行状态。该生产 Git 流程仍待完整工程接通和验收；当前的固定 Git 输入只准备并验证固定来源的独立目录，原生更新器可接收该目录，但没有从产品 `main` 更新活动生产目录的完整入口与编排。

现阶段 APP `server-only` 包和实例内 `server/docker/scripts/update.sh` 更新合同仍然有效。请按[安装指南](installation.md)、[发行与更新说明](application-release.md)选择当前可执行入口；本页所述目标流程不表示功能已交付，也不废弃既有包更新能力。

脚手架模板包含继续开发所需源码；APP源码由用户自己的Git管理。APP默认部署发行物只有server-only包，包含后端及`server/public/`浏览器成品，不包含开发源码全集、Git、实例秘密或数据。

server-only包内的许可证、第三方告知、发行身份位于`server/.peanut/`，依赖锁位于`server/composer.lock`，安装/更新工具位于`server/docker/scripts/`。完整使用手册阅读同一APP来源版本，不把开发分支的新说明当作旧实例已经具备的能力。
