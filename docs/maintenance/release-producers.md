# 上游发行与冻结候选生产者

仅供Peanut源码维护者使用。产品日常交付见[产品Git合同](../public/application-release.md)；本文件维护模板生产、固定资格、冻结消费者与既有APP包/更新器的技术合同，不把内部命令追加为用户日常步骤，不反复派生测试APP。内部完整候选不等于公开发行、生产安装或新的默认流程。

## GitHub 正式发布入口

先完成模块、客户端清单及其原生锁的最终变更，再运行现有 `scripts/prepare-product-release-candidate` 刷新 `RELEASE_METADATA.json` 的锁摘要，随后生成 API、legal 和 inventory。此后原生锁再次变化时，须先重新准备同一未封存版本的候选；legal 生成器只维护自己的字段，不负责刷新依赖锁摘要。普通 `--static-only` 无制品证据模式只检查开发结构，不代表这些发行摘要已经通过精确候选核验。

1. 将已审候选集成到 `dev`，核对远端提交后，手动触发该提交的 `ci.yml`。工作流使用仓库指定的 PHP 和 MySQL 补丁版本。公共 CI 执行数据库策略和资格驱动的独立合同测试。完整资源验收在维护环境读取私有登记，并绑定实际应用、数据库、租约和制品。
2. 使用 `scaffold-release.yml` 对同一提交各生产一次 ST、MT 模板，固定独立 CLI 提交并完成原生创建检查，保存原始归档、清单和 candidate-stage。完整八组是按需授权的专项测试，不是通用发行前置；原失败和未完成结果保留，不补写通过。
3. 按受保护的 `dev` → `main` PR 与现有平台权限进入发布。在 `main` 的同一工作流选择 `publish=true`，提供精确 source、成功候选 run、成功 CI run 和原始传输包摘要；唯一发布器直接发布原四附件。正式 Release/latest 产生后，再用默认 `peanut create`（不传 ref 或本地归档）完成 ST/MT 首装、启动、IAB 登录及基本业务，结果如实记录。

推送分支或创建标签不会触发这两份手动工作流。开发机的 `peanut release` 生成本地双版包。CLI 可选的 `github-ci` Recipe 管理下游应用自己的 CI 配置与版本，采用它不会发布上游脚手架。

## 数据库维护输入

普通开发、启动和原生迁移不要求自写租约或候选证明；共享开发库仍核对实际部署目标、登记 endpoint、数据库名和应用凭据。`PEANUT_RESOURCE_LEASE_PROOF` 仅供固定资格、恢复等原有专用合同使用。

普通运行配置使用 `server/.env` 与 ThinkPHP 原生 `PHP_ENV_NAME`，不再使用PHP自定义文件选择器。Compose的 `PEANUT_SERVER_ENV_FILE` 如仍用于选择挂载源，仅属于编排输入，不传给PHP作为配置加载入口。

seed、受控迁移维护和固定资格的资源选择使用独立进程输入，不从普通 `.env` 或 ThinkPHP Env 读取：`PEANUT_DATABASE_RESOURCE_ID` 选择已登记数据库；`PEANUT_DEPLOYMENT_TARGET` 必须匹配APP_ENV和登记环境；`PEANUT_DATABASE_CONSUMER` 明确选择登记endpoint；`PEANUT_DATABASE_ENDPOINT_ID` 是可选精确断言；`PEANUT_RESOURCE_LEASE_PROOF` 提供原操作要求的真实租约。先通过 `scripts/project-resource-registry database-env --resource-id=<已登记ID> --deployment-target=<已登记target> --consumer=<host或container>` 读取非秘密选择；该命令输出target、resource/endpoint/consumer及登记地址，不读取密码、不授予租约、不修改应用配置。只将维护选择传入相应命令进程，实际DB连接继续由原生应用配置加载并逐项匹配登记，不把全部输出追加到 `.env`。固定库的登记/选择检查可用同一工具的 `validate` 加相同三参数；它只证明该数据库范围，不证明健康或租约。维护者仅在明确提供绝对 `PEANUT_RESOURCE_REGISTRY` 进程输入时读取维护登记，不自动寻找Project，不从示例猜测地址、身份或凭据。普通运行和首次安装直接使用 ThinkPHP 默认连接；安装配置器不生成这些维护环境项或私有资源登记。

这些字段是现有受控维护操作的输入，不是普通日常配置的通用必填表；缺失时仅相应受控操作明确拒绝，不能用空默认值、私有配置loader或备用库替代门禁。一次性身份文件只在安装/明确的demo输入入口读取，不进入全局Env。

成对备份/恢复worker从APP自己的登记部署解析精确数据库选择，将其作为Compose子进程参数传给既有guard。实际claim命令在原生初始化后的同一进程中，复用备份源的连接核对，确认default和type均为MySQL，且实际host/port/database匹配所选production登记，随后才调用领取服务；配置核对本身不连接数据库。备份清单及隔离恢复证据从当前APP登记中的唯一production备份源绑定数据库，随后匹配原生默认MySQL连接；多匹配拒绝，不能按DB_NAME猜资源。普通备份中心读取无需把维护字段放进应用 `.env`；缺少完整APP备份/隔离目标登记仍拒绝，不自动投影维护者资源。旧 `local-stack-env` / `local-stack-ports` 资源子命令已退出，端口使用各项目原生配置。

## 正常模板发行

源码维护、模板生产和应用运行分开：`project-resource-*`、CI编排、Core冻结以及源码仓专用HTTP消费者不交付生成APP。旧MT012、039固定actor操作和Workflow/Task/Article一次性驱动已退役；历史恢复使用当时固定Git版本，不把旧任务授权迁入当前源码。通用资源工具仍由Code维护者入口使用；生成APP的数据库guard只保留APP登记、endpoint、环境和原生连接校验，备份/恢复worker继续消费它，不依赖Project或lease脚本。普通安装和迁移继续走原生入口。

临时截图只应放既有`output/`工作目录。根目录两份旧对比截图同时被Git与版本化`source_snapshot.excluded_paths`排除，防止无Git本地快照将它们当源码。正常品牌资源、许可证与依赖锁保留。

