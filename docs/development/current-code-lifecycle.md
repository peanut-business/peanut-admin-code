# 当前代码导读

路径相对本应用源码根；本文解释实际接线，不是第二套规范或完整产品验收。先读[开发规范](standard.md)和[应用约定](application-conventions.md)。调用顺序以本次原生锁及实际加载源码为准；执行者分别记录源码、依赖、命令与验证范围，不能从本文推断某项运行验证已通过。

### 既有实例显式采用维护工具

旧实例的既有计划和 `trusted` 工具保持冻结。需要修复升级协调器时，从已审阅、固定提交的工具源码根生成独立维护工具目录；它不是产品包 B，不修改 B 的身份或摘要。源码须 clean，`server/vendor` 须由该源码 Composer lock 安装为 production 依赖；目录只包含协调器的唯一工具闭包、入口、lock 和锁定 vendor，不包含运行配置或凭据。

```sh
"$PHP" /fixed/source/scripts/upgrade tooling-pack --source-root=/fixed/source --output=/new/maintenance-tools
```

制包返回 manifest SHA-256、source commit/tree。传输前后由操作者在执行任何包内入口前独立核对该固定 manifest 摘要及其中每个文件的摘要/模式、无额外文件或链接；不能仅执行一个尚未核实的包内校验器建立信任。维护包来源与本次产品 B 的 source commit 分别登记；迁移语义修复仍须通过正常脚手架升级进入实际 B。

```sh
"$PHP" /verified/maintenance-tools/scripts/upgrade tooling-observe --instance-root=/installed/app
"$PHP" /verified/maintenance-tools/scripts/upgrade tooling-adopt --instance-root=/installed/app \
  --tooling-root=/verified/maintenance-tools --expected-tooling-sha256=MANIFEST_SHA256 \
  --expected-before-sha256=OBSERVED_BEFORE_SHA256 --workspace=/new/tooling-adoption
```

`observe` 只读安装身份、release identity、保护配置、旧协调器和原计划状态的摘要。`adopt` 使用与正常升级相同的产品锁和原生 update 锁，再核对精确前态；活动或需恢复的操作拒绝采用。它将固定闭包复制到独立 workspace、备份原工具并持久化 `adoption.json`，最后原子切换实例的 `.peanut/upgrades/tooling-active.json` 指针；不覆盖 APP 或原工具、不修改旧计划、trusted、journal、数据库或卷。收据与 active 指针由当前执行 owner 持有、模式为 0600。中断在收据持久化之后且 active 指针切换之前，可用相同 pins 重入；更早的非空半成品 workspace 拒绝使用，由 owner 查明后处理。

新计划通过采用后的同一公开 `update.sh` 入口创建，显式指定收据与原实例；使用新的空产品升级 workspace。每次入口在 Composer 加载前核实完整闭包和 active 收据，并在取得产品锁后重核；新计划绑定收据摘要。原计划不允许换工具重绑。

```sh
PEANUT_UPGRADE_PHP="$PHP" /new/tooling-adoption/tools/server/docker/scripts/update.sh plan \
  --instance-server=/installed/app/server --tooling-receipt=/new/tooling-adoption/adoption.json \
  --archive=/fixed/server.tar.gz --expected-sha256=ARCHIVE_SHA256 --workspace=/new/product-update
```

后续 `apply|verify|recover` 使用同一 wrapper、instance、收据和产品 workspace。业务 apply 的备份、写者暂停、迁移、恢复和人工 Gate 沿用原状态机。若尚未改变业务或原计划状态，可以运行 `tooling-recover --instance-root=... --receipt=...` 撤销工具指针；已经改变业务时必须先走产品恢复，工具恢复不能代替数据库/文件恢复。原安装工具始终保留。

固定镜像环境准备仅支持 Dockerfile、PHP/MySQL/cron 镜像配置和辅助 recipe 不变、无 active collaboration 的既有 Compose 演进。旧/新 Compose 都在原实例 project-directory、同一只读保护 env 下解析；服务集合、固定本地镜像、挂载、网络、端口、数据库模式和其余配置必须完全一致，只允许 PHP stop signal 从缺省/SIGQUIT 改为 SIGTERM。目标 nginx 配置用原固定 nginx 镜像执行原生 `nginx -t`。不 build/pull 镜像、不改保护 env、不新建或清空数据库/卷。prepare 记录旧/新 recipe、保护 env、镜像、Compose 摘要和计划/输入绑定；后续步骤拒绝第三种 recipe，原 `runtime_environment_changed` 要求保留。未知变化拒绝，不能删 flag 或用旧环境重新打包冒充通过。

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

