---
title: 版本与发布
description: 认识源码 Release、双 Edition 安装包、升级包与派生应用版本。
---

# 版本与发布

产品/Core/双 Edition 的同号规则，以及客户 Instance、Module 的独立版本含义，见[产品与实例版本](guide/version-identity.md)。已发布 v3.0.14 实际采用历史 Core Alpha.13，不回写旧 tag 或制品来制造一致。

## 先分清你要下载的东西

Peanut Admin 的源码 Release、正式安装包、升级包和派生应用版本各自解决不同问题：

| 交付物 | 用途 | 可以做什么 |
| --- | --- | --- |
| 源码 Release | 核对 Peanut Admin 的公开源码身份 | 核心开发、审阅和生成定制应用 |
| Edition 安装包 | 第一次部署一个全新应用 | 在空环境中安装 Standalone 或 Multi-tenant |
| Edition 升级包 | 更新已经部署的应用 | 只在同一个 Edition 内按兼容范围升级 |
| 派生应用版本 | 你自己的业务仓库和发布线 | 维护业务代码、配置和第三方 Module |

完整安装包不能当升级包使用：它没有办法安全区分用户业务代码、第三方 Module、环境配置和 Peanut Admin 管理文件。Standalone 与 Multi-tenant 之间也不是普通版本升级；需要转换时必须另行设计数据归属、Schema、停机和恢复方案。

## 双 Edition 正式附件

当前正式版本是 [`v3.0.14`](https://github.com/peanut-business/peanut-admin-code/releases/tag/v3.0.14)。它从同一冻结源码提供
Standalone 与 Multi-tenant 安装包，并分别提供从 `3.0.13` 到 `3.0.14` 的同 Edition 升级包；不得跨 Edition
套用，也不得用完整安装包覆盖已有应用。每个 archive 都必须与 Release 中的 manifest、`SHA256SUMS`
或 `SHA256SUMS.upgrades` 核对。

> [!WARNING] v3.0.14 已知依赖风险
> 发布后的锁文件复核发现前端依赖仍有 high/moderate advisory，因此 v3.0.14 不宣称漏洞清零。
> `main` 已修复 Platform、PC、Web 的已识别 high；UniApp 仍受当前 DCloud Vue 3 工具链固定的
> Vite 5.2.8 约束。需要这些修复的用户应等待下一次完成资格的正式 Release，不要把开发分支当成制品。

正式版本号和附件列表以 [GitHub Releases](https://github.com/peanut-business/peanut-admin-code/releases) 页面为准。下面的 `X.Y.Z` 是文件命名示意，实际值必须直接采用 Release 页面显示的值。

从首个正确 Edition 分发基线开始，每个正式 Release 会随附件提供以下两套安装物：

```text
peanut-admin-X.Y.Z-standalone.tar.gz
peanut-admin-X.Y.Z-standalone.tar.gz.manifest.json
peanut-admin-X.Y.Z-multi-tenant.tar.gz
peanut-admin-X.Y.Z-multi-tenant.tar.gz.manifest.json
SHA256SUMS
```

首个 Edition 分发基线没有可诚实支持的旧 Edition，因此只提供安装包，不提供升级包。后续版本
才会提供下列两套升级物，并把该基线或更高受支持版本写进兼容范围：

```text
peanut-admin-X.Y.Z-standalone-upgrade.tar.gz
peanut-admin-X.Y.Z-standalone-upgrade.tar.gz.manifest.json
peanut-admin-X.Y.Z-multi-tenant-upgrade.tar.gz
peanut-admin-X.Y.Z-multi-tenant-upgrade.tar.gz.manifest.json
SHA256SUMS.upgrades
```

基线 Release 缺少升级包是明确的产品边界，不代表可以用完整安装包覆盖已有目录。如果其他正式
Release 尚未列出声明应有的 Edition 附件，不要根据文件名猜测下载地址，也不要使用开发分支或
另一个 Edition 的制品。安装包的下载、校验、配置、安装和登录步骤见[快速开始](/getting-started)；
已部署应用的升级步骤见[部署与升级](/guide/deployment-upgrade)。

## 如何核对制品身份

下载后，将 archive 的 SHA-256 与 Release 页面、对应外部 manifest 和 `SHA256SUMS`（升级包使用 `SHA256SUMS.upgrades`）中的同名记录逐字比较。manifest 至少应与文件名相符地声明：

- Edition（`standalone` 或 `multi-tenant`）；
- 产品/目标版本；
- archive 文件名与摘要；
- 安装包或升级包的协议类型。

升级包还要核对源版本范围、目标版本、migration chain、受管文件和恢复边界。摘要或身份不一致时保持现有应用不变并停止；不要修改 manifest、checksum 或 plan 来让检查通过。

## 源码 Release 与部署不是一回事

- Git Tag 和 Release 提供不可变的源码身份。
- 正式安装包和升级包是从同一个冻结 Release 生成的、各有 Edition 标识的制品。
- 资格结果只说明某个固定候选通过了约定检查；部署结果只说明某个应用环境实际采用了一个版本。
- 派生应用可以在自己的仓库中拥有独立版本，不会改变 Peanut Admin 的源码 Release。

报告版本时，请分开写出源码、制品、已部署实例和 smoke 验证分别完成了什么；不要把其中一个状态当成另一个状态。

## 定制应用的版本边界

普通用户直接下载所选 Edition 的正式安装包即可。只有需要自定义名称、slug、package identity 或加入业务代码时，才从固定正式 Release 使用 `create-app` 生成派生应用。生成目录随后进入用户自己的业务仓库，并由用户维护业务代码、环境、发布和备份；它不是新的 Peanut Admin 官方源码仓库，也不取代正式安装包。

## 升级入口

升级前固定当前 Edition、源版本和目标版本，先按[部署与升级](/guide/deployment-upgrade)完成包校验、preflight、备份、冲突计划、apply、migration/依赖/构建、verify 和 recover 准备。升级包只接受发布说明声明的同 Edition、同大版本兼容范围；超出范围时按 Release 的 fresh/rebuild 方案处理。
