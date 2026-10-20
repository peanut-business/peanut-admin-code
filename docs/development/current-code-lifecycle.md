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

### Owner 限定的 HTTP SQL 计时

默认关闭。部署 owner 可在已获准的传统 FPM pool 本地进程环境中配置 `env[PEANUT_HTTP_SQL_TIMING_REQUEST_ID]` 为一个符合 RequestTrace 格式的精确请求 ID；保留 `clear_env`，由 pool 显式传递这一个变量。只有真正的 `fpm-fcgi` 请求且 `X-Request-Id` 与该值匹配才会选择采样。选择器只读 `getenv(..., true)` 的 OS/putenv 本地值，不接受普通 SAPI/FastCGI 参数或仅靠 HTTP header 开启；此变量不是 `server/.env` 白名单项，不将请求值复制进本地环境。CLI、长驻 worker 和其他请求不安装新 listener、不输出采样日志。启用仍须运行资源 owner、FPM 配置和变更准入；源码具备该开关不表示保留的 APP 可立即启用，本次不修改或重载任何运行配置、环境、镜像或 deployment binding。不得改 APP_DEBUG 来取得样本。

选择请求在 AppService 注册最开始确认没有既有 SQL listener 或已创建的连接后，先登记原生 Db listener，再仅为当前默认连接启用 trigger_sql。冲突时放弃采样，不清空 listener、不改变原配置；未选中时原 APP_DEBUG 的 SQL 日志行为不变。选中请求的 listener 替代原生缺省 raw SQL 日志 fallback，只累计非 CONNECT 的成功原生回调数与秒数，不保留、哈希或输出 SQL、绑定参数、DSN、身份或凭据。最终 Request 在 Http::run 初始化之后才创建，所以计数暂存为本次 FPM 请求的标量；HttpEnd 核验最终 header、owner 本地值和现有 RequestTrace ID 后，仅输出 `http_sql_timing` 的 request_id、count、sql_seconds、coverage_start、coverage_elapsed_seconds。记录发生在原生日志 save 之前，无响应字段或新指标 header。

覆盖从 `AppService.register.before_composition` 到 HttpEnd；HttpEnd 后的 middleware end SQL、此前的初始化查询、直接 PDO 或没有成功原生计时回调的语句、其他未启用 trigger_sql 的连接不在此计数保证内。原生秒数从 PDO prepare 前到 bind/execute 后，不包括连接建立、result fetch/hydration；CONNECT 被排除。SQL 字符串的原生构造和回调、汇总本身仍有成本，样本是 instrumented 观测，不是零开销基线，不宣称已测响应耗时或节省比例。同一计时行用 hrtime 记录覆盖区间总秒数 coverage_elapsed_seconds，以比较 SQL 回调合计与其余覆盖区间成本；它不是端到端 HTTP 耗时，差值不能直接归到某个函数。使用普通 FPM 的请求内生命周期，不据此宣称常驻/协程采样安全。

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

开发联调明确选择本地 PHP/Web Core：PHP 使用真实 Composer path link，普通 PHP 源码修改直接由开发消费者读取，不以逐次提交、固定包、发布或重新封装 development-runtime 为前提；依赖声明、路径或配置合同变化才刷新相应原生依赖/服务登记。Web Core 保留 dist 导出，由显式本地 Core 模式的标准开发入口托管原生 TypeScript watch。开发链接与候选、正式 APP 的固定依赖分别使用，不把维护者目录或活链接放进交付 APP。

候选首装与独立 APP 派生先用 `freeze-core-release --php-core-dir=<固定干净PHP仓> --web-core-dir=<固定干净Web仓> --version=<统一精确版本> --output=<不存在的绝对目录>` 生成一个 PHP ZIP、六个 Web TGZ 及实际 `release-core-inputs.json`，记录来源 commit/tree、版本与摘要；无需先公开发布 Core。随后固定同一 Code 源码，依次为 MT、ST 调用 `build-scaffold-release --version=<同一版本> --source-commit=<固定Code提交> --edition=multi-tenant|standalone --workflow=full --internal-core-inputs=<冻结回执绝对路径> --output=<各自不存在的绝对目录>`。该脚手架候选通过原生 Composer/pnpm/npm 投影双 Core 声明及锁，携带七个包，将真实投影文件与归档纳入受管清单及 baseline；非 Core 锁定版本与来源保持不变。

再从所选形态的真实 `scaffold-manifest.json` 生成 APP：现有 `create-app` 使用 `--source-channel=development --source-commit=<同一固定Code提交> --source-tree=<对应tree> --scaffold-manifest=<候选清单绝对路径> --profile=full --workflow=full` 及正常产品名称、slug、package、target、edition 参数。候选受管程序和固定归档直接取自该产物，复用安全路径读取、文件摘要、品牌 token 与 Composer content-hash 渲染；五个 app-owned 初始默认仍由同一固定生成器生成。严格核固定来源、inventory、形态、完整受管集合及七包摘要，不接受候选新增路径抢占 app-owned、excluded 或未知文件。品牌相关 Plugin/API 元数据按最终 APP 字节由既有生成器重建，记录真实 baseline 和所消费候选清单摘要。`generation_source` 保留 development/null，`scaffold_candidate.formal_release_eligible=false`，不把未公开候选标成已发布版本。APP 的 Composer/npm/pnpm 依赖通过自身携带的 packages 相对路径与原生锁安装；不得依赖维护者 Core checkout、私有 Project 或临时外部 file 路径。候选生成、APP 自有依赖安装与实际首装分别记录结果，生成源码不等于运行或资格通过。

正式交付在固定候选完成所需资格及人工 Gate 后，依次公开 Core、核公开渠道可取的精确版本与来源，再生成正式 APP 依赖、锁和发行包；main/tag、正式发布及生产授权保持独立，内部候选构建不代替这些 Gate。

客户 APP 制包绑定干净的已提交 Git commit/tree，并按 Git blob 校验当前发行源码；合法客户修改和新增文件不要求重写 `.peanut/application-manifest.json` 的上游采用摘要。该清单的归属及原受管 baseline 路径、摘要仍需严格校验并保持不变，供后续冲突检测使用。初始 generated-template 没有独立 APP Git 身份，仍须逐文件符合生成清单，不接受生成后修改。

维护者内部完整 APP 使用 `build-internal-edition-candidate --source-commit=<固定提交> --edition=multi-tenant --profile=full --workflow=full --core-inputs=<冻结回执绝对路径> --output=<不存在的绝对目录>`。edition、业务 profile 与 workflow 分别传入原生生成器；当前内部完整候选只接受 full/full，固定选择 Admin、Platform、PC SPA、UniApp H5。PHP ZIP 与六个 Web TGZ 原字节全部携带，绑定冻结回执、Git 来源、SHA-256；Web 原生锁使用从同一 TGZ 计算的 SHA-512 SRI。仅投影四端真实声明及 Core peer 闭包，不为 testing 等未消费包创造消费者或宣称运行覆盖。原生 Composer/pnpm/npm 生成锁后，非 Core 包的固定版本与来源身份必须保持不变；随后复用 `package-release.sh --internal-candidate` 在隔离目录 frozen install/ci、核实际安装位置及归档内容，再构建现有四端并组装完整 APP。安装阶段禁用生命周期脚本，Composer 使用 no-scripts；显式构建入口仍执行正常类型检查和构建，不运行治理测试、PHP 安装或 SQL。

内部投影保留原 `.peanut/application-manifest.json` 与 baseline 的字节和原摘要，以 `.peanut/internal-candidate.json` 单独记录依赖声明、锁和七包的投影树及新摘要；不把改写后的内容称为原始基线。封存源码校验与含四端浏览器产物的 APP release identity 分别核验，最终归档保留二开源码、原基线和全部七包，不携带 vendor/node_modules。回执只证明本次实际完成的输入、安装来源和四端构建；内部版本不等于公共发包，`formal_release.eligible=false`，不证明安装、升级或生产运行资格。源码实现与独立审查不代替构建；完整构建须在最终 dev 输入固定后单独授权，资源部署和测试仍遵守各自 Gate。

内部完整候选成功后正常清理临时现场；失败时 PHP builder 保留本次 0700 私有临时根，嵌套 `package-release.sh --internal-candidate` 同时保留实际 snapshot/build、原生 PM 锁、已安装状态及安装来源证明的实际输出（若已产生），不在内层 EXIT trap 提前删除。两层输出保留路径、调用 UID/PID 与清理责任；上下文文件使用 0600，不转储环境或凭据，不把失败材料装入交付归档，也不把部分证明称为通过。生命周期 owner 是该次真实 Build Session owner，诊断完成后按明确范围清理；超时或强制终止未执行 finally/trap 时，先对账同一 Job/子进程和已登记 staging 根，不启动重跑来重新取得现场。调用者的持久 Job 日志与输入回执位于临时清理范围外。

该 builder 的无依赖源码快照通过原生 `ApplicationCreator::apiGeneratorPath` 使用调用工程已准备的 `scripts/generate-api-contracts.php`。原生生成器必须先核工具与快照的 generator、Web manifest/lock、toolchain 字节相等，并核已安装 pnpm 锁、openapi-typescript 精确版本和 CLI 位置；APP 输入及输出仍取快照投影，不复制 node_modules、不临时下载，也不放宽身份检查。内部制包回执另记实际 generator 源码摘要、依赖锁摘要和经原生核对的工具版本；这不代替候选随后自身的隔离安装。

维护应用源码可直接在当前工程开发。内部 `create-app` 按干净源码的实际 commit、tree、inventory 和形态生成独立应用，记录 `.peanut/application-manifest.json` 及受管基线。`generation_source` 记录仓库、请求的 ref、`development`／`prerelease`／`stable` 渠道和正式发行版本（开发来源为 `null`）；`template.version` 是升级兼容与 baseline 版本，不能替代实际源码身份。开发默认使用当前模板；显式候选采用上述固定产物消费者，不能借同号历史发行清单把自己的来源标成已发行。公开来源仍须核既有固定发行锁、仓内封存路径与清单，公开候选 commit/tree 和清单封存的 scaffold commit/tree 分别保留。生成应用的根 AGENTS.md 来自专用公开模板，不复制维护者入口。生成器不复制 Git 历史、私有维护资料、秘密或已安装依赖，也不改变 Core 包和许可证身份。创建工程、首次安装数据库、创建租户是三件事。取得完整产品发行包的使用者按包内说明装依赖和安装，不必先运行维护者生成器。

业务 CRUD 预览另从 `server/app/adminapi/services/generator/GeneratorRenderService.php::render` 进入，目标是已登记模块的 admin-web 贡献，不是创建整个应用。其页面使用 Web 已声明的 Element Plus，表格行插槽、独立分页、确认事件和权限键遵循实际组件合同；列表与提交状态复用公开 `useAsyncList/useAsyncAction`。输入未声明软删除时不生成回收动作，已声明时保留普通/回收接口及主键类型；失败结果不能提示操作成功。预览返回 create/merge 与原文件摘要，不会自动应用合并或建表。源码仓可复用 `server/tests/fixtures/generator-element-plus-inputs.php` 的四组定义分别调用该入口；PHP 模板回归、实际前端消费者检查和完整产品安装是不同验证范围。