本节解释可复用产品入口。普通APP默认生成server-only部署包，完整开发源码由APP自己的Git管理；制包、受管文件与回退见[APP发行](../public/application-release.md)。源码维护专用的固定迁移和冻结现场恢复协议位于源码仓`docs/maintenance/`，不随普通APP交付，不作为日常开发前置。旧发行、baseline和冻结工具保持原版本。

开发联调明确选择本地 PHP/Web Core：PHP 使用真实 Composer path link，普通 PHP 源码修改直接由开发消费者读取，不以逐次提交、固定包、发布或重新封装 development-runtime 为前提；依赖声明、路径或配置合同变化才刷新相应原生依赖/服务登记。Web Core 保留 dist 导出，由显式本地 Core 模式的标准开发入口托管原生 TypeScript watch。开发链接与候选、正式 APP 的固定依赖分别使用，不把维护者目录或活链接放进交付 APP。

客户 APP 制包绑定干净的已提交 Git commit/tree，并按 Git blob 校验当前发行源码；合法客户修改和新增文件不要求重写 `.peanut/application-manifest.json` 的上游采用摘要。该清单的归属及原受管 baseline 路径、摘要仍需严格校验并保持不变，供后续冲突检测使用。初始 generated-template 没有独立 APP Git 身份，仍须逐文件符合生成清单，不接受生成后修改。

维护应用源码可直接在当前工程开发。内部 `create-app` 按干净源码的实际 commit、tree、inventory 和形态生成独立应用，记录 `.peanut/application-manifest.json` 及受管基线。`generation_source` 记录仓库、请求的 ref、`development`／`prerelease`／`stable` 渠道和正式发行版本（开发来源为 `null`）；`template.version` 是升级兼容与 baseline 版本，不能替代实际源码身份。开发默认使用当前模板；显式候选采用上述固定产物消费者，不能借同号历史发行清单把自己的来源标成已发行。公开来源仍须核既有固定发行锁、仓内封存路径与清单，公开候选 commit/tree 和清单封存的 scaffold commit/tree 分别保留。生成应用的根 AGENTS.md 来自专用公开模板，不复制维护者入口。生成器不复制 Git 历史、私有维护资料、秘密或已安装依赖，也不改变 Core 包和许可证身份。创建工程、首次安装数据库、创建租户是三件事。取得完整产品发行包的使用者按包内说明装依赖和安装，不必先运行维护者生成器。

业务 CRUD 预览另从 `server/app/adminapi/services/generator/GeneratorRenderService.php::render` 进入，目标是已登记模块的 admin-web 贡献，不是创建整个应用。其页面使用 Web 已声明的 Element Plus，表格行插槽、独立分页、确认事件和权限键遵循实际组件合同；列表与提交状态复用公开 `useAsyncList/useAsyncAction`。输入未声明软删除时不生成回收动作，已声明时保留普通/回收接口及主键类型；失败结果不能提示操作成功。预览返回 create/merge 与原文件摘要，不会自动应用合并或建表。源码仓可复用 `server/tests/fixtures/generator-element-plus-inputs.php` 的四组定义分别调用该入口；PHP 模板回归、实际前端消费者检查和完整产品安装是不同验证范围。

首次安装使用 `server/database/install.php`。开发 APP 通过 `peanut upgrade` 选择固定模板、审阅 plan 后以同一 plan apply／verify／recover，沿用 Scaffold 归属、冲突、账本及文件前像恢复。CLI 先验证归档、完整成员、摘要与独立 tooling 锁，再从该固定缓存引导同一原生源码升级器；旧 APP 无需先手工修改升级脚本或安装新运行依赖。已更新的本机 `scripts/upgrade plan|apply|verify|recover --scope=source` 也接受该模板缓存作为 `--package`，固定 `--instance-root` 和绝对 `--plan`。创建与源码升级不安装业务、不连接或迁移数据库、不启动服务、不部署，也不要求额外人工 prepare。APP 从自身固定提交发行 server 包后，宿主已安装 `server/docker/scripts/update.sh` 映射同一协调器的 `--scope=server`，以 APP 自有归档、外部可信摘要及 workspace 驱动 product plan/state。目标未验代码不作为维护入口。