`peanut create --demo=none|workbench`默认`none`，选择写入`.peanut/application-manifest.json`的`application.demo`与app-owned `server/config/peanut.php`的`demo.preset`。`workbench`在正常首次安装中写入持久演示数据并显式标识；升级不会隐式播种。迁出独立Module应用的模块源码从旧受管模板退出时，原生升级计划要求显式preserve/replace决定；preserve沿既有resolve/apply转换为app-owned，旧任务脚本仍按普通三方删除规则处理。

正常本地开发发行由独立`peanut-cli`提供`peanut config`与`peanut release`，环境与路径未变时日常只运行`peanut release`。发行以当前磁盘文件生成受限源码快照，允许dirty、无HEAD及未提交新增源码。默认输出`release/<scaffold版本>/{standalone,multi-tenant}`；显式`--version`必须等于`release-versions.json.scaffold_template`，既有版本拒绝覆盖，只有显式`--overwrite`且目标内容能由原生manifest、构建回执、归档和managed文件逐项验证时才重建。配置/发行测试仍需人工Gate确认后执行。

当前脚本的直接用途与归属：

既有 `peanut.scaffold-upgrade-plan.v2` 的 `identity.plugin_target` 与 action reason `installed_plugin_target_only_omitted` 是已持久化计划的历史序列化字段；实际承载当前 Module 装配目标。计划摘要、apply/verify/recover及manifest重新绑定共同消费这些字节，本轮保持原协议字段，不将其解释为另一种产品扩展身份；当前公开名称统一为Module。

| 用途 | 入口与实际消费者 | 交付 |
| --- | --- | --- |
| 应用开发环境 | `project-env`、`project-composer`、`run-with-environment-file`；原生安装/开发/构建调用 | 保留；不包含本机host或依赖目录 |
| 本地Core联调 | `core-dev`、`local-core-composer`、`local-core-web`、`local-core-links.mjs`；应用开发者显式选择Core | 保留；不隐式冻结发行 |
| 原生产品生成物 | `generate-api-contracts.php`、`module-frontend-entries.mjs`、`release-dependency-locks.mjs`、`sync-brand-assets.mjs`；API、模块、锁和品牌生成入口 | 保留 |
| 安装/升级/部署 | `migrate-installation-state`、`scaffold-doctor`、`scaffold-upgrade`与`scaffold-runtime/`、`deploy-update.sh`；应用生命周期入口 | 保留 |
| 运维数据保护 | `ops-backup-worker`、`ops-restore-worker`；APP登记的备份/隔离恢复与生成后的`environment-guard.php` | 保留产品所需guard；剥离资格及私有lease工具 |
| 应用源码检查 | `check-*`、`format-source.mjs`、`run-php-test`；应用开发者按需调用 | 保留；执行测试仍取独立授权 |
| 模板/Core生产与仓库维护 | create/build/prepare/package/release/qualification入口、`project-resource-*`、CI编排、CodeGraph、专用consumer及维护测试 | 只在Code维护，不随APP交付；CodeGraph的registry也不交付 |
| 已结束任务 | MT012、039固定操作、Workflow fixture/transition、Task/Notification与Article/Document一次性驱动 | 当前源码退出；原恢复使用固定历史版本 |

生成APP的guard保留`requiredEnvironment`、进程维护选择、APP资源登记、环境/endpoint/数据库/Edition匹配、原生PDO、等待连接、基线表/列/索引及当前安装校验。仅维护侧使用的schema lease、P0-E/consumer-upgrade资格、固定development-test准入和collaboration受控观察不进入APP；产品guard遇资格资源明确拒绝，不能以缺少私有工具降级跳过身份检查。

职责按阶段划分：

- 配置：CLI绑定已安装工具、本地Core与输出路径，保存于`.local/toolchain`；Code的`project-env`提供登记环境。
- 输入准备：CLI调用Code既有`scripts/prepare-product-release-candidate --prepare-release-workspace=<操作目录> --release-output-root=<输出根>`。Code负责源码复制、正式Core解析/原生锁投影；显式`--with-local-core`时从配置路径复制Core、按原生锁冻结包并保留本地摘要，不改写Core源码或伪造Git身份。
- 派生生成：同一Code准备入口在源码副本安装锁定依赖，生成API和inventory。CLI不维护这组内部执行顺序。
- 封装：CLI调用Code的`scripts/build-scaffold-release`生成两Edition，核对原生来源身份与输出，原子采用最终版本目录。

模板静态声明唯一登记在`scripts/scaffold-runtime/scaffold.conf.json`。`source_snapshot`控制输入扫描，PHP源码身份与CLI本地升级摘要同读；其中`excluded_names`排除任意层同名工具/依赖目录，`excluded_paths`登记源码相对路径，两个name-pattern列表保留环境示例、排除秘密及运行文件。这组pattern采用PHP/JavaScript共同支持的正则子集、忽略大小写、不含`~`，读取时校验并编译。源码中保留`.github`、`.githooks`等标准配置不代表它们会交付APP，不能一概排除点开头的源配置。

`delivery`声明交付排除、归属、profile和adapter元数据；`inventory_omissions`登记不生成库存行的维护者入口；`file_mappings`明确source→target整文件映射及其分类、transform与profile。`transforms`按顺序选择已有转换器，匹配精确path、prefix或既有路径pattern，未匹配的普通文件原样复制。`variables`声明支持的应用变量，`text_replacements`收录已有全局及按文件的字面替换，动态值只引用已声明变量及固定前后缀。复杂的版本、锁、品牌、模块与Edition转换继续由`ApplicationCreator`及既有投影器执行；配置不承载表达式或脚本。

`build-application-template-inventory`展开配置为现有inventory，`ApplicationCreator`消费其映射和转换声明。配置及读取器随原生升级脚本进入APP受管载荷；配置自身使用`copy`，不参与应用变量或品牌改写，其摘要进入来源证明。修改声明后运行原生inventory生成入口；不另维护手工库存或CLI过滤清单。

