# 当前代码导读

路径相对本应用源码根；本文解释实际接线，不是第二套规范或完整产品验收。先读[开发规范](standard.md)和[应用约定](application-conventions.md)。调用顺序以本次原生锁及实际加载源码为准；执行者分别记录源码、依赖、命令与验证范围，不能从本文推断某项运行验证已通过。

## 1. 产品与代码

这是模块化管理应用基础，不是预先完成的全部行业系统。官方模块及workflow、artifact-revision、entitlement-quota等可选模块以实际module.json和插件锁为准；fixture.delivery-record是测试模块，不算产品。已有身份组织、会话、审计、设置字典文件、文章分类、富文本、会员/OAuth、任务通知CSV、机器/Webhook、充值回调退款及运营诊断等源码；存在源码不代表外部渠道和业务已经全部验收。

PHP技术Core和Web公共包不含完整业务。模块类型使用各模块composer.json声明的 `PeanutAdmin\Modules\...` 前缀及src目录，Core技术前缀保留。platform是运营端、web是租户后台、pc是网站、uniapp是移动客户端；职责与默认组合见[默认交付](../architecture/default-delivery-and-ownership.md)。H5、微信构建和终端实际运行需要分别验证。

## 2. 当前Controller

[BaseController](../../server/app/BaseController.php)接收App，从该App取得Request，构造时检查xxClass声明；`$this->xx`按受信源码声明解析。接口必须有正式绑定，返回类型、继承、保留名、真实属性冲突和循环均检查。`$this->context`从当前App读取已登记reader；未知属性、赋值和unset拒绝，不造默认身份。

```php
// ArticleController 的接入片段，省略 use、校验及其他声明。
/** @property-read ArticleAdministration $crud */
protected string $crudClass = ArticleAdministration::class;
// CrudTrait 通过 $this->crud 执行明确的管理合同。
```

Model在一次Controller操作内复用，新记录显式resetControllerModel；Validator和Query每次取得干净对象。业务服务仍构造注入；直接PHP调用不自动注入，测试显式传服务或建立独立App绑定。CrudTrait统一允许输入、可写字段、分页结果与显式软删动作，不能开放未授权通用写入。详见[Controller规范](controller-access-and-namespaces.md)。

## 3. HTTP实际顺序

[HTTP入口](../../server/public/index.php)先加载受控环境，再加载Composer，最后执行Http::run、Response::send和Http::end。[CLI入口](../../server/think)同样先加载环境和依赖，再进入console。当前框架的服务初始化应区分应用service.php和框架初始化器：

```text
server/public/index.php
→ server/bootstrap/environment.php → server/vendor/autoload.php
→ new App / Http::run
→ App::initialize → load配置、公共文件、事件和app/service.php
→ app/service.php登记AppService → AppService::register及模块绑定
→ ModuleExtensionService::register → ThinkPHP登记模块原生Service
→ AppInit事件
→ 框架RegisterService初始化器 → BootService → AppService::boot → 模块Service::boot
→ HttpRun、全局中间件及MultiApp → 应用中间件（安装、维护限制）
→ 显式路由 → 路由中间件（身份、模块、权限）
→ Controller构造/initialize → Controller中间件 → action参数解析及业务
→ 中间件逆序返回、日志及finally恢复 → Response::send → Http::end
```

这里的 `AppService::register` 发生在加载本应用service.php时，早于AppInit；不能因为框架的RegisterService初始化器在AppInit之后，就把应用服务注册也写成之后。[AppService](../../server/app/AppService.php)登记现有身份reader、基础设施和模块接口；boot通过Model::maker为相应模型接入Scope策略。

模块可以在同一个 Provider 上继承原生 `think\Service`，通过原生 register/boot 接入事件、路由及命令；普通绑定型 Provider 无需空生命周期方法。详细扩展时点、租户启停钩子和失败责任见[框架扩展与生命周期](../architecture/framework-extension-and-lifecycle.md)。

Controller的initialize由构造函数直接无参调用，不自动注入；Service::boot由框架服务启动机制调用，两者不同。路由认证早于Controller构造，不能移到initialize之后的Controller中间件。Callback与Container::make的构造/后置回调也不是同一入口；扩展前应核实际invokeClass、make和resolving调用路径。HttpEnd不代替可靠任务。

