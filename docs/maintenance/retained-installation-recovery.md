# 冻结开发现场的受限恢复协议

仅适用于正文明确限定身份、状态和来源的既有冻结现场。它不是脚手架正常开发或新APP创建前置，不允许据此创建新候选、恢复旧owner或重放失败。实际现场的停止、预算、恢复引用由所属任务负责；代码具备某分支不等于该分支已在真实环境验收。

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

若该事务已有完整 prepared，而 checkpoint 尚在 source-verified，后续 admission 明确绑定其原 `prepared_identity_sha256`，准确追加第四个 `scripts/product-upgrade-host` 的旧/新 SHA、模式与 Git 来源。只在原 source-check/source-project 消费此更正 host；其他 helper 和旧 trusted 不改，不新增 host 模式。现有投影只校验并采用，不再调用 producer、删除目录或回退 phase；open 的 transition_admission 也须绑定同一 prepared 摘要及四工具来源。

host 逐一从投影内原 release-versions、source_projection 和 files 行核六份声明 Web 归档的精确源/目标路径、源字节摘要与目标 0644；完整 prepared 文件集合（含原 Core owner inputs）须与 inventory 的 hash/mode 全等。只有这六个已证实输出加入 host 和 Runner 的派生映射，不能按目录前缀忽略任意归档。源程序集合的严格相等继续保留；后继按同一精确派生集合写意图、退休旧身份、发布 CAS head、native admission，runtime identity 仍逐行核实际文件及依赖。首次 prepare 未包含的新目标仅能从真实 absent 前像进入已知后像，未知现存文件继续拒绝；原恢复证据、实际完成的 vendor、旧 verified 来源保持不变。

该有限 internal 投影消费的是已交付运行候选，不要求其中存在四客户端开发用 node_modules；其 locks 子步骤沿原生声明及 Composer 来源校验，不传普通开发投影的 `--installed`。普通入口仍执行该参数。原 verified proof 的 managed/app-owned 摘要和前后 fence 保留实际源码与锁字节，六份 Web 归档继续逐份核摘要，真实 PHP vendor 继续原生 completed proof、vendor-check 与 vendor-state 检查。它不安装前端依赖、不重新构建客户端，也不把投影成功当作重新完成包级安装资格。

已真实发布 head、全部程序意图完成而 native admit 尚未完成时，采用独立有限角色 `repair=head-published-admission`。原生 CAS 将 canonical deployment head 写为 0644、安装目录保护为 0750；head 是受 owner、单链接、canonical 路径及精确摘要保护的部署元数据，读取入口遵循同一模式，其他私有输入的 0600 合同保持原样。它仍绑定原 verified origin、同四工具、prepared 摘要、当前 checkpoint/pending/head 与完成的 vendor journal；仅接 head-published、未激活、全部 intent done 的现场。入口另核保护 admission 的 `native_php_binary` path/sha256 与实际 PHP_BINARY 完全一致，不接受任意二进制选择。命令直接完成 native admission 后才将 checkpoint/pending 推进 admitted；不进入 runtimeInstall，不重投影、重写 intent、重发 CAS 或回退 phase。

此角色复用原观察以及既有 partial plan/backup/apply/completion 的宿主命名空间：在真实 instance lock handoff 下，以已登记 PHP 的全新独立进程调用原固定 W/trusted/server/docker/scripts/update-plan.php source-admit，并由该子进程加载 R 自己的 vendor/APP。原 native helper 内容及 binding 摘要不改，private、数据库/r1/模块前沿及 execution lock 的全部严格检查保留；不把宿主 dev/inode 冒充容器坐标，也不删除锁或 anchor 字段。独立子进程的原始 stdout/stderr 直接沿原私有日志保存，实际返回 admitted 后才推进状态。既有安装完成路径合法移除 partial 标记、发布真实 installed pair；之后 open 仍沿原 Docker 运行消费并重发其 admission，必须绑定同 transition_admission、PHP 二进制、四工具及安装完成证明，不额外启服务或改数据库路线。

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