普通生成 APP 即使已经首装，也以真实 installed／baseline 不可变锚点、APP 身份和当前私有配置摘要绑定源码计划；不伪造 development-runtime。源码写入持有原生 product／project／instance 锁，并由现有宿主适配器只读核对实际进程、打开文件及已配置容器。活动进程、保存服务、未知操作、权限不足或无法完整观察时拒绝；调用者须先按自己的现有维护合同停写和排空，CLI 不隐式启停。观察需要宿主可用的 `ps`、`lsof` 及完整可读的 Docker 容器视图；配置文件不存在也不跳过容器排除，无法观察时保持拒绝，不自动安装工具或启动 daemon。已有受保护 runtime projection／server-release／partial 安装继续使用各自完整维护入口，不以普通 source-only 分支取消其门禁。源码 apply 的维护标记延续至同计划 verify 或 recover；失败保留标记和前像。完成不重新开放流量，后续依赖、构建、实际迁移、业务确认与开放写入按运行维护合同执行。源码恢复只证明文件恢复，不是 DB 恢复资格。

普通已安装 APP 的 server-release registered 数据库升级在首次 `plan --scope=server` 显式选择 `--backup-tool=/absolute/protected/tool.json`；工具为实例 owner 或 root 持有的 canonical、单链接、0600 JSON。原 product plan 冻结其路径、owner、模式和完整字节摘要；同一 plan 再次调用仍须选择同一工具，apply／verify／recover 从原计划复核，工具缺失、变化或数据库 provider 改变即停止。bundled 和 source／runtime 路径不接受此参数。工具复用已审的 `ssh-docker-exec` 固定 SSH alias、现有容器和客户端绝对路径；`source_schema_backup` 绑定该 APP 的 `database_resource_id`、`database`、`whole_schema=true`、实际 `server_uuid`、MySQL 8.4 精确 `mysql_version` 与 30–600 秒 `timeout_seconds`。凭据仍只由现有数据库容器中的原环境提供，不复制给 APP 普通账号。PHP/Nginx 使用自己的固定镜像和 APP bridge；registered 不准备或依赖本地 MySQL 镜像、容器或数据目录。

原生 backup 在原计划、真实 writer／DDL 排除窗口和流量关闭后，以独立 Root 客户端核精确目标 UUID／schema／版本、客户端摘要和直接完整可见权限，读取目标 schema 的完整 tables／views／routines／events／triggers／definers 及入出 FK 元数据；跨库仅观察引用边，不读取其他 schema 的业务行或定义。捕获前后须保持同一元数据和客户端。完整逻辑备份保留 routines、triggers、events、原 definer、CREATE／DROP／USE 及配对公共／私有文件、安装身份，过程终态、原始定义、dump 摘要和工具引用由原 whole-backup HMAC 认证。普通 APP PDO 继续使用自己的 schema 权限和业务证明，权限模式匹配只接受实际 `partial_revokes` 下的精确 schema literal，不借 Root 可见性代替。


host-only 的普通 generated source APP 使用显式 `schema:application … --scope=source-application` 或 `plugin:install|upgrade … --scope=source-application`，绑定真实 owner/thread/Session/Gate、干净 APP Git、安装完成锚点、当前依赖与私有配置，以及自身 application registry 中的精确开发专库。普通同源资源域沿用 APP 自己的原生租约；共享 central 资源域必须在 APP 自有 `lease_policy.native_authority` 明确固定工具 root、Git common-dir、commit 和三份 lease/application/registry 工具摘要。工具身份与 APP candidate/worktree 分开，不能改 cwd 分裂锁库，也不把私有 Project 注入产品 PHP。维护标记、实例/product/schema/package 锁和真实停写观察复用现有入口；source-development 的资格 fixture、Identity 受限逆向与 server-release 门禁保持各自范围。

