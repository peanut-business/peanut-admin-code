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

客户 APP 制包绑定干净的已提交 Git commit/tree，并按 Git blob 校验当前发行源码；合法客户修改和新增文件不要求重写 `.peanut/application-manifest.json` 的上游采用摘要。该清单的归属及原受管 baseline 路径、摘要仍需严格校验并保持不变，供后续冲突检测使用。初始 generated-template 没有独立 APP Git 身份，仍须逐文件符合生成清单，不接受生成后修改。

维护者内部完整 APP 使用 `build-internal-edition-candidate --source-commit=<固定提交> --edition=multi-tenant --profile=full --workflow=full --core-inputs=<冻结回执绝对路径> --output=<不存在的绝对目录>`。edition、业务 profile 与 workflow 分别传入原生生成器；当前内部完整候选只接受 full/full，固定选择 Admin、Platform、PC SPA、UniApp H5。PHP ZIP 与六个 Web TGZ 原字节全部携带，绑定冻结回执、Git 来源、SHA-256；Web 原生锁使用从同一 TGZ 计算的 SHA-512 SRI。仅投影四端真实声明及 Core peer 闭包，不为 testing 等未消费包创造消费者或宣称运行覆盖。原生 Composer/pnpm/npm 生成锁后，非 Core 包的固定版本与来源身份必须保持不变；随后复用 `package-release.sh --internal-candidate` 在隔离目录 frozen install/ci、核实际安装位置及归档内容，再构建现有四端并组装完整 APP。安装阶段禁用生命周期脚本，Composer 使用 no-scripts；显式构建入口仍执行正常类型检查和构建，不运行治理测试、PHP 安装或 SQL。

内部投影保留原 `.peanut/application-manifest.json` 与 baseline 的字节和原摘要，以 `.peanut/internal-candidate.json` 单独记录依赖声明、锁和七包的投影树及新摘要；不把改写后的内容称为原始基线。封存源码校验与含四端浏览器产物的 APP release identity 分别核验，最终归档保留二开源码、原基线和全部七包，不携带 vendor/node_modules。回执只证明本次实际完成的输入、安装来源和四端构建；内部版本不等于公共发包，`formal_release.eligible=false`，不证明安装、升级或生产运行资格。源码实现与独立审查不代替构建；完整构建须在最终 dev 输入固定后单独授权，资源部署和测试仍遵守各自 Gate。

内部完整候选成功后正常清理临时现场；失败时 PHP builder 保留本次 0700 私有临时根，嵌套 `package-release.sh --internal-candidate` 同时保留实际 snapshot/build、原生 PM 锁、已安装状态及安装来源证明的实际输出（若已产生），不在内层 EXIT trap 提前删除。两层输出保留路径、调用 UID/PID 与清理责任；上下文文件使用 0600，不转储环境或凭据，不把失败材料装入交付归档，也不把部分证明称为通过。生命周期 owner 是该次真实 Build Session owner，诊断完成后按明确范围清理；超时或强制终止未执行 finally/trap 时，先对账同一 Job/子进程和已登记 staging 根，不启动重跑来重新取得现场。调用者的持久 Job 日志与输入回执位于临时清理范围外。

该 builder 的无依赖源码快照通过原生 `ApplicationCreator::apiGeneratorPath` 使用调用工程已准备的 `scripts/generate-api-contracts.php`。原生生成器必须先核工具与快照的 generator、Web manifest/lock、toolchain 字节相等，并核已安装 pnpm 锁、openapi-typescript 精确版本和 CLI 位置；APP 输入及输出仍取快照投影，不复制 node_modules、不临时下载，也不放宽身份检查。内部制包回执另记实际 generator 源码摘要、依赖锁摘要和经原生核对的工具版本；这不代替候选随后自身的隔离安装。

维护应用源码可直接在当前工程开发。内部 `create-app` 按干净源码的实际 commit、tree、inventory 和形态生成独立应用，记录 `.peanut/application-manifest.json` 及受管基线。`generation_source` 记录仓库、请求的 ref、`development`／`prerelease`／`stable` 渠道和正式发行版本（开发来源为 `null`）；`template.version` 是升级兼容与 baseline 版本，不能替代实际源码身份。开发来源直接使用当前模板，不能借同号历史发行清单把自己的来源标成已发行。公开来源须核固定发行锁与清单，公开候选 commit/tree 和清单封存的 scaffold commit/tree 分别保留。生成应用的根 AGENTS.md 来自专用公开模板，不复制维护者入口。生成器不复制 Git 历史、私有维护资料、秘密或已安装依赖，也不改变 Core 包和许可证身份。创建工程、首次安装数据库、创建租户是三件事。取得完整产品发行包的使用者按包内说明装依赖和安装，不必先运行维护者生成器。

