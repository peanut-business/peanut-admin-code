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
→ AppInit事件
→ 框架RegisterService初始化器 → BootService → AppService::boot
→ HttpRun、全局中间件及MultiApp → 应用中间件（安装、维护限制）
→ 显式路由 → 路由中间件（身份、模块、权限）
→ Controller构造/initialize → Controller中间件 → action参数解析及业务
→ 中间件逆序返回、日志及finally恢复 → Response::send → Http::end
```

这里的 `AppService::register` 发生在加载本应用service.php时，早于AppInit；不能因为框架的RegisterService初始化器在AppInit之后，就把应用服务注册也写成之后。[AppService](../../server/app/AppService.php)登记现有身份reader、基础设施和模块接口；boot通过Model::maker为相应模型接入Scope策略。

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

维护应用源码可直接在当前工程开发。内部create-app按精确commit、tree、manifest和形态生成独立应用，记录 `.peanut/application-manifest.json` 及受管基线；生成应用的根AGENTS.md来自专用公开模板，不复制维护者入口。生成器不复制Git历史、私有维护资料、秘密或已安装依赖，也不改变Core包和许可证身份。创建工程、首次安装数据库、创建租户是三件事。取得完整产品发行包的使用者按包内说明装依赖和安装，不必先运行维护者生成器。

业务 CRUD 预览另从 `server/app/adminapi/services/generator/GeneratorRenderService.php::render` 进入，目标是已登记模块的 admin-web 贡献，不是创建整个应用。其页面使用 Web 已声明的 Element Plus，表格行插槽、独立分页、确认事件和权限键遵循实际组件合同；列表与提交状态复用公开 `useAsyncList/useAsyncAction`。输入未声明软删除时不生成回收动作，已声明时保留普通/回收接口及主键类型；失败结果不能提示操作成功。预览返回 create/merge 与原文件摘要，不会自动应用合并或建表。源码仓可复用 `server/tests/fixtures/generator-element-plus-inputs.php` 的四组定义分别调用该入口；PHP 模板回归、实际前端消费者检查和完整产品安装是不同验证范围。

首次安装使用 `server/database/install.php`。产品升级使用已经安装的 `scripts/upgrade`，入口分plan、apply、verify和recover；入口先核包内 inventory、逐文件 SHA-256、版本兼容及来源/目标身份，不能先运行未校验目标包代码，也不是git pull或无约束composer update。后端环境通过 `PEANUT_SERVER_ENV_FILE` 指定。

已安装入口默认连续执行。需要新版维护能力时，可使用另行核验固定源码身份及工具 SHA-256 的可信外部维护工具；它必须自带已安装的 Composer/Core 依赖，`--instance-root` 仍指向原完整实例，不能加载尚未认证目标包的依赖，也不能向旧应用手工植入新脚本。

升级的文件物化使用可信入口自身的 Scaffold 引擎和已安装依赖；`--project-root` 只指定操作目录，不要求 Compose 宿主实例或排除 vendor 的目标 staging 安装依赖。目标运行依赖仍按目标原生锁构建到独立 PHP 镜像。prepare 在发布 staging 目录前，将与候选、计划、包、工具和实例配置绑定的所有权记录连同原配置及运行回执快照原子发布；同计划未完成 prepare 可核对绑定后原生重建自己的 staging，不覆盖原恢复快照。未知或旧无绑定目录拒绝，不追认或手工清理。已经进入恢复方向的计划只能完成原生恢复。

`apply` 可单次指定 `--stop-before=health` 或 `--stop-before=activate`，其他命令不接受该参数，重复或未知值拒绝。前者在真实迁移核验完成后停于 `migration_verified`，后者在真实健康检查及受管文件 verify 完成后停于 `healthy`；所有前置阶段回执和文件核验账本均已持久化。两者仍要求数据库恢复、保持写入关闭且 `activation_started=false`，返回 `status=paused`、边界、真实阶段、candidate、计划/状态 SHA-256 及已有证据，不产生新阶段或失败回执。同计划不带暂停参数的 `apply` 继续执行；已开始或越过请求边界时拒绝，不能回退或抹去进行中的阶段。暂停不证明恢复验收，`recover` 仍按原配对恢复合同执行，激活开始后只允许前向修复。

官方模块随维护者明确选择和审阅的固定 scaffold 来源整体吸收，绑定真实源码 commit/tree、release manifest、逐文件摘要与 canonical 模块 manifest/lock；这些摘要证明选定输入的内容，不认证发布者，不要求签名私钥。预检以已安装且通过生产 canonical 核验的官方图为起点，投影目标官方模块的完整控制器、服务、迁移及客户端贡献，先以原生 `PluginArtifactWriter::checkLock()` 核固定模板原文的完整 canonical 图；产品名称等参数渲染改变源文件字节后，按原生 make → lock 重建 APP 派生 manifest 和含原客户模块的锁并核依赖。计划同时固定原文图、渲染参数、APP 派生图与派生字节，应用及新基线使用同一派生字节，不要求渲染后摘要等于模板原文摘要。官方包与模块的变化必须提升各自版本，同版本内容不得变化；模块成员、根路径及官方归属不能借升级转给客户包。非官方 bundled 包只有在当前应用受管清单、固定 from/target 声明中的包/成员/根路径与来源合同一致，完整包文件均为 scaffold owner 且当前内容、权限、受管基线摘要全部吻合时，才能按上游整体吸收；命名空间或 bundled 标签不能替代归属证明。客户模块、app-owned 重叠和未登记的额外官方根文件受到保护，冲突不得靠单文件填入或手改摘要解决。应用/核验检查目标完整插件图，恢复检查原图；`module:adopt-package` 保持私有模块源码入口，不用于接收 official.*。

server-only Compose 实例使用 `server/docker/scripts/update.sh plan|apply|recover`。维护者使用另行核验提交与脚本 SHA-256 的上游维护工具时，可显式传 `--instance-server=/absolute/instance/server`；Compose、数据库和私有配置仍取这个原实例，工具来自已核源码，目标归档只按外部可信 SHA-256 校验后消费。plan 将实际维护工具固化到 workspace，apply/recover 复核工具哈希，不需要在运行实例内手工修补升级器。归档文件按路径排序后严格比较路径、内容 SHA-256 和权限；归档遍历顺序不作为内容身份。

只有 Nginx 配置变化时，apply 在维护/停机前用实例登记的不可变 Nginx 镜像执行目标配置的 `nginx -t`，结果绑定计划、镜像及配置 SHA-256。切换后重新创建 PHP/Nginx 容器，使文件绑定挂载读取新文件；数据库容器保持原样。Dockerfile、Compose 或其他运行配置变化仍要求独立准备兼容镜像，本入口不自动放行。

PHP 维护容器将实例挂载为 `/instance/server`，保留发布及插件清单中的 `server/` 路径；workspace 在宿主机和各维护容器中使用同一个规范化绝对路径。私有运行确认只让 runtime、private 和 public/storage 按实例合同可写，程序文件保持只读。

数据库迁移后调用原生模块协调命令时，子进程从自己的受控环境文件重新加载配置；父进程已加载的后端及临时安装变量不传入子进程，仍保留环境文件选择和非配置进程环境，不放宽禁止外部配置覆盖的规则。

迁移适配器将成对备份的数据库身份按字段排序后严格比较，保持资源、端点、库名和类型一致，不因 JSON 字段顺序误拒。SQL 目标复用安装器的 scaffold/overlay 版本解析，APP 独立发行序号仅作为自有迁移的默认版本，不用于过滤 Peanut 上游迁移。核验通过原生 runner 检查适用 SQL 的不可变摘要和 pending 集合，缺少任何应执行的迁移账本行即拒绝完成；不能只检查查询实际返回的状态行。

数据库配置固定分层：应用后端只使用 `DB_HOST`、`DB_PORT`、`DB_NAME`、`DB_USER`、`DB_PASS`；MySQL 管理密码只在 Docker/部署编排环境使用 `MYSQL_ROOT_PASSWORD`。应用 `server/.env` 不保存 root 密码，Docker 私有环境（例如 `server/docker/.env`）不改用应用密码别名。现行实现不使用 `MYSQL_ROOT_PASSWORD_FILE`，旧 `DB_ROOT_PASS` 或旧 root 密码文件只允许被一次性兼容迁移消费，不能作为新的配置来源。Compose 向 MySQL 官方镜像映射 `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` 仅是容器初始化边界，不改变上述应用配置命名。

文件协调层仍使用已有基线和三方差异：上游变化可更新，本地变化保留，双方变化或未知冲突明确报告；应用自有和第三方文件不自动覆盖。所有权变更需要精确adoption，不能把整个后端或前端都标为自有。文件锁、计划新鲜度、恢复副本和逐文件替换不等于完整数据库事务。

完整升级还要独立准备目标依赖与前端、备份并限制写入、执行应用/模块迁移、切换及健康/业务验证。失败恢复按同一计划处理数据库、公共与私有文件及受管代码；activation_started后不得自动回灌旧备份。文件层recover不证明数据恢复，fresh不能冒充升级，不保证任意历史版本或未支持rename自动迁移。详见[模块交付](../architecture/module-development-delivery.md)与[开发规范](standard.md)。

## 10. SSR与两种形态

SSR常驻Node为多个请求生成HTML，全局可变Token、租户、store或私有HTML缓存可能串身份，这是风险模型而非已证实事故。包含网站客户端的应用中，`pc/composables/useRequest.ts`在请求作用域建立client；服务端只读取Host并通过Nuxt适配器核可信Host，显式不转发个人Cookie，访问令牌也只在浏览器取得。上游地址和协议来自部署配置，不采用任意请求输入。Pinia和个人状态必须保持当前Nuxt实例及浏览器边界，公开HTML不能混入个人数据。

`pc/utils/rendering-policy.ts` 的 `pcPublicSsrPages` 是唯一公开 SSR 登记：首页、资讯列表、分类、详情和关于我们。Nuxt 的 `pages:resolved` 从真实文件路由生成规则，并同步已初始化的 Nitro 配置，动态参数只覆盖自身路由段；每个未登记页面显式 CSR，`/**` 也默认 CSR，所以新增资讯子页面不会继承整个目录的 SSR。政策页面目前属于公开 CSR；登录、OAuth、个人资料、收藏、账户安全、充值属于私有 CSR。没有新页面登记时不要扩大 SSR 通配符。

公共 Layout 只消费既有网站公开配置；头像、昵称、登录和充值导航在 `MemberNavigation.client.vue` 中读取。新闻详情用请求级 `useAsyncData` 保存固定公开字段，匿名请求不携个人凭据，响应额外字段不进入新闻 payload。`ArticleFavorite.client.vue` 在浏览器消费既有鉴权详情接口中的收藏布尔值，收藏写入沿原权限接口；失败或失效个人会话保留可匿名阅读的正文。关于我们复用网站标题、logo、简介、slogan 和 copyright，不新增配置表。

PC URL 为 `/`，物理产物仍放 `server/public/pc`；Admin、Platform、Mobile 和 PHP API 保留独立 Nginx location。`pc/.env.production` 或显式 `PEANUT_CLIENT_ENV_FILE` 选择 `NUXT_PC_RENDER_MODE=hybrid|spa`：Hybrid 执行 `npm run build` 并运行 `.output/server/index.mjs`；SPA 必须另用包含 `NUXT_PC_RENDER_MODE=spa` 的配置执行 `npm run generate`，只部署该次生成的完整 `.output/public`，无需 PC Node SSR 服务。正式 Docker 构建已分别保存 Hybrid 输出、重新 generate SPA，再由 `PEANUT_PC_RENDER_MODE` 选择对应 Nginx；不能将 Hybrid 的 public 目录当成完整 SPA。

hybrid公开首屏、浏览器个人态、代理、缓存、退出及完整SPA模式需要真实HTTP/浏览器验证。类型检查、源码构建或模拟上游不能证明生产PHP、数据库、CDN及实际终端正确。发布前另核各依赖支持范围，不把开发标识当发布版本。

standalone与multi-tenant共用业务模块、tenant_id结构及MultiTenantDataScopePolicy。前者固定真实默认租户并隐藏SaaS运营及租户切换，不限制员工数量；后者启用运营平台、租户开通与Host管理。形态投影和运行配置须匹配；改一个环境值不会迁移数据或合并多个租户。