这一路径的 selected-table capture 继续使用原 SourceApplicationBackup，完整恢复接入同一个 ordinary registered provider。工具另登记绝对 `python_command`，whole-schema 工具及其身份固定在原生计划的 protected workspace；真实源程序、依赖、配置、安装身份和精确 UUID/schema 均须与同一计划一致。完整 capture 不是资格：显式 `--application-recovery=rehearse` 在第一条 DROP/CREATE 或存储替换前，以同一固定客户端 dump 逐字节核当前整库前像，同时核全部保护目录的内容、缺失状态及根权限；任一漂移无 DB/存储写入拒绝。实际恢复后再核还原结果，apply 前再次核当前前像。真正失败/迁移后的 rollback 使用单独明确准入，并在写前用原 provider 保存当前数据库、存储及请求/独审引用的 afterimage，不把 rehearsal 的前像相等要求冒充 rollback 合同。整个 source APP 维护保持停写；完整 DB 恢复使用独立原生 PHP 阶段，不沿用恢复前的 PDO。安装历史、失败与当前 journal 原位保留；只恢复既有 provider 的持久目录及精确数据库，不把 Identity inverse 扩为通用恢复。

application schema 的原始 preflight、backup、实际恢复演练和带证据的 rebind 分别留存；apply 消费明确的 admitted plan，不覆盖原计划。保护执行窗口只短暂取得已有资源 mutex，子进程期间保留 in-use 边界及必要续租；实际子进程、过期或 UNKNOWN 都不能自动释放、重放或变成新额度。原 plan/actor/APP/DB/source 和原期限保持不变；已知失败或过期时，由真正未参与者先核全部本地/远端实际终态，再以 `application-recovery-admit --lease <原ID> --owner <原owner>` 在原物理锁域明确建立限时 recovery-only authority，不释放重领、不把旧失败改成 COMPLETED。普通 renew 仍拒绝过期租约。新权限只允许原计划的 restore，或已真实恢复后的 open，不能 plan/apply/rehearse。

central 对账使用 operation 已固定的 versioned `terminal_review.path`；普通 APP 使用自身 `server/private/maintenance/source-recovery-review-<lease>.json` 的原生认证记录，不依赖私有 Project。central 的原 workspace 必须精确为该 operation 已登记且持有物理锁的 `output_root/backup/application-recovery`，原计划入口为 `output_root/plan.json`；它们位于 APP 源码外，不搬移备份或改登记来迁就准入。普通 APP 的 workspace 必须是原租约精确 `output-dir` backup 根下的 `application-recovery`；不以任意外部路径或“位于 APP 内”替代原输出合同。两者均核 owner/private mode、canonical 路径及 context 中固定的 `native-plan.json`/`backup-tool.json` 原路径。独审记录协议为 `peanut.source-application-terminal-reconciliation.v1`、verdict=`ORIGINAL_PLAN_RECOVERY_ONLY`，须绑定不同 reviewer_thread、原 owner/lease、当前 state/metadata SHA、真实进程终态和远端效果已知、当前明确有效的 `effective_expires_at`（最多一小时）、原 workspace/plan_path，以及 `terminal_evidence` 和 `approved_sha256` 中全部原 plan/context/tool/配对 backup/journal/终态附件摘要。它是实际独立审查的回执，作者不得自造或自签独审成功。适配器在原锁下核真实 PID/birth、当前源码/配置/物理资源及精确原绑定，保存原状态/metadata/journal/独审原文和 rebind 到原 backup workspace；不改原计划或清除原过期/UNKNOWN 证据。再次过期仍需新的真实对账与授权，不能隐式续原计划。中断的 restore 不能重放；只有认证的实际 provider completed/终态成功凭据才可进入只读核对既有恢复结果及收口。源码检查不证明实际 APP 首装、迁移、数据保全或恢复已经通过。

成功迁移后仍不开放写入。显式 `schema:application source-recovery --scope=source-application --application-recovery=open` 复核本批原始完成回执、application migration 与全部已安装插件的原生图及迁移状态，再先关闭当前程序对应的旧恢复点、最后清除维护标记；不启动任何服务，不把该结果称作业务资格。失败、未知或尚未完成的操作保持保护；开放写入后旧备份不得再次回灌。