业务 CRUD 预览另从 `server/app/adminapi/services/generator/GeneratorRenderService.php::render` 进入，目标是已登记模块的 admin-web 贡献，不是创建整个应用。其页面使用 Web 已声明的 Element Plus，表格行插槽、独立分页、确认事件和权限键遵循实际组件合同；列表与提交状态复用公开 `useAsyncList/useAsyncAction`。输入未声明软删除时不生成回收动作，已声明时保留普通/回收接口及主键类型；失败结果不能提示操作成功。预览返回 create/merge 与原文件摘要，不会自动应用合并或建表。源码仓可复用 `server/tests/fixtures/generator-element-plus-inputs.php` 的四组定义分别调用该入口；PHP 模板回归、实际前端消费者检查和完整产品安装是不同验证范围。

首次安装使用 `server/database/install.php`。开发 APP 吸收上游使用已安装 `scripts/upgrade --scope=source` 的 plan、apply、verify、recover，固定 `--instance-root`、已认证完整包 `--package` 和绝对 `--plan`；沿用 Scaffold 归属、冲突与文件恢复，只修改开发源码，不操作运行实例。APP 从自身固定提交发行 server 包后，宿主已安装 `server/docker/scripts/update.sh` 映射同一协调器的 `--scope=server`，以 APP 自有归档、外部可信摘要及 workspace 驱动唯一 product plan/state。目标未验代码不作为维护入口。

应用和模块的版本化 SQL 使用锁定的 `topthink/think-migration` 官方执行器及真实 `Migrator`，通过其 MySQL Adapter 扩展读取、更新原有 `pa_schema_migration`／`pa_module_migration` 唯一账本；不复制旧历史到另一张 `migrations` 表。Peanut 生命周期保留原 SQL 顺序、目标发行选择、checksum、applying／failed、repair／adopt、业务 preflight、安装身份与恢复；执行成功仅由官方 `migrated()` 回调写入。完整 text key 是持久化身份，官方整数版本使用固定 SHA-256 前 60 位加一的投影，先检查源文件与该 scope 历史 key 并集，碰撞即拒绝，不按数组顺序重编号。这个整数不表示日期或回退目标。SQL 保留自身事务边界并排空多结果集，不在不可逆 DDL 外再套事务。

直接 CLI 的 `migrate:run preflight|apply|verify` 使用与 `schema:application` 相同的参数、实例上下文和准入；`migrate:status` 委托该入口的 preflight，不创建迁移表，输出真实 text key。源码开发需要原有 `--scope=source-development` 和实际 owner／thread／session／gate、维护及恢复证据；发行升级仍走安装实例的正式升级入口。官方通用的目录扫描执行、`--date`、rollback、breakpoint 和 seed 命令不注册，不能绕过生命周期保护。模块仍通过 `plugin:install`／`plugin:upgrade`／受控 `plugin:reconcile` 进入同一官方执行器，不由应用 schema 命令接管其业务语义。

源码开发 preflight 按真实 pending 顺序列出逐条来源、前置证明和后继依赖；缺少业务终态保全或完整恢复覆盖时，计划仍不授予执行权限。原仅覆盖模块迁移来源字段的两账本恢复合同不能用于包含菜单、权限、通知或认证数据变化的链。已迁移归属的 File 应用历史，只在原不可变 SQL、现行 canonical adoption 声明和原账本 key／checksum／applied 一致时识别为历史保留；不删除原行、不把它记入模块账本、不以历史记录代替当前 File 数据证明。未知额外历史继续拒绝。

源码阶段账本按原有序链核对已完成前缀、当前 applying 与尚未执行项，逐条使用 SQL 真实发行身份；旧历史的完整原行保持不变。源码 apply 只接受现有九条固定 SQL 中由原生账本算出的有序 pending 和精确摘要；维护窗口、原生完整备份、恢复引用、baseline 与完整表并集必须一致，旧两账本证据不能扩大使用。维护证据必须证明 HTTP、worker 和其他受影响 writer 的实际排除，且窗口开始不晚于真实备份开始；租约或 JSON 的声明本身不证明外部进程已停止。完整原结构／数据 payload、原生终态 rc0、manifest 与 RESTORE 摘要仍逐次核对，不自动恢复。

备份范围复用固定表映射，不由 SQL 解析或调用者清单扩展。首次 applying 前核对完整原 scope；后继不再拿链初元数据否定前序合法 DDL，而按当前真实前态、旧账本和有序完成前缀逐条检查。固定 DML（含唯一应用账本）按实际事件、更新列及派生更新列检查触发器和入向级联；未知写入在对应阶段前拒绝，已完成前缀和失败证据保留。纯 DDL 的业务表触发器不会因存在而一概阻塞。该维护观察要求当前 PDO 具备直接全局 SELECT 和本库 TRIGGER 可见性且无部分撤权，以免遗漏跨库入向 FK；不自动授予权限。成功结果仅在原生 applied 后读回真实阶段账本并绑定原 source／DB／window、备份引用和安全 after 结果；它是当次窗口证据，不是当前状态健康核对、跨进程私态重算或完整资格通过。

