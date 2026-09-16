# PB08A 脚手架品牌与官方网站合同

> 状态：Implemented；静态门禁通过，真实浏览器证据并入 PB08B
>
> 应用基线：`88da40579f250f24700106bbe331e2706c48044b`
>
> 核心只读基线：`7fbd445d8fa547830b7782a7ac147d9ed414e0fd`
>
> 应用测试 owner：`PB08A-BRAND-SCAFFOLD-001`、`PB08A-INSTALL-001`、`PB08A-OFFICIAL-SITE-001`

## 1. 决策与目标

PB03–PB07 已固定核心与应用领域边界。PB08A 只把当前应用变成可克隆、可改名、默认品牌完整的中性脚手架，并把 `docs-site` 建成 Peanut Admin 官方网站与文档门户；它不迁移业务能力，不修改核心 Runtime，也不提前开始 PB09 或 SaaS。

品牌 owner 继续是应用。现有 `WebsiteConfigService + WebsiteConfigStore + PaConfigWebsiteStore + pa_config(type=website)` 扩展为唯一可变 Runtime，不新增 Product Brand facade、不引入第二张配置表、不复制核心 Settings。管理端、PC、UniApp/H5 和后端公开配置必须从该服务得到同一份规范化品牌 DTO，Controller/Logic/端组件不得再各自维护字段规则或产品 fallback。

fresh clone 与尚未连接 Runtime 的静态构建需要确定性默认值。仓库新增一个机器可读的 bootstrap brand manifest 和一组源资产，作为安装前的唯一默认源；安装器从它写入 `pa_config`，客户端构建只从它生成静态 fallback。安装成功后 `pa_config` 是唯一可修改事实源，manifest 不参与运行时双读、双写或覆盖已保存值。生成产物必须可由检查命令重建，禁止手工维护第二套默认品牌。

## 2. 现状事实与停止线

限定 CodeGraph/静态审计确认：

- `WebsiteConfigService` 当前拥有 12 个规范字段，但管理端 `WebsiteConfig` DTO 和表单仍提交已退出的 `logo/favicon/copyright/icp` 字段，保存链与 UI 不一致。
- 公共 `IndexApplicationService::getConfigData` 仍逐字段读取 `ConfigService`；PC 与 UniApp 只消费 `shop_name/shop_logo`，并各自保留小写 `peanut` fallback。
- 管理端 HTML、壳层、logo/favicon 和登录轮播没有统一消费品牌配置；UniApp `pages.json`、manifest、package 元数据和固定 `static/logo.png` 未产品化。
- 默认品牌图片为空，`server/public/favicon.ico` 仍是 ThinkPHP 图标，`server/config/project.php` 用同一 favicon 代替头像、菜单和文档等不同用途。
- `server/composer.json` 仍是 ThinkPHP 项目元数据；UniApp 仍名为 `uni-preset-vue` 且 manifest 名称/描述为空。
- `.env.example` 与 `deploy/production.env.example` 把开发局域网 `192.168.192.2` 当默认数据库地址；安装种子和管理端登录表单仍暴露固定 `admin123456`。
- `docs-site` 当前只有文档首页、开始使用、部署、API、开发指南和管理员手册，尚不是官网门户。

以下停止线固定：

1. 不修改 `/Users/xing/Documents/company-projects/peanut-admin-core`，不触碰其既有 `.playwright-cli/`。
2. 不修改 `vendor/`、任一 `node_modules/`、已封存 Playwright/LikeAdmin parity 证据或 PB03–PB07 测试。
3. 不新增公开 Composer/npm 包；公开边界仍只有 `peanut-admin/core` 与 `@peanut-admin/admin`。
4. 不直接修改 `server/database/init.sql`。数据库变更只能新增迁移；安装凭据的动态写入由安装器负责。
5. 不把生产域名、局域网地址、真实账号、密钥或证书写成模板默认值。
6. 不预设赞助、商业服务、多版本文档或 SaaS 宣传；没有已发布事实的能力不得出现在官网 CTA 或版本页。
7. 应用仓没有根 `LICENSE`，既有审计要求公开发布前完成 provenance/clean-room、LICENSE、NOTICE 与第三方清单。PB08A 包元数据只能标记 `proprietary/UNLICENSED`；未经用户明确许可不得推断或授予 MIT/Apache-2.0，PB09 在该决策完成前停止。

