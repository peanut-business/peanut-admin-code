# 开发者中心与文档

生效要求：可查、可管、可追溯；不是第五类租户前端，也不因存在页面或命令就代表能力已经验证。开发环境默认提供工具，生产仅开放获准的维护动作。

## 1. 展示范围

模块编号/中文名/版本/来源/入口/维护者；依赖、被依赖、缺项/冲突；公开服务输入输出/受众/例子；HTTP参数/结果/认证/权限/错误；事件发布订阅/格式与任务处理/重试；自有表/迁移/资源；四端贡献/导航；安装/开通/人员权限/Scope来源；配置/可编辑者；框架生命周期/规范；检查结果/缺文档；打包文件预览/安装条件。

能由页面追到接口→用例→依赖服务→数据owner→事件→文档/验证；也能解释未出现/拒绝/未执行原因、实际中间件顺序、容器实现及注册者，不靠猜测标绿。

## 2. 单一事实来源

| 信息 | 唯一来源 |
| --- | --- |
| 模块、依赖、各端入口、权限/事件定义 | 随源码结构化声明，编译目录仅派生 |
| API/公开服务 | 实际路由、类型、标记、注释，生成后核对 |
| 独立权限包与 API 的实例关联 | 平台维护的全局关联及修订；源码默认项仅用于尚未实例配置的权限包 |
| 实例安装/升级、租户开通 | 各自数据库记录/交付结果 |
| 人员许可/本次Scope | 当前身份、角色绑定范围和有效授权，非租户名称/请求列表 |
| 架构/机制原因 | 本目录的公开专题，不手抄机器目录 |

源码扫描、数据库、页面不得各编辑一份模块定义。开发界面改静态定义留源码diff；不只改不可追踪DB副本。声明/发现/注册/有效/测试通过分开，动态未解析就标未解析。

Provider目录复用当前App由ModuleComposition保存的同一实例，支持原生think\Service和构造注入；不得为了展示bindings重复无参new、执行register/boot或启动未选择模块。公共服务的绑定标识与Provider详情来自同一次读取。尚未在当前App实例化的Provider仅展示声明/类型事实，空bindings不能解释为其没有扩展能力；runtime_effective保持not_checked。原生只读入口为`php think developer:center --module=<实际模块键>`，它不是运行验收或自动执行事件的入口。