固定 metadata SQL 将外键 `IS_LOCAL_PARENT` 显式编码为无符号整数 0/1，和严格解析器及后续 effect scope 的整数合同一致；不依赖 MySQL JSON 对比较表达式的布尔编码，也不放宽解析器接受类型。完整 facts 在普通 PDO 的只读事务开始前采集 metadata，随后在只读快照核实际全表覆盖及普通 SHOW CREATE 摘要；备份前后、apply 前继续完整重检。原 apply guard 在模块合法 DDL 后重新证明当前全部对象的覆盖、完整可见性和相同 effect policy，不强求旧 DDL 全集不变。采集回调只核身份、锁、工具、窗口和调用者事务状态，不递归调用 facts，不跳过原 guard。采集失败保留输出和进程终态，阻止相应动作，不自动重试；这些源码合同不证明真实描述文件、网络、采集或备份已经通过。

已完成源码采用且仍处于 `admitted`、未激活的 partial APP，若真实 APP 代码需要修正，使用显式源码后继事务。`plan --scope=source --instance-root=APP --package=FIXED_RAW_TARGET --partial-source-successor=PREVIOUS_W/source-runtime.json` 复用前任已认证目标 snapshot，核对当前完整 runtime、应用来源、head、partial 锚、工具和完成的 vendor journal；目标必须有不同固定 commit/tree，应用选择、同版本开发合同及七个 Core 输入保持一致。新计划仍走原三方分类与完整来源验证，不接受直接覆盖 APP 文件、手改 manifest/hash、借四工具维护准入加载 APP 类或用 `runtime-refreeze` 冒充源码升级。

随后以新 W、新计划及真实前任 head/generation 调用 `runtime-prepare`，同时传入同一 `--partial-source-successor`。原产品锁、项目锁和真实 instance lock 内，原生入口只允许全部程序意图已完成、流量关闭的前任接班；先保存 owner HMAC 收据及精确前任 checkpoint、计划、snapshot、vendor 和来源证明引用，再将活动 pointer 交给新事务。前任 W/P 及其状态保持原样，不伪标 `opened/recovered`；正常入口拒绝已被接替的旧 W 命令。交接失败保留实际 checkpoint、收据和活动 pointer，不自动回退或重发动作。新 W 的 `apply → verify → runtime-transition` 继续使用原恢复前像、完整源码图、受保护父子投影、程序意图和 head CAS；原 head 字节进入正常部署历史，真实新来源推进 generation。

后继投影的 producer 来自新 W 的固定工具，消费当前已验证源码 proof 和真实完整图，不要求打包 APP 拥有客户端开发依赖目录。新的 native admit 与 partial plan/backup/apply/completion 使用同一固定宿主 PHP 和真实锁转交，安装完成仍生成 installed pair 后再沿原 Docker open。新源码改变 adapter 摘要，因此必须新采集 metadata、生成新 partial plan，并取得匹配全部当前表及原件的原生备份；旧失败输出与次数保留，不能移用旧 metadata/plan/backup 或给旧额度归零。Core、API、前端等未变输入的既有证据按其实际合同复用；源码、运行、真实业务和发布资格分别记账。

APP 的 `backup` 提供原 `plan` 和新 `backup_output`；`apply` 提供原 `plan` 和原生 `backup.json`。二者复用原完整 native dump/probe/process 与 accepted-capture 消费者，拒绝外部运维摘要替代。六个真实原文件均保留原路径和摘要；仅 canonical `server/.peanut/development-runtime.json` 原件接受正常 0644 或 0600，读取前后核 owner/group/mode/device/inode/size/hash，备份及 RESTORE 元数据保留其真实原模式。其他原件和全部备份 payload 仍为 0600；不改原件权限，不用副本假冒原件。

APP `apply` 在持久写入单次 attempt 后使用原 PLS 和每条原 SQL。File 最早 unify 只有实际 Host 当前 File 调用持有的内存 capability、同一 context/PDO、原 r1/history/backup/source/窗口均一致，且完整旧两表、其余九对象缺失、File/object/旧 storage config 三计数为零、两表计数器为 1 和 session 序列默认时，才可执行。执行后仍须完整 File canonical 后态，并核空表、计数器、精确 local account／两 spaces／两 routes 默认数据及时间窗、原 config 和 Identity 业务行保全；catalog、审计和 tenant module profile 由各自正常生命周期处理。未知或非空旧结构继续拒绝；原模块账本记录真实 executed，不造 adopted 或历史 applied。

