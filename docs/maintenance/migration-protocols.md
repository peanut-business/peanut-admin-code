# 源码维护：安装基线与迁移协议

这是当前源码中已存在的受限维护协议参考，不是普通APP安装步骤或自动派工依据。源级、模块和生产迁移各走自己的入口；固定SQL来源、原账本、业务保全、备份和真实权限不得省略。本文不声称当前数据库已经执行或恢复通过。

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