## 3. 品牌字段与生命周期

现有网站字段保留为同一配置分组内有明确用途的端级字段，不再另设旧字段或别名。PB08A 新增公共信息后，规范字段固定为：

| 字段 | 责任与消费者 | 默认/规则 |
|---|---|---|
| `name` | 管理端产品名、通用 fallback | `Peanut Admin`，必填，最多 60 字 |
| `web_logo` / `web_favicon` / `login_image` | 管理端壳层、登录页、浏览器图标 | 源资产或空的可选登录背景；不得引用依赖包资产 |
| `shop_name` / `shop_logo` / `h5_favicon` | UniApp/H5 与通用消费端 | 名称必填；空 logo/favicon 由服务解析为通用源资产 |
| `pc_logo` / `pc_title` / `pc_ico` | PC 页头与页面 metadata | 空端级图片由服务解析为通用源资产；标题最多 120 字 |
| `pc_desc` / `pc_keywords` | PC SEO metadata | 可选，最多 500 字 |
| `slogan` | 登录页、关于页、官网可复用产品定位 | 中性、可替换，不含环境或商业承诺 |
| `copyright` | 管理端、PC、H5 页脚的纯文本版权 | 不含固定年份；年份由视图生成 |
| `official_url` | 应用内官网入口 | 仓库默认空，由正式部署显式提供，避免把验收域名固化进模板 |
| `github_url` | 应用内源码入口 | 默认 `https://github.com/peanut-business/peanut-admin-code` |

`copyright.config` 继续只拥有 ICP 等备案展示项，不再承担产品版权文案；旧 `logo/favicon/copyright/icp` 网站字段不得重新加入兼容层。所有 URL/图片读取和保存继续经过现有 URL mapper。端级 fallback、字段长度、URL scheme 白名单和完整批量写都由 `WebsiteConfigService` 唯一实现。

生命周期固定为：

```text
bootstrap brand manifest + source assets
  ├─ fresh install ──> pa_config(type=website) ──> WebsiteConfigService ──> 四端 Runtime
  └─ build sync ─────> 生成的静态 favicon/logo/metadata fallback（API 未返回前使用）
```

manifest 修改是克隆后首次安装前的脚手架覆盖入口；安装后的品牌修改只通过管理端网站配置进入 `WebsiteConfigService`。构建同步不得覆盖数据库值，Runtime 也不得回写 manifest。

## 4. 安装、安全与中性默认值

空库安装必须显式提供 `ADMIN_INITIAL_PASSWORD`。安装器在任何业务表写入前验证：非空、至少 12 字符，并同时包含字母与数字；缺失或不合格立即失败。安装器只在确认空库后使用现有密码算法生成随机盐和摘要，并在内存中把唯一管理员 seed 替换为该摘要后才执行初始化 SQL；匹配不到或匹配多于一条都必须停止。最终 JSON 只报告管理员用户名，不回显密码、摘要或盐。

已安装库和升级路径不得要求该变量、不得轮换已有管理员密码。`web` 登录表单只可预填用户名 `admin`，密码保持空；README、示例环境和文档不得发布固定密码。直接导入 `init.sql` 不属于受支持安装路径，文档统一指向 `server/database/install.php`；若安装过程中失败，成功结果不得留下可用的仓库已知密码。

本机 PHP 示例数据库主机使用 `127.0.0.1`，生产 Compose 示例使用 bundled profile 的中性服务名 `mysql`；external DB 必须显式覆盖。不把 `192.168.192.2`、`*.007345.xyz` 或任何个人环境写成模板默认。

默认资产必须按用途区分：产品 logo/favicon、管理员头像、会员头像、功能菜单占位图、登录背景和内容示例图不得全部复用同一 favicon。默认示例数据只能说明功能，不得包含参考项目品牌、AUX/模板营销文案、虚构商业背书或可误认成生产联系方式的内容。

## 5. 四端 Host 与元数据责任