首次安装使用 `server/database/install.php`。开发 APP 吸收上游使用已安装 `scripts/upgrade --scope=source` 的 plan、apply、verify、recover，固定 `--instance-root`、已认证完整包 `--package` 和绝对 `--plan`；沿用 Scaffold 归属、冲突与文件恢复，只修改开发源码，不操作运行实例。APP 从自身固定提交发行 server 包后，宿主已安装 `server/docker/scripts/update.sh` 映射同一协调器的 `--scope=server`，以 APP 自有归档、外部可信摘要及 workspace 驱动唯一 product plan/state。目标未验代码不作为维护入口。

普通已安装 APP 的 registered 数据库升级在首次 `plan --scope=server` 显式选择 `--backup-tool=/absolute/protected/tool.json`；工具为实例 owner 或 root 持有的 canonical、单链接、0600 JSON。原 product plan 冻结其路径、owner、模式和完整字节摘要；同一 plan 再次调用仍须选择同一工具，apply／verify／recover 从原计划复核，工具缺失、变化或数据库 provider 改变即停止。bundled 和 source／runtime 路径不接受此参数。工具复用已审的 `ssh-docker-exec` 固定 SSH alias、现有容器和客户端绝对路径；`source_schema_backup` 绑定该 APP 的 `database_resource_id`、`database`、`whole_schema=true`、实际 `server_uuid`、MySQL 8.4 精确 `mysql_version` 与 30–600 秒 `timeout_seconds`。凭据仍只由现有数据库容器中的原环境提供，不复制给 APP 普通账号。PHP/Nginx 使用自己的固定镜像和 APP bridge；registered 不准备或依赖本地 MySQL 镜像、容器或数据目录。

原生 backup 在原计划、真实 writer／DDL 排除窗口和流量关闭后，以独立 Root 客户端核精确目标 UUID／schema／版本、客户端摘要和直接完整可见权限，读取目标 schema 的完整 tables／views／routines／events／triggers／definers 及入出 FK 元数据；跨库仅观察引用边，不读取其他 schema 的业务行或定义。捕获前后须保持同一元数据和客户端。完整逻辑备份保留 routines、triggers、events、原 definer、CREATE／DROP／USE 及配对公共／私有文件、安装身份，过程终态、原始定义、dump 摘要和工具引用由原 whole-backup HMAC 认证。普通 APP PDO 继续使用自己的 schema 权限和业务证明，权限模式匹配只接受实际 `partial_revokes` 下的精确 schema literal，不借 Root 可见性代替。

当前普通升级合同尚未覆盖任意视图、stored routine、event、trigger 的外部效果及跨库 FK；真实完整观察发现这些对象、非事务表或共享 tablespace 时明确 `NOT_READY`，保留 protected 元数据、已捕获备份和现场，指出需要审阅的具体对象，不进入受管代码或数据库写入。目标 InnoDB tablespace 元数据也必须真实读取；普通受支持的 file-per-table 路径显式使用 `--no-tablespaces`，不生成全局 tablespace DDL，`--set-gtid-purged=OFF` 不改变实例全局 GTID 状态。完整 dump 不等于 effects 审阅或恢复资格。恢复仍需原有明确授权和原计划恢复窗口，核当前外部效果及精确目标、工具、客户端；不改 definer、不启停 event、不隐式授予全局权限。已开始但无成功终态的恢复不得重放；activation 开始后不得自动回灌旧数据。本段源码能力不代表普通升级、定制数据保全或实际恢复已经验证完成。

应用和模块的版本化 SQL 使用锁定的 `topthink/think-migration` 官方执行器及真实 `Migrator`，通过其 MySQL Adapter 扩展读取、更新原有 `pa_schema_migration`／`pa_module_migration` 唯一账本；不复制旧历史到另一张 `migrations` 表。Peanut 生命周期保留原 SQL 顺序、目标发行选择、checksum、applying／failed、repair／adopt、业务 preflight、安装身份与恢复；执行成功仅由官方 `migrated()` 回调写入。完整 text key 是持久化身份，官方整数版本使用固定 SHA-256 前 60 位加一的投影，先检查源文件与该 scope 历史 key 并集，碰撞即拒绝，不按数组顺序重编号。这个整数不表示日期或回退目标。SQL 保留自身事务边界并排空多结果集，不在不可逆 DDL 外再套事务。

`20261019-module-migration-provenance-metadata` 是 `20261016-module-migration-provenance` 的唯一固定修正：MySQL 8.4 的 CHECK 元数据会以完整转义引号返回，旧 SQL 的裸引号比较会误选重复 ALTER。原文件、原摘要及已存在的 failed／applied 整行保留；当前源选择改用新 key／固定摘要，官方执行器只为自己实际执行的新 SQL 写 applied。不新增状态枚举或另一张账本；`supersessions` 明确列出旧真实状态、旧整行及新修正行，`awaiting_correction` 不等于完成，只有新真实 applied 才是 `resolved_by_correction`。这不是接受任意 SQL 编辑、失败或历史的通用开关。发行快照及已 applied 迁移不重写，也不把所有未发布 dev 源码都称为永远不可修。

首次安装使用固定的 `server/database/baselines/5.0.2-r2/`：canonical `manifest.json` 声明来源、覆盖的精确应用历史 key／hash、101 个业务表、运行输入与数据阶段，`baseline.lock` 固定清单及 schema／data 字节。r2 将 File 的五张基础表直接定义为统一存储后的结构，并播种无凭据的 local account、公开／私有 space 和默认 route；不执行旧数据映射，也不预建后续六张 File 增量表。它来自版本化 Code Schema 与 SQL，不能从业务数据库导出；不携带安装账号、固定密码、渠道密钥或环境地址。运行身份由原 BootstrapService 创建，当前租户 ID 显式绑定，停用渠道的 callback key 由固定 SQL 中的 MySQL 原生 `RANDOM_BYTES(32)` 在运行时生成。APP branding 不改写固定 payload 或历史 SQL，派生 APP 清单保留同一基线身份。

明确空库准入后，仅官方 Adapter 的显式 `createSchemaTable()` 以原生 Table API 建立原 `pa_schema_migration`；绑定连接、状态读取和已有库升级不会隐式建表。真实 `CleanInstallMigration` 经官方选择和执行，依次执行固定 Core schema、原业务身份初始化、固定应用 schema、基础数据、canonical permission catalog／品牌默认及版本化数据收敛；保留应用表尚未建立时的原身份初始化边界，避免触发另一轮租户业务播种；数据填充后的 menu key 非空收口仍在同一官方 Migration 中。新鲜安装的成功日志 key 为 `20261019-clean-install-5.0.2-r2`，官方 Migration 的名字、整数版本及账本写入均绑定实际基线身份。覆盖清单中的应用历史效果已折入固定定义／数据，不重放历史 ADD，不插入未执行历史 key 的 applied 行；后续仅执行未覆盖的真实增量。覆盖只在基线自身真实 applied、固定 hash 和所有历史来源一致、且无混合旧历史时成立。基线 failed／applying 不变成 fresh 可重试。

已经交付的 `5.0.2-r1` 四件原字节保留。历史选择读取实际唯一基线记录，分别验证 r1 或 r2 自己的 key、revision、manifest／payload hash 及真实 applied 状态；未知或多条基线记录拒绝，不能用 r2 清单解释 r1。发行包同样按声明的固定基线身份验证数据文件，不执行目标包 PHP。r2 只修正后续空库安装，不将当前 r1 部分安装库重新播种或覆盖；其无损续作仍须满足独立的原生保护合同和准入。

File 新鲜安装的采用来源绑定同一 PDO 上真实 applied 的固定基线 key／manifest hash，安装完成后的受保护 `baseline.json` 在现有 `database.migration_chain` 中保留该真实 id／sha，供已记录采用证据重验；它不要求已移入模块的旧应用 SQL 路径，也不伪造旧应用 SQL 的 applied 行。已有旧应用迁移的真实采用记录仍按原账本和原摘要验证。模块 SQL、声明摘要、完整前后态证明及原生执行准入各自保留；应用基线身份不代替模块执行历史。File 的固定 CHECK 词法解析只将数据库返回的反斜线加单引号分隔符转换为同一个普通字符串 token，字符串内容含反斜线或引号的这种表示仍拒绝；完整 AST 与结构匹配不放宽。

已安装升级不执行 clean seed；没有基线记录的旧库仍沿原历史及固定修正。模块安装／升级在应用结构完成后使用同一官方模块迁移和真实 adoption 合同，基线覆盖不代替模块自身的执行／采纳记录。当前 partial 库不获得空库或基线身份，不清数据、不重新播种。固定来源和代码检查不证明新空库安装、当前业务、恢复或发布已经通过；这些各按实际登记、窗口、剩余预算和执行回执验收。

| 实际前态 | 当前固定选择与结果 |
| --- | --- |
| 旧 key 精确 3822 重复约束 failed，当前两列／两 CHECK 完全 canonical | 保留旧 failed；新 key 执行真实 no-op 分支并落自己的 applied 后，才报告关系已解决 |
| 旧 key 已 applied，原摘要／完整 canonical 后态一致 | 保留旧 applied；新 key 仍须执行并记录自己的检查结果，不重放旧 SQL |
| 固定 clean baseline fresh，旧／新修正 key 均无行 | 基线自身真实 applied 后按固定清单报告 covered_by_baseline；两条历史 SQL 均不重放、不造 applied 行 |
| 旧 key 无行，完整 before-additions | 仅新 key 执行原生 ADD，再记录新 key applied；部分／不同结构拒绝 |

旧 applying、未知错误、任一固定来源摘要不符、新修正缺失／failed／applying 均不冒充已解决。Runner、source schema 前态／备份、模块前置及 server／product 升级验证使用相同固定选择；验证必须核每个实际选中的 key 都有真实 applied 行、pending 为空，保留原失败关系而不暗映旧 failed 为 applied。

本次开发 runtime 的受限 partial 首装续作只通过原生 owner CLI：`php server/database/install.php --partial=plan|backup|apply --partial-input=/absolute/private/input.json`。没有普通 HTTP 恢复路由，普通 execute 仍拒绝 partial、`safe_retry=false`。调用者须实际 CLI 文件 owner（或 root）、原 InstallationExecutionContext、guided 的原 Setup Token、原 execution.lock 和正常原生精确数据库准入；不借他人 lease。原生 candidate 必须是干净 Git checkout，关键程序字节须与实例一致。当前源只接明确 development runtime 的这一次迁移失败形态，不假称能恢复任意安装或正式部署。

0600、单链接、无符号链接的输入只含 `setup_token`、`actor`（owner/thread/gate）、`original_runtime`／`original_progress`／`original_configuration`／`original_failure`／`original_ledger`、`maintenance`，以及对应步骤的 `plan`／`backup_tool`／`backup_output`／`backup`。原始引用均为 `{path,sha256}`，必须来自实际保全的 native runtime、配置、进度、production 失败日志及原只读失败台账；保留原字节，不重编码或补造 stage 证明。旧 progress 仅有 modules/mode/time，不能单独证明 fresh 完成。计划联合核同 APP 应用身份、原 init／18 个原迁移来源、17 个真实 applied 与原 failed、原 DB UUID／库、当前初始化表与身份计数、模块阶段未开始及无其他 schema 写权限。账号值不输出、不重新输入或重建，原模块集合不改变。缺失／冲突或已出现后续 attempt 均继续阻塞。