每次操作可变工作区集中在Code `.local/release/build-<随机ID>/`，包含`source`、本地Core的`core-php`/`core-web`、`core-inputs`及子进程临时目录；封装器通过`--work-root`使用该操作目录，正常结束清理自己的工作区。为保证最终采用与恢复使用同卷rename，输出根下仅短暂存在`.<版本>.stage-*`及覆盖时的`.<版本>.previous-*`，成功后只保留两Edition最终产物。已有未知owner的隐藏目录不迁移或清扫，既有版本也不因规则更新自动重打。

固定候选和正式资格仍使用本节后续描述的固定源码commit/tree、原生锁与资格流程；这些条件不扩展为普通开发期本地发行的前置条件。也可直接调用原生入口，显式传`--version`、`--source-commit`、`--edition`和`--output`；普通正式模板不传`--internal-core-inputs`。每个Edition输出`peanut-scaffold-<edition>.tgz`与配套同名`.json`，均来自该次原生APP投影，不另生成运行APP。

配套`peanut.template-package.v1`清单绑定产品、版本、Edition、profile、Workflow、源码身份、inventory/Edition profile和归档SHA-256/长度；源码身份可为准确Git commit/tree或本地快照摘要。归档为只含普通文件的USTAR+gzip；`scaffold-manifest.json`保持受管升级语义；`template-create.json`另列全部创建文件、模式、摘要、渲染声明、APP manifest模板和可写目录。受管载荷在`files/`，自有初始默认在`defaults/`，自有文件不进入升级受管集合。归档不携带APP安装依赖、Git、秘密、数据库、上传和实例安装身份。

同一原生入口在本地和CI使用。普通本地 `source_identity.kind=local` 发行使用 `project-env` 已按PHP/Node manifest约束及原生Composer/pnpm锁验证的登记工具；推荐版本差异不阻止本地包，`scaffold-build-inputs.json`记录实际PHP/Node/Python/pnpm/Composer版本。固定Git/CI来源仍要求精确工具版本。该回执还绑定源码、生产脚本/工具要求与原生锁摘要、系统/架构/zlib身份、全部成员内容及模式指纹和最终附件摘要；不含时间、临时绝对路径或秘密，也不进入APP载荷。模板输入读取器将此回执纳入资格和候选锁；检查按原源码和最终包重算输入/载荷/附件身份，不重新安装tooling，不把检查机器的环境冒充原构建环境。archive摘要证明容器字节，payload摘要证明排序后的路径/模式/内容，二者不能互相替代。

同一次已准备的工具环境内重复生产时，在两个独立输出根分别用标准入口构建双Edition，再调用`node scripts/release-template-artifacts.mjs compare <原构建根> <version> <commit> <tree> <inventory-sha256> <重建根>`，仍严格核生产输入、完整环境身份、载荷、四附件摘要及实际字节完全相同。CI候选job执行这一比较后才校验自己生成的模板；此原生比较不因独立重建的口径而放宽。