- 后端：`WebsiteConfigService` 输出完整规范 DTO；`IndexApplicationService`、工作台版本信息和安装器只装配该服务或 bootstrap reader，不逐字段复制规则。
- 管理端：未登录品牌 bootstrap 与登录后 Runtime 使用同一 DTO；页面标题、navbar/menu logo、favicon、登录背景、slogan、页脚和网站配置表单全部对齐规范字段。
- PC：store 拥有一次配置加载；layout/head metadata 只消费 store DTO，无小写 `peanut` 或环境域名 fallback。
- UniApp/H5：store 拥有一次配置加载；页面标题、manifest/package 元数据、about/login、logo/favicon 由规范字段或生成 fallback 提供，不保留固定 `/static/logo.png` 产品引用和“感谢使用本产品”等泛化占位文案。
- 包元数据：`server/composer.json`、`web`、`pc`、`uniapp` 与 `docs-site` 的 name/description/homepage/repository/license 只描述 Peanut Admin 应用或对应私有构建包，不冒充 ThinkPHP/Uni preset，也不改变两个公开核心包边界。

## 6. 官方网站与文档门户

`docs-site` 继续使用 VitePress，同站分为官网首页与文档区。首版信息架构固定为：

1. 产品首页：定位、已交付能力、适用场景、快速开始、GitHub 和文档 CTA。
2. 能力与场景：管理端、PC、UniApp/H5、后端、部署与扩展边界；明确 SaaS 尚未实现。
3. 快速开始：依赖、安装、首次凭据、启动和最短成功路径。
4. 开发指南：仓库结构、两包边界、覆盖 Host、测试与贡献方式。
5. 部署与升级：Docker/原生、空库/前滚、环境变量、备份和回滚停止线。
6. API 与扩展：认证、响应、公开包、稳定 override；内部领域目录不是独立包。
7. 管理员手册：当前产品模块、权限与安全操作。
8. 版本与发布：已发布版本、核心包锁和变更入口，不提前宣布 PB09。
9. 全局入口：本地搜索、GitHub、404、官网/文档互返和移动导航。

已完成的来源调研仅吸收信息架构和交付方式：