`plan` 只读原状态并在 `private/partial-installation/partial-plans/` 保存受保护计划，不授予写入。`backup` 输入增加该原 plan、新建 `private/partial-installation/partial-backups/` 下的输出目录和精确已登记 tooling；原 `source_schema_backup` 合同须对同一库明确 `whole_schema=true`、实际表覆盖、固定 MySQL／client 与超时，原生 lease 同时覆盖 tooling 和 output-dir。复用既有 SSH/container native dump／probe／终态 process 管道，完整导出此库结构、数据、views／triggers／routines／events，另存原进度、配置、runtime、失败与台账原件和 `.env` 的 0600 私有字节；不输出秘密。`backup.json`／`RESTORE.json` 有独立 partial 协议、实际 DB／plan／source 绑定、完整 payload 摘要及原生终态，不接受旧 empty dump 或仅 rc/hash 的运维摘要。当前对象集之外后来创建的对象须另行核归属及精确恢复授权，dump 不能自动恢复“原不存在”；不清库、不自动 restore、不把恢复说明称为恢复执行。

`apply` 必须提供原 plan 和这份真实当前 afterimage manifest，重新核实际原生 DDL／exclusive 权限、同一有效排除 writer 窗口、全部原证据、实际 PHP／加载 Core 与实例程序来源。维护记录 `peanut.installation-partial-maintenance.v1` 绑定 resource_id/server_uuid/database/progress_sha256、active 时间窗、`excluded_writers=[http,workers,other-writers]` 与实际停止回执引用；这是外部 writer 排除的运维合同，JSON 声明和锁本身不证明外部 writer 已停止。数据库、实例 private 安装输出及备份输出各须正常唯一 writer，不能改资源别名绕准入。正式运行须先审阅精确目标／当前备份／读写与剩余额度；源码交付不授权执行。

partial 在任何计划输出／attempt／applying 前核原生 metadata 可见性和对象观察：不足以观察入向跨库外键或 trigger 的权限不能把空结果当作安全。Code 维护分支保留原普通连接可见性检查；APP 分支使用下述既有备份传输的真实 metadata 观察，普通 master PDO 仍只用自己的 schema 权限。完整 schema 上拒绝未审 trigger 和跨 schema 外键，原 plan／backup 保存真实 DDL／外键／effect boundary，受控写入前重查；没有真实完整可见性证据时只阻塞 partial，不扩权、换 master 账号或接受调用者声明。

续作先持久保存单次 partial-attempt，再仅执行新修正；旧 18 行逐字节保持。然后复用原 installModules／原真实 module admission／health／completion，不再次 fresh／seed，不删失败行或提前移除 progress。唯一成功完成后才由原完成逻辑写 baseline／完成锁并移除 progress。任何新失败、中断或已有 attempt 均阻塞再次调用，保留数据与证据，不自动重试；部分模块已开始的其他状态不纳入这条窄入口。备份、恢复和完整质量资格仍分别记录为真实执行、未执行或未知。

冻结的开发 internal-candidate 若仍在同一 APP 0.1.0／full／multi-tenant 的 r1、Identity 九条 executed、前两个模块 active、File 尚未安装的真实 17 模块前沿，可经现有 `scaffold-upgrade preflight --from-manifest --to-manifest` 建立 `internal-candidate-preinstall-correction`。from 是原生旧 scaffold manifest；to 是由最终审阅源码产生的原生 raw scaffold 目录中的 manifest，模板版本仍为 5.0.2，七份 Core 输入、内部资格限制与原九份 File SQL 必须完全保留。此分支只接受已审的基线、File 1.0.15 修正、同一前沿尚未安装的 Document 1.0.0、Article 1.0.5、ImportExport 1.0.12 的精确 schema grant literal 识别与原生派生 manifest，以及对应维护、图验证和普通 registered 升级的精确公共消费者修正。四模块的 module.json、版本、成员/根路径及原 SQL 保持原字节；Identity/ContentTools 仍为唯一 active 模块。精确路径及这四个 pending 版本之外的变化继续拒绝，不放宽普通、已安装、正式或生产的同版限制，也不改变 APP 身份/版本/选择。raw 输入不伪装成带 META-INF 的正式升级包。

模块原有保全检查仍使用自己的实际 master PDO：File、Document、Article 要求目标 schema 的直接 SELECT 与 TRIGGER，File adoption 和 ImportExport 的对应可见性检查要求直接 schema SELECT。五处 grant scope 识别均读取同一 PDO 的实际 partial_revokes 模式；模式 0 只额外接受当前已验证数据库名生成的精确 escaped literal scope，模式 1 使用原 literal 名称，未知模式拒绝。不删除任意反斜杠、不做新增通配匹配、不改变原 REVOKE 与 global/schema 覆盖规则。Root metadata 或备份客户端的权限不能代替普通 master 的这些条件；源码修正不授权授予权限或证明实际权限、安装及运行已通过。

已审维护消费者的有限路径同时涵盖原生工具、普通 registered 升级的对应消费者、`ApplicationSchemaHost` 和完整源图 Resolver；不接受目录前缀或任意新增路径。冻结的完整 APP 虽含 full lock，仍保留 internal-candidate 运行身份，普通 `forSourceUpgrade` 继续拒绝 release 身份。该受限分支只通过 `forInternalPreinstallSourceUpgrade` 读取完整图：调用者必须是实际原生 Runner，而非传入布尔值、协议字段或任意 context 数组；Runner 在原 owner、实际持锁、UUID、r1／17／active2 和私有前沿检查后绑定同一进程与 canonical R，初始还核真实旧完整发行文件、身份原件及摘要。完整 lock、manifest、模块／前端根和 canonical source 校验复用原 `resolve(true)`，不替换为 backend lock，不复制 R 隐藏运行身份，也不从 R 加载 vendor。

prepare 与后续 apply、post-apply、verify、recover、recovery verify 和 resume 的图读取，须重新核原受保护的 source plan、checkpoint、pending、工具／raw 摘要、原始文档及 current／held-preimage 链，绑定同一 candidate、plan 和 R；未建立 pending 的阶段仅限独立 tools-prepared 且尚无 source recovery。文件后像核对与图核对分层完成，不能在证明旧程序前像时递归地先取得待证明的图权限。已变更 R 不重新作为旧发行程序装载；受控退役原身份经真实意图与前像证明后回到普通 development 源图入口，手工删身份文件不建立该权限。真实安装完成使用原 completion 证明，不回退到旧 r1 条件。source plan／checkpoint 的完整性来自实际 SHA、0600／owner、工具及 pending 绑定；HMAC 仅指原已有的 partial-owner 和 backup 记录，不新增签名系统。

原生 source database reader 对该 APP 的公开 `.peanut/application-manifest.json` 核 canonical 路径、owner、单链接和原生 0644 后读取 JSON；私有配置、checkpoint、备份及其他 private JSON 仍按各自原 0600 合同读取。不 chmod 原件，不放宽通用私有 reader，也不改变 APP registry、资源、endpoint 或数据库身份校验。上述源码闭合不证明运行确认、source adoption、安装或恢复已经执行通过。

首次工具输入使用现有 `create-app --scaffold-manifest` 从最终原生 raw 生成独立离线 APP，再通过原 `prepare-vendor.sh` 按其真实 lock 安装依赖；实际加载 Core、锁和七份输入须一致。离线工具仅作维护输入，不替换原实例或创建运行/数据库身份；不复制 vendor，不将未证明 lock 一致的 Code 工作目录 vendor 当作工具来源。维护进程使用自己的加载图和可信 resource reader，将原实例作为显式数据根，不加载其 vendor。后续独立原生 source-admit 子进程和实际 APP 安装进程仍使用原实例自己的 reader 和加载图。

原 owner 在正常关闭流量、固定容器和数据库 writer 排除窗口内，以现有 `runtime-prepare` 消费该真实 ready plan 和 raw 目录，使用实际不存在的 head（`none`）及 generation `0`。首次验证原 ServerReleaseIdentity 和 internal marker，保存有类型的旧身份、来源清单、私有/数据库前沿和完整源码及派生前像；后续不把已变化程序当作一次新的旧包验证。原 source apply/verify 写实际目标并留下同事务绑定的事件。原 projector 生成真实新开发声明，先持久保存新身份、下一 head 及受保护写意图，再撤下精确旧 release/internal 运行身份，最后发布 development marker、CAS head 和原生 admission。两种运行身份并存仍然拒绝；root application-release 保留历史来源，不能冒充当前运行身份。外部原始归档及其摘要继续由实际交付证据保全，不从内部身份反推或伪造归档摘要。工具、metadata 描述文件或后续窗口尚未就绪时，可以在真实 source-admitted、流量关闭处停止；这不代表 Source19 plan、备份或 SQL 已获准。

补偿仅允许同一未激活事务、完整且未变的前像、已知预期后像及原私有/数据库前沿未推进；先撤下新开发身份，再恢复原运行身份，恢复后的 internal-candidate 保持关闭，不发放旧开发准入。未知编辑、安装双记录、数据库/模块进度或激活均阻止源码补偿；不恢复数据库、不清库、不重放 SQL。此源码能力不代表已生成目标制品、已进行 source adoption、已完成安装或恢复资格；实际运行须固定最终集成源码并遵守原资源准入与失败预算。

同一 internal 事务已 `prepared` 且 source apply 已完成时，若冻结 Runner 的后像检查存在已确认缺陷，使用原生受控修正入口继续完整链：

```text
php SOURCE/scripts/upgrade verify --scope=source --instance-root=R --package=BOUND_RAW --plan=P --resume-applied-source=W/recovery/VERIFY.json
php SOURCE/scripts/upgrade runtime-transition --scope=source --instance-root=R --workspace=W --transition=W/source-runtime.json --resume-applied-source=W/recovery/TRANSITION.json
php SOURCE/scripts/upgrade runtime-open --scope=source --instance-root=R --workspace=W --transition=W/source-runtime.json --resume-applied-source=W/recovery/OPEN.json
```

此分支只替换已审 Git 来源的 entry 与 Runner；完整旧冻结闭包先校验，其他 helper、vendor、binding、trusted、原 P、source recovery 和目标应用来源全部保留。旧程序后像核对先完成文件证明，再由外层执行完整图证明，不能在建立 checkpoint 来源期间递归进入同一来源检查。维护工具来源单独输出为 `execution_tool_correction`，不改应用 manifest 或伪装成目标应用版本；不重建 APP／W、不重播 apply，不提供任意 override 或跳过原 graph／前像保护。

每个命令使用当时真实 checkpoint／pending／ledger／head 的独立保护 admission，`protocol=peanut.applied-source-tool-admission.v1`、`command` 为该命令，其余保护文件、两文件 Git pins、authority、原生租约、完整独占资源窗口沿用下方合同。额外绑定原 `source_recovery_sha256` 和 `origin_binding_sha256`；前两步只接 prepared、未激活、无恢复或安装进展，完整 candidate 历史必须依次为 READY、apply started、apply applied。verify 仍执行完整文件与图证明，成功 verified 事件额外记录修正来源及首份 admission 的 path／sha256；transition 仅接受此后唯一 verified 事件，并通过 `origin_admission` 引用同一首份文件，不能换修正源码。首份文件仅作不可变来源证明，不在合法阶段推进后重放旧快照。

open 仍须原生安装完成及当前 admitted／未激活证明、真实部署 head、完整 verified 来源、停止容器与关闭流量；其 admission 与 verified 事件绑定同一修正来源。原生安装完成按既有合同保留 `partial_origin_deployment` 并推进部署 binding，`origin_binding_sha256` 使用该原部署前沿重建核对，修正机制本身不写 binding。每次均重核旧 tools／依赖、source recovery、实际前后像、当前资源登记及原 owner 窗口；不增加 verify、transition、安装或 open 额度，失败与未知结果保留且不自动重放。