新鲜 Notification 的 producer 仅在同一真实 installation admission、固定三条来源顺序、namespace/adoption 前驱状态、七目标对象全缺失、父键/catalog/约束名均满足时标为 `after_predecessor`；报告仍为 pending、`may_apply=false`、队列数未知。两个原前驱执行后由原 producer preflight 和保全合同重新核验，不能把缺 outbox 当作空队列。

原模块安装与 health 成功后，APP 先持久保存认证的 completion intent，绑定真实 health 前沿、原 plan/backup/source/DB 及正常 writer 将写入的 baseline/installed 精确摘要，再由原完成逻辑写这两记录。只有 intent 和真实双记录全部匹配，才以原 deployment head CAS 发布真实 installed anchors，并原位接续 source-runtime checkpoint/pointer 和正常 runtime admission。原 partial head、binding 及 source generation 保留在历史中；不会伪造 installed hash、重置 source history 或提前删除 progress。写完双记录后的退出、CAS、checkpoint 或 admission 失败，后续同一 `apply` 只做 finalize-only，核原绑定和真实完成记录，不再运行任何 SQL。仅有 attempt、单边记录或实际业务失败继续阻塞；intent 不能代替真实双记录，不能用于补造安装成功。完成安装后的 source-runtime 禁止恢复旧 partial 源。这里的源码支持不代表当前残留、网络、备份、安装或恢复已经实际通过，所有运行额度与人工 Gate 独立保留。

开发运行投影以 `server/.peanut/development-runtime.json` 识别，保留自身的来源、文件和依赖校验，不创建 `release-identity.json`。数据库入口复用该身份读取器后使用投影的 `server/resources/project-resources.json`，已配置实例可使用 `server/private/resources/project-resources.json`；两者都必须属于该 APP。与 server-only 发行实例一样，开发运行投影拒绝外部 `PEANUT_RESOURCE_REGISTRY` 和维护者登记。未绑定运行投影的源码工程仍按原源码入口读取根资源登记。

开发运行投影也使用同一首次安装向导。配置、预检和执行直接消费 ApplicationSourceIdentity 的原生严格身份；只接受 development 环境目标，HTTP 继续使用已验证的部署准入。未安装投影通过既有启动准入发布安装能力；已有完成锁时仍先验证安装健康、原始回执和当前部署链。首装基线以 development-runtime 记录投影身份和原 APP manifest 摘要，不将其标作 server release；源码和依赖身份变化仍须经正常生命周期采用。投影由 package-release-files.py 的 development-runtime 动作从真实生成 APP、原生锁定依赖及完整来源清单产生，不手写身份，也不等同发行包。

永久 APP 的完整安装等待固定正式 Core 包可取得以及其发行 Gate。普通源码修复和完整包组装准备不以公开发包为前置；发行前确需运行固定候选时，仅在正常登记的独立候选目录使用同一原生完整归档、PHP-FPM/Nginx 和静态路径。内部候选身份保持不可正式发行，不用临时运行投影安装替代这条确认链，也不冒充永久 APP 验收。候选资源、库、写集及次数按原授权分别登记，不能借换目录、Edition 或新版本重置失败预算。

既有 `--internal-candidate` 完整包保留原 `kind=internal-candidate`、`scope`、`formal_release_eligible=false`、marker、来源及七个 Core 固定锁的合同，同次生成的后端身份带入这些已有字段及原 marker 字节。完整候选核验继续逐文件验证原 sealed source、四端静态、原生后端元数据和插件派生，不接受任意额外文件。后端读取器核对 marker、真实程序、来源和 Core 锁；首次配置仅提供 development 部署目标，正式后端升级入口拒绝候选作为目标。此处不增加新身份协议，也不把候选资格改为正式。