菜单三条固定应用 SQL 的业务事实由 Identity 的 `MenuSchemaMigrationProof::before(PDO,migration)`／`after(PDO,privateBefore)` 提供。preflight 只公开 `safe_summary`；当前 pending 前驱未完成时列为 deferred，不构造影子菜单、解析 UPDATE 或预测 UUID／自增分配。源码维护 Host 和发行 `update-database.php` 均在原同 PDO 的执行回调内重新取得有限私有 facts，原 SQL 排空后、官方唯一 applied 写入前核 `business_postconditions_verified=true`，随后释放 private_state；它不进入计划、结果或日志。后继步骤以当前真实状态重新捕获，不用链初摘要否定后续合法变更。定制／歧义目录按原 SQL 的采用、跳过和冲突分支处理，不增加整个迁移的拒绝 Gate。

权限三条固定应用 SQL 由 Identity 的 `PermissionSchemaMigrationProof::before(PDO,migration)`／`after(PDO,privateBefore,executionBounds)` 证明。preflight 只公开 `safe_summary`，部门迁移尚有 pending 菜单结构前驱时明确 deferred；Host 不再投影权限 CHECK、提取 SQL 选择器或预测最终行。两个原生直接消费者在同 PDO 的 before 回调重新捕获私有事实，readiness／department 严格返回 `true` 请求原不可变 SQL 整批执行两侧的原生时钟，SQL 排空后、唯一 applied 写入前交给 Identity 校验真实 UTC 毫秒区间及业务保全。platform-ops 不请求时钟，时间字段仍须保持；私有事实只驻留当前阶段回调，后继重新捕获，不进入计划、结果或日志。

菜单／权限回调业务断言不替代真实私有备份及完整结构／数据恢复。断言失败沿官方执行器保留 failed／已完成阶段与原恢复点，不伪回滚 DDL、不自动重放或伪造 applied。安全结果仅记录 id／hash／readonly、具体成功字段及安全计数／时钟范围标识，不能重建原私有前态；涉及这些阶段的独立 verify／已完成 migrate 重放当前分别拒绝 `APPLICATION_SCHEMA_MENU_LIVE_REVERIFY_REQUIRED`／`APPLICATION_SCHEMA_PERMISSION_LIVE_REVERIFY_REQUIRED`，不以旧成功摘要冒充实时保全复验。固定 provenance 即使位于多条链中也须为 before_additions；原源码身份、原生权限、完整恢复覆盖与发布资格保持独立约束。

`plugin:upgrade`／`plugin:install` 的 `--scope=source-development --dry-run` 为明确选择的单个插件生成原生计划，同时必须提供实际 `--owner`／`--thread`／`--session`／`--gate`。入口在初始化数据库前核对干净源码、原安装标记和当前原生资源租约，并在真实产品文件锁、应用迁移 advisory lock 与插件原生锁内读取；缺少真实来源字段迁移时只列为应用前置项。宿主使用当前插件命令上下文，不冒充应用 schema 或发行升级，不授予安装、采用、catalog 或迁移写入能力；结果固定 `execution_admitted=false`，恢复覆盖仍单独要求。

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

server-only Compose 实例使用 `server/docker/scripts/update.sh plan|apply|verify|recover`。`--instance-server=/absolute/instance/server` 显式选择原实例；协调器和维护工具仍来自该实例已安装的 APP 制品，Compose、数据库和私有配置也取原实例。目标归档只按外部可信 SHA-256 校验后消费。plan 将已安装维护工具固化到 workspace，每次执行前由协调器复核工具清单和哈希。归档文件按路径排序后严格比较路径、内容 SHA-256 和权限；归档遍历顺序不作为内容身份。

该 Compose 的 PHP/Nginx/MySQL 只提供固定运行环境，应用代码与 vendor 位于宿主 `server/`，公共上传、runtime、私有上传及安装身份随该目录持久保留；数据库数据使用 `server/docker/mysql/` 目录挂载。运行配置取 `server/docker/.env`，应用配置取 `server/.env`，两者不互换。`start.sh` 和 `update.sh` 不构建应用镜像；APP 独立发行来源与 Peanut 上游来源分别绑定。完整开发源码包保留 APP 二开和源码吸收合同，`package-release.sh --server-only` 生成实例部署包；PC SPA 浏览器产物来自同次发行，SSR 本批后置。

