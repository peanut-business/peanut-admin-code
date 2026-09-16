---
title: 在线演示
description: Peanut Admin v3.0.14 的 Platform、共享管理端与 Tenant 绑定入口。
---

# 在线演示

以下入口运行在可丢弃的 `production-candidate` 体验实例，基础源码来自正式
[`v3.0.14`](https://github.com/peanut-business/peanut-admin-code/releases/tag/v3.0.14)，并叠加可追溯、只包含演示 seed
和部署回执接线的 overlay。overlay 有独立 commit 与 SHA-256，不会被冒充为正式 Release 源码。
它用于体验产品，不承载真实业务数据，也不代表你的生产环境已经完成部署或 Provider 资格。

| 入口 | 地址 | 账号 |
| --- | --- | --- |
| 实例 Platform | [pa-platform.007345.xyz/platform/](https://pa-platform.007345.xyz/platform/) | `platform@pa-demo.example` |
| 共享管理端（可选择 Tenant A / B） | [pa-admin.007345.xyz/admin/](https://pa-admin.007345.xyz/admin/) | `tenant-a@pa-demo.example` |
| Tenant A 管理端 | [pa-tenant-a.007345.xyz/admin/](https://pa-tenant-a.007345.xyz/admin/) | `tenant-a@pa-demo.example` |
| Tenant B 管理端 | [pa-tenant-b.007345.xyz/admin/](https://pa-tenant-b.007345.xyz/admin/) | `tenant-b@pa-demo.example` |
| 默认 Tenant 管理端 | [pa-admin.007345.xyz/admin/](https://pa-admin.007345.xyz/admin/) | `admin@pa-demo.example` |

本次预发布的访问密码由部署 owner 在交付回复中提供，不写入公开文档。演示策略会拒绝受保护的
破坏性写操作；请勿输入真实个人信息、支付凭据、OAuth 密钥或其他敏感数据。

要创建自己的隔离实例，请从[快速开始](/getting-started)进入。完整源码身份与适用边界见
[版本与发布](/releases)。