当前普通升级合同尚未覆盖任意视图、stored routine、event、trigger 的外部效果及跨库 FK；真实完整观察发现这些对象、非事务表或共享 tablespace 时明确 `NOT_READY`，保留 protected 元数据、已捕获备份和现场，指出需要审阅的具体对象，不进入受管代码或数据库写入。目标 InnoDB tablespace 元数据也必须真实读取；普通受支持的 file-per-table 路径显式使用 `--no-tablespaces`，不生成全局 tablespace DDL，`--set-gtid-purged=OFF` 不改变实例全局 GTID 状态。完整 dump 不等于 effects 审阅或恢复资格。恢复仍需原有明确授权和原计划恢复窗口，核当前外部效果及精确目标、工具、客户端；不改 definer、不启停 event、不隐式授予全局权限。已开始但无成功终态的恢复不得重放；activation 开始后不得自动回灌旧数据。本段源码能力不代表普通升级、定制数据保全或实际恢复已经验证完成。

当前模板包由创建与源码升级共同消费，包含完整 APP 开发源码及独立 tooling；源码升级验证模板协议、归档安全、来源／Edition、成员／模式及摘要，只采用受管源码。包内含有安装、宿主或迁移程序的源码，不代表此命令可以执行它们。既有 Edition source 包入口仍严格要求 `upgrader.scope=source`，拒绝混入 host_driver／database_driver；迁移源码按 append-only 清单核完整性。运行实例更新只消费 APP 自身发行的 server 包。

升级包的 `compatibility.source` 使用合法 Semver 范围 `minimum_inclusive <= 当前源码版本 < maximum_exclusive`，上界必须等于目标版本。现有 `same-major` 包要求来源、下界和目标处于同一主版本，不能用于 4→5；明确跨主版本来源的包须标记 `major_policy=source-range`。跨主版本可能改变业务功能、API、依赖和迁移行为。原生源码 upgrade plan 在目标官方模块图之外保留客户 Module，并使用目标版本自身的 Module Kernel 合同检查其 `kernel_constraint`；同时扫描明确 `use PeanutAdmin\Kernel\...` 的类及直接静态方法调用。目标类/方法不存在或不再是公开静态方法时，plan 记录 Module、文件、行号并保持 blocked，不自动重写业务源码；动态调用、实例方法和业务语义仍由受影响测试/人工审阅负责。执行前审阅所选来源→目标的变化及 APP 自有业务、依赖、API 与迁移影响，备份并解决已知风险后再尝试。CLI 跨主版本 apply 必须传入 `--confirm-major-upgrade`；AI 在执行前须向人说明来源→目标、风险和计划并取得明确确认，已提前明确授权本次操作或明确范围的可按该授权执行，不重复确认，泛开发授权不足以确认跨主版本 apply。当前开发仅吸收源码，不包含生产数据库迁移或部署；新源码尚未发行，不能据此推断既有包可跨主版本或目标兼容性已获完整验证。

开发来源归入公开同版本时，原生 `release-adoption-plan|apply|verify|recover` 使用同一 Scaffold 计划、锁、账本及恢复目录。只有 APP 实际 generation tree 与固定公开候选 tree、inventory 均相等，原受管 baseline 逐文件完整，且公开锁、封存清单及文件摘要核对后，才只改应用来源元数据；客户定制和 APP 自有版本保留。候选 tree 与封存 scaffold tree 是不同角色，不因版本号或 inventory 单独相同就视为等价。源码确有变化时必须使用目标版本严格升版且来源范围覆盖当前兼容版本的真实升级包，继续走现有三方合并；范围不覆盖时返回 `SOURCE_RANGE_UNSUPPORTED`。安装包本身不冒充升级包。普通源码升级和同内容归位均更新清单中的当前来源字段；同计划恢复还原旧清单，不需要网络或发行 checkout。

受管文件重命名只能由目标 Scaffold manifest 的 `renames` 显式声明 `from`、`to` 与 `owner`，不按文件名、路径相似度或相同字节猜测。旧路径未被 APP 定制且目标路径空闲时可以在同一计划内自动迁移；旧路径已定制或目标路径已存在时必须进入显式 `preserve`／`replace` 决策，旧、目标两端都已定制时禁止歧义保留，只允许明确 `replace`。链式或循环重命名、归属不一致、目标已被 application manifest 声明占用，或计划后任一路径发生漂移时均 fail closed；apply、verify 与 recover 同时绑定重命名前后的两个路径及新 baseline。