[ExecutionContextStore](../../server/app/common/execution/ExecutionContextStore.php)是进程内栈；run在finally恢复并检查上下文一致性。reader可在装配阶段建立，具体身份在请求或任务边界内进入。普通FPM和串行任务的恢复检查不能扩大为Swoole协程或全部常驻并发安全。

## 4. 入口中间件

| 入口 | 链路及用途 |
| --- | --- |
| 租户后台 | LoginMiddleware → OfficialModuleMiddleware（模块路由）→ AuthMiddleware → OperationLogMiddleware；会话/Host、安装开通、动作权限与留痕 |
| 会员 | CheckTokenMiddleware及模块检查；核JWT、服务端会话、会员和租户，建立ConsumerExecutionContext |
| 匿名网站 | PublicTenantModuleMiddleware；可信Host或已登记默认租户及公开动作，匿名不取消隔离 |
| 平台 | 平台Host、登录与权限；不冒充租户员工 |
| 外部回调 | 原始HTTP → 回调用例 → 绑定及验签 → 模块 → 业务；第三方tenant_id不是信任来源 |

后台位置为 `server/app/adminapi/http/middleware/`，会员为 `server/app/api/middleware/`，公共模块边界为 `server/app/common/infrastructure/module/`。应用middleware.php处理安装和维护，路由按需要认证；根app/middleware.php为空不表示没有MultiApp。安装、开通、动作和数据范围不能合并成is_admin；HTTP保护入口，服务仍须保护CLI和worker调用。

## 5. 文章编辑例子

`POST /adminapi/official.article.edit` 登记在[文章模块路由](../../server/app/modules/official/article/route/app.php)。该路由实际依次登记Login、OfficialModule、Auth和OperationLog中间件。

员工会话、Host和状态检查 → 模块检查 → 文章编辑权限 → ArticleController/CrudTrait::edit → ArticleValidate及允许/可写字段 → ArticleAdministration合同 → 服务核上下文和动作权限 → 事务内锁定分类、文章并核文件引用 → tenantOwnership Scope及模型写入检查 → 保存和响应 → 留痕及上下文恢复。

合同由模块Provider::bindings接入。文章和分类复用标准CRUD签名；软删除、回收查看、恢复与永久清理分别授权，文件引用不随软删物理清理。非HTTP调用同样必须建立合法执行范围，不能把任意CLI调用视为已授权。普通保存保持同步，不自动通知全部监听者。

## 6. 钩子与特性

| 机制 | 实际作用及边界 |
| --- | --- |
| AppService register/boot | 登记依赖；Model::maker给对应模型接策略，不是万能属性注入 |
| BaseController initialize | 对象构造期间的无参初始化，不是构造前或自动注入点 |
| handle(Request,Closure $next) | 前置检查、后置响应及finally状态恢复 |
| TenantOwnedModel::scopeTenantOwnership | 经该模型查询增加范围，裸Db::table不会自动继承 |
| onBeforeInsert/onBeforeUpdate | 模型写归属检查，不能假定原始批量SQL触发它们 |
| CrudTrait beforeAdd/Edit/Delete | 同步模板钩子，默认空，不是异步事件 |
| ModuleProvider::bindings | 模块接口进入同一容器，不自动授予业务权限 |
| readonly/属性提升 | 固定属性及减少样板，不是深拷贝或授权 |
| SensitiveParameter | 减少堆栈泄露，不是存储加密或所有日志脱敏 |
| finally、事务及行锁 | 恢复本地状态或保证覆盖的数据库一致性，不撤销外部付款或保证恰好一次 |

app/event.php保留AppInit、HttpRun、HttpEnd、LogLevel和LogWrite项，但默认listener内容、bind及subscribe为空。专用通知、工作流和任务记录不等于一个统一Outbox总线。可靠协作遵守[事件任务专题](../architecture/events-and-tasks.md)。

## 7. Worker实际执行

