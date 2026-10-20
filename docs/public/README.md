# Peanut Admin 产品使用资料

面向APP开发者、部署者、模块开发者及API使用者。本目录随脚手架模板和APP开发源码版本化交付，不依赖维护者私有Project或本机绝对路径。

| 目标 | 唯一说明 |
| --- | --- |
| 选择来源、理解版本与升级对象 | [发行渠道与版本身份](release-channels.md) |
| 首次配置并安装新实例 | [安装指南](installation.md) |
| APP制包、部署更新与恢复 | [APP发行与server-only](application-release.md) |
| 查接口、刷新SDK | [API合同与SDK](api-and-sdk.md) |
| 编写和交付业务模块 | [模块开发](module-development.md) |

脚手架模板包含继续开发所需源码；APP源码由用户自己的Git管理。APP默认部署发行物只有server-only包，包含后端及`server/public/`浏览器成品，不包含开发源码全集、Git、实例秘密或数据。

server-only包内的许可证、第三方告知、发行身份位于`server/.peanut/`，依赖锁位于`server/composer.lock`，安装/更新工具位于`server/docker/scripts/`。完整使用手册阅读同一APP来源版本，不把开发分支的新说明当作旧实例已经具备的能力。