独立权限包定义仍由原系统/模块权限声明维护；平台编辑的是权限包与已登记 API 的关联，不改写 API 路径、受众、所属模块或公开/认证模式。API 目录的自动发现复用既有路由 inventory 和合同生成器，开发者中心继续展示同一生成目录；平台权限配置入口见[身份与授权](../architecture/identity-organization-access.md#3-部门岗位角色和数据范围)。

### 开发者导航与身份边界

平台管理端独立“开发者工具”导航组仅包含实例级子页（GET /platformapi/developer-center/catalog 及 /platformapi/instance-tools/*），由独立 Platform Session、平台权限及开发实例开关控制。实例模块安装、升级、重试仅此组，不在租户 Admin 再显示另一套“模块治理”安装器；源码锁、实例安装、租户权益、人员许可四种状态互不替代。

原本将全部内容挤在 `#/developer` 的页面已按正式只读/写能力拆为五个可直达子页：
- `#/developer/modules` **本机模块**：作者中文名（稳定 Module key）、源码详情与按模块筛选，仅调用有权的 Developer Catalog GET；沿用原权限标识 `platform.developer.catalog`。
- `#/developer/installations` **安装与依赖**：实际安装/租户开通数/依赖/阻断，独立调用 Instance Modules GET，不要求读取 Developer Catalog。
- `#/developer/official` **官方模块目录**：稳定/RC 渠道、发行与更新、兼容性及固定版本预检；独立调用官方目录 GET，有权时可附加一次 Developer Catalog GET 取得本地作者中文名，无该权限时只显示未证实候选的技术 key。
- `#/developer/operations` **安装与升级**：预检后的确认、受权上传包、提交任务、任务编号查回；只凭对应写动作或 Task GET 的既有授权显示，不自动访问 Catalog、安装列表或任务详情，原模块版本/摘要/幂等回执合同不变。
- `#/developer/diagnostics` **运行诊断**：模块声明、发现、注册、生成 API 数量及证据状态；仅 Developer Catalog GET，不加载安装、官方发行或任务查询。

历史 `#/developer` 被认证后重定向到**当前平台身份第一条有权子页**（优先本机模块）；拒绝无权深链，不以“页面存在”构造权限。菜单为五个正式 Platform page 节点，Action/Operation 节点分别归属其业务子页，角色目录、API、菜单来源均以 Scaffold Shell 声明和当前 Identity 作者元数据同步。页面切换仅加载本页需要的读取能力，后台无权页既不渲染功能也不发请求。正式浏览器验收应点击五个子页、刷新深链、前进后退、按权限拒绝，并区分只读验证与提交真实模块任务。

租户自助管理沿现有 Admin 客户端/正式租户菜单和对应 tenant-permission / 模块权益过滤，**不等于**实例模块安装权或 Platform Session。原代码生成器涉及当前数据库结构，只在 standalone development 的独立本地实例工具路由存在；在 MT 租户中不开放该跨表浏览/写入入口，未完成租户安全隔离审计前禁止放宽。生产构建中也不编译本地实例工具。角色、套餐或模块安装时不得因导航可见而自动授予跨租户操作权限。

### PC / H5 源码宿主的原生 Module 入口发现

工业脚手架源码仓自身允许没有 `server/app/modules`：当 `modules.lock` 是正式的零模块清单时，
Nuxt PC (`pc/nuxt.config.ts`) 与 UniApp (`uniapp/scripts/module-pages.mjs`) 共用
`scripts/module-frontend-entries.mjs` 返回空的模块贡献，不需要造空目录或将Module源码
复制到脚手架。本地正式Module作者应用拥有此目录时仍递归按Module声明加载；
根目录或子节点为符号链接、正式锁声明了前端模块但没有作者源码、
已声明前端contribution不在约定位置都继续fail-closed。

实际来源核对：原生Service Manager登记的Code PC `20185`、H5 `20182`
在缺少目录时报 `ENOENT scandir server/app/modules` 并以退出码1停止。
调整共用入口后PC Nuxt与H5 UniApp均成功启动；H5 HTTP200。
PC Nuxt独立启动成功但页面HTTP503：
下游当前登记Code PHP `20180` 的 `/api/index/config` 和 `/api/pc/config`
对登记前端域名返回HTTP500，因此不得以默认Brand覆盖错误后宣称Tenant内容验收成功。
公开配置需合法租户Host/数据库/权限源校验，前端不得绕过。
本阶段只验证原现有宿主；无新模块安装/身份/租户配置写入，
临时启动的PC/H5在复核后恢复为不监听状态。旧ST/MT永久测试APP的版本仍是各自固定来源，
不可替代当前Scaffold源码候选的业务验收。

### G02 字典类型与数据抽屉错误恢复

现有字典类型主页已实现序列屏蔽、失败时保留旧行及按原请求参数重试，本轮不重构；独立的 `web/src/views/system/dict/components/DataDrawer.vue` 也必须保持同一合同。列表失败记录当时的 `type_id/name/page_no/page_size`，用户改动未搜索的输入时重试仍按原失败请求，不跨类型。关闭、切换类型、卸载均使请求代次失效，迟到的HTTP响应不覆盖新字典数据；成功空列表与错误要区分。仍通过正式 `getDictDataList` 和 `peanut.reference-codes.read`，不改变任何字典写权限或数据库结构。

### G03 Standalone 生成器最新预览与回执合同

`web/src/views/dev-tools/code/index.vue` 使用正式 `POST /adminapi/generator/preview` 与 `GeneratorController::preview`。先显示加载态，只允许最后一次预览响应覆盖内容；弹窗关闭、卸载后晚到的请求不得恢复旧数据，失败与成功无文件分开，并允许对同一数据表重试。原PHP生成器 `GeneratorService` 与安全的ZIP冲突审阅、一次性下载合同不改，原 `core.generator.read` 权限不改。该开发工具仅属于Standalone实例，不能因源测试通过就声称MT5020已运行。

### 平台与租户菜单权限树的迁移门禁（G18）

目录、菜单、按钮必须沿现有正式 `system_menu` 源构建真实可展开树，不允许只在前端凭空合成目录。平台 `permission-menus` 读接口追加原生 tenant-scope 目录节点（type M，不可被旧菜单编辑写入）；菜单和按钮来源保持 Core/Identity 作者的 C/A 原行、修订及动作约束。搜索须保留父节点，重复、环及按钮挂子菜单应拒绝；实际 API 方法/路径只从当前有权读取的正式 API 目录和现有生效关联批量投影，不能发 N+1 请求或用源码默认替代实例明确配置。

**Update, native-menu API binding (staged, not live at 5020):** The native authorization permission mapping already supports multiple registered API IDs per permission and is consumed by AdminAuthorizationService. The authorized Platform menu page now exposes that EXISTING mapping directly for C/A entries with exactly one non-custom, active native tenant permission owner. POST/PUT methods and paths are resolved from the server API catalog and submitted only as API IDs. The new PUT /platformapi/permission/menus/apis requires independent Platform menu/API read and write permissions; it validates both menu and permission revisions, rejects duplicate/foreign/retired/unavailable API IDs, and records the operator, reason, previous and next mappings atomically. No hand-made central bundle is required. A binding affects every role holding that permission, never bypassing tenant license or prerequisites. Shared permission codes are blocked across the entire native registration, including hidden entries; the current development catalog has four such codes spanning twenty menus and requires an explicit Module-author contract decision instead of automatic grants. On Admin /app/roles, the native menu tree allows strict, non-inheriting edits only for exclusively owned assignable permissions, keeping shared/nonmenu grants in the existing keyed editor and original RoleAdminService CAS writer. The staged Platform G18 role-node grant store, migration and source switch are separate and are NOT activated on the live 5020 MySQL instance. The prior rejected G18 schema operation remains out of scope.

Admin `system/menu` 编辑原生入口还受 standalone InstanceToolAccessGuard 限制；MT 不能为实现租户树授权而放宽实例菜单管理写权限。Admin租户角色授权与 Platform 实例角色属于不同scope，不可复用或跨租户套用同一角色API身份。

### G18 直接角色授权源码与当前上线范围

Platform 的直接角色节点保存已经在 Scaffold 原生权限路由增加受控的 `POST /platformapi/roles/nodes/prepare` 与 `/save`：只接受原生登记节点的 key，不接受前端自带的 API/菜单事实；强制独立 Platform 操作员/权限API目录可读能力、严格的变更者写权限；写入由 Identity 的 `PlatformRoleNodeWriteContract` 委托 `PlatformAccessAdminService::replaceRoleNodes`，事务中重新比较角色 CAS revision 与完整节点/API/实例目录摘要，并记录审计。Platform 角色候选树可准备、确认和读回，后端的 `ThinkPhpPlatformAuthorizationRepository` 仅在一次性全角色等价迁移后由全局 `PlatformRoleDirectGrantStore` 单源读取；撤权、未授权直调、最后控制管理员、旧角色恢复均有源测试。

**禁止混淆源码与运行：** Identity 模块已包含四个直接关系表的迁移定义，但必须先取得独立可回收的合法 MySQL 目标并完成全角色等价、备份、切换回滚验收。当前 5020 主开发数据库未应用直接关系 DDL，运行来源明确为 `legacy`：原 `permission_keys` 仍为有效单一授权源，新直接写入合同检查缺表即拒绝，不自动运行迁移或改变现有角色。前端当前旧角色编辑仍须标明兼容窗口；不能以按钮能点击或 HTTP 合同存在证明直接授权已投产。

Tenant/Admin 范围与 Platform 不同：多租户 `/admin/system/menu` 的单独菜单管理属 standalone 实例工具，`MenuController::assertInstanceToolAccess` 在 MT 明确拒绝；正式租户角色入口为 `/admin/app/roles`。其弹窗可以在本租户有 `core.menu.read` 时展示本租户许可证过滤后的正式菜单树（只读），原 `RoleAdminService::replacePermissions`、Owner 固定角色与租户权限上限不变。**Tenant 的直接 menu→API 保存、tenant role→node/API 写入、服务端同源鉴权切换仍未实现**，不能拿 Platform 的 G18 关系表或已退役测试宿主替代，也不能使用现有非隔离业务库试迁移。

### 模块列表必须呈现作者中文名称与稳定标识

平台开发者目录的正式 Module Manifest name 是显示名唯一来源；Platform 实例安装状态接口已经返回 name，页面不能在类型解码时丢弃。模块下拉、安装列表、已采用模块更新、官方目录及套餐模块选择均优先显示“中文名称（module.key）”，代码、依赖、安装版本、权限和租户权益始终使用稳定 key，**不得以中文名作为变更/授权标识**。官方目录尚未存在本地作者声明的模块仅显示原始 key，不臆造中文翻译。

套餐管理在获得独立 Platform 模块读取权限时一次读取可分页作者目录进行名称投影，读取失败保留可追溯 key，不能用平台角色或其它租户目录绕过授权。名称投影是只读呈现；套餐保存与成员授权仍使用原 keys 及原有显式写入路径。前端不得重复维护人工 key→中文名常量表。涉及 Element Plus 列插槽的改动，必须执行真实 Vue 模板编译检查；vue-tsc 无报错不代表 Vite 模板编译成功。

## 3. 可操作范围

UI/CLI共用服务。开发可创建模块、编辑声明、生成/检查/预览/打包；生产仅获准诊断、安装升级/维护，要求对象、权限、预检、审计。不向普通用户暴露任意PHP上传、Shell、类名、路径或DB连接；诊断不能泄露其他租户/秘密。

## 4. 文档与注释的交付要求

### G11/G14 安装状态列表一致性

`#/developer/installations` 只按当前 Platform `platform.module.read`
调用 `GET /platformapi/instance-tools/modules`，显示真实已部署模块、源码版本、
必需/可选依赖、启用租户数和阻断待办，不得以“模块active”冒充租户已获授权。
同一筛选+分页的在途GET去重；用户更改筛选或页码时后发请求具有优先权，
旧响应/错误不能覆盖当前行。一次读取失败保留**同一页面仍有效的上次成功行**与分页，
展示“刷新失败/上次结果”警告，首次失败才显示失败而非成功空列表。
离开子页、生命周期销毁后废弃在途结果，不向其他页面带入旧安装目录。
原生Platform后端/module-installation/许可来源不改，此处只是现有只读消费者的异步合同。

### 官方模块目录、安装与升级

开发者中心的“官方模块目录与更新”显式查询官方 GitHub Release，默认稳定渠道；RC 需要主动选择。目录列出可发现模块、依赖、Edition/Kernel 条件和撤回状态，并区分 `modules.lock` 中的源码版本、公开可用版本和数据库安装状态。已采用源码仍可进行安装预检，不能用源码锁推断运行已安装；发现新版不代表兼容或安装成功。脚手架更新显示同主版本的正式候选，源码升级继续使用 `peanut upgrade --check`、原生计划及应用自有代码保全合同。

`GET instance-tools/modules/updates`、`POST instance-tools/modules/official-install` 和 `POST instance-tools/modules/official-update` 均经过平台登录、分别对应的 `platform.module.read/install/update` 权限和 development/debug 实例工具门禁；普通租户和生产普通管理会话无权使用。新路由通过原 API metadata/目录生成链登记，不写入另一份手工权限目录。

**预检与任务提交的同一意图：** 平台开发者在官方目录选择一个发行包后，
异步预检只接受其原始模块key、版本、SHA-256、Tag、渠道与当前子页一致的回执；
离开官方子页、改选渠道或卸载页面时丢弃迟到结果，不能让旧预检重新出现在安装界面。
安装/升级确认框打开到用户点击确认之间再次核对原选择、操作类型、预检结果对象与权限，
任何变化都停止，不发送新的任务POST。服务端仍以固定目录身份、散列、幂等键和实时
权限/模块迁移预检为准，前端校验不替代事务与审计。丢失响应不能当作执行失败自动重发，
任务结果必须用正式任务查询回执确认。此源级控制不表示5020已采用新UI，也不改变G18准入。

模块安装/升级固定操作、目录 Tag、模块版本、包 SHA-256 和渠道。服务验证目录及包的独立 GitHub API digest，v2 目录的旧包引用必须回读不可变原始 Release，禁止引用链、任意 URL、本机路径或 Shell 参数。页面先调用原生模块预检；确认时提交 `selection_sha256` 及稳定的 `Idempotency-Key`，由现有 Ops 模块任务队列处理。响应丢失时沿原幂等键查询/重试，不重建任务；Worker 按实时源版本、权限、依赖与迁移再次准入。选择摘要只确认目标，不冒充环境不变或数据库事务证明。

v3 目录的当前全渠道累积撤回策略同时约束目录呈现和固定包选择；稳定渠道不会漏掉最新 RC 中的撤回。已撤回条目保留可见撤回状态，禁用新的在线选择，不自动卸载既有实例。旧 v1/v2 目录显示未提供撤回名单，不能标成已验证当前未撤回。安装预检验证源码、依赖、Edition 和迁移计划，运行安装状态由原任务再次核对；部署、租户开通和人员权限各有独立事实来源。

发现/预检不发布模块，也不自动执行脚手架升级。运行安装结果仍以原任务状态和应用验收为准；协议测试、Vue 组件测试和读公开目录成功，不能代替真实应用首装/跨租户验证。

官方目录GET本身可能读多个GitHub Release列表及v3撤回策略；本地页面在同一官方子页查询期间**只允许一个在途请求**，重复点击不重复发起整组外呼。用户离开子页或切换稳定/RC渠道时，旧响应不能覆盖新目录或错误状态，卸载消费者同样使旧结果失效。出站原生 `OutboundHttpAttemptObservation` 已提供headers/body分段计时和Request-ID，不因优化UI而跨请求缓存撤回策略；正式HTTP总时长、SQL/外呼次数与p95仍须同一有权请求ID现场取证，单次模拟适配器计数不等于真实网络收益。

功能交付=实现＋中文说明＋接口/服务合同＋正常/失败例子＋版本＋相应检查＋检索入口。人工文档解释用途/负责人/输入输出/读取来源/状态/正常失败/授权/事务/重试/升级/例外和验证；图/伪代码英文标识配中文，标设计示意或已验证。

代码注释覆盖自有公开服务、关键逻辑、配置/迁移：输入限制、变更/副作用、错误、防重复及理由。不每行写废话、不逐文件复制Markdown，不手改第三方/生成代码；安全不因注释即生效。数据字典/关系可从迁移或隔离库生成，补充业务含义。接口/服务、模块/权限/配置/事件目录优先生成；自动化不能猜业务流程。

维护者与使用者资料分开：生成应用/模块附带绑定版本的使用资料、公开合同、命令、错误与升级说明，不依赖维护者工作区或私有治理资料。资料从公开源和机器声明派生；生成应用的 AGENTS/README 必须指向其实际可访问资料。创建工程模板、上游受管代码、再生成文件、应用自有扩展、实例秘密/数据分别标明责任，不能以最新脚手架全目录覆盖升级。


## 5. 生成链路与版本绑定

结构化源→OpenAPI/服务/事件目录→可读资料/模块SDK→对照真实路由/Validator/响应→绑定源码提交与工具版本。沿用openapi-typescript/实际路由inventory/模块PHP metadata/manifest/ModuleArtifactWriter，OpenAPI目标3.0.3。缺请求/响应合同标route_only，不编造；运行事实和验证证据分别接入。

先生成API/SDK，再通过原生入口重建模块摘要/modules.lock；锁发生变化后，再运行同一API生成入口刷新最终目录对锁的来源摘要，然后处理模板清单/编译。`api-catalog.json` 的 inputs 绑定 `modules.lock`，不能在锁更新后仍使用旧目录执行安装或 reconcile。check-openapi检查派生差异。SDK随产品/模块，不塞回Core；真实消费者按授权确认。跨仓同任务/提交关联，发布材料精确版本，不据生成命令成功宣称完整CI。

生成的 `api-catalog.json` 为 API 提供独立 `api_id`、受众及源码访问模式。安装和正常模块 reconcile 通过既有 `ModuleCatalogApplier` 将选定范围同步到实例 API 目录，消失的 API 标为退役，不另建运行时扫描入口。同步不授予角色权限；尚未实例配置的关联跟随源码默认增删并更新修订，已显式保存的实例关联（包括获准保存的空集合）保留。新增关联只接受同受众、活动且受保护的 API，退役 API 可供辨认和解除既有关联。生成应用仍由原创建流程按最终 APP 输入重建 API 目录，发行及升级继续使用既有迁移、摘要和清单链路。

公开人工正文、代码、类型和机器声明随 Code 源仓维护；生成参考位于指定目录或版本化制品。生产不实时依赖维护者治理仓。大型手册、OpenAPI 和迁移数据按需读取，不为压缩体积手改真值或删除有效数据。

## 6. 唯一专题

架构范围和工程选择见[总览](../architecture/overview.md)；模块发现、调用、依赖和交付见[模块专题](../architecture/module-development-delivery.md)；身份、Scope、服务绑定和事件任务各有专题；语言与生命周期见 PHP 规范；声明式业务开发见应用约定。导航只由[文档目录](../README.md)维护。

每项机制按实际需要细化，不造空壳文件、不设数量上限；以单一事实、版本、类型/生成器和真实检查控制质量。

## 7. 实际阅读入口

从 [公开文档目录](../README.md) 按任务阅读；接口真值与 SDK 入口见 [API 与 SDK](../public/api-and-sdk.md)。完整开发源码产品及生成应用交付版本化 `docs/` Markdown 使用资料，不携带独立文档站工程或要求文档站依赖安装，也不依赖维护者私有站点、绝对路径或私有 API 副本。资料只展示可公开合同，不保存令牌或自动调用生产 API。浏览器可通过资格环境登记的只读本地文件服务访问实际交付资料；路径、逐文件摘要和 API 合同版本须与该应用发行清单绑定。这证明资料交付及可读取性，不代表文档站构建或远端发布，旧线上站不代表新候选。

生成应用的 API 指南指向 `server/generated/openapi.json` 及 `api-catalog.json`，可由支持 OpenAPI 的工具读取。维护者可读目录不等于生成应用自动携带同一组件。结构化 metadata/路由生成合同与 SDK，不会自动理解全部 PHP 注释；复杂字段、时序、事务和错误仍需人工说明与用例核对。`info.version` 按 [API 与 SDK](../public/api-and-sdk.md) 从同一源码树的 `release-versions.json#source_product_version` 生成，不能仅因源码版本提升继续发布过期的生成合同。