若同一事务实际停在 `source-verified`、vendor 已 forward/completed 而投影尚未创建，保护 admission 可选择唯一 `repair=source-verified-projection`；只属于 runtime-transition 及其后 runtime-open，不属于 verify 或普通投影。它在原两工具之外准确增加 `scripts/package-release-files.py` 的 old/new SHA、模式和 Git 来源，其他旧闭包不改。transition 另绑定 `vendor_switch_sha256`，核原/目标 vendor proof、同 transition/plan 的 completed journal、未激活、无程序写意图/下一 head/投影目录；只检查已切换的 vendor，不再 stage/switch，不倒退 phase。原 verified 事件和首份 admission 不改，新维护来源通过 `origin_admission` 引用原 verified 来源；open 还以 `transition_admission` 的 path/sha256 绑定同一修复来源、决定和 vendor journal，仍须原生安装完成。

真实 Runner 在原锁内重新读取现有 verified source proof，调用既有 internal Resolver 完整图检查，并在检查前后比对图全部 artifact、模块/前端根（包含新增文件、空目录）、源身份及依赖声明输入。结果经该次父子管道交给受审第三 producer；producer 只接该保护事务、原 plan/完整 verified ledger/source proof 及相同输入，独立核 canonical 模块与前端内容、manifest/lock pins，生成前后重核输入 fence。它复用已经完成的真实图证明，不在子进程伪造 issuer，不放宽普通 `forSourceUpgrade` 的 release 根拒绝。PHP helper 仍从原 trusted 加载。准确同 R 投影完成后，原冻结 host 的 source-project 再核全部后像；原身份退休、写意图、CAS head、native admission 和安装后 open 继续原链，不用其他 APP 或新运行实例替代。

该有限 internal 投影消费的是已交付运行候选，不要求其中存在四客户端开发用 node_modules；其 locks 子步骤沿原生声明及 Composer 来源校验，不传普通开发投影的 `--installed`。普通入口仍执行该参数。原 verified proof 的 managed/app-owned 摘要和前后 fence 保留实际源码与锁字节，六份 Web 归档继续逐份核摘要，真实 PHP vendor 继续原生 completed proof、vendor-check 与 vendor-state 检查。它不安装前端依赖、不重新构建客户端，也不把投影成功当作重新完成包级安装资格。

该有限维护入口将每次 child 的原始 stdout/stderr 保存到原 W/recovery 内 action/顺序/阶段专属的 0600、独占新建文件，目录保持原 owner 私有；producer 的原 PHP/node/vendor 子进程同样在抛错前保存原字节。日志不存环境或额外命令凭据，不回显原始 stderr；对外只返回阶段、退出码和摘要，原始文件仅在本地受保护证据中读取。没有为了采日志单独重放动作；部分投影、未知 vendor/journal 或写入失败均保全并停止对应动作，不能删除现场或靠重用 action 名字重试。原失败次数保留，新的明确工程授权下实际次数单独记录；源码修复不自带运行、数据库恢复、清库或发布授权。

尚未写源码的 `preparing` 取消与源码恢复分开。prepare 和 preparing-cancel 检查同 candidate 的完整账本；只有 operation=`preflight`、status=`ready` 且原 plan／identity 摘要完全相同的事件属于只读前置。任一 started、failed、recovered、实际变更或未知事件均阻止该分支，后来的 READY 不能遮盖旧事件；通用账本状态和 applied／verified 的实际证明不变。

冻结工具自身存在已确认取消缺陷时，仅允许从已审 Git 源码入口执行：

```text
php SOURCE/scripts/upgrade runtime-recover --scope=source --instance-root=R --workspace=W --transition=W/source-runtime.json --cancel-unmodified-preparing=W/recovery/ADMISSION.json
```

此参数只属于 source `runtime-recover`，不是通用工具替换开关。CLI 在 APP／Core autoload 前验证原 checkpoint、完整旧 tools／tool-inputs／bundle／dependencies；另核 SOURCE 中已提交的 entry 和 Runner 的准确路径、摘要、模式及 commit/tree，再从原冻结闭包加载其他 helper 和 vendor。旧 Runner 不重复加载，更正 Runner 不重新从自身目录加载 live helper；旧 binding、trusted 和依赖不改。不能仅在 dev 改代码后直接运行旧冻结入口，不能改旧 trusted/hash 或换 W 绕过 pending。

ADMISSION 是原 owner 在 W/recovery 内准备的 canonical 0600 单链接 JSON 对象，使用 `protocol=peanut.source-preparing-cancel-admission.v1`、单次 `maximum_uses=1` 和唯一 `action_id`。字段合同如下；摘要均为原始字节 SHA-256，结构摘要用既有 canonical JSON。

| 字段 | 绑定 |
| --- | --- |
| `authority` | 已有明确单次动作决定的 `path`／`sha256`；摘要不代替正常 owner、原生租约或动作授权 |
| `instance_root`、`workspace`、`candidate`、`source_kind` | 原 R／W／candidate；只限 `raw-internal-preinstall-scaffold` 的 internal r1 前沿 |
| `expected_head`、`expected_generation` | 必须为 `null`、整数 `0` |
| `checkpoint_sha256`、`pending_sha256`、`ledger_sha256`、`plan_file_sha256`、`plan_identity_sha256` | 当前准确文件及计划 identity |
| `binding_sha256`、`tools_sha256`、`tool_modes_sha256`、`dependencies_sha256`、`tool_inputs_sha256`、`tool_bundle_sha256` | 原完整冻结闭包，不是只核两文件 |
| `corrected_source` | `root`／`commit`／`tree`；`files` 按 entry、Runner 顺序仅含 `scripts/upgrade` 与 `scripts/scaffold-runtime/ScaffoldUpgradeRunner.php`，各含 `old_sha256`、`sha256`、`mode` |
| `lease_proof`、`lease`、`lease_resources_sha256` | 同源码仓实际 Git common-dir 的 ACTIVE 原生租约路径、`lease/owner/thread/candidate/gate`、原 resources.tsv 摘要；实际锁须完整存在并覆盖 R／W |
| `registry_path`、`registry_sha256`、`runtime_resource_id` | 本次原生独占窗口的当前绝对登记文件及摘要、准确运行资源 ID；只读数据，不作为 APP 配置或加载器来源 |

取消只支持与原 APP 绑定的单一物理 MySQL 管理独占窗口，不接受 `mysql-schema`／`mysql-access` 或共享模式。须从当前登记核同 resource ID／数据库／host endpoint／Gate、physical policy 与完整 service/container/volume/port 管理集合，并核同 R／namespace／数据库的运行资源、HTTP 端口和两个 Compose 容器锁；同物理端点的注册策略须一致，遗留共享或其他管理占用仍阻止动作。output-only 租约或仅固定 resources.tsv 摘要不能证明这些范围。原生 claim 在其正常显式登记环境中完成；实际取消子进程保留原 APP 只读前沿的无外部 `PEANUT_RESOURCE_REGISTRY` 合同，当前维护者登记仅经保护 admission 的绝对 `registry_path` 读取，不将它导入 APP 环境。

原生取消须核 exact preparing、未激活、backups／intents 空、无 source recovery／恢复目录或其他未知进展，完整旧程序、当前 managed／app-owned／baseline 与私有及普通 PDO 只读 r1 前沿不变，原 vendor-preflight 已存在并与当前完整 vendor／composer／lock 匹配，准确容器、镜像、挂载、网络仍停止且流量已关闭。它不安装依赖、不重建 APP／四端、不执行 SQL 写入或 service 重启。W/recovery 在取消前仅允许原 vendor-preflight 与本次 admission；旧证据和所有其他 W/P 保留。

全部条件在原生锁内重核后，只创建 0600 的原 checkpoint／pending beforeimage、audit 与 cancel-intent，再将同 checkpoint／pending 标为 recovered 并保持 closed；不写 source success 或 ledger，不 admit/open/restore，不动源码、vendor、DB 或原部署 head。固定一次性文件使部分写入可被识别；任何元数据写入失败返回 UNKNOWN，保留 audit／intent／前像，禁止自动重放，须另有具体收口决定。取消额度独立于已耗尽的 prepare 和未用后继额度；成功取消不自动授权新 prepare 或新 W。

生成 APP 的 `install_modules` 续作是同一原生 CLI 的另一条有限分支。它只接受自己的 application registry、实际 development runtime 和已准入但未激活的 source-runtime transition；不依赖维护者 Project、Git checkout 或 Project lease。原 Code `migrate_database` 分支及其约束保留。APP 分支只接真实 r1 applied、Identity 九条 executed 和前两个模块 active 的固定 File 前沿，保留原 17 模块选择；不将部分库当空库，不重新 seed。source-runtime 可以准入这份真实残留，但在完成安装前拒绝 `runtime-open`。

APP 的 `plan` 输入为原 Setup Token（guided）及 `backup_tool`：该值是 `private/partial-installation/` 内 0600 的既有 `source_schema_backup` SSH/Docker 工具描述文件路径。Host 从实际受保护进度、配置、失败日志、当前 runtime、同一 master PDO 和自己登记的 host/container endpoint 生成事实，在原 instance/update 与 execution 锁内保存由既有私有 update key 认证的计划。它不接受调用者提供的 actor、maintenance 或原文件声明。实际 Compose 排除窗口由已有 source-only registered guard 读取，绑定本 APP 的已停止 PHP/Nginx、镜像、挂载与原有网络；外部数据库 writer 仍由运行 owner 在正常资源窗口排除，APP 文件锁不冒称已证明外部 writer 停止。计划不授予执行权限。

APP plan 先创建原 authority/plan_id，再由同一既有 SSH/Docker adapter 的独立 Root client 执行固定只读身份、权限和目标 metadata 语句，不替换普通 PDO。客户端实际证明全局 SELECT、目标 TRIGGER 可见性及未启用 partial revokes，核 UUID、host/port、版本、schema、resource/endpoint；只观察目标全表结构、目标 triggers 和触及目标的入向/出向 FK，不读取业务行或扫描其他 schema DDL。请求和真实终态回执在原私有目录保留 0600、摘要和 HMAC，绑定原 runtime/checkpoint/head、PHP/Core、工具/adapter、窗口、nonce 和有效期；手写 JSON、布尔值或历史外部 dump 不能替代。初次 proof 引用及稳定语义进入原 plan/baseline/accepted backup，后续新采集的时间、路径和进程回执独立验证，不以新回执字节等同旧回执。

完整 facts 在普通 PDO 的只读事务开始前采集 metadata，随后在只读快照核实际全表覆盖及普通 SHOW CREATE 摘要；备份前后、apply 前继续完整重检。原 apply guard 在模块合法 DDL 后重新证明当前全部对象的覆盖、完整可见性和相同 effect policy，不强求旧 DDL 全集不变。采集回调只核身份、锁、工具、窗口和调用者事务状态，不递归调用 facts，不跳过原 guard。采集失败保留输出和进程终态，阻止相应动作，不自动重试；这些源码合同不证明真实描述文件、网络、采集或备份已经通过。

APP 的 `backup` 提供原 `plan` 和新 `backup_output`；`apply` 提供原 `plan` 和原生 `backup.json`。二者复用原完整 native dump/probe/process 与 accepted-capture 消费者，拒绝外部运维摘要替代。六个真实原文件均保留原路径和摘要；仅 canonical `server/.peanut/development-runtime.json` 原件接受正常 0644 或 0600，读取前后核 owner/group/mode/device/inode/size/hash，备份及 RESTORE 元数据保留其真实原模式。其他原件和全部备份 payload 仍为 0600；不改原件权限，不用副本假冒原件。