开发运行投影以 `server/.peanut/development-runtime.json` 识别，保留自身的来源、文件和依赖校验，不创建 `release-identity.json`。数据库入口复用该身份读取器后使用投影的 `server/resources/project-resources.json`，已配置实例可使用 `server/private/resources/project-resources.json`；两者都必须属于该 APP。与 server-only 发行实例一样，开发运行投影拒绝外部 `PEANUT_RESOURCE_REGISTRY` 和维护者登记。未绑定运行投影的源码工程仍按原源码入口读取根资源登记。

开发运行投影也使用同一首次安装向导。配置、预检和执行直接消费 ApplicationSourceIdentity 的原生严格身份；只接受 development 环境目标，HTTP 继续使用已验证的部署准入。未安装投影通过既有启动准入发布安装能力；已有完成锁时仍先验证安装健康、原始回执和当前部署链。首装基线以 development-runtime 记录投影身份和原 APP manifest 摘要，不将其标作 server release；源码和依赖身份变化仍须经正常生命周期采用。投影由 package-release-files.py 的 development-runtime 动作从真实生成 APP、原生锁定依赖及完整来源清单产生，不手写身份，也不等同发行包。

明确登记的临时多租户源码首次部署消费真实 `generated-template` 制品。原生生成器的 APP 自有公共资源登记尚未分配数据库时，部署允许其 `resources.databases=[]`；宿主数据库、Compose、镜像和端口仍须由维护者资源登记精确绑定，首次启动后显式选择原生安装配置并生成实例私有资源登记，不从空模板推测资源。制品已有数据库投影时仍核对所选资源唯一身份、库名和完整容器端点；永久 APP 自有 Release 始终要求该投影。此范围仅用于带固定来源、到期时间与替换 owner 的多租户首次安装，不用于自动 APP 升级，也不覆盖已有实例目录。

首次配置的 `database_source` 明确选择 `bundled` 或 `registered`。后者只从 APP 自有公共资源登记选择唯一数据库和 `host`／`container` 消费端点，环境、Edition、库名和地址必须匹配登记；已有普通账号与密码仅写入私有 0600 配置。配置发布不连接数据库、不建库、不创建或修改账号、不授予权限；连接与权限仍由安装向导实际 ThinkORM/PDO 的 `database-check` 判断。只有 `bundled` 才调用内置数据库 provision。APP 不读取维护者的私有 Project 登记作为运行依赖；维护者需先通过原生 `application-registry` 投影准备 APP 自有来源。

外部已有数据库使用后端配置的 database_source=registered。原生启动器从实例 .env 自动选择随产品交付的 compose.registered-database.yaml，只启动 PHP 与 Nginx；不启动、拉取或初始化内置 MySQL，也不调用其 provision。普通账号、端点和数据库仍须来自当前 APP 自有登记并经真实 PDO 复查。bundled 使用原来的默认 Compose、MySQL 健康依赖及 provision 路径。

若希望在尚未配置 .env 时通过网页完成外部数据库配置，可在已完成原生镜像与实例编排配置后运行 server/docker/scripts/start.sh -f /absolute/app/server/docker/compose.registered-database.yaml；这里必须是当前 APP 自带的官方文件，不是私有复制的 override。安装 bootstrap 保留原有 owner、Token、不可变程序、只读 FPM 与 HTTP 准入；开发运行投影使用 development 环境，发行包保持原环境。网页真实提交 registered 配置后仍由既有 owner 发布，再切入实际数据库复查和安装。配置前如需正常关闭，stop.sh 传相同 -f 官方文件参数；配置后启动和停止会从 .env 自动识别。即使额外启用 Compose profile，外部数据库模式的原生 up/stop 也只选择 PHP/Nginx。

启动前必须由实际 Docker Compose 成功解析官方合成配置；不支持 !reset 等原生能力或服务依赖不符时停止该启动路径，不改成私有备用编排。上述行为的源码交付不代表实际安装、登录、404 或删锁保护已经验收。

尚未启动 PHP 的主机首次配置，由该实例真实 owner 在程序完整性准入、初始流量关闭和实例原生锁下执行 `php server/docker/scripts/update-plan.php configure-startup --instance-server=/absolute/app/server --configuration-input=/absolute/private/input.json`。输入必须是当前 owner 持有的单链接普通 0600 文件，JSON 只含 `setup_token` 和 `configuration`：前者为本次真实 Setup Token，后者与 `/installapi/configure` 使用同一字段合同。原生发布器一次生成 `.env`、实例私有资源登记和配置记录，不改 APP 来源身份、安装锁或数据库；Setup Token 与数据库密码不进入输出。已存在配置、安装锚点或待发布输入时拒绝覆盖。输入由 owner 在正常生命周期中保护及回收，不放入版本库；不得手写配置记录冒充完成。网页只将输入暂存为私有待发布记录，绑定 APP 身份、实际 Setup Token 和所选登记摘要，仍由正常 owner 发布，登记漂移时停止。

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
