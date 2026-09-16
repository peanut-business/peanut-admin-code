# Peanut Admin

Peanut Admin 是基于 ThinkPHP 8、Vue 3、Element Plus、Nuxt 3 与 UniApp 的企业应用脚手架。
当前开发源码正在准备未发布的 `3.1.0` Application 候选；最新正式 Application Release 仍是
`3.0.14`。同一代码线支持单实例（`standalone`）和多租户（`multi-tenant`）部署，覆盖管理端、PC、
H5/小程序、Tenant 隔离和实例内平台管理。开发锁或准备检查通过不代表 `3.1.0` 已完成资格或发布。

[当前 Standalone 演示](https://peanut-admin.007345.xyz/admin/) ·
[当前多租户演示](https://pa-admin.007345.xyz/admin/) ·
[演示账号与入口](https://peanut-admin-doc.007345.xyz/demo-access) ·
[文档中心](https://peanut-admin-doc.007345.xyz) ·
[3.0.14 Release](https://github.com/peanut-business/peanut-admin-code/releases/tag/v3.0.14) ·
[1.x 历史 Release](https://github.com/peanut-business/peanut-admin-code/releases/tag/v1.1.5) ·
[更新日志](CHANGELOG.md)

## 当前版本能力

- 管理后台：菜单、角色、管理员、部门、岗位、字典、文件、定时任务、日志和系统设置。
- 业务模块：会员、标签、余额、通知、充值退款、文章、装修、热门搜索和客服设置。
- 多端应用：Vue 3 管理端、Nuxt 3 PC、UniApp H5/小程序。
- 多租户：默认 Tenant、可信 TenantContext、Tenant-first 数据访问、缓存/文件/任务/审计隔离。
- 实例内平台管理：独立 PlatformOperator、Tenant 生命周期、首个 owner 和 TenantModule 管理。
- 交付：3.0.14 canonical Schema 空库安装、同 Edition 3.0.13 → 3.0.14 升级包、Docker Compose 和不可变源码 Release。

`3.0` 不支持旧大版本数据库或脚手架原地升级，也不包含套餐、订阅、计费、试用、发票、
应用市场或跨实例运营平台。短信、支付、微信/OAuth 和对象存储仍需部署方提供真实凭据并
完成平台登记。固定候选已完成 P0-E 8/8，并发布 annotated `v3.0.14` tag、GitHub Release、
确定性源码包、许可证附件和 SPDX SBOM。当前线上 Standalone 与 Multi-tenant Demo 已按登记资源
提供体验；Multi-tenant Demo 使用 `v3.0.14` 正式源码加可追溯的 seed-only overlay，Standalone
仍是独立旧部署。它们是可丢弃体验实例，不替代
正式业务生产环境的独立部署证明。

## 技术栈

| 层 | 技术 |
| --- | --- |
| 后端 | ThinkPHP 8、PHP 8.3、JWT |
| 管理端 | Vue 3、Element Plus、Vite、TypeScript |
| PC | Nuxt 3、Element Plus |
| H5 / 小程序 | UniApp |
| 数据库 | MySQL 8.0.36+ / 8.4 |
| 生产运行 | Docker Compose、Nginx、PHP-FPM |

## 快速开始

### 1. 准备环境

- PHP 8.3、Composer 2.10.2（先运行 `scripts/project-composer prepare`）
- MySQL 8.0.36+ 或 8.4
- Node.js 20/22、pnpm 9

### 2. 创建定制应用与配置

普通用户首次部署应直接从正式 Release 下载 Standalone 或 Multi-tenant 安装包；只有需要改产品名、
package identity 或继续开发业务代码时，才从固定 tag 运行创建器：

```bash
git clone --branch vX.Y.Z --depth 1 git@github.com:peanut-business/peanut-admin-code.git
cd peanut-admin-code
php scripts/create-app \
  --name="Acme Console" \
  --slug=acme-console \
  --package=acme/acme-console \
  --target=/absolute/path/to/acme-console \
  --edition=standalone
cd /absolute/path/to/acme-console
git init
cp .env.example .env
cp server/.env.example server/.env
chmod 600 .env server/.env
```

需要一个实例服务多个组织时，把 `--edition` 改为 `multi-tenant`。创建器使用完整版本化
inventory，生成 `.peanut/application-manifest.json` 和受管文件基线；
应用业务与稳定 Host/override 入口属于 app-owned，不由 future scaffold 默认接管。直接 clone
仍用于维护 Peanut Admin 参考应用，不再是创建正式下游应用的入口。详见
[创建独立应用](docs/create-application.md)。

根目录 `.env` 只维护端口、镜像和构建代理。数据库、`JWT_SECRET` 及下列后台身份全部填写在
`server/.env`；PHP、CLI、安装器和 Compose 中的后台进程都读取这一份文件，不接受 `PHP_*`
别名绕过：

```dotenv
DEPLOYMENT_MODE=standalone
ADMIN_INITIAL_EMAIL=admin@example.com
ADMIN_INITIAL_PASSWORD=<至少 12 位；演示模式固定为 peanut1234>
TENANT_IDENTIFIER_HMAC_KEY=<至少 32 字节的稳定随机值>
PLATFORM_IDENTIFIER_HMAC_KEY=<另一份至少 32 字节的稳定随机值>
```

多租户部署把 `DEPLOYMENT_MODE` 改为 `multi-tenant`，并额外提供与管理员不同的
`PLATFORM_INITIAL_EMAIL` 和 `PLATFORM_INITIAL_PASSWORD`，再显式填写 `PLATFORM_HOSTS`、
`TENANT_ADMIN_HOSTS` 与 Owner 邀请投递模式。Tenant 专属域名由 Platform 动态绑定，未知 Host
会被应用拒绝。HMAC 生成后必须稳定保存；随意更换
会使既有身份索引失配。

### 3. 安装与启动

本项目日常开发的唯一默认入口是 `scripts/local-stack.sh`。它使用 Peanut Admin 项目登记的
`/opt/homebrew/bin/php` 8.3.24 与 `/usr/local/bin/composer` 2.8.10 托管宿主 API，Web、PC、
Mobile、Docs 和固定网关可由 development Compose 运行；Docker PHP 仅用于本机生产模式
预览、生产构建和显式容器等价 Gate。

若需要同时开发本地 `peanut-admin-core`，执行
`scripts/local-core-composer install`（PHP）及 `scripts/local-core-web link`（Web）。前者仅在
被 `.gitignore` 忽略的 `.local/` 中生成 Composer path overlay，后者仅替换本机
`node_modules` 中的包软链接；两者都不改变正式清单或 lock 文件。发布安装继续使用固定的
远程 tag。详见 `docs/development/local-core-composer.md`。

```bash
./scripts/local-stack.sh dev-up
./scripts/local-stack.sh status
```

登记的默认入口为 `http://127.0.0.1:20187/admin/`；API、Web、Mobile、MySQL、PC、Docs 与
本地生产预览的登记默认端口依次为 `20180`、`20181`、`20182`、`20183`、`20185`、
`20186`、`20190`。除唯一数据库 `20183` 外，本地监听均从 `.local/stack.env` 读取；
每个 clone/worktree 可直接维护自己的私有文件，`ensure_env` 不会重写已有值。
非秘密示例见 `deploy/local-stack.env.example`。停止时运行
`./scripts/local-stack.sh dev-down`，该命令会同时停止容器和受 PID/日志管理的宿主 PHP。
使用安装时提供的管理员邮箱和密码登录。管理身份必须使用有效邮箱，不能只填写 `@` 前的
用户名。安装器只接受空数据库；旧大版本数据库不能原地升级为 3.0，应保留旧实例并为新版本准备独立空库。

隔离的本地多租户体验使用 `./scripts/local-multi-tenant-demo up`，固定
`admin.peanut-admin.test:20179`、`platform.peanut-admin.test:20176`、两个 Tenant 测试域名、
API `20178` 和登记的 `peanut_admin_development_mtlocal01` 数据库；启动前必须
由当前运行任务的项目 lease 同时持有这些资源。资源和启动步骤见
[`docs/operations/local-demo-access.md`](docs/operations/local-demo-access.md)；账号、密码和本地入口见
内部 [Demo access handoff](docs/operations/demo-access.md)。

## Demo 访问边界

仓库不发布或汇总本地与线上 Demo 的登录地址、账号或密码。获授权的运行资源 owner 维护这些
访问事实；交接边界见 [Demo access handoff](docs/operations/demo-access.md)。

正式生产环境使用根 `compose.yaml`，从不可变 release tag 构建 PHP/Nginx 镜像。上表多租户
入口是独立空库、独立 Compose project 和独立 origin 的候选体验环境；Standalone 是独立的
可丢弃演示部署。
两个 Tenant 域名已完成 DNS、TLS、Host 保留、反向代理和应用内持续绑定；错误 Tenant 账号
登录会被拒绝。当前体验实例使用登记的人工 Owner 邀请交付模式，不依赖生产邮件 Provider。
首次部署、
`standalone`/`multi-tenant` 配置、空库安装、数据库备份和回滚停止线见
[部署与升级](https://peanut-admin-doc.007345.xyz/guide/deployment-upgrade)。

## 目录结构

```text
peanut-admin/
├── server/       # ThinkPHP 后端、数据库安装器与迁移
├── web/          # Vue 3 管理端
├── platform/     # Vue 3 实例 Platform 控制面
├── pc/           # Nuxt 3 PC 客户端
├── uniapp/       # UniApp H5 / 小程序
├── docs-site/    # VitePress 官方文档站
├── deploy/       # Docker 与 Nginx 生产配置
└── docs/         # 架构、开发和发布文档
```

## API 与扩展

- 响应：`{"code": 20000, "msg": "ok", "data": {...}}`
- 认证：`Authorization: Bearer <token>`
- 常用错误码：未登录 `40100`、无权限 `40300`、业务错误 `40000`
- 扩展业务应通过应用 Module/Host 接入，不复制 Core Runtime。

完整的认证、权限、公开包和外部回调边界见
[API 与扩展](https://peanut-admin-doc.007345.xyz/api)。

## 文档

- [开始使用](https://peanut-admin-doc.007345.xyz/getting-started)
- [文档入口](https://peanut-admin-doc.007345.xyz/guide/)
- [开发指南](https://peanut-admin-doc.007345.xyz/guide/development)
- [部署与升级](https://peanut-admin-doc.007345.xyz/guide/deployment-upgrade)
- [核心概念](https://peanut-admin-doc.007345.xyz/guide/concepts)
- [数据、权限与多租户](https://peanut-admin-doc.007345.xyz/guide/data-permissions-tenancy)
- [版本与发布](https://peanut-admin-doc.007345.xyz/releases)
- [在线演示](https://peanut-admin-doc.007345.xyz/demo-access)

文档源码位于 `docs-site/`，由 Cloudflare Pages 项目 `peanut-admin-docs` 发布到
`peanut-admin-doc.007345.xyz`：

```bash
cd docs-site
pnpm install --frozen-lockfile
PEANUT_DOCS_SITE_URL=https://peanut-admin-doc.007345.xyz pnpm build
npx wrangler pages deploy .vitepress/dist --project-name=peanut-admin-docs --branch=main
```

## 版本与许可证

当前正式源码版本为 [`v3.0.14`](https://github.com/peanut-business/peanut-admin-code/releases/tag/v3.0.14)。
它提供双 Edition fresh install 和从 3.0.13 开始的同 Edition 升级包，但不提供 1.x 数据库或脚手架原地升级；最后一个已封存的 1.x 历史版本是
[`v1.1.5`](https://github.com/peanut-business/peanut-admin-code/releases/tag/v1.1.5)。源码发布、演示部署与
业务生产部署是三类独立证据；本仓公开演示不代表任何第三方业务生产环境已部署。

Peanut Admin 当前源码自开源许可变更提交起采用 Apache-2.0；既有不可变 tag 与 GitHub Release
保留其发布时附带的许可证。公开 Core 包同样采用 Apache-2.0。具体边界见 [LICENSE](LICENSE)、[NOTICE](NOTICE) 和
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。