APP `apply` 在持久写入单次 attempt 后使用原 PLS 和每条原 SQL。File 最早 unify 只有实际 Host 当前 File 调用持有的内存 capability、同一 context/PDO、原 r1/history/backup/source/窗口均一致，且完整旧两表、其余九对象缺失、File/object/旧 storage config 三计数为零、两表计数器为 1 和 session 序列默认时，才可执行。执行后仍须完整 File canonical 后态，并核空表、计数器、精确 local account／两 spaces／两 routes 默认数据及时间窗、原 config 和 Identity 业务行保全；catalog、审计和 tenant module profile 由各自正常生命周期处理。未知或非空旧结构继续拒绝；原模块账本记录真实 executed，不造 adopted 或历史 applied。

新鲜 Notification 的 producer 仅在同一真实 installation admission、固定三条来源顺序、namespace/adoption 前驱状态、七目标对象全缺失、父键/catalog/约束名均满足时标为 `after_predecessor`；报告仍为 pending、`may_apply=false`、队列数未知。两个原前驱执行后由原 producer preflight 和保全合同重新核验，不能把缺 outbox 当作空队列。

原模块安装与 health 成功后，APP 先持久保存认证的 completion intent，绑定真实 health 前沿、原 plan/backup/source/DB 及正常 writer 将写入的 baseline/installed 精确摘要，再由原完成逻辑写这两记录。只有 intent 和真实双记录全部匹配，才以原 deployment head CAS 发布真实 installed anchors，并原位接续 source-runtime checkpoint/pointer 和正常 runtime admission。原 partial head、binding 及 source generation 保留在历史中；不会伪造 installed hash、重置 source history 或提前删除 progress。写完双记录后的退出、CAS、checkpoint 或 admission 失败，后续同一 `apply` 只做 finalize-only，核原绑定和真实完成记录，不再运行任何 SQL。仅有 attempt、单边记录或实际业务失败继续阻塞；intent 不能代替真实双记录，不能用于补造安装成功。完成安装后的 source-runtime 禁止恢复旧 partial 源。这里的源码支持不代表当前残留、网络、备份、安装或恢复已经实际通过，所有运行额度与人工 Gate 独立保留。

直接 CLI 的 `migrate:run preflight|apply|verify` 使用与 `schema:application` 相同的参数、实例上下文和准入；`migrate:status` 委托该入口的 preflight，不创建迁移表，输出真实 text key。源码开发需要原有 `--scope=source-development` 和实际 owner／thread／session／gate、维护及恢复证据；发行升级仍走安装实例的正式升级入口。官方通用的目录扫描执行、`--date`、rollback、breakpoint 和 seed 命令不注册，不能绕过生命周期保护。模块仍通过 `plugin:install`／`plugin:upgrade`／受控 `plugin:reconcile` 进入同一官方执行器，不由应用 schema 命令接管其业务语义。

源码开发 preflight 按真实 pending 顺序列出逐条来源、前置证明和后继依赖；缺少业务终态保全或完整恢复覆盖时，计划仍不授予执行权限。原仅覆盖模块迁移来源字段的两账本恢复合同不能用于包含菜单、权限、通知或认证数据变化的链。已迁移归属的 File 应用历史，只在原不可变 SQL、现行 canonical adoption 声明和原账本 key／checksum／applied 一致时识别为历史保留；不删除原行、不把它记入模块账本、不以历史记录代替当前 File 数据证明。未知额外历史继续拒绝。

源码阶段账本按原有序链核对已完成前缀、当前 applying 与尚未执行项，逐条使用 SQL 真实发行身份；旧历史的完整原行保持不变。源码 apply 只接受现有九条固定 SQL 中由原生账本算出的有序 pending 和精确摘要；维护窗口、原生完整备份、恢复引用、baseline 与完整表并集必须一致，旧两账本证据不能扩大使用。维护证据必须证明 HTTP、worker 和其他受影响 writer 的实际排除，且窗口开始不晚于真实备份开始；租约或 JSON 的声明本身不证明外部进程已停止。完整原结构／数据 payload、原生终态 rc0、manifest 与 RESTORE 摘要仍逐次核对，不自动恢复。

备份范围复用固定表映射，不由 SQL 解析或调用者清单扩展。首次 applying 前核对完整原 scope；后继不再拿链初元数据否定前序合法 DDL，而按当前真实前态、旧账本和有序完成前缀逐条检查。固定 DML（含唯一应用账本）按实际事件、更新列及派生更新列检查触发器和入向级联；未知写入在对应阶段前拒绝，已完成前缀和失败证据保留。纯 DDL 的业务表触发器不会因存在而一概阻塞。该维护观察要求当前 PDO 具备直接全局 SELECT 和本库 TRIGGER 可见性且无部分撤权，以免遗漏跨库入向 FK；不自动授予权限。成功结果仅在原生 applied 后读回真实阶段账本并绑定原 source／DB／window、备份引用和安全 after 结果；它是当次窗口证据，不是当前状态健康核对、跨进程私态重算或完整资格通过。

菜单三条固定应用 SQL 的业务事实由 Identity 的 `MenuSchemaMigrationProof::before(PDO,migration)`／`after(PDO,privateBefore)` 提供。preflight 只公开 `safe_summary`；当前 pending 前驱未完成时列为 deferred，不构造影子菜单、解析 UPDATE 或预测 UUID／自增分配。源码维护 Host 和发行 `update-database.php` 均在原同 PDO 的执行回调内重新取得有限私有 facts，原 SQL 排空后、官方唯一 applied 写入前核 `business_postconditions_verified=true`，随后释放 private_state；它不进入计划、结果或日志。后继步骤以当前真实状态重新捕获，不用链初摘要否定后续合法变更。定制／歧义目录按原 SQL 的采用、跳过和冲突分支处理，不增加整个迁移的拒绝 Gate。

权限三条固定应用 SQL 由 Identity 的 `PermissionSchemaMigrationProof::before(PDO,migration)`／`after(PDO,privateBefore,executionBounds)` 证明。preflight 只公开 `safe_summary`，部门迁移尚有 pending 菜单结构前驱时明确 deferred；Host 不再投影权限 CHECK、提取 SQL 选择器或预测最终行。两个原生直接消费者在同 PDO 的 before 回调重新捕获私有事实，readiness／department 严格返回 `true` 请求原不可变 SQL 整批执行两侧的原生时钟，SQL 排空后、唯一 applied 写入前交给 Identity 校验真实 UTC 毫秒区间及业务保全。platform-ops 不请求时钟，时间字段仍须保持；私有事实只驻留当前阶段回调，后继重新捕获，不进入计划、结果或日志。

菜单／权限回调业务断言不替代真实私有备份及完整结构／数据恢复。断言失败沿官方执行器保留 failed／已完成阶段与原恢复点，不伪回滚 DDL、不自动重放或伪造 applied。安全结果仅记录 id／hash／readonly、具体成功字段、安全计数及原生整批 SQL 的两点 UTC 毫秒／历史连接标识，不保留业务行或私有候选，不能重建原私有前态；涉及这些阶段的独立 verify／已完成 migrate 重放当前分别拒绝 `APPLICATION_SCHEMA_MENU_LIVE_REVERIFY_REQUIRED`／`APPLICATION_SCHEMA_PERMISSION_LIVE_REVERIFY_REQUIRED`，不以旧成功摘要冒充实时保全复验。固定 provenance 即使位于多条链中也须为 before_additions；原源码身份、原生权限、完整恢复覆盖与发布资格保持独立约束。

`schema:application verify-receipt --scope=source-development --result=<原native结果>` 单独核“原接受证据”，不是上述实时保全重算。新观测仍需当次实际 owner/thread/session/gate 和正常原生读取准入，在数据库初始化前核完整 `pa_schema_migration` 只读范围；不借用已过期的原 writer 租约。结果只接受原 native writer 的 `private/installation/application-schema/source-development/result-<32hex>.json`，核目录／文件 owner、0700／0600、无链接和文档完整性，单独自报 digest 或任意路径 JSON 不算 writer 来源。原 apply 在真实 applied 回读后、原锁内封存计划路径／字节摘要、原 request／观察时刻、所属合同源码摘要、真实 native executionBounds 及原窗口／备份／有序台账引用；不补造旧收据缺失的字段。

复验绑定原 Git commit/tree、物理 DB／登记资源、原计划 baseline、原时间有效的维护窗口及其原生 dump／终态 process／RESTORE 全链，再在当前只读快照核原选定台账行和原 after 安全字段。原租约现在过期不会抹掉历史成功，当前合法业务定制、grant／revision／时间变化和原 scope 外后续迁移台账追加不要求与旧后态永久相等。合同算法或备份引用字节已变化／丢失、未知来源或未受验收据继续拒绝；不切换工具版本、不复制私有事实或恢复临时数据库来合成证明。输出明确 `verification_level=accepted_original_window_receipt`、`original_window_receipt_verified=true`，同时 `original_private_algorithm_recomputed=false`、`current_target_verified=false`、`qualification=not_run`；它只证明原接受记录及其绑定，不证明当前业务末态、重新计算私有保全或恢复资格。

`plugin:upgrade`／`plugin:install` 的 `--scope=source-development --dry-run` 为明确选择的单个插件生成原生计划，同时必须提供实际 `--owner`／`--thread`／`--session`／`--gate`。入口在初始化数据库前核对干净源码、原安装标记和当前原生资源租约，并在真实产品文件锁、应用迁移 advisory lock 与插件原生锁内读取；缺少真实来源字段迁移时只列为应用前置项。计划和 capture 固定 `execution_admitted=false`，不冒充应用 schema 或发行升级。

同一源码插件入口移除 `--dry-run` 后必须另外提供受保护的 `--source-plan`、`--maintenance`、`--recovery`，不同时执行 backup。宿主要求真实 DDL 原生准入，在上述三个原生锁仍持有时重算完整计划、固定 SQL 闭包和原表范围，逐字段匹配原计划，再核当前有效窗口与真实 native dump／终态 process receipts／RESTORE 合同及独立 Notification 原文件字节。maintenance 使用 `peanut.source-plugin-maintenance.v1`，绑定 source/database/authority/registry、完整 pending/selected、baseline 摘要、已捕获的 scope.tables、有效时间和原排除 writer 回执；它仍是运维排除的证明入口，JSON 声明或 advisory lock 本身不证明外部 writer 已停止。capture 必须始于该窗口内。执行前还要求当前 PDO 对完整元数据有直接 SELECT/TRIGGER 可见性；未知触发器和范围外级联写入阻止对应操作，不自动扩张备份。

仅当前同一进程、同一命令上下文和 master PDO 内的宿主能力允许原 PLS 执行安装或升级；开始 lifecycle、每段迁移及其原 after／applied 边界、catalog 和失败标记都复核 source/actor/lease/window/backup，原事务提交前和业务 before 完成后的 SQL 执行前再次复核。执行仍由原 PLS 和模块自己的保全合同完成，不复制 SQL executor，不伪造安装或发行升级身份。原未知 SQL、namespace、append-only 修复和 File 采用身份限制保留。失败保留已提交前缀和实际失败现场，不自动重放、回滚或 restore。

