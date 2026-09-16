---
title: 快速开始
description: 先选择 Edition，再从正式安装包完成校验、配置、安装和登录。
---

# 快速开始

第一次使用 Peanut Admin 时，先选择适合你的 Edition，再下载对应的正式安装包。安装包、升级包和源码 Release 是不同的交付物；不要用移动分支或另一套应用源码代替正式附件。

## 1. 先选 Edition

| Edition | 适合场景 | 入口特点 |
| --- | --- | --- |
| **Standalone** | 一个组织自建和使用一套管理系统 | 不需要在同一实例中管理多个组织；安装后直接进入本组织的管理端 |
| **Multi-tenant** | 一个实例服务多个相互隔离的组织 | 包含 Platform 管理入口、共享管理端和按 Tenant 隔离的业务入口 |

选定后，整个生命周期都要保持这个 Edition：首次安装下载对应安装包；后续升级只下载同 Edition 升级包。Edition 转换不是普通升级，完整安装包也不能拿来覆盖已有应用。

## 2. 下载所选 Edition 的正式安装包

正式版本号和下载附件以 [GitHub Releases](https://github.com/peanut-business/peanut-admin-code/releases) 页面为准。本文使用 `vX.Y.Z` 作为明确占位符；它不是一个可直接下载的版本。正式包发布后，安装包、外部 manifest 和 `SHA256SUMS` 会随 Release 附件提供。

### Standalone

在 Release 页面选择与版本完全一致的以下文件（实际版本以页面为准）：

```text
peanut-admin-X.Y.Z-standalone.tar.gz
peanut-admin-X.Y.Z-standalone.tar.gz.manifest.json
SHA256SUMS
```

也可以使用 GitHub CLI 下载同一组附件：

```bash
gh release download vX.Y.Z \
  --repo peanut-business/peanut-admin-code \
  --pattern 'peanut-admin-X.Y.Z-standalone.tar.gz' \
  --pattern 'peanut-admin-X.Y.Z-standalone.tar.gz.manifest.json' \
  --pattern 'SHA256SUMS'
```

### Multi-tenant

在同一个 Release 页面选择多租户附件：

```text
peanut-admin-X.Y.Z-multi-tenant.tar.gz
peanut-admin-X.Y.Z-multi-tenant.tar.gz.manifest.json
SHA256SUMS
```

下载命令如下；把 `vX.Y.Z` 和文件名替换成 Release 页面显示的正式值：

```bash
gh release download vX.Y.Z \
  --repo peanut-business/peanut-admin-code \
  --pattern 'peanut-admin-X.Y.Z-multi-tenant.tar.gz' \
  --pattern 'peanut-admin-X.Y.Z-multi-tenant.tar.gz.manifest.json' \
  --pattern 'SHA256SUMS'
```

如果对应 Edition 的附件尚未出现在正式 Release 页面，先停止，不要改用开发分支、临时地址或另一个 Edition 的包。

## 3. 校验安装包

在解压前完成校验：

```bash
shasum -a 256 peanut-admin-X.Y.Z-standalone.tar.gz
# Multi-tenant 安装时把上面的文件名替换为 multi-tenant 包
```

将输出与 Release 页面和同名 `.manifest.json` 中的 `archive.sha256` 逐字比较，并确认 manifest 的 Edition、版本和 archive 文件名与所选包一致。任一摘要不一致、文件缺失或 Edition 不匹配，都保持应用不变并重新下载正确附件。

校验通过后再解压：

```bash
tar -xzf peanut-admin-X.Y.Z-standalone.tar.gz
cd peanut-admin-X.Y.Z-standalone
# Multi-tenant 安装时进入对应的 multi-tenant 目录
```

## 4. 配置你的环境

安装包只提供中性的配置模板。复制模板，并只在你自己的部署环境中填写数据库、域名和随机密钥：

```bash
cp .env.example .env
cp server/.env.example server/.env
chmod 600 .env server/.env
```

在 `server/.env` 至少确认：

```dotenv
DEPLOYMENT_MODE=standalone
PEANUT_INSTALLATION_MODE=automatic
```

Multi-tenant 安装改为：

```dotenv
DEPLOYMENT_MODE=multi-tenant
PEANUT_INSTALLATION_MODE=automatic
PLATFORM_HOSTS=<your-platform-host>
TENANT_ADMIN_HOSTS=<your-tenant-admin-host>
```

同时填写你自己的 `DB_*` 连接参数和部署专用随机密钥。数据库必须是空库；不要清空或接管不确定归属的数据库。初始身份不要写进 `server/.env`；创建独立的 permission-0600 文件，并只在安装命令上用 `PEANUT_INSTALLATION_ENV_FILE` 选择它，命令结束后删除该文件。

## 5. 安装并登录

Standalone 只需要一组初始管理身份：

```bash
ADMIN_INITIAL_EMAIL='<your-admin-email>' \
ADMIN_INITIAL_PASSWORD='<your-strong-password>' \
php server/database/install.php
```

Multi-tenant 还需要一组与管理身份不同的平台身份：

```bash
ADMIN_INITIAL_EMAIL='<your-tenant-admin-email>' \
ADMIN_INITIAL_PASSWORD='<your-tenant-admin-password>' \
PLATFORM_INITIAL_EMAIL='<your-platform-email>' \
PLATFORM_INITIAL_PASSWORD='<your-platform-password>' \
php server/database/install.php
```

密码至少 12 位，并使用你自己生成的强密码。安装器会在确认空库后建立 Schema、当前 migration 账本和初始身份；命令输出不包含密码。安装完成后按安装包的部署方式启动应用，在你配置的管理端入口登录：Standalone 进入本组织管理端；Multi-tenant 先用平台身份进入 Platform，需要管理某个组织时再进入对应 Tenant 管理端。

如果安装器报告目标非空、版本/摘要不一致或身份不合格，停止并修正配置或换用明确的空库；不要直接重试覆盖已有数据。

## 6. 需要定制时再使用 `create-app`

只有需要自定义名称、slug、package identity 或加入自己的业务代码时，才从一个固定正式 Release 运行 `create-app`。普通安装不需要这一步。生成结果不属于 Peanut Admin 的新官方源码，也不会自动成为产品来源；生成完成后由你把目录放进自己的业务仓库，并为该仓库维护自己的环境、发布和备份记录。

已有应用升级请直接阅读[部署与升级](/guide/deployment-upgrade)，不要重新下载完整安装包覆盖现有目录。

## 如果你要参与核心开发

核心开发者才需要从固定源码 Release checkout 安装依赖并启动本地开发栈。该路径与普通用户的正式安装包消费路径分开：

```bash
git clone https://github.com/peanut-business/peanut-admin-code.git
cd peanut-admin-code
cp .env.example .env
cp server/.env.example server/.env
chmod 600 .env server/.env
composer install --working-dir=server
npm ci --prefix platform
pnpm --dir web install --frozen-lockfile
```

Platform 使用自己的 `package-lock.json`，Web 使用自己的 `pnpm-lock.yaml`；不要在
worktree 之间复制 `node_modules`。开发环境同样要选择 `DEPLOYMENT_MODE`，并使用空库和
显式初始身份。启动本地栈后，按命令输出的入口登录；不要把核心开发环境当成用户正式安装输入。

## 下一步

先读[核心概念](/guide/concepts)，再根据任务进入[开发总览](/guide/development)。已经有部署中的应用时，直接进入[部署与升级](/guide/deployment-upgrade)；需要生成定制应用和交付 Module 时，阅读[创建应用与交付 Module](/guide/application-module-lifecycle)。