模块Provider登记TaskWorkerContributor → 注册表组合通知、导出等处理者 → 领取任务与租约 → 验证可信信封和授权 → 进入任务及业务模块范围 → 各checkpoint核租约、权限和模块 → 最终fence → 保存结果并finally清理。

任务不携带长期Token或上一次执行身份。撤权与失租分别处理，不无限重试无权任务。具体handler必须在正确批次及外部动作前调用checkpoint，框架不能截获任意代码的全部副作用。事务和租约不能代替外部接口的幂等及结果核对。

## 8. 读代码的方法

先选择一个业务动作：入口 → AppService → 模块路由 → 中间件 → Controller/CrudTrait → 应用用例 → 公开合同和所属模块数据访问 → 测试。构造和方法注入、员工和会员安全域、模块和人员授权、manifest和原生lock各有职责，不按重复词删除机制。

跨模块配置转移可从ImportExport的三个适配器追到Settings或Integration公开合同，以及Identity已公开的模块配置服务；详细的租户状态、条件写入、秘密引用和审计边界见[PHP规范](php-thinkphp-guidelines.md)。不要绕过这些边界直接访问别人的私表。

需要核验框架时，查看实际已安装依赖中的App::initialize/load/register/bootService、Http::run/end、Route dispatch、Callback exec、Dispatch中间件管线及Container的invokeClass/invokeReflectMethod/bindParams/make/invokeAfter。仅阅读源码不等于已经执行相关场景。

## 9. 应用生成、安装与升级

客户 APP 制包绑定干净的已提交 Git commit/tree，并按 Git blob 校验当前发行源码；合法客户修改和新增文件不要求重写 `.peanut/application-manifest.json` 的上游采用摘要。该清单的归属及原受管 baseline 路径、摘要仍需严格校验并保持不变，供后续冲突检测使用。初始 generated-template 没有独立 APP Git 身份，仍须逐文件符合生成清单，不接受生成后修改。

维护应用源码可直接在当前工程开发。内部 `create-app` 按干净源码的实际 commit、tree、inventory 和形态生成独立应用，记录 `.peanut/application-manifest.json` 及受管基线。`generation_source` 记录仓库、请求的 ref、`development`／`prerelease`／`stable` 渠道和正式发行版本（开发来源为 `null`）；`template.version` 是升级兼容与 baseline 版本，不能替代实际源码身份。开发来源直接使用当前模板，不能借同号历史发行清单把自己的来源标成已发行。公开来源须核固定发行锁与清单，公开候选 commit/tree 和清单封存的 scaffold commit/tree 分别保留。生成应用的根 AGENTS.md 来自专用公开模板，不复制维护者入口。生成器不复制 Git 历史、私有维护资料、秘密或已安装依赖，也不改变 Core 包和许可证身份。创建工程、首次安装数据库、创建租户是三件事。取得完整产品发行包的使用者按包内说明装依赖和安装，不必先运行维护者生成器。

业务 CRUD 预览另从 `server/app/adminapi/services/generator/GeneratorRenderService.php::render` 进入，目标是已登记模块的 admin-web 贡献，不是创建整个应用。其页面使用 Web 已声明的 Element Plus，表格行插槽、独立分页、确认事件和权限键遵循实际组件合同；列表与提交状态复用公开 `useAsyncList/useAsyncAction`。输入未声明软删除时不生成回收动作，已声明时保留普通/回收接口及主键类型；失败结果不能提示操作成功。预览返回 create/merge 与原文件摘要，不会自动应用合并或建表。源码仓可复用 `server/tests/fixtures/generator-element-plus-inputs.php` 的四组定义分别调用该入口；PHP 模板回归、实际前端消费者检查和完整产品安装是不同验证范围。

首次安装使用 `server/database/install.php`。开发 APP 吸收上游使用已安装 `scripts/upgrade --scope=source` 的 plan、apply、verify、recover，固定 `--instance-root`、已认证完整包 `--package` 和绝对 `--plan`；沿用 Scaffold 归属、冲突与文件恢复，只修改开发源码，不操作运行实例。APP 从自身固定提交发行 server 包后，宿主已安装 `server/docker/scripts/update.sh` 映射同一协调器的 `--scope=server`，以 APP 自有归档、外部可信摘要及 workspace 驱动唯一 product plan/state。目标未验代码不作为维护入口。