Notification forward／File adopt 的只读 source 计划在原所属业务报告通过后，可明确表达 `planning_complete=true`、`source_admission_deferred=true`，同时仍保持 `may_apply=false`、`execution_admitted=false`；`source-plugin-plan` 只来自当前真实 host/PDO/原生锁的读取区间，永不由实际写入准入方法返回。这样可以先计算精确恢复闭包，不能据此写入。实际 apply 先验证原计划、真实原备份和有效窗口，取得真实进程内能力后，在同一插件锁内重做原生计划，要求这次真正 `may_apply=true`、固定 effects／原范围／旧 ledger 未变，再进入原 PLS。admission 分别绑定原 deferred 计划和这次可执行计划的摘要；原 File 后态、历史应用记录、安装身份、原 Notification 前后态及所有未知/失败 Gate 仍由原合同判断，不为本地开发伪造已安装或 applied。

source File adopt 的原记录沿用唯一原生 module ledger 和原 provenance；其 `source-plugin` admission 按原 source/DB/operation/request、备份窗口引用和内容摘要解释为历史原生准入记录，供原 adopted readback 验证。这个内容摘要不是发布者认证，不授予未来写入权限，也不是跨进程重新计算私有保全事实；新的写入仍须重新持有实际 native 能力。其他安装／server-release admission 规则不变。

`peanut.source-plugin-result.v1` 在原所属 after 实际通过、原 PLS 返回并回读真实 applied ledger 后，封存到 `private/installation/plugin-source-development/result-<32hex>.json`。它保存真实回调顺序的 key／SQL 摘要、所属合同源码摘要、after 允许公开的安全字段、实际原台账行、原 native／可执行计划、备份范围、准入及窗口引用；不保存 private before DTO、PDO 或 callable。目录／文件为受保护的 0700／0600，写入与 rename 后 fsync 仍在原锁和有效准入内。完成结果明确 `verification_level=native_same_process_lifecycle_completion`、`cross_process_preservation=not_verified`，不以返回成功冒充独立保全复验。

同一 `plugin:install`／`plugin:upgrade` 入口的 `--scope=source-development --dry-run --verify-receipt=<原native结果>` 单独只读复验原接受记录，仍须当前实际 owner／thread／session／gate 和正常原生 `pa_module_migration` 读取准入，不接受 capture／apply 参数。消费者核原受保护命名空间、owner／权限／无链接、文档完整性、原 Git commit／tree 与 writer／PLS／所属合同的真实源码字节，再核原时间有效的维护窗口、原生 dump／终态 process／RESTORE 全链、完整阶段安全字段及当前只读快照中原选定台账行。原租约现已过期不抹掉原成功；原范围外后续台账行和正常业务数据变化不与旧后态永久比较。结果明确 `verification_level=accepted_original_window_receipt`、`original_window_receipt_verified=true`、`original_private_algorithm_recomputed=false`、`current_target_verified=false`、`qualification=not_run`。旧 stdout、任意路径 JSON、缺字段旧结果或单独自算 digest 不算受验原始回执；原源码或备份引用字节变化／丢失继续拒绝。

后续正常业务可改变数据：当前目标结构／业务约束检查、原执行窗口的 before/SQL/after 保全、基于受验原始证据的跨进程复验必须分别表达。旧安全摘要不能重建私有事实；缺少所属业务复验 API 时保留拒绝，不持久化私有 DTO、不恢复临时数据库、不重放 SQL 或制造新的 preservation=true。原接受记录的只读复验不代替私有保全算法重算、当前业务终态或恢复资格。

Plugin 计划中的 `lifecycle_effects` 绑定真实生产链：begin、每条 migration/adopt、catalog 注册、失败标记分别提交，后段失败不会撤销前段；失败标记本身可能失败。单模块 catalog 同步仍包含全局 core permissions/data conditions，以及 permission、target、condition、protected resource、operation 与三类关系、menu definition、setting definition、reference-code set；还会改三张安装/归属表。操作关系可能删除后重建，菜单按全局 key 更新，不能以单租户或 `module_key` 过滤备份。该激活路径不写 settings values、reference-code entries、tenant revisions 或 role grants。File adopt 另写 module ledger 与平台审计，不能称为只补账。菜单冲突日志保留为证据，不随数据库恢复回退。

同一 Plugin 命令显式追加成对的 `--backup-tool`／`--backup-output` 时，在原生计划 consumer 内调用下述同一个选表备份适配器，直到 capture 完成仍持有原三层锁；每阶段重新读取原计划和精确 scope。它使用当前真实计划而非调用者提供的表名，不执行 Plugin SQL 或 lifecycle 写入。原生读取声明必须覆盖所有被备份共享表的完整行，另保留该原计划所需的读取声明；工具和新私有目录仍须在同一正常原生租约内。备份 payload 由完整已存表组成，原不存在表单独记录；恢复这些表的原不存在状态需要对本次实际新增对象另行批准的精确移除，不能假称 SQL dump 自动完成。

当前闭包支持 catalog/lifecycle（十四张共享表，reference-code 表可不存在）及已有 File 报告决定的四个固定 canonical 阶段：adopt 加账本和审计、image execute 加 file_object 和三张新表、references/snapshots execute 加各自新表。unify 禁止 execute，原报告继续决定 execute/adopt/reject；`FileMigrationPreservation::before(PDO,key)`／`after(PDO,privateState)` 仍是每次实际 File 写入的必要保全要求，不因类存在而从 `write_requirements` 消失。Task 的 job adoption／negative profile、Notification 的 delivery adoption／producer identity 四份固定 DDL 另按精确 key／hash 加各自真实写表及模块账本；Document base 按固定源加入六张 Document 表，barriers 只加入 document_operation，原不存在对象仍单独记录。Article67 只在精确 key／hash 且 execute 时加入 article 与 article_body_save 两张实际写表；新 save 原不存在仍单独记录，真实 Document2 前驱及 Article 内层私态保全继续作为写入要求。Member session 固定 ALTER／CREATE 也只按精确 key／hash 且 execute 加入 member 与 member_session 两表，目标缺失及所属 before／after 保全要求保持。ImportExport 固定 CREATE IF NOT EXISTS 按精确 key／hash 且 execute 加入 operation 与 row_error 两表；已有兼容表和原不存在对象分别保全，所属实际 API 仍是写入要求。各阶段继续保留所属业务合同的实际保全要求。其他待执行 SQL（包括未覆盖的 namespace 阶段）、未知 File 方向或原 hash 不符仍阻止 capture；不靠 `owned_tables` 或扫描 SQL 表名推断闭包。可见触发器仅被记录，范围外触发器写入未获准。Plugin 输出使用独立 `peanut.source-plugin-backup.v1`／`peanut.source-plugin-recovery.v1`／`peanut.source-plugin-restore-instructions.v1`，不能喂给应用 schema 的恢复消费者。`captured` 仍不是完整恢复、写入准入或资格通过。

Task／Notification 的四阶段只由原 `PluginLifecycleService` 接入公开 `before(PDO,exactMigration)`／`after(PDO,wholeBefore)`。原生准入先保持，当前 applying 行真实提交后、原 SQL 前捕获；descriptor 只有 `module_key`／`migration_key`／`checksum`／`stageLedger`。后者是宿主固定只读闭包，绑定原 PDO／连接、请求、当前行及真实前驱，业务 before／after 的各自只读快照均调用它；完整模块账本字段必须保持，当前行仍为 applying，历史 checksum／原生 resolution 不重写，也不虚构未来 applied。原 SQL 排空后、官方唯一 applied 写入前要求同阶段 `preservation_verified=true` 和安全摘要字段；wholeBefore 含 live Closure，只保留当前回调内存，成功或失败后释放，不 serialize／hash／持久化。普通 Notification forward report 的 all-applied 约束保持，不用它替换当前 applying 阶段证明。

Notification forward 的私有回执独立于 `database.sql`。原生计划只公开固定目标路径的保护属性、原文件 hash／字节数或可证明缺失；同一包锁下的 capture 另将存在的原始 JSON 字节保存为 0600 的 `notification-forward-original.json`，并在备份前后重核原状态，manifest／恢复指引绑定该独立 payload。原缺失仅记录事实，不授权删除现有回执；恢复必须先保留真实失败 afterimage／attempt，证明本次精确文件及必要目录归属，再按明确授权恢复原字节或原缺失，不递归删除、不自动恢复或重试。rename 后目录 fsync 失败可能已有新回执，不能假定 DB 事务回滚了文件。仅有数据库选表 payload 仍不能声称回执已覆盖；新增源能力不放宽执行、资源登记、外部 writer／DDL 排除或恢复门禁。

File 四个 canonical 阶段只通过原 `PluginLifecycleService` 消费保全 API。execute 在原报告允许后、同 PDO 无外层事务时捕获；原 SQL 排空后的官方 completion 先核原报告已达 adopt 后态，再将本条 `private_state` 传入 after，要求同 key／SQL hash 的 `preservation_verified=true` 后才允许唯一 applied。adopt 在原生采用事务内捕获，原账本和平台审计写入及原报告后态核对之后、提交之前完成 after；失败只正常回滚该 DML 事务，不声称可以回滚已完成 DDL。private_state 含真实 PDO 对象，只在本条回调中持有，不 serialize、散列、记录或从公共摘要重建；成功或失败后释放。原 preflight 不额外调用该 API 扫描 File 行，已 applied 历史仍按原 resolution 合同读取，不把旧阶段摘要当作以后实时数据保全。Host、发行入口经这两条原路径消费，不创建第二执行器或新 autoload；真实备份、writer／DDL 排除、catalog／audit 恢复及现有执行／复验门禁仍独立要求。

Document 两条固定迁移通过所属模块的 `DocumentMigrationPreservation::before(PDO,key)`／`after(PDO,innerPrivateState)`，由原 PLS 实际消费。原生准入及同 PDO 无外层事务前置保持，before 后只保留内层私态；原 SQL 整份成功、结果排空后，在官方唯一 applied 前调用 after 并核 module／key／SQL hash／readonly／preservation_verified、具体 delta 和六表范围，再释放私态。base 只允许六表全不存在后原 CREATE；barriers 只追加 operation 六列和 phase CHECK，其余五表及 operation 原元数据／自增保持，旧值保全依固定无行 DML 的原生 ADD 语义，不扫描历史正文或 operation 行，不转换旧 RichText／Article。五表 metadata preservation query 不替代此六表阶段合同或真实私有备份；本接线不开放 source apply、补造 adopted／applied、live verify 或恢复资格。

Article67 由原 PLS 消费 `ArticleMigrationPreservation::before(PDO,key)`／`after(PDO,innerPrivateState)`。捕获前先从当前 canonical Document 来源核对两条固定原 SQL、真实 installed owner、原生 already_applied 账本和 resolution；E 的父键／六表元数据观察不替代这些前驱。原 SQL 完整执行后、唯一 applied 前重核同一前驱账本／来源及原生 admission，要求固定 Article 四列／CHECK 与新空 save 表的两表 delta；旧内容依原无行 DML 的 ADD 语义保留，不散列或转换历史 HTML。内层私态和前驱事实只驻留本条回调，成功或失败后释放；已有完整 target 或缺少 ledger 不授权重放／伪 adopt。该接线不开放 Plugin 源码写入、Article 普通发布来源转换、独立 live 保全复验、恢复或旧候选重试。