另一次准备环境后的独立本地/CI重建，分别记录固定源码commit/tree、原生锁、生产脚本、来源URI/规范仓身份、实际有效工具及相关库身份，并比较声明的主发行物实际字节和安全解包后的路径、类型、模式与内容。执行时间、runID、仅作运行记录且不影响产物的临时/工作路径和非交付工具镜像的构建ID单独作为执行来源记录，不要求整份执行回执byte相同；须先证明有效环境字段相同或明确已知差异及未知项，不能仅凭工具版本字符串、相同commit或包相同倒推全部环境相同，也不能忽略包内有效来源或依赖差异。时间等归档字段由标准生产者规范化，不事后改包或手改hash。此口径采用[主发行物可复现定义](https://reproducible-builds.org/docs/definition/)及[时间规则](https://reproducible-builds.org/docs/timestamps/)；[SLSA v1.2 tracks](https://slsa.dev/spec/v1.2/tracks)由生态选择是否采用可复现构建，不表示所有日志/回执均需byte相同或已取得认证。

主发行物或有效输入不符时保留差异，不继承原组合结论；独立重建通过也不自动继承完整资格。正式发布优先传递已验证原件，原件seal/digest及候选锁、发行清单和原资格摘要仍严格绑定；更换环境或最终包先记录确切输入与比较结果，不能自动继承旧PASS。内部Core候选与公开依赖/锁不同，须作为不同组合记录并验证。

升级工具依赖单独位于`tooling/`，由该次固定输入的最终APP投影Composer锁以no-dev、no-scripts、no-plugins安装形成；`upgrade-tooling.json`绑定锁、实际生产包身份和全部文件字节/模式，并由包清单摘要覆盖。此域不进入APP创建清单、受管源码或APP vendor。CLI只校验和缓存，不执行依赖安装或要求人工prepare；PHP入口在require之前核完整tooling集合，并将Composer根命名空间映射到同一模板的`files/server`。`--check`核固定工具载荷、锁和清单，不重新安装依赖。

采用内部固定Core输入时，来源和工具环境仍绑定原固定源码；隔离tooling安装只消费最终APP投影的Composer清单、原生锁、模块根及Core身份。保留模块作者已有Core约束和Module指纹，不为投影改写模块兼容声明；原生Composer拒绝不兼容的组合。最终Core投影完成后只刷新API catalog输入摘要，并同步现有manifest/baseline，不重建前端。

官方模块内容及Module指纹随版本固定，不因APP品牌替换而改变；发行器拒绝这些域含APP身份token。内部固定PHP Core输入若与生成APP中的受管归档同路径，只有当前生成manifest和baseline摘要共同证明旧载荷后，才采用输入回执绑定的新字节，并同步原manifest/baseline摘要；新路径沿原新增文件合同处理，未知或漂移的目标仍拒绝。此操作只修改本次临时投影，不覆盖历史归档或正式版本。CLI仅替换声明的宿主身份，按最终字节更新Composer content-hash与API inputs，创建0644模式的受管基线及准确APP文件/tree摘要。CLI创建不依赖PHP、Composer、维护者源码仓或私有Project；运行准备是独立动作。本地归档来源明确标记development/local，不能当作正式GitHub已发布。正式附件仍需对应资格与获准CI；CLI入口源码存在不代表已有新版CLI发行。

源码升级从APP实际记录导出`peanut.scaffold-recorded-baseline.v1`，只作为from输入，不伪造官方模板，也不能作为发行目标。基线物理文件仍为0644；原逻辑权限记录在`baseline_mode`，旧APP缺失时必须显式提供精确原版本清单作为权限证据。普通`template-plan`拒绝已安装或受保护运行现场；原运行协调器的维护/恢复合同继续保留。新模板来源及配套清单摘要进入原生计划并在rebind重核，apply/recover沿原计划执行。

## 候选资格阶段与CI保留输入

`python3 scripts/p0e-runtime-qualification candidate --candidate=<完整当前提交> --run-id=<本次唯一ID> --candidate-input=<固定输入JSON绝对路径> --candidate-input-sha256=<该文件SHA-256> --output-dir=<不存在的外部绝对目录>`读取原生生产者已经生成的双 Edition 来源、锁、内部清单及归档摘要，再调用固定独立 CLI 从同一四附件在两个临时空目标创建，核 manifest、源码 commit/tree、Edition、文档及无 Git 或隐式依赖安装。不为准入重新制包。专项 full8 如另获授权，可继续通过 `cli_creation={path,sha256}` 消费该原件；候选阶段不运行常驻 APP 构建或业务安装。

输入协议为`peanut.p0e-candidate-inputs.v1`，只接受`protocol`、`source={commit,tree}`、`cli={root,commit,tree}`和`template_build_root`四项。CLI必须是干净且来源正确的独立源码仓；模板构建根必须是该固定Code来源的完整两Edition原生输出。此入口不读取常驻APP、数据库登记或连接信息，不创建/安装常驻运行APP、不安装依赖、不使用数据库或启动服务／浏览器。输出目录已存在即拒绝；失败保留日志、实际部分创建目标和`candidate-stage.json`，不能覆写或自动重放。

结果协议为 `peanut.p0e-candidate-stage.v1`，只标记模板身份及 CLI 创建组件通过；原八组状态仍为 `not_run`，常驻 APP 构建、业务安装和 full8 未由此执行，`publication_qualified=false` 不被改写。候选原件结合实际成功 CI、合法 main/tag 和模板完整性进入有限范围正式发行；专项测试如另获授权，仍保留原断言及失败预算。公开 latest 消费验收在实际发布后进行，不能提前填写通过。

完整资格的生命周期输入可提供独立`fresh_applications={standalone:...,multi-tenant:...}`；缺省时相应fresh组件仍为`not_run`。每项沿用`root,head,tree,resource_id,endpoint_id,database,lease,environment,manifest_sha256,baseline_sha256,adopted_source,first_install={path,sha256},database_identity`，绑定独立APP根及原登记的development、ephemeral、`application_runtime=false`、`allowed_scenarios`含`first_install`的Edition/schema，不能复用永久APP的库。APP manifest须精确来自最终Code commit/tree与原资格Edition归档，Core合同与最终Code一致；原native admission核真实owner/thread、源码、独占schema及实际proof目录。首装事实只读原`server/private/installation/installed.json`并调用`verifiedInstallationFacts()`：要求`source.kind=generated-application`，source与顶层`application_manifest_sha256`都等该最终APP manifest原SHA，receipt的三个release版本与原生APP合同一致。原`install.php --status`同时提供实际Composer/Core依赖预检和当前安装健康；健康或历史升级回执不能代替首装事实。永久APP的历史安装、升级保留、恢复、健康及当前采用仍须全部通过，缺任一required组件不标fresh组通过；不重装永久APP、不接受自报fresh标记。此候选首装不代替公开发行后从正式附件重新下载、CLI创建和原生首装的实际验证。

`scaffold-release.yml` 固定完整 CLI 提交，按同一原生入口各生产一次双 Edition 模板并创建候选。CI 将两个完整模板根、四附件和 candidate-stage 以 tar 保留成员模式和隐藏文件，附原始 SHA-256。publish 从指定成功 run 下载这一原件一次，核来源和字节后直接发布，不要求本地再创建、重新制包或另行上传 full8 运输包。候选 job 保持 contents:read；只有正式 main 发布 job 取得 contents:write。

CI 的 `scaffold-release.yml` 可显式选 `publish=false, pack_only=true` 并填写精确 `expected_source_commit`，只生产各 Edition 模板，跳过 CLI 创建；该身份 artifact 不能代替发布所需 candidate-stage。标准生产器依赖包括锁定的 Composer、Web pnpm 和 UniApp npm，均不运行安装脚本；pnpm 使用 `--package-import-method=copy`，满足原有单硬链接路径保护。独立重现比较保留为显式专项能力，不是每次发行的重复构建步骤。CLI scaffold-ci Recipe 的同一标准生产步骤随 Recipe 自身版本维护。

生产 job 通过原生 `scripts/ci-producer prepare <RUNNER_TEMP下不存在的目录>` 在既有 Ubuntu runner 内构建一次临时工具镜像，再用 `exec <同一目录> -- <原命令>` 执行标准生产器、比较器及默认 candidate。同一次prepare的两轮共用同一实际 image ID，不发布镜像或另设服务。`tools/ci-producer/inputs.json` 固定同 Debian/bookworm、linux/amd64 的官方 PHP/Python 完整镜像 digest、官方 Node 校验和、Composer PHAR 摘要、pnpm 包摘要和 Debian snapshot 的签名 metadata/精确包版本；仅以官方扩展 helper 编译原本必需的 pdo_mysql/zip，不重编 PHP 解释器。Python 复制完整安装并补齐固定 runtime 库；实际五工具、PHP 扩展、解释器及 PHP 子进程路径和 Python 动态库均核对，输入不符即在制包前停止。实际工具/包/路径/库摘要、image ID 和生产工具文件摘要随每个原生 scaffold-build-inputs.json 的 environment.ci_toolchain 封存，在显式选择的同一次 prepare 复现比较中保持全身份相同；这些字段不进入便携模板载荷。独立prepare所得image构建ID与主发行物复现分记，采用前述独立重建口径，不修改或归一化原回执。普通本地开发工具仍由项目工具链合同管理，不把容器环境自报当产品资格。

## Module 生命周期资格输入

完整八组中的当前组名为 `module-lifecycle`。它保留四份相互独立的执行结果：Module 原生安装、禁用保持、显式恢复、升级只读计划、手工回滚计划、退休/重新安装/清除恢复；受控首次安装失败与前向修复；Member 上传所有权与租户隔离；Task/导入导出的拒绝、幂等、原子失败、撤销、私有交付与跨租户隔离。候选模板创建通过不代表这一组通过，组数仍是八，不接受旧 installed-lifecycle 备份框架的输入或通过回执。

生命周期输入使用现有 `peanut.p0e-lifecycle-inputs.v1`，增加 `module_qualification` 对象，协议为 `peanut.module-qualification-input.v1`。其 `source={commit,tree}` 必须等于固定 Code 候选；`reviewed_module_source={root,commit,tree,modules_lock_sha256,resource_id}` 指向受审 Module 专用 APP 的只读源码及权威中央资源登记，不能作为资格写入目标。机器上的主 Module APP、已有业务数据库及未分配的 application 级资源 stub 不构成隔离资格写入许可。

`lifecycle_application`、`failure_recovery_application` 与 `installed_application` 分别提供现有 runtime admission 使用的 `root,head,tree,resource_id,endpoint_id,database,lease,environment={path,sha256},manifest_sha256`，以及实际原生 `database_identity={server_uuid,server_hostname,server_port,database,transport}`。三者须为分别登记的 synthetic qualification APP/schema，沿用已有测试资源合同显式声明 `application_runtime=false`、`lifecycle=ephemeral`、`allowed_scenarios` 包含 `module_lifecycle`，使用各自真实 owner/thread 和独占 schema lease，与 Code、受审 Module 源及彼此的根/数据库分开；不能自动创建登记、复制源码到主 APP 或重置现有数据库。中央登记通过显式 `PEANUT_RESOURCE_REGISTRY` 消费，不将维护者机器路径写进产品默认。

前两个 APP 的数据库必须分别为空，各自供既有 `ModuleLifecycleMySqlTest.php` 与 `ModuleFailureRecoveryMySqlTest.php` 使用；第二个独立执行同一 ModuleMigrationRun 原生基线，不复用或清空第一个库。两脚本通过显式 `--application-root=`、`--expected-server-uuid=` 在各自根执行原生依赖、迁移与退休，首次写入前核实际 MySQL UUID/schema，回执包含实际完整数据库身份，不修改调用 Code 树。Lifecycle成功后，同一首个APP还须执行既有`ModuleRuntimeGovernanceTest.php`；复用原native admission/environment和前后上下文校验，非零退出拒绝，原日志末行必须为`MODULE-RUNTIME-GOVERNANCE-D2-001 passed sha256=<64hex> plan_digest=<64hex>`，现results绑定实际source/log摘要、归档/计划摘要及APP/resource/database身份，不另造JSON回执或新的APP。第三个 APP 必须已经原生安装且为 multi-tenant，并提供固定 `modules_lock_sha256`；Member/File/Task/ImportExport/Notification 的来源与 manifest 摘要须匹配受审 Module 源。默认八模块 Code 不会被补塞这些业务模块。

`identity_context={path,sha256}` 指向 0600 文件，提供 `member_access_token,alpha_access_token,beta_access_token,no_export_access_token,audit_failure_access_token,platform_access_token,alpha_manager_access_token`；它们必须是该隔离 APP 已有真实原生会话，不接受 JSON 声明的角色或伪造 Context。alpha 必须是非 Root 普通导出管理员，显式持有导出及 `peanut.file.download-original`；manager 会话通过正常 Role API 撤销和恢复导出权。撤权后的 File 边界使用原 TenantAuthService 重新验证同一真实 alpha 会话，不能把旧 Context 的过期授权基准当作最终撤权证明。 单独补验新增在途场景时，原 `run.php --action=task-inflight-revocation` 只读取 alpha 与 manager 两个真实会话，使用原生固定 plan、准入、PDO/数据库身份和实际 Module helper；不登录未消费的其余身份，也不重跑 Member 或既有 Task 场景。它输出该 scene 的原生正数检查标记及同 v1 结果，producer 明确为 `task-inflight-revocation`；不生成完整组 summary/checkpoint，不能取得 full8 或 transport 资格。 owned 子 CLI 的早期失败输出和安全阶段数据保存在原 plan 所在私有目录，以独占创建的 0600 原件保留；对外只报告阶段与摘要；子上下文未验证时仍记为 not_run。另显式给出既有 `other_tenant_id,alpha_role_id`。producer 通过原生 CheckTokenMiddleware、TenantAuthService 与 PlatformOperatorSessionService 的 context/assertAllowed 入口取得上下文；不创建账号、不重置密码、不自行发 token 或伪造安装身份。alpha 的许可证套餐必须仅归其一个租户，资格通过当前 PlanLicenseAdminService 按 revision 撤销依赖闭包，只剔除所撤销 owner/API 及丢失前置条件的权限，其余原 permission ceiling 保留，再恢复原选择；共享套餐拒绝执行。原函数以当前具体 TaskImportExportRuntime 资格适配器执行既有断言，不扩张 ImportExportWorkerRuntime 公开合同。每次停止/恢复核 alpha 当前可用性、beta 保持可用、RBAC 原行不变；停止另核原生 ModuleGuard 的 MODULE_TENANT_DISABLED，job 必须以当前签名重授权 fence 的 TASK_PERMISSION_DENIED 结束，不能将任意 dead 当作模块停止证明。

`server/fixtures/module-lifecycle/run.php --help` 只显示合同，不初始化 APP。完整执行仍由原 `scripts/p0e-runtime-qualification run/resume` 在明确授权后消费上述输入，先重核来源、路径、资源及 native admission，再调用五个 producer。输出记录每项真实退出/摘要/APP/数据库身份，失败和未知状态保留实际隔离 APP、数据库、文件与日志，不能回填 passed 或靠恢复旧整库备份掩盖写入。本文及源码静态检查不证明已完成数据库、HTTP 或 full8 执行；这些是发行前独立资格任务。

开发期只确认已安装APP的两项业务时，复用同一`plan/claim/run`并显式选`--groups=module-lifecycle --module-installed-only`。该封闭选项只接受原生命周期协议的`protocol,source,module_qualification`；Module对象只需受审来源、`installed_application`及原真实会话/租户/Role选择，仍核干净固定源码、APP原生采用afterimage、模块锁、私有环境、数据库身份和原owner/thread的schema lease。APP环境不注入私有Project；独立Code准入子进程只从原plan的`registry_path/registry_sha256`恢复固定维护登记给原native lease校验。它只调用上面的原`member-upload`、`task-import-export`入口，不重放已填充库上的Lifecycle、RuntimeGovernance或FailureRecovery，也不要求无关CLI模板及两永久APP已采用最终候选。计划scope为`module-installed-components`；即使两项原生结果通过，完整Module组仍为`not_run`，原run以非零保留现场和结果，不能取得full8或transport资格。资源owner核对原两项操作终态后按原租约入口收口，不用此选项绕过真实身份、改写旧证据或自动重试。

### Module Task 必须补全的独立资格任务

本轮退出四份合同已失效的历史手工入口：TaskImportExportHostTest、CrontabTenantIsolationTest、TaskNativeRootLifecycleTest 及 Task/feature-harness。它们曾受 CI changed-path/broad 的泛型文件选择消费；退役是清除失效实现，不是使 CI 变绿。没有修改 selector 隐藏文件，也没有删除专项八组的有效断言。源码中的通用 Unit/ExecutionRoot 断言和当前 Member/Task 函数保留；现行八组及真实 Module 路径仍须按各自输入执行，局部结果不能代替 full8 或 CI。

下列是 **正式发行前必须补全的 Module 专用资格任务，当前状态为待补实现及实际验证，本节资格全部 NOT_RUN**。已有相近断言源码不构成 native 场景已通过的证明。

| 任务 | 当前保留的对应源码与边界 | 仍须完成的真实资格 |
| --- | --- | --- |
| Cron 到 Task 的调度链 | FileTaskInputBoundary 保留命令准入/非零退出；当前 Task 函数保留真实异步执行和导出幂等 | 用当前原生 Module/许可证、真实会话及自有隔离资源验证 Cron add 忽略伪 tenant_id，跨租户 detail/delete 拒绝；同一到期 window 每租户恰好一个 job，重复窗口及 terminal job 不重跑；未知命令失败、手动 retry 恢复成功，Cron 不保留第二条执行 error/status 通道。 |
| 重试、trace 和诊断 | 当前 Task 函数验证异步成功、授权/模块撤销、上下文清空；这些不是 Cron retry 资格 | 验证真实三次 retry/dead、5/10 秒 backoff、attempt/event 账本与稳定 job/handler/request trace；ScheduledTenantContext finally 清空；终态失败进入现有诊断聚合，Task worker 启动失败只写允许的诊断字段。 |
| native CLI、root、lease 与 final fence | A1WorkerAuthorizationLifecycle 的批次撤销/最终 fence 和 ExecutionRootLifecycle 的 opaque root 规则是单元或局部源码断言；新 Task 函数有 before-worker 模块撤销与 tampered envelope 拒绝 | 真正经 Cron CLI、签名 codec、MySQL claim 执行双租户任务，各自具备独立 System root 和真实 job/attempt/handler metadata；ambient worker/scheduler/start 及 handler 内递归调度在 claim/enqueue 前拒绝且队列不变；实置 lease 过期后同 job 真实 reclaim/new attempt/new root；handler 返回后的实时许可证/授权撤销被最终 fence 拒绝；伪 envelope 不进入 native handler/root。不得用 fake unit 或 before-worker revoke 等价替代。 |
| XLSX 与 caller 边界 | 当前异步 CSV 私有交付/租户隔离和 FileTaskInputBoundary 保留；CSV 不等于 XLSX | 原生生成 XLSX 并解 ZIP 核文本/数值与公式形输入的 inline text；逐个核 Admin、Jobs、Member、Recharge、OperationLog 当前所属 Module 的 Xlsx owner 注入，不能各自造 Zip writer、静态查找或 deep import Task/Engine。业务来源仅消费受审 Module APP，不放回默认八模块 Code。 |
| 通用 Task producer、账本与恢复 | 当前函数有导出幂等、审计故障原子性、签名/资源/operation/targets 拒绝；不覆盖旧 feature-harness 全部意图 | 以当前构造与真实授权补齐 producer payload/idempotency conflict、外层业务事务回滚连同 job/event 原子撤销、租户/account/member/resource/operation/targets 重授权漂移、target set 顺序规范化、并发 claim 排他、stale lease fencing/恢复账本、payload 与 attempt-token 摘要损坏拒绝、未知 handler 安全错误、cancel optimistic revision、审计不含 envelope/payload/idempotency 明文。两 Edition 沿现行真实租户模型，不恢复旧 InstanceScoped/no-tenant_id 模型。 |

四份旧入口的数据库重建/删除、raw session/tenant_module 引导、旧 official 命名及过时构造不作为替代实现保留。未来资格使用当前明确输入、原生安装/身份/许可证、隔离资源和真实保护链；失败保留现场，不重建旧 SourceApplicationBackup 框架。Module 生命周期两个 native case 仍逐字段比较非 case 的 permission/menu/setting catalog，默认 Module 目录由原生 CatalogApplier 投影，不能用空快照冒充隔离证明。

## 冻结内部候选

两个 Core 已有有效冻结归档、但需要选用不同批次的 PHP 与 Web 时，使用同一生产者组合固定 Git 输入：`freeze-core-release --reuse-php-inputs=<PHP冻结回执绝对路径> --reuse-web-inputs=<包含六个Web归档的冻结回执绝对路径> --output=<不存在的绝对目录>`。该入口核原生回执状态、来源、版本及归档实际摘要，复用已有 Web 包检查后原字节复制七包，输出同一 schema 的完整 `release-core-inputs.json`；不重建、不重打包、不改版本或既有归档。PHP-only 回执可供 PHP 段选择，其保留的 published Web 字段不替代第二份冻结 Web 输入；两份源回执和归档保持原位。该组合可直接供下述 `--internal-core-inputs` 消费，不需要重新冻结已有效的 Core 源码。

候选首装与独立 APP 派生先用 `freeze-core-release --php-core-dir=<固定干净PHP仓> --web-core-dir=<固定干净Web仓> --version=<Core默认精确版本> --php-version=<PHP候选精确版本> --web-version=<Web候选精确版本> --output=<不存在的绝对目录>` 生成一个 PHP ZIP、六个 Web TGZ 及实际 `release-core-inputs.json`，记录来源 commit/tree、各自版本与摘要；两Core与脚手架版本可不同，Web六包内部仍同号，无需先公开发布Core。随后固定同一Code源码，依次为MT、ST调用 `build-scaffold-release --version=<脚手架版本> --source-commit=<固定Code提交> --edition=multi-tenant|standalone --workflow=full --internal-core-inputs=<冻结回执绝对路径> --output=<各自不存在的绝对目录>`，或由 `peanut release --internal-core-inputs=<同一冻结回执> --path=<固定Code源码> --source-commit=<固定Code提交> --output=<不存在的绝对目录>` 串行生成两形态。该候选通过原生Composer/pnpm/npm投影双Core声明及锁，携带七包，将真实投影文件与归档纳入受管清单及baseline；非Core锁定版本与来源保持不变。候选同样输出 `peanut.template-package.v1` 的TGZ/JSON，可由CLI显式本地 `create --archive ... --manifest ...` 消费；保持development/null来源与 `scaffold_candidate.formal_release_eligible=false`。正式发行生产者拒绝 `internal_core_projection`，不从本地候选直接发布。

再从所选形态的真实 `scaffold-manifest.json` 生成 APP：现有 `create-app` 使用 `--source-channel=development --source-commit=<同一固定Code提交> --source-tree=<对应tree> --scaffold-manifest=<候选清单绝对路径> --profile=full --workflow=full` 及正常产品名称、slug、package、target、edition 参数。候选受管程序和固定归档直接取自该产物，复用安全路径读取、文件摘要、品牌 token 与 Composer content-hash 渲染；五个 app-owned 初始默认仍由同一固定生成器生成。严格核固定来源、inventory、形态、完整受管集合及七包摘要，不接受候选新增路径抢占 app-owned、excluded 或未知文件。品牌相关 Plugin/API 元数据按最终 APP 字节由既有生成器重建，记录真实 baseline 和所消费候选清单摘要。`generation_source` 保留 development/null，`scaffold_candidate.formal_release_eligible=false`，不把未公开候选标成已发布版本。APP 的 Composer/npm/pnpm 依赖通过自身携带的 packages 相对路径与原生锁安装；不得依赖维护者 Core checkout、私有 Project 或临时外部 file 路径。候选生成、APP 自有依赖安装与实际首装分别记录结果，生成源码不等于运行或资格通过。

正式交付在固定候选完成所需资格及人工Gate后，选取适用的已发行Core，或按独立授权发行所需Core；核公开渠道可取的各自精确版本、最终包内容/摘要与来源，再生成正式APP依赖、原生锁和发行包并复核受影响的最终组合。不因脚手架升版重复发行未变化的Core；main/tag、正式发布及生产授权保持独立，内部候选构建不代替这些Gate。

维护者的双 Edition 候选由 `scripts/build-scaffold-release` 生成。当前发行采用适用 CI、双 Edition 原生生产、候选 CLI 创建、来源与摘要检查，以及受保护的 main/tag；full8 按需执行。既有 APP 的采用、保全和恢复是各实例自己的任务，不因发行再批量同步。失败保留实际输出、退出码和预算；没有受影响输入变化的结果直接复用。

## 唯一发布器及 main 绑定

正式发布仍只使用 `scripts/publish-github-release`，提供原始 `candidate-stage.json`、完整双 Edition 模板根和输出目录。`check-release-consistency` 核注释 tag、精确源码 commit/tree、版本、公开 Core、原生锁、法律文件及四附件摘要；读取已生产的原件，不重新构建。候选锁和外部清单保留真实 `candidate-passed`、有限验收 scope 和 full8 未执行状态，不伪造完整资格。

在 `main` 的 `scaffold-release.yml` 选择 `publish=true`，提供 `build_run_id`、`ci_run_id`、`source_commit` 和 `build_transport_sha256`。工作流核两个成功同仓 run 的实际 workflow/event/source；只接受候选 run 中唯一未过期的 `scaffold-template-qualification-<source>-<run>-<attempt>`。`verify-qualified-release-transport.py` 核原 tar SHA、安全路径和普通成员、生产者源码、candidate-stage、两版 CLI 创建 manifest 及原模板字节。它不执行运输包中的脚本，不要求 full8 summary，也不另造运输格式。pack-only 身份包不能进入发布。

稳定模式接受 `X.Y.Z`、注释 tag `vX.Y.Z` 并标记 latest；显式预发行模式只接受严格预发行 SemVer，永不标 latest。本地 `--prepare-only` 只准备文件；正式写入仍限定本仓 main 的唯一工作流。既有正式 Release 不覆盖，失败或 UNKNOWN 先对账同一实际对象，不自动重新上传、重建或删除他人附件。

原始模板、tag、候选锁及发布清单的产品来源绑定源码 S。运输验证器从 S 的 Git 原件读取 tree、版本、inventory 和生产者摘要，不用执行工具 checkout 的字节替代历史生产输入。实际远端 main 必须等于 S，或包含同仓真实已合并 dev → main PR 的正常合并提交 M：M 的精确父序列为 `[B,S]`，B 是 S 祖先，M 与 S 同树，并核唯一 PR、merged 状态、仓库和分支身份。发布工具可以在 M 之后正常集成；其执行 HEAD 必须是实时 main，工具 commit/tree 与产品来源 S 在外部清单和现有 `MAIN_INTEGRATION_PROOF.json` 分开记录，不把后续工具修正标作 S 的内容。发布器以真实 Git/GitHub 状态核对，并在对外写入前重核远端 main/tag，保留漂移拒绝、平台权限和实际发布授权。

发布说明与清单明确有限验收和仍未完成的历史 full8，原 FAIL/预算保留。公开 Release/latest 产生后，默认 `peanut create` 不指定 ref 或本地 archive，分别创建 ST/MT，再按自身登记首装、启动并由真实 IAB 登录和执行基本业务。此后验结果不能由候选 CLI 创建、源码健康或已有 APP 结果替代。

## 固定公开依赖准备


`scripts/prepare-product-release-candidate` 只用于Peanut上游源码仓的隔离发行工作树，不随生成APP交付，也不是APP首次安装或线上更新入口。旧 `scripts/package-release.sh` 已退出当前源码；现行[产品交付](#产品交付)由产品自身的 Git 定版与更新流程负责，不再把旧包作为额外步骤。

跨仓候选接续可参照[Core 独立发行](../development/core-release.md)的来源与公共渠道边界，使用 `scripts/continue-product-release`：默认只读计划；`--apply` 才建立任务输出根的状态并调用现有工具。候选依次核公开 Core 身份、原生锁、生产者依赖和库存，经审核提交最终源码封存后，在外部 `scaffold-templates/` 构建双 Edition；不再把生成清单/P0-E 投影提交回源码形成第二个产品提交。制品和资格绑定该最终 commit/tree，部分输出或身份漂移拒绝重放。`--core-web-package-evidence` 仍只替代六包元数据读取，原注释 tag、提交、成功 Action、GitHub Release 和固定摘要检查保留。

续发器只在同一获准 CI 内调用唯一发布器。外部结果未知时保留原 publication intent；远端无完整附件不自动重发。发布器创建后和续发核对均要求精确附件集合、Release/channel/size/digest，并实际下载每个 asset 比较本地字节。差异只报告，不覆盖、删除或重新上传远端附件。旧阶段状态不作为新模板流程输入，必须保留原状态和原失败预算。APP 使用正式模板和自身 Git，不依赖此维护者编排或私有 Project。

发布器在prepare完成及最终外部写入前，复用`release-asset-verification.mjs local <输出目录> <version>`将本地附件重新绑定到已封存的`RELEASE_MANIFEST.json`、候选锁及资格模板摘要；发布后同样先核这条本地绑定再核远端。准备后改写附件、重装依赖或重新压包不能只凭远端与当前本地相同而通过。

执行前固定一个尚未使用的产品版本、PHP Core 和 Web Core 的公开精确版本及各自完整 Git 提交。先运行 `--dry-run` 核对输入与将调用的原生命令；移除该选项才会准备候选：

```sh
python3 scripts/prepare-product-release-candidate \
  --version="${PRODUCT_VERSION:?set an unused product prerelease version}" \
  --core-php-version="${CORE_PHP_VERSION:?set a published exact version}" \
  --core-php-reference="${CORE_PHP_COMMIT:?set the verified full commit}" \
  --core-web-version="${CORE_WEB_VERSION:?set a published exact version}" \
  --core-web-reference="${CORE_WEB_COMMIT:?set the verified full commit}" \
  --dry-run
```

实际准备使用原生 Composer、npm 和 pnpm 更新声明及锁，随后核对 PHP 来源、六个 npm 包的公开仓库/`gitHead`/完整性及原生锁。pnpm 未记录 tarball URL 时，不自行拼接下载地址；发行身份使用官方 registry 实际返回的地址。缺来源字段、锁不一致、仍含本地依赖或命令失败时停止；正常可捕获的执行失败会恢复该工具负责的版本文件和锁文件原字节。异常终止或并发修改仍须保留现场并检查，不能把这一恢复视为运行实例的灾备机制。

产品候选版本与生成 APP 的初始版本分别记录，不改写 APP 上游基线。准备结果保留 `technical_qualification.result=pending`；原生冻结安装、四端构建、真实完整包、安装/更新及对应发行资格仍须分别验证。`--dry-run` 不联网验证版本存在；候选准备成功也不创建 tag、发布 Release 或取得部署授权。

具体可用渠道以该发行包的清单为准。开发分支、维护者工作树、私有 Project、临时 Core 压缩包和 CI 产物都不自动成为正式发布渠道。

## 产品交付

产品程序由自己的 Git main 提交交付，首次安装和已有目录更新分别遵守[产品 Git 交付](../public/application-release.md)。旧 server-only APP 包、固定 Git 包准备器和归档更新器已退役，不再运行其构建脚本。源码模板发行与 CLI create/upgrade 保留原生产合同，不能把删除历史 scaffold/releases 当作正式渠道重新发行或覆盖既有版本的授权。

完整 APP 在产品定版环节调用现有 `scripts/deploy-update.sh --prepare-release --version=<产品声明版本>`，按锁构建 admin 及 MT platform 并提交 `server/public` 对应两目录；生产从 Git 消费普通静态文件，不再次安装前端依赖或构建。该动作属于产品第4步发版准备，不移入 CLI 模板打包：模板仍交付源码，`source_snapshot.excluded_paths` 继续排除开发机器已有的 public 静态输出，防止将一个 APP/Edition 的产物混入其他模板。生成 APP 自己的 Git 发行才跟踪其静态文件。原始 Core 归档校验保留在模板、升级解包和依赖准备边界，普通 PHP 版本声明读取与 HTTP 安装预检只读声明及已安装依赖。