已安装工具核 inventory、文件 SHA-256/权限、来源/目标身份及实际受管内容。维护 PHP 使用 `PEANUT_UPGRADE_PHP` 指定已登记、满足 PHP 8.3 的绝对入口。目标 vendor 按原生锁在同一计划的 workspace 准备，锁和完整性符合时复用；不构建应用镜像。文件、依赖、数据库回执是从属证据，不另推进升级生命周期。未知 staging 或缺绑定恢复材料拒绝追认。

官方模块随维护者明确选择和审阅的固定 scaffold 来源整体吸收，绑定真实源码 commit/tree、release manifest、逐文件摘要与 canonical 模块 manifest/lock；这些摘要证明选定输入的内容，不认证发布者，不要求签名私钥。预检以已安装且通过生产 canonical 核验的官方图为起点，投影目标官方模块的完整控制器、服务、迁移及客户端贡献，先以原生 `PluginArtifactWriter::checkLock()` 核固定模板原文的完整 canonical 图；产品名称等参数渲染改变源文件字节后，按原生 make → lock 重建 APP 派生 manifest 和含原客户模块的锁并核依赖。计划同时固定原文图、渲染参数、APP 派生图与派生字节，应用及新基线使用同一派生字节，不要求渲染后摘要等于模板原文摘要。官方包与模块的变化必须提升各自版本，同版本内容不得变化；模块成员、根路径及官方归属不能借升级转给客户包。非官方 bundled 包只有在当前应用受管清单、固定 from/target 声明中的包/成员/根路径与来源合同一致，完整包文件均为 scaffold owner 且当前内容、权限、受管基线摘要全部吻合时，才能按上游整体吸收；命名空间或 bundled 标签不能替代归属证明。客户模块、app-owned 重叠和未登记的额外官方根文件受到保护，冲突不得靠单文件填入或手改摘要解决。应用/核验检查目标完整插件图，恢复检查原图；`module:adopt-package` 保持私有模块源码入口，不用于接收 official.*。

后端部署更新复用 `server/docker/scripts/update.sh plan|apply|verify|recover`。`--instance-server=/absolute/instance/server` 显式选择原实例；协调器和维护工具仍来自该实例已安装的 APP 制品，Compose、数据库和私有配置也取原实例。该入口消费 `--server-only` 生成的后端更新归档，不把仅后端更新包当作完整 APP 交付。目标归档只按外部可信 SHA-256 校验后消费。plan 将已安装维护工具固化到 workspace，每次执行前由协调器复核工具清单和哈希。归档文件按路径排序后严格比较路径、内容 SHA-256 和权限；归档遍历顺序不作为内容身份。

Compose 使用已准备的固定 PHP-FPM/Nginx 镜像和正常 bridge 网络、服务 DNS；普通部署不使用 ThinkPHP 开发服务或 Vite。应用代码与 vendor 位于宿主 `server/`，公共上传、runtime、私有上传及安装身份随该目录持久保留；只有 bundled 数据库使用 `server/docker/mysql/`。运行配置取 `server/docker/.env`，应用配置取 `server/.env`，两者不互换。`start.sh` 和 `update.sh` 不构建应用镜像。APP 独立发行来源与 Peanut 上游来源分别绑定。静态入口、API、安装页面及其错误由现有 Nginx 明确路由；HTTPS 使用已登记域名及外部私钥/CA，不能关闭校验或依赖固定容器 IP、host 网络或 SSH 隧道替代登记路线。PC 使用同次构建的 SPA 静态产物，SSR 后置。

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

菜单、权限与短信迁移先在原连接逐 SQL 执行所属模块的业务断言，所有合法后继迁移及模块协调完成后，再将业务表的完整 schema 与全行终态摘要存入受保护的原生完成回执。摘要保留 NULL、二进制值、重复行及 schema 类型差异，不保存私有行载荷；verify 重新读取实际终态并严格比较，不能将旧安全摘要当作实时证明，也不能跨连接复用原 before DTO。server 更新的签名回执同时绑定原计划、配对备份、执行来源、目标 release identity 和真实数据库身份；源码维护沿用原计划及恢复证明保护。缺少终态摘要的旧回执或开放写入后的业务漂移须明确拒绝复核，不补写历史证明、不自动重放 SQL。模块预检的应用 schema 延后条件仅接受固定 `20261019-module-migration-provenance-metadata` 来源及不可变摘要，旧 provenance 账本仍原位保留。

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