Member session 固定迁移通过原 PLS 消费 `MemberSessionMigrationPreservation::before(PDO,key)`／`after(PDO,innerPrivateState)`。原 canonical 顺序、账本和 native admission 仍由宿主负责；before 仅接受 member revision 列／CHECK 与 session 表均未出现的原态，同 PDO 无外层事务捕获内层私态。原 ALTER＋CREATE 整份成功后、官方唯一 applied 前核固定 module／key／hash／readonly、两表 delta 与保全成功，不请求时钟。既有完整 target 或 partial 均不因此获得 replay、noop 或 adopt；失败沿原生账本保留真实部分效果及原恢复点，私态在本条结束释放。旧行保全依所属固定原生 ADD 默认值及 CREATE 语义，不另扫历史会员／session 行；真实 writer 排除、精确备份恢复和来源准入仍独立要求。

ImportExport 固定阶段由模块 `ImportExportMigrationPreservation::before(PDO,key)`／`after(PDO,innerPrivateState)` 与原 PLS 配对。它按固定源核必要字段、索引、FK、enforced CHECK 及父键；已有兼容表的完整元数据、计数器和可见触发器在窗口内保持，额外自定义元数据不由本迁移改写。缺失表只由原 CREATE IF NOT EXISTS 创建，完整原 SQL 后、唯一 applied 前核两表 delta 和本次新建空表数量。历史行保全依固定 SQL 无行 DML、已有表跳过和真实 writer 排除；不扫描业务行、建立影子 schema 或把元数据摘要称为历史私态重算。目标已有不等于采用，目标缺失也不越过原 canonical 顺序／账本／来源准入；原 failed／UNKNOWN、namespace、备份工具范围及恢复权限继续独立约束。

SMS 保全复用 Notification 的公开业务证明；包含完整原行及候选组的输入只保留在执行回调内，计划仅公开安全摘要和整体摘要。源码 Host 与发行 `update-database.php` 两个直接消费者共用相同 before／after 方法，在原同 PDO 捕获本条私有输入；SMS before 严格返回 `true` 请求既有官方执行器的数据库秒／UTC 毫秒时钟，包围整份不可变 SQL 的执行及结果排空，不拆分 SQL或把批次边界称为某条 UPDATE 的精确时间。官方唯一 applied 写入前须核同 id／hash／readonly 的 `sms_reservation_verified=true`，证明同一个可行整数秒、旧列保全及新增字段后态，只公开过滤后的安全结果，随后释放私有输入。该接线不放宽当前链恢复与写入准入；安全结果不能重建私有前态，两个消费者的 SMS 独立 verify／已完成 migrate 重放均保持 `APPLICATION_SCHEMA_SMS_LIVE_REVERIFY_REQUIRED`。

源码应用的受限备份使用原入口 `migrate:run backup --scope=source-development`，必须带相同真实 owner／thread／session／gate、受保护的当前 `--plan`、已登记的 `--backup-tool` 和新建的 `--backup-output` 私有目录。原生租约必须精确包含该工具的 `tooling-resource-id` 和该目录的 `output-dir`，以及完整的数据库读取声明；原产品锁、同 PDO schema advisory lock、源码／库身份和计划摘要持续核验。缺少当前计划、登记或权限时停止，不创建新库、重装客户端或借用其他任务的管理工具。

preflight 的 `backup_scope` 只从本次 pending 的固定 SQL 取表并加入应用账本，记录原表 DDL／自增摘要、可见入出 FK 和触发器摘要。九条全部 pending 时是原有十一张受影响表；子集只取实际并集。元数据中引用的其他表不会自动变成恢复写集。schema 元数据可见性受当前账号限制，原生备份客户端另核同库直接 SELECT／TRIGGER 可见权限；备份期间必须排除受影响 DDL，不能把一致性事务当作 DDL 隔离。

工具登记的 `transport` 必须为既有 `ssh-docker-exec`，包含真实 `ssh_command`、`ssh_alias`、`docker_command`、`container_name`、`mysql_command`、`mysqldump_command`、`timeout_command`、`digest_command`。`source_schema_backup` 精确登记 `database_resource_id`、`database`、允许的 `tables`、`mysql_version` 与 30–600 秒的 `timeout_seconds`，不使用 schema 前缀或任意 SQL。程序只在既有容器内读取其原 `MYSQL_ROOT_PASSWORD` 给固定客户端，不向本机传递密码。调用前后核对实际 server UUID、库名、主机、端口、MySQL 版本与两个客户端二进制摘要；远端命令有原生终止期限，失败保留私有输出和本地终态回执，不自动重试。

成功调用原生 `mysqldump` 后，0700 目录保留 0600 的完整选表 `database.sql`、`backup.json`、原生进程回执、`RESTORE.json` 与 `recovery.json`。原生客户端以 InnoDB 一致性事务、逐行输出、binary hex、完整建表及 triggers 导出选定表；不带 `--databases`／`--add-drop-database`、routines、events 或表空间，不复制存储目录。普通输出只含位置、内容摘要和表清单，不含业务行。原生逻辑备份与快照限制依据 [MySQL 8.4 mysqldump 文档](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)。

`RESTORE.json` 绑定原库、源码、对象和 payload，给出同一登记工具的 MySQL stdin 使用入口及前置条件；本入口不执行恢复。原生 SQL 包含选定表的 DROP／CREATE／数据与触发器语句，恢复须另有明确动作授权及正常原生 DDL／独占准入，先保留失败证据、核查入出 FK／触发器对范围外对象的影响，再由原 owner 在排除受影响写者的窗口内执行并核实际结构／数据。不能改成全库 restore，也不能只凭行数判定保全。`captured` 只表示真实备份输出已生成，恢复和资格仍为 `not_executed`／`not_run`；应用迁移仍受业务后态及现有单条来源字段迁移的限制。现有 recovery 引用必须匹配原生 capture 的 manifest、payload、成功进程回执及恢复说明，文件名或手写“已备份”声明不能替代这些输出。

认证 CHECK／IP 索引迁移没有行 DML，由 Identity 的公开业务证明检查必要 client 前态、原目标定义及精确目标后态。源码 Host 与发行直接消费者共用其 before／after 宿主方法，在同 PDO 的原 SQL 前后消费，仅 held callback 保留私有观察；官方 applied 前必须绑定同 id／hash／readonly 的 `target_metadata_verified=true`，只封存安全成功字段和目标元数据摘要。Auth 不请求 SQL 时钟，不扫描令牌或事件原行，不因无关自定义列或触发器拒绝；两条 DDL 之间仍保留实际失败与恢复边界。独立 verify 复用既有 Auth 目标元数据比较，不能把它解释成历史业务行保全。

File 四条 canonical adoption 目标若按既有证明选择执行原 SQL，官方执行器在标记 applied 前再次调用原 File 证明，必须观察到完整后态并返回 adopt；证明失败保留 failed 事实。此检查覆盖原证明的结构与数据不变量，逐原行保全、触发器影响及恢复范围仍须由实际迁移合同和维护窗口覆盖。

完整上游升级包的 manifest 固定 `upgrader.scope=source`，只携带源码预检与 Scaffold 工具，不携带运行实例的数据库或宿主升级驱动；迁移源码仍按 append-only 清单核完整性。运行实例只消费 APP 自身发行的 server 包。

升级包的 `compatibility.source` 使用合法 Semver 范围 `minimum_inclusive <= 当前源码版本 < maximum_exclusive`，上界必须等于目标版本。现有 `same-major` 包要求来源、下界和目标处于同一主版本，不能用于 4→5；明确跨主版本来源的包须标记 `major_policy=source-range`。跨主版本可能改变业务功能、API、依赖和迁移行为。原生源码 upgrade plan 在目标官方模块图之外保留客户 Module，并使用目标版本自身的 Module Kernel 合同检查其 `kernel_constraint`；同时扫描明确 `use PeanutAdmin\Kernel\...` 的类及直接静态方法调用。目标类/方法不存在或不再是公开静态方法时，plan 记录 Module、文件、行号并保持 blocked，不自动重写业务源码；动态调用、实例方法和业务语义仍由受影响测试/人工审阅负责。执行前审阅所选来源→目标的变化及 APP 自有业务、依赖、API 与迁移影响，备份并解决已知风险后再尝试。CLI 跨主版本 apply 必须传入 `--confirm-major-upgrade`；AI 在执行前须向人说明来源→目标、风险和计划并取得明确确认，已提前明确授权本次操作或明确范围的可按该授权执行，不重复确认，泛开发授权不足以确认跨主版本 apply。当前开发仅吸收源码，不包含生产数据库迁移或部署；新源码尚未发行，不能据此推断既有包可跨主版本或目标兼容性已获完整验证。

开发来源归入公开同版本时，原生 `release-adoption-plan|apply|verify|recover` 使用同一 Scaffold 计划、锁、账本及恢复目录。只有 APP 实际 generation tree 与固定公开候选 tree、inventory 均相等，原受管 baseline 逐文件完整，且公开锁、封存清单及文件摘要核对后，才只改应用来源元数据；客户定制和 APP 自有版本保留。候选 tree 与封存 scaffold tree 是不同角色，不因版本号或 inventory 单独相同就视为等价。源码确有变化时必须使用目标版本严格升版且来源范围覆盖当前兼容版本的真实升级包，继续走现有三方合并；范围不覆盖时返回 `SOURCE_RANGE_UNSUPPORTED`。安装包本身不冒充升级包。普通源码升级和同内容归位均更新清单中的当前来源字段；同计划恢复还原旧清单，不需要网络或发行 checkout。

受管文件重命名只能由目标 Scaffold manifest 的 `renames` 显式声明 `from`、`to` 与 `owner`，不按文件名、路径相似度或相同字节猜测。旧路径未被 APP 定制且目标路径空闲时可以在同一计划内自动迁移；旧路径已定制或目标路径已存在时必须进入显式 `preserve`／`replace` 决策，旧、目标两端都已定制时禁止歧义保留，只允许明确 `replace`。链式或循环重命名、归属不一致、目标已被 application manifest 声明占用，或计划后任一路径发生漂移时均 fail closed；apply、verify 与 recover 同时绑定重命名前后的两个路径及新 baseline。

已安装工具核 inventory、文件 SHA-256/权限、来源/目标身份及实际受管内容。维护 PHP 使用 `PEANUT_UPGRADE_PHP` 指定已登记、满足 PHP 8.3 的绝对入口。目标 vendor 按原生锁在同一计划的 workspace 准备，锁和完整性符合时复用；不构建应用镜像。文件、依赖、数据库回执是从属证据，不另推进升级生命周期。未知 staging 或缺绑定恢复材料拒绝追认。

官方模块随维护者明确选择和审阅的固定 scaffold 来源整体吸收，绑定真实源码 commit/tree、release manifest、逐文件摘要与 canonical 模块 manifest/lock；这些摘要证明选定输入的内容，不认证发布者，不要求签名私钥。预检以已安装且通过生产 canonical 核验的官方图为起点，投影目标官方模块的完整控制器、服务、迁移及客户端贡献，先以原生 `PluginArtifactWriter::checkLock()` 核固定模板原文的完整 canonical 图；产品名称等参数渲染改变源文件字节后，按原生 make → lock 重建 APP 派生 manifest 和含原客户模块的锁并核依赖。计划同时固定原文图、渲染参数、APP 派生图与派生字节，应用及新基线使用同一派生字节，不要求渲染后摘要等于模板原文摘要。官方包与模块的变化必须提升各自版本，同版本内容不得变化；模块成员、根路径及官方归属不能借升级转给客户包。非官方 bundled 包只有在当前应用受管清单、固定 from/target 声明中的包/成员/根路径与来源合同一致，完整包文件均为 scaffold owner 且当前内容、权限、受管基线摘要全部吻合时，才能按上游整体吸收；命名空间或 bundled 标签不能替代归属证明。客户模块、app-owned 重叠和未登记的额外官方根文件受到保护，冲突不得靠单文件填入或手改摘要解决。应用/核验检查目标完整插件图，恢复检查原图；`module:adopt-package` 保持私有模块源码入口，不用于接收 official.*。