- [Vben Admin](https://doc.vben.pro/en/)：首页把介绍、预览、快速开始和指南入口放在首屏附近。
- [Ant Design Pro](https://github.com/ant-design/ant-design-pro)：README 以定位、预览、开始使用、生态和维护状态组织交付信息。
- [Arco Design Pro](https://arco.design/docs/en-US/pro/start)：模板定位、安装使用和工程能力分层清楚。
- [SoybeanAdmin](https://docs.soybeanjs.cn/)：教程、版本与项目入口分区。
- [Pure Admin](https://pure-admin.cn/)：产品入口、文档、预览和版本信息集中导航。

这些来源不授权复制 UI、文案、路由、API 或品牌资产。官网必须以 Peanut Admin 当前仓库和发布事实为准。

## 7. 实施顺序与写入白名单

PB08A 串行执行，避免 Runtime、种子和官网同时修改同一事实源：

1. bootstrap manifest、源资产、同步/检查命令和品牌服务字段；
2. 安装器、迁移/默认配置、示例环境与初始凭据；
3. 管理端网站表单及壳层；
4. PC 与 UniApp/H5 消费；
5. 包元数据、README、用户/开发/部署/升级文档；
6. docs-site 官网与文档门户；
7. 与 PB08B 共用唯一一次桌面/移动真实浏览器验收。

允许写入：新增品牌 manifest/源资产/同步脚本；`server/config`、安装器和新增 migration；品牌相关的 `server/app` Host；四端品牌组件、store、metadata 与各自 `public/static` 生成产物；包元数据；`docs-site`；README 与产品化、用户、开发、部署、升级文档；新增 PB08A 聚焦测试和 CI 登记。

发现必须修改核心仓、依赖目录、PB03–PB07 业务 Runtime、已有 migration 或 `init.sql` 时立即停止并先修订合同。

## 8. 测试所有权与最低验收

### `PB08A-BRAND-SCAFFOLD-001`

由新增的无浏览器品牌合同测试拥有，一次证明：manifest schema/资产存在；生成产物无漂移；`WebsiteConfigService` 的规范字段、默认、URL 白名单、完整原子写和四端消费入口一致；品牌扫描不包含已登记的小写 fallback、ThinkPHP/Uni preset/AUX 残留和环境特定默认值。只运行变更 PHP lint、四端受影响 typecheck/build 中的最低集合和一次 `git diff --check`。

### `PB08A-INSTALL-001`

由安装器聚焦测试和 PB08B 的唯一空库安装共同拥有：缺少/弱 `ADMIN_INITIAL_PASSWORD` 时零业务表写入；合法密码只创建一个 root admin 且输出不泄露秘密；fresh install 得到完整品牌字段和存在的默认资产；已有库升级不改管理员密码或现有品牌。PB08A 不重复 PB00 安装/parity 验收。

### `PB08A-OFFICIAL-SITE-001`

静态构建先检查导航、内部链接、搜索索引、404 和页面清单。真实浏览器不在 PB08A 另跑；它与 PB08B 合并为唯一一次桌面/移动 Chromium 验收，覆盖官网导航/CTA/搜索/404、管理端登录页、PC 和 H5 默认品牌。原生 UniApp 只做 manifest/typecheck，不增加第二套真机组合。

## 9. 完成定义

PB08A 只有在以下条件全部满足后才可标记完成：

- 唯一 bootstrap 默认源和唯一可变 Runtime 已实现，四端无第二套产品 fallback。
- fresh clone 官方安装不依赖仓库固定密码，空库默认品牌和用途化资产完整。
- 管理端、PC、UniApp/H5、后端、元数据、README 和文档的产品身份一致且可覆盖。
- docs-site 已具备第 6 节全部分区，所有对外能力陈述可由当前代码/发布记录证明。
- 用户手册、开发文档、部署与升级文档已同步。
- 三个测试 owner 通过且浏览器验收只执行一次。

通过只表示脚手架与官网门禁完成；不表示 PB08B 集成门禁、PB09 正式发布、真实第三方渠道或 SaaS 已完成。

## 10. 当前实施记录

品牌/脚手架 Runtime 已完成：`server/config/brand.json` 是 bootstrap 默认值和源资产索引，`scripts/sync-brand-assets.mjs` 生成并检查 Web、PC、UniApp 与 docs-site 静态副本；安装后仍由 `WebsiteConfigService + pa_config(type=website)` 唯一拥有可变值。管理端网站表单、登录页/壳层/页脚、PC metadata/layout 和 UniApp login/about 已消费同一规范 DTO；ThinkPHP/Uni preset/AUX、固定密码、固定旧 logo 和小写产品 fallback 已退出运行路径。用途化默认头像、菜单、文档与支持资产不再复用 favicon。

空库安装现要求显式 `ADMIN_INITIAL_PASSWORD`，先验证再以随机盐把唯一管理员 seed 在内存中替换，输出不回显秘密；已有库/升级不要求该变量。应用包元数据已改为 Peanut Admin 身份，并因根许可证尚未获批而明确标记 `proprietary/UNLICENSED`。

此前的最低验证已通过：品牌与网站服务聚焦 PHP 测试、安装 bootstrap 测试、变更 PHP/SVG lint、生成资产无漂移、Web/PC/UniApp typecheck、Composer manifest/lock 与 Compose 配置。未执行真实数据库、全量构建或浏览器；这些只在 PB08B 的唯一正式候选验收执行。

官网与文档门户已完成：`docs-site` 现包含产品首页、能力与场景、文档门户、快速开始、开发、部署升级、API/扩展、管理员手册、版本信息与自定义 404；导航、CTA、搜索和 GitHub 入口统一消费生成品牌 manifest/资产。README、用户手册、开发、部署/升级文档已同步，公开页面不固化历史验收域名、服务器 IP、局域网数据库或过期 PHP 版本。

`PB08A-OFFICIAL-SITE-001` 唯一静态门禁通过：品牌生成检查、VitePress 构建与内部链接、搜索索引、关键页面和 404 产物均成功。按合同未另跑浏览器；官网导航/CTA/搜索/404、管理端登录页、PC 与 H5 默认品牌的桌面/移动真实 Chromium 证据只在 PB08B 执行一次。PB08A 实现至此完成。