完整上游升级包的 manifest 固定 `upgrader.scope=source`，只携带源码预检与 Scaffold 工具，不携带运行实例的数据库或宿主升级驱动；迁移源码仍按 append-only 清单核完整性。运行实例只消费 APP 自身发行的 server 包。

升级包的 `compatibility.source` 使用合法 Semver 范围 `minimum_inclusive <= 当前源码版本 < maximum_exclusive`，上界必须等于目标版本。现有 `same-major` 包要求来源、下界和目标处于同一主版本，不能用于 4→5；明确跨主版本来源的包须标记 `major_policy=source-range`。跨主版本可能改变业务功能、API、依赖和迁移行为。执行前审阅所选来源→目标的变化及 APP 自有业务、依赖、API 与迁移影响，备份并解决已知风险后再尝试。CLI 跨主版本 apply 必须传入 `--confirm-major-upgrade`；AI 在执行前须向人说明来源→目标、风险和计划并取得明确确认，已提前明确授权本次操作或明确范围的可按该授权执行，不重复确认，泛开发授权不足以确认跨主版本 apply。当前开发仅吸收源码，不包含生产数据库迁移或部署；新源码尚未发行，不能据此推断既有包可跨主版本或目标兼容性已获完整验证。

开发来源归入公开同版本时，原生 `release-adoption-plan|apply|verify|recover` 使用同一 Scaffold 计划、锁、账本及恢复目录。只有 APP 实际 generation tree 与固定公开候选 tree、inventory 均相等，原受管 baseline 逐文件完整，且公开锁、封存清单及文件摘要核对后，才只改应用来源元数据；客户定制和 APP 自有版本保留。候选 tree 与封存 scaffold tree 是不同角色，不因版本号或 inventory 单独相同就视为等价。源码确有变化时必须使用目标版本严格升版且来源范围覆盖当前兼容版本的真实升级包，继续走现有三方合并；范围不覆盖时返回 `SOURCE_RANGE_UNSUPPORTED`。安装包本身不冒充升级包。普通源码升级和同内容归位均更新清单中的当前来源字段；同计划恢复还原旧清单，不需要网络或发行 checkout。

已安装工具核 inventory、文件 SHA-256/权限、来源/目标身份及实际受管内容。维护 PHP 使用 `PEANUT_UPGRADE_PHP` 指定已登记、满足 PHP 8.3 的绝对入口。目标 vendor 按原生锁在同一计划的 workspace 准备，锁和完整性符合时复用；不构建应用镜像。文件、依赖、数据库回执是从属证据，不另推进升级生命周期。未知 staging 或缺绑定恢复材料拒绝追认。

官方模块随维护者明确选择和审阅的固定 scaffold 来源整体吸收，绑定真实源码 commit/tree、release manifest、逐文件摘要与 canonical 模块 manifest/lock；这些摘要证明选定输入的内容，不认证发布者，不要求签名私钥。预检以已安装且通过生产 canonical 核验的官方图为起点，投影目标官方模块的完整控制器、服务、迁移及客户端贡献，先以原生 `PluginArtifactWriter::checkLock()` 核固定模板原文的完整 canonical 图；产品名称等参数渲染改变源文件字节后，按原生 make → lock 重建 APP 派生 manifest 和含原客户模块的锁并核依赖。计划同时固定原文图、渲染参数、APP 派生图与派生字节，应用及新基线使用同一派生字节，不要求渲染后摘要等于模板原文摘要。官方包与模块的变化必须提升各自版本，同版本内容不得变化；模块成员、根路径及官方归属不能借升级转给客户包。非官方 bundled 包只有在当前应用受管清单、固定 from/target 声明中的包/成员/根路径与来源合同一致，完整包文件均为 scaffold owner 且当前内容、权限、受管基线摘要全部吻合时，才能按上游整体吸收；命名空间或 bundled 标签不能替代归属证明。客户模块、app-owned 重叠和未登记的额外官方根文件受到保护，冲突不得靠单文件填入或手改摘要解决。应用/核验检查目标完整插件图，恢复检查原图；`module:adopt-package` 保持私有模块源码入口，不用于接收 official.*。

