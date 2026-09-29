# Peanut Admin 使用资料

本目录随产品源码和固定发行包提供，面向部署者、模块开发者与 API 使用者。它不依赖维护者的私有 Project 仓库。

- [发行渠道与版本身份](release-channels.md)
- [取得发行包并首次安装](installation.md)
- [API 合同与 SDK](api-and-sdk.md)
- [模块开发与交付](module-development.md)

完整开发源码包保留 APP 根目录的说明、许可证、第三方告知、固定依赖锁、生成的 SDK 类型及现有升级工具。server-only 生产包只含 `server/`，其发行身份、许可证及第三方告知在 `server/.peanut/`，依赖锁在 `server/composer.lock`；升级/恢复入口另行交付。以各包自身的发行清单判断版本，阅读旧发行包自带的旧文档不构成漂移。