完整 APP 的正式归档复用 `scripts/package-release.sh /absolute/output --application-root=/absolute/app`。输入为 APP 自己干净、已提交的 Git 源码和原生锁；PHP/Web Core 与脚手架使用固定、可取得的同一上游发行版，APP 名称、包标识、Edition 和独立版本保留自己的身份。隔离构建完成 Web、Platform、PC SPA、UniApp H5，分别装入 `server/public/admin`、`platform`、`pc`、`mobile`。归档保留四端源码、业务模块、声明与锁、安装/官方迁移入口、根来源清单和升级基线；不装入 `.git`、vendor、node_modules、缓存、真实数据、账号密钥、安装完成状态或机器配置。浏览器 bundle 内的依赖代码随静态产物保留。目标 Git、remote 和历史在部署现场单独保全；归档名称与外部摘要同时记录真实 APP/Edition/APP 版和上游版/来源，不能用上游版代替 APP 版。

完整 APP 同次组装还复用原生 `peanut.server-release.v1`，按实际后端字节生成 `server/.peanut/release-identity.json`、后端插件锁、版本、APP 自有资源登记及维护工具。根 `.peanut/application-release.json` 绑定整个完整包。完整模式不执行 server-only 的源码裁剪，不另建运行投影；Compose、entrypoint、owner 配置、安装向导与 HTTP 部署准入直接消费既有后端发行身份。生成的后端元数据不成为 APP 受管源码或新的升级基线，精确 Git ignore/源码快照排除使下一次打包从当前已提交源码重新生成，拒绝目标路径冲突。修改后端源码须重新组装并通过原生生命周期采用，不能手改身份或摘要追认。

后端部署更新复用 `server/docker/scripts/update.sh plan|apply|verify|recover`。`--instance-server=/absolute/instance/server` 显式选择原实例；协调器和维护工具仍来自该实例已安装的 APP 制品，Compose、数据库和私有配置也取原实例。该入口消费 `--server-only` 生成的后端更新归档，不把仅后端更新包当作完整 APP 交付。目标归档只按外部可信 SHA-256 校验后消费。plan 将已安装维护工具固化到 workspace，每次执行前由协调器复核工具清单和哈希。归档文件按路径排序后严格比较路径、内容 SHA-256 和权限；归档遍历顺序不作为内容身份。

Compose 使用已准备的固定 PHP-FPM/Nginx 镜像和正常 bridge 网络、服务 DNS；普通部署不使用 ThinkPHP 开发服务或 Vite。应用代码与 vendor 位于宿主 `server/`，公共上传、runtime、私有上传及安装身份随该目录持久保留；只有 bundled 数据库使用 `server/docker/mysql/`。运行配置取 `server/docker/.env`，应用配置取 `server/.env`，两者不互换。`start.sh` 和 `update.sh` 不构建应用镜像。APP 独立发行来源与 Peanut 上游来源分别绑定。静态入口、API、安装页面及其错误由现有 Nginx 明确路由；HTTPS 使用已登记域名及外部私钥/CA，不能关闭校验或依赖固定容器 IP、host 网络或 SSH 隧道替代登记路线。PC 使用同次构建的 SPA 静态产物，SSR 后置。

开发运行投影以 `server/.peanut/development-runtime.json` 识别，保留自身的来源、文件和依赖校验，不创建 `release-identity.json`。数据库入口复用该身份读取器后使用投影的 `server/resources/project-resources.json`，已配置实例可使用 `server/private/resources/project-resources.json`；两者都必须属于该 APP。与 server-only 发行实例一样，开发运行投影拒绝外部 `PEANUT_RESOURCE_REGISTRY` 和维护者登记。未绑定运行投影的源码工程仍按原源码入口读取根资源登记。

开发运行投影也使用同一首次安装向导。配置、预检和执行直接消费 ApplicationSourceIdentity 的原生严格身份；只接受 development 环境目标，HTTP 继续使用已验证的部署准入。未安装投影通过既有启动准入发布安装能力；已有完成锁时仍先验证安装健康、原始回执和当前部署链。首装基线以 development-runtime 记录投影身份和原 APP manifest 摘要，不将其标作 server release；源码和依赖身份变化仍须经正常生命周期采用。投影由 package-release-files.py 的 development-runtime 动作从真实生成 APP、原生锁定依赖及完整来源清单产生，不手写身份，也不等同发行包。

永久 APP 的完整安装等待固定正式 Core 包可取得以及其发行 Gate。普通源码修复和完整包组装准备不以公开发包为前置；发行前确需运行固定候选时，仅在正常登记的独立候选目录使用同一原生完整归档、PHP-FPM/Nginx 和静态路径。内部候选身份保持不可正式发行，不用临时运行投影安装替代这条确认链，也不冒充永久 APP 验收。候选资源、库、写集及次数按原授权分别登记，不能借换目录、Edition 或新版本重置失败预算。

既有 `--internal-candidate` 完整包保留原 `kind=internal-candidate`、`scope`、`formal_release_eligible=false`、marker、来源及七个 Core 固定锁的合同，同次生成的后端身份带入这些已有字段及原 marker 字节。完整候选核验继续逐文件验证原 sealed source、四端静态、原生后端元数据和插件派生，不接受任意额外文件。后端读取器核对 marker、真实程序、来源和 Core 锁；首次配置仅提供 development 部署目标，正式后端升级入口拒绝候选作为目标。此处不增加新身份协议，也不把候选资格改为正式。

首次配置的 `database_source` 明确选择 `bundled` 或 `registered`。网页的后者只从 APP 自有公共资源登记选择唯一数据库和 `host`／`container` 消费端点；部署 owner 则在下面的首次配置输入中明确声明自己的单库登记。两者复用同一环境、Edition、库名和端点校验；已有普通账号与密码仅写入私有 0600 配置。配置发布不连接数据库、不建库、不创建或修改账号、不授予权限；连接与权限仍由安装向导实际 ThinkORM/PDO 的 `database-check` 判断。只有 `bundled` 才调用内置数据库 provision。APP 不读取维护者的私有 Project 登记作为运行依赖。普通 APP 可在封包前维护根 `resources/project-resources.json`；已封包实例的公共登记属于程序身份，不得改写它或预造私有配置来追加部署资源。

外部专库的新实例以 `python3 server/docker/scripts/configure-runtime.py --php-image=<prepared-immutable-image> --database-source=registered` 准备 Docker 配置；不写本地 MySQL image/port/root 密码。首次向导尚未发布 `server/.env` 时，正常 `start.sh -f compose.registered-database.yaml` 显式选本包的 registered overlay；发布后复用 `PEANUT_DATABASE_SOURCE=registered` 自动选择。两种情况均只启动 PHP/Nginx，不创建 MySQL 数据目录，不受 `COMPOSE_PROFILES` 意外选中内置库的影响。registered 模式不能传本地 `--mysql-host-port`；bundled 模式仍校验固定 MySQL 镜像和 root 凭据。完整源码中普通、空的 `.gitkeep`/`.gitignore` 目录占位可保留；已有数据、密钥、链接、配置或安装状态不得被初始化入口覆盖。每个 APP 使用自己明确登记的专库，不能通过共享首装 schema 或替换 `.env` 安装另一 Edition。

外部已有数据库使用后端配置的 database_source=registered。原生启动器从实例 .env 自动选择随产品交付的 compose.registered-database.yaml，只启动 PHP 与 Nginx；不启动、拉取或初始化内置 MySQL，也不调用其 provision。普通账号、端点和数据库仍须来自当前 APP 自有登记并经真实 PDO 复查。bundled 使用原来的默认 Compose、MySQL 健康依赖及 provision 路径。

若希望在尚未配置 .env 时通过网页完成外部数据库配置，可在已完成原生镜像与实例编排配置后运行 server/docker/scripts/start.sh -f /absolute/app/server/docker/compose.registered-database.yaml；这里必须是当前 APP 自带的官方文件，不是私有复制的 override。安装 bootstrap 保留原有 owner、Token、不可变程序、只读 FPM 与 HTTP 准入；开发运行投影使用 development 环境，发行包保持原环境。网页真实提交 registered 配置后仍由既有 owner 发布，再切入实际数据库复查和安装。配置前如需正常关闭，stop.sh 传相同 -f 官方文件参数；配置后启动和停止会从 .env 自动识别。即使额外启用 Compose profile，外部数据库模式的原生 up/stop 也只选择 PHP/Nginx。

启动前必须由实际 Docker Compose 成功解析官方合成配置；不支持 !reset 等原生能力或服务依赖不符时停止该启动路径，不改成私有备用编排。上述行为的源码交付不代表实际安装、登录、404 或删锁保护已经验收。

尚未启动 PHP 的主机首次配置，由该实例真实 owner 在程序完整性准入、初始流量关闭和实例原生锁下执行 `php server/docker/scripts/update-plan.php configure-startup --instance-server=/absolute/app/server --configuration-input=/absolute/private/input.json`。输入必须是当前 owner 持有的单链接普通 0600 文件，JSON 包含 `setup_token` 和 `configuration`：前者为本次真实 Setup Token，后者与 `/installapi/configure` 使用同一字段合同。owner 的 `registered` 模式还必须包含 `resource_registry`，`bundled` 模式禁止该字段。该登记仅允许 `schema_version: 1`、当前 APP slug 的 `project_id`、`authority: {"role":"application"}`、`resources: {"databases":[单个数据库]}`；不接受登记路径、维护者登记、其他资源或凭据字段。它不由 HTTP 输入提供，也不更改候选或正式发行身份。

单库登记必需字符串字段为 `stable_resource_id`、`purpose`、`owner`、`version`、`database`、`schema`、`namespace`、`data_source`、`freshness_requirement`、`health_check`；另需 `service_type: "mysql"`、`fallback: "none"`、`application_runtime: true`、`environments` 和 `deployment_modes` 字符串列表。至少声明一个 `upstream_endpoint` 或 `container_endpoint`，每个仅含 `endpoint_id`、`host`、整数 `port` 和分别为 `["host"]` 或 `["container"]` 的 `consumers`。数据库 ID 必须与 `configuration.database_resource_id` 相同，所选 consumer、目标环境、Edition、库名仍通过现有登记解析器校验。DB 普通账号/密码仅放在 `configuration.database_user`／`database_password`，不放入资源登记；最终私有登记自动使用实例 `.env` 凭据引用。部署者须自行核准真实目标、权限和备份，此声明不证明数据库健康、为空或已获操作授权。

原生发布器一次生成 `.env`、实例私有资源登记和配置记录，不改 APP 来源身份、安装锁或数据库；Setup Token 与数据库密码不进入输出。已存在配置、安装锚点或待发布输入时拒绝覆盖。输入由 owner 在正常生命周期中保护及回收，不放入版本库；不得手写配置记录冒充完成。网页继续只将已有公共登记的选择暂存为私有待发布记录，绑定 APP 身份、实际 Setup Token 和所选登记摘要，仍由正常 owner 发布，登记漂移时停止。owner 单库登记不会成为第二套全局资源事实源，也不会为网页提供注册或改绑权限。

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