server-only Compose 实例使用 `server/docker/scripts/update.sh plan|apply|verify|recover`。`--instance-server=/absolute/instance/server` 显式选择原实例；协调器和维护工具仍来自该实例已安装的 APP 制品，Compose、数据库和私有配置也取原实例。目标归档只按外部可信 SHA-256 校验后消费。plan 将已安装维护工具固化到 workspace，每次执行前由协调器复核工具清单和哈希。归档文件按路径排序后严格比较路径、内容 SHA-256 和权限；归档遍历顺序不作为内容身份。

该 Compose 的 PHP/Nginx/MySQL 只提供固定运行环境，应用代码与 vendor 位于宿主 `server/`，公共上传、runtime、私有上传及安装身份随该目录持久保留；数据库数据使用 `server/docker/mysql/` 目录挂载。运行配置取 `server/docker/.env`，应用配置取 `server/.env`，两者不互换。`start.sh` 和 `update.sh` 不构建应用镜像；APP 独立发行来源与 Peanut 上游来源分别绑定。完整开发源码包保留 APP 二开和源码吸收合同，`package-release.sh --server-only` 生成实例部署包；PC SPA 浏览器产物来自同次发行，SSR 本批后置。

明确登记的临时多租户源码首次部署消费真实 `generated-template` 制品。原生生成器的 APP 自有公共资源登记尚未分配数据库时，部署允许其 `resources.databases=[]`；宿主数据库、Compose、镜像和端口仍须由维护者资源登记精确绑定，首次启动后显式选择原生安装配置并生成实例私有资源登记，不从空模板推测资源。制品已有数据库投影时仍核对所选资源唯一身份、库名和完整容器端点；永久 APP 自有 Release 始终要求该投影。此范围仅用于带固定来源、到期时间与替换 owner 的多租户首次安装，不用于自动 APP 升级，也不覆盖已有实例目录。

只有 Nginx 配置变化时，apply 在维护/停机前用实例登记的不可变 Nginx 镜像执行目标配置的 `nginx -t`，结果绑定计划、镜像及配置 SHA-256。切换后重新创建 PHP/Nginx 容器，使文件绑定挂载读取新文件；数据库容器保持原样。Dockerfile、Compose 或其他运行配置变化仍要求独立准备兼容镜像，本入口不自动放行。

PHP 维护容器将实例挂载为 `/instance/server`，保留发布及插件清单中的 `server/` 路径；workspace 在宿主机和各维护容器中使用同一个规范化绝对路径。私有运行确认只让 runtime、private 和 public/storage 按实例合同可写，程序文件保持只读。

数据库迁移后调用原生模块协调命令时，子进程从自己的受控环境文件重新加载配置；父进程已加载的后端及临时安装变量不传入子进程，仍保留环境文件选择和非配置进程环境，不放宽禁止外部配置覆盖的规则。

迁移适配器将成对备份的数据库身份按字段排序后严格比较，保持资源、端点、库名和类型一致，不因 JSON 字段顺序误拒。SQL 目标复用安装器的 scaffold/overlay 版本解析，APP 独立发行序号仅作为自有迁移的默认版本，不用于过滤 Peanut 上游迁移。核验通过原生 runner 检查适用 SQL 的不可变摘要和 pending 集合，缺少任何应执行的迁移账本行即拒绝完成；不能只检查查询实际返回的状态行。

数据库配置固定分层：应用后端只使用 `DB_HOST`、`DB_PORT`、`DB_NAME`、`DB_USER`、`DB_PASS`；MySQL 管理密码只在 Docker/部署编排环境使用 `MYSQL_ROOT_PASSWORD`。应用 `server/.env` 不保存 root 密码，Docker 私有环境（例如 `server/docker/.env`）不改用应用密码别名。现行实现不使用 `MYSQL_ROOT_PASSWORD_FILE`，旧 `DB_ROOT_PASS` 或旧 root 密码文件只允许被一次性兼容迁移消费，不能作为新的配置来源。Compose 向 MySQL 官方镜像映射 `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` 仅是容器初始化边界，不改变上述应用配置命名。

文件协调层仍使用已有基线和三方差异：上游变化可更新，本地变化保留，双方变化或未知冲突明确报告；应用自有和第三方文件不自动覆盖。所有权变更需要精确adoption，不能把整个后端或前端都标为自有。文件锁、计划新鲜度、恢复副本和逐文件替换不等于完整数据库事务。

部署实例更新还要准备目标依赖、配对备份并限制写入、执行应用/模块迁移及健康/业务验证。失败恢复按同一计划处理数据库、公共与私有文件、安装身份、受管代码及依赖；activation_started 后不得自动回灌旧备份。开发源码范围的 recover 不证明数据恢复，不保证任意历史版本或未支持 rename 自动迁移。详见[模块交付](../architecture/module-development-delivery.md)与[开发规范](standard.md)。

## 10. SSR与两种形态

SSR常驻Node为多个请求生成HTML，全局可变Token、租户、store或私有HTML缓存可能串身份，这是风险模型而非已证实事故。包含网站客户端的应用中，`pc/composables/useRequest.ts`在请求作用域建立client；服务端只读取Host并通过Nuxt适配器核可信Host，显式不转发个人Cookie，访问令牌也只在浏览器取得。上游地址和协议来自部署配置，不采用任意请求输入。Pinia和个人状态必须保持当前Nuxt实例及浏览器边界，公开HTML不能混入个人数据。

`pc/utils/rendering-policy.ts` 的 `pcPublicSsrPages` 是唯一公开 SSR 登记：首页、资讯列表、分类、详情和关于我们。Nuxt 的 `pages:resolved` 从真实文件路由生成规则，并同步已初始化的 Nitro 配置，动态参数只覆盖自身路由段；每个未登记页面显式 CSR，`/**` 也默认 CSR，所以新增资讯子页面不会继承整个目录的 SSR。政策页面目前属于公开 CSR；登录、OAuth、个人资料、收藏、账户安全、充值属于私有 CSR。没有新页面登记时不要扩大 SSR 通配符。

公共 Layout 只消费既有网站公开配置；头像、昵称、登录和充值导航在 `MemberNavigation.client.vue` 中读取。新闻详情用请求级 `useAsyncData` 保存固定公开字段，匿名请求不携个人凭据，响应额外字段不进入新闻 payload。`ArticleFavorite.client.vue` 在浏览器消费既有鉴权详情接口中的收藏布尔值，收藏写入沿原权限接口；失败或失效个人会话保留可匿名阅读的正文。关于我们复用网站标题、logo、简介、slogan 和 copyright，不新增配置表。

PC URL 为 `/`，物理产物仍放 `server/public/pc`；Admin、Platform、Mobile 和 PHP API 保留独立 Nginx location。`pc/.env.production` 或显式 `PEANUT_CLIENT_ENV_FILE` 选择 `NUXT_PC_RENDER_MODE=hybrid|spa`：Hybrid 执行 `npm run build` 并运行 `.output/server/index.mjs`；SPA 必须另用包含 `NUXT_PC_RENDER_MODE=spa` 的配置执行 `npm run generate`，只部署该次生成的完整 `.output/public`，无需 PC Node SSR 服务。当前部署选定 SPA，只消费同次 APP 发行生成的完整浏览器产物；SSR 常驻服务及 Hybrid 运行合同本批后置，不能将 Hybrid 的 public 目录当成完整 SPA。

hybrid公开首屏、浏览器个人态、代理、缓存、退出及完整SPA模式需要真实HTTP/浏览器验证。类型检查、源码构建或模拟上游不能证明生产PHP、数据库、CDN及实际终端正确。发布前另核各依赖支持范围，不把开发标识当发布版本。

standalone与multi-tenant共用业务模块、tenant_id结构及MultiTenantDataScopePolicy。前者固定真实默认租户并隐藏SaaS运营及租户切换，不限制员工数量；后者启用运营平台、租户开通与Host管理。形态投影和运行配置须匹配；改一个环境值不会迁移数据或合并多个租户。
