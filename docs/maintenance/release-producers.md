# 上游发行与冻结候选生产者

仅供Peanut源码维护者使用。产品日常交付见[产品Git合同](../public/application-release.md)；本文件维护模板生产、固定资格、冻结消费者与既有APP包/更新器的技术合同，不把内部命令追加为用户日常步骤，不反复派生测试APP。内部完整候选不等于公开发行、生产安装或新的默认流程。

## 数据库维护输入

普通运行配置使用 `server/.env` 与 ThinkPHP 原生 `PHP_ENV_NAME`，不再使用PHP自定义文件选择器。Compose的 `PEANUT_SERVER_ENV_FILE` 如仍用于选择挂载源，仅属于编排输入，不传给PHP作为配置加载入口。

seed、受控迁移维护和固定资格的资源选择使用独立进程输入，不从普通 `.env` 或 ThinkPHP Env 读取：`PEANUT_DATABASE_RESOURCE_ID` 选择已登记数据库；`PEANUT_DEPLOYMENT_TARGET` 必须匹配APP_ENV和登记环境；`PEANUT_DATABASE_CONSUMER` 明确选择登记endpoint；`PEANUT_DATABASE_ENDPOINT_ID` 是可选精确断言；`PEANUT_RESOURCE_LEASE_PROOF` 提供原操作要求的真实租约。先通过 `scripts/project-resource-registry database-env --resource-id=<已登记ID> --deployment-target=<已登记target> --consumer=<host或container>` 读取非秘密选择；该命令输出target、resource/endpoint/consumer及登记地址，不读取密码、不授予租约、不修改应用配置。只将维护选择传入相应命令进程，实际DB连接继续由原生应用配置加载并逐项匹配登记，不把全部输出追加到 `.env`。固定库的登记/选择检查可用同一工具的 `validate` 加相同三参数；它只证明该数据库范围，不证明健康或租约。维护者仅在明确提供绝对 `PEANUT_RESOURCE_REGISTRY` 进程输入时读取维护登记，不自动寻找Project，不从示例猜测地址、身份或凭据。普通运行和首次安装直接使用 ThinkPHP 默认连接；安装配置器不生成这些维护环境项或私有资源登记。

这些字段是现有受控维护操作的输入，不是普通日常配置的通用必填表；缺失时仅相应受控操作明确拒绝，不能用空默认值、私有配置loader或备用库替代门禁。一次性身份文件只在安装/明确的demo输入入口读取，不进入全局Env。

成对备份/恢复worker从APP自己的登记部署解析精确数据库选择，将其作为Compose子进程参数传给既有guard。实际claim命令在原生初始化后的同一进程中，复用备份源的连接核对，确认default和type均为MySQL，且实际host/port/database匹配所选production登记，随后才调用领取服务；配置核对本身不连接数据库。备份清单及隔离恢复证据从当前APP登记中的唯一production备份源绑定数据库，随后匹配原生默认MySQL连接；多匹配拒绝，不能按DB_NAME猜资源。普通备份中心读取无需把维护字段放进应用 `.env`；缺少完整APP备份/隔离目标登记仍拒绝，不自动投影维护者资源。旧 `local-stack-env` / `local-stack-ports` 资源子命令已退出，端口使用各项目原生配置。

## 正常模板发行

正常本地开发发行由独立`peanut-cli`提供`peanut config`与`peanut release`，环境与路径未变时日常只运行`peanut release`。发行以当前磁盘文件生成受限源码快照，允许dirty、无HEAD及未提交新增源码。默认输出`release/<scaffold版本>/{standalone,multi-tenant}`；显式`--version`必须等于`release-versions.json.scaffold_template`，既有版本拒绝覆盖，只有显式`--overwrite`且目标内容能由原生manifest、构建回执、归档和managed文件逐项验证时才重建。配置/发行测试仍需人工Gate确认后执行。

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

升级工具依赖单独位于`tooling/`，由该固定源码的Composer锁以no-dev、no-scripts、no-plugins安装形成；`upgrade-tooling.json`绑定锁、实际生产包身份和全部文件字节/模式，并由包清单摘要覆盖。此域不进入APP创建清单、受管源码或APP vendor。CLI只校验和缓存，不执行依赖安装或要求人工prepare；PHP入口在require之前核完整tooling集合，并将Composer根命名空间映射到同一模板的`files/server`。`--check`核固定工具载荷、锁和清单，不重新安装依赖。

官方模块内容及Plugin指纹随版本固定，不因APP品牌替换而改变；发行器拒绝这些域含APP身份token。CLI仅替换声明的宿主身份，按最终字节更新Composer content-hash与API inputs，创建0644模式的受管基线及准确APP文件/tree摘要。CLI创建不依赖PHP、Composer、维护者源码仓或私有Project；运行准备是独立动作。本地归档来源明确标记development/local，不能当作正式GitHub已发布。正式附件仍需对应资格与获准CI；CLI入口源码存在不代表已有新版CLI发行。

源码升级从APP实际记录导出`peanut.scaffold-recorded-baseline.v1`，只作为from输入，不伪造官方模板，也不能作为发行目标。基线物理文件仍为0644；原逻辑权限记录在`baseline_mode`，旧APP缺失时必须显式提供精确原版本清单作为权限证据。普通`template-plan`拒绝已安装或受保护运行现场；原运行协调器的维护/恢复合同继续保留。新模板来源及配套清单摘要进入原生计划并在rebind重核，apply/recover沿原计划执行。

## 候选资格阶段与CI保留输入

`python3 scripts/p0e-runtime-qualification candidate --candidate=<完整当前提交> --run-id=<本次唯一ID> --candidate-input=<固定输入JSON绝对路径> --candidate-input-sha256=<该文件SHA-256> --output-dir=<不存在的外部绝对目录>`复用原生`build-scaffold-release --check`核双Edition来源、成员、模式和固定tooling，再调用独立CLI从同一四附件在两个临时空目标创建，核manifest、源码commit/tree、归档摘要、Edition、文档及无Git或隐式依赖安装。最终完整八组的`generated-application`复用同一创建断言helper；常驻APP源码构建和其余断言继续保留，不在候选阶段运行。

输入协议为`peanut.p0e-candidate-inputs.v1`，只接受`protocol`、`source={commit,tree}`、`cli={root,commit,tree}`和`template_build_root`四项。CLI必须是干净且来源正确的独立源码仓；模板构建根必须是该固定Code来源的完整两Edition原生输出。此入口不读取常驻APP、数据库登记或连接信息，不创建/安装常驻运行APP、不安装依赖、不使用数据库或启动服务／浏览器。输出目录已存在即拒绝；失败保留日志、实际部分创建目标和`candidate-stage.json`，不能覆写或自动重放。

结果协议为`peanut.p0e-candidate-stage.v1`，只标记模板合同及CLI创建组件通过；全部八组状态仍为`not_run`，常驻APP源码构建、真实业务和完整资格未验证，`publication_qualified=false`。该阶段不是完整summary，不能给旧summary手工追加摘要。原八组接受条件及失败预算继续有效，固定APP及业务／部署消费者须在各自真实阶段闭合。

`scaffold-release.yml`显式选定完整CLI提交，核其真实树并生成同协议输入，构建后调用上述同一候选入口。除了原四附件，CI将完整`standalone/`、`multi-tenant/`根和阶段结果保存在独立Actions artifact；用tar保留隐藏默认文件和可执行模式，附实际传输包SHA-256。该tar仅供同一固定来源资格链传输，不是公开模板包或已采用的APP；后续job须核具体source/run及实际字节，不能重新构建后假定附件相同。候选job保持contents:read，正式发布仍须独立的完整八组及原main/平台Gate，不因候选通过自动运行。

CI 的 `scaffold-release.yml` 可显式选 `publish=false, pack_only=true`，并填写精确 `expected_source_commit`；它只按同一标准生产器独立构建双 Edition 两轮、严格比较来源/锁/工具输入和实际附件字节，跳过独立 CLI checkout 与 candidate 创建。原比较回执及两轮完整输出保存在身份专名 Actions artifact；这不是 full8 或发行资格。默认候选入口和正式发布 Gate 保留，`pack_only=true` 无法进入 ingress 或 publish job。CLI scaffold-ci Recipe 仅含同一标准双轮模板生产，无候选或发布步骤，要求源码中保留原生 `scripts/ci-producer` 及其 `tools/ci-producer/` 输入。两入口的 pnpm 固定锁安装均显式使用 `--package-import-method=copy`，使生产者工具文件满足原有单硬链接路径保护；不改变依赖版本或放宽该保护。

生产 job 通过原生 `scripts/ci-producer prepare <RUNNER_TEMP下不存在的目录>` 在既有 Ubuntu runner 内构建一次临时工具镜像，再用 `exec <同一目录> -- <原命令>` 执行标准生产器、比较器及默认 candidate。同一次prepare的两轮共用同一实际 image ID，不发布镜像或另设服务。`tools/ci-producer/inputs.json` 固定同 Debian/bookworm、linux/amd64 的官方 PHP/Python 完整镜像 digest、官方 Node 校验和、Composer PHAR 摘要、pnpm 包摘要和 Debian snapshot 的签名 metadata/精确包版本；仅以官方扩展 helper 编译原本必需的 pdo_mysql/zip，不重编 PHP 解释器。Python 复制完整安装并补齐固定 runtime 库；实际五工具、PHP 扩展、解释器及 PHP 子进程路径和 Python 动态库均核对，输入不符即在制包前停止。实际工具/包/路径/库摘要、image ID 和生产工具文件摘要随每个原生 scaffold-build-inputs.json 的 environment.ci_toolchain 封存，在同一次prepare的双轮原生比较中保持全身份相同；这些字段不进入便携模板载荷。独立prepare所得image构建ID与主发行物复现分记，采用前述独立重建口径，不修改或归一化原回执。普通本地开发工具仍由项目工具链合同管理，不把容器环境自报当产品资格。

## 冻结内部候选

候选首装与独立 APP 派生先用 `freeze-core-release --php-core-dir=<固定干净PHP仓> --web-core-dir=<固定干净Web仓> --version=<Core默认精确版本> --php-version=<PHP候选精确版本> --web-version=<Web候选精确版本> --output=<不存在的绝对目录>` 生成一个 PHP ZIP、六个 Web TGZ 及实际 `release-core-inputs.json`，记录来源 commit/tree、各自版本与摘要；两Core与脚手架版本可不同，Web六包内部仍同号，无需先公开发布Core。随后固定同一Code源码，依次为MT、ST调用 `build-scaffold-release --version=<脚手架版本> --source-commit=<固定Code提交> --edition=multi-tenant|standalone --workflow=full --internal-core-inputs=<冻结回执绝对路径> --output=<各自不存在的绝对目录>`，或由 `peanut release --internal-core-inputs=<同一冻结回执> --path=<固定Code源码> --source-commit=<固定Code提交> --output=<不存在的绝对目录>` 串行生成两形态。该候选通过原生Composer/pnpm/npm投影双Core声明及锁，携带七包，将真实投影文件与归档纳入受管清单及baseline；非Core锁定版本与来源保持不变。候选同样输出 `peanut.template-package.v1` 的TGZ/JSON，可由CLI显式本地 `create --archive ... --manifest ...` 消费；保持development/null来源与 `scaffold_candidate.formal_release_eligible=false`。正式发行生产者拒绝 `internal_core_projection`，不从本地候选直接发布。

再从所选形态的真实 `scaffold-manifest.json` 生成 APP：现有 `create-app` 使用 `--source-channel=development --source-commit=<同一固定Code提交> --source-tree=<对应tree> --scaffold-manifest=<候选清单绝对路径> --profile=full --workflow=full` 及正常产品名称、slug、package、target、edition 参数。候选受管程序和固定归档直接取自该产物，复用安全路径读取、文件摘要、品牌 token 与 Composer content-hash 渲染；五个 app-owned 初始默认仍由同一固定生成器生成。严格核固定来源、inventory、形态、完整受管集合及七包摘要，不接受候选新增路径抢占 app-owned、excluded 或未知文件。品牌相关 Plugin/API 元数据按最终 APP 字节由既有生成器重建，记录真实 baseline 和所消费候选清单摘要。`generation_source` 保留 development/null，`scaffold_candidate.formal_release_eligible=false`，不把未公开候选标成已发布版本。APP 的 Composer/npm/pnpm 依赖通过自身携带的 packages 相对路径与原生锁安装；不得依赖维护者 Core checkout、私有 Project 或临时外部 file 路径。候选生成、APP 自有依赖安装与实际首装分别记录结果，生成源码不等于运行或资格通过。

正式交付在固定候选完成所需资格及人工Gate后，选取适用的已发行Core，或按独立授权发行所需Core；核公开渠道可取的各自精确版本、最终包内容/摘要与来源，再生成正式APP依赖、原生锁和发行包并复核受影响的最终组合。不因脚手架升版重复发行未变化的Core；main/tag、正式发布及生产授权保持独立，内部候选构建不代替这些Gate。

维护者内部完整 APP 使用 `build-internal-edition-candidate --source-commit=<固定提交> --edition=multi-tenant --profile=full --workflow=full --core-inputs=<冻结回执绝对路径> --output=<不存在的绝对目录>`。edition、业务 profile 与 workflow 分别传入原生生成器；当前内部完整候选只接受 full/full，固定选择 Admin、Platform、PC SPA、UniApp H5。PHP ZIP 与六个 Web TGZ 原字节全部携带，绑定冻结回执、Git 来源、SHA-256；Web 原生锁使用从同一 TGZ 计算的 SHA-512 SRI。仅投影四端真实声明及 Core peer 闭包，不为 testing 等未消费包创造消费者或宣称运行覆盖。原生 Composer/pnpm/npm 生成锁后，非 Core 包的固定版本与来源身份必须保持不变；随后复用 `package-release.sh --internal-candidate` 在隔离目录 frozen install/ci、核实际安装位置及归档内容，再构建现有四端并组装完整 APP。安装阶段禁用生命周期脚本，Composer 使用 no-scripts；显式构建入口仍执行正常类型检查和构建，不运行治理测试、PHP 安装或 SQL。

内部投影保留原 `.peanut/application-manifest.json` 与 baseline 的字节和原摘要，以 `.peanut/internal-candidate.json` 单独记录依赖声明、锁和七包的投影树及新摘要；不把改写后的内容称为原始基线。封存源码校验与含四端浏览器产物的 APP release identity 分别核验，最终归档保留二开源码、原基线和全部七包，不携带 vendor/node_modules。回执只证明本次实际完成的输入、安装来源和四端构建；内部版本不等于公共发包，`formal_release.eligible=false`，不证明安装、升级或生产运行资格。源码实现与独立审查不代替构建；完整构建须在最终 dev 输入固定后单独授权，资源部署和测试仍遵守各自 Gate。

内部完整候选成功后正常清理临时现场；失败时 PHP builder 保留本次 0700 私有临时根，嵌套 `package-release.sh --internal-candidate` 同时保留实际 snapshot/build、原生 PM 锁、已安装状态及安装来源证明的实际输出（若已产生），不在内层 EXIT trap 提前删除。两层输出保留路径、调用 UID/PID 与清理责任；上下文文件使用 0600，不转储环境或凭据，不把失败材料装入交付归档，也不把部分证明称为通过。生命周期 owner 是该次真实 Build Session owner，诊断完成后按明确范围清理；超时或强制终止未执行 finally/trap 时，先对账同一 Job/子进程和已登记 staging 根，不启动重跑来重新取得现场。调用者的持久 Job 日志与输入回执位于临时清理范围外。

该 builder 的无依赖源码快照通过原生 `ApplicationCreator::apiGeneratorPath` 使用调用工程已准备的 `scripts/generate-api-contracts.php`。原生生成器必须先核工具与快照的 generator、Web manifest/lock、toolchain 字节相等，并核已安装 pnpm 锁、openapi-typescript 精确版本和 CLI 位置；APP 输入及输出仍取快照投影，不复制 node_modules、不临时下载，也不放宽身份检查。内部制包回执另记实际 generator 源码摘要、依赖锁摘要和经原生核对的工具版本；这不代替候选随后自身的隔离安装。

## 唯一发布器及main绑定

产品 GitHub Release 的发布入口是单一脚本 `scripts/publish-github-release`，显式提供 `--qualification`、`--template-artifacts` 和 `--output`。模板输入是完整的 `standalone/`、`multi-tenant/` 原生构建目录；校验器按各自声明的 Workflow 复用 `build-scaffold-release --check`，逐项核源码投影、成员/模式、固定工具载荷及摘要，不重新安装 tooling。该 check 会生成临时源码投影，需要已准备的生产者依赖；它不安装业务、连接数据库或登记运行 APP。正式附件是两套 `peanut-scaffold-<edition>.tgz/.json`，同一模板用于创建与升级；开发参考源码归档、许可证、SBOM、候选锁和外部发布清单另附。APP 的 server-only 包归各自 APP。

资格回执必须绑定最终候选 commit/tree 和 `peanut.template-release-inputs.v1` 的四附件长度/摘要、Edition、Workflow、生产输入回执、工具及内部清单摘要；旧 installer/upgrade 回执不会自动通过。默认稳定模式只接受 `X.Y.Z` 和注释 tag `vX.Y.Z`，通过原 candidate、tag、qualification、main 和固定依赖门禁后才以 `--latest` 发布。显式 `--prerelease` 只接受严格预发行 SemVer，且不标 latest。本地只用 `--prepare-only`；正式写入限定本仓 main 的 `scaffold-release.yml` CI。

该workflow已有独立publish job：只读取指定同仓、固定source、成功完成的原workflow run中唯一未过期的`scaffold-full-qualification-<source>`，核独立提供的transport SHA、原八组summary/group/plan/checkpoint和main合并凭据，再让唯一发布器复制原四附件并核SHA，发布阶段不重打模板。模板candidate job只证明标准构建重建及CLI创建，full8明确NOT_RUN，其artifact不能改名冒充完整资格。`p0e-runtime-qualification transport`可封存真实完整结果，独立ingress job接收已获准存在于同仓GitHub Release的原transport附件数字ID；不接收URL，不创建Release或上传Release附件。

导入时显式选择`publish=false`、`qualification_ingress_asset_id=<既有附件数字ID>`、`qualification_source_commit=<本次workflow精确提交>`及独立记录的`qualification_transport_sha256`、`main_integration_sha256`。入口只读固定同仓GitHub API，核附件ID、严格文件名`scaffold-full-qualification.tgz`、uploaded状态、大小上限和GitHub摘要，然后将原件交给与publish共用的`verify-qualified-release-transport.py`：核transport SHA、安全路径/普通成员、精确source/tree、真实八组plan/checkpoint/summary/receipt摘要与lifecycle绑定、原cleanup、main-integration SHA，并复用原生模板检查确认四附件及生产输入仍等于原资格。单独自报passed不能取得资格；它不执行或补做full8，也不加载transport中的脚本。成功后只把同一transport原字节上传为本run的`scaffold-full-qualification-<source>`，由现有publish可信run认证消费。

既有Release附件的准入和对外上传仍需各自授权；此只读导入接口不授权创建临时Release、借其他run token、增加runner/凭据/新存储或任意URL下载。没有合法原full8包及受控附件时，导入成功路径、后续正式发行与真实端到端均NOT_RUN，不能以接口已接线代替实际运行。不得伪造summary、tag或合并凭据；历史固定候选与证据保持原字节。是否已经公开发行以实际渠道核验为准。

产品 tag、qualification、候选锁、归档及 Edition 制品始终精确绑定已资格确认的源码提交 S 和源码树。实际发布要求实时远端 `main` 与本地 `origin/main` 一致，且等于 S；或等于同仓真实已合并 `dev` → `main` PR 的正常合并提交 M。后一种情况只接受精确父序列 `[B,S]`、B 为 S 祖先、M 与 S 同树，以及 GitHub API 的唯一 PR、merged 状态、提交、仓库和分支身份全部一致。开放 PR 的测试合并、squash/rebase、后续同树提交、未知或歧义 API 证据均拒绝，M 不被重新标为已资格确认的源码。

M 不等于 S 时，发布命令还须传入 `--main-integration=/absolute/premerge.json`。该外部回执只有七个字段：`schema_version=1`、`repository="peanut-business/peanut-admin-code"`、`source_commit=S`、`base_commit=B`、`ready_for_stable=true`、UTC ISO8601 `readiness_at` 与 `premerge_observed_at`；要求资格准备完成时间不晚于合并前观察时间，且观察时间严格早于真实 PR 的 `merged_at`。回执不改写原资格字节，也不代替原生资格检查或发布授权。发布器在输出目录保存独立 `MAIN_INTEGRATION_PROOF.json`，记录实时 main/tag、图、PR 及输入/API 摘要，供外部发布回执使用；它不改变候选锁或发布源码身份。创建 Release 前再次核 live main/tag 与 PR，任何漂移即停止。`--prepare-only` 仍只准备制品；Core 的当前 main 发布规则和候选检查器的 `--require-main` 精确合同保持原状。

## 固定公开依赖准备


`scripts/prepare-product-release-candidate` 只用于Peanut上游源码仓的隔离发行工作树，不随生成APP交付，也不是APP首次安装或线上更新入口。现有 `scripts/package-release.sh` 仅按下文的[既有包技术合同](#既有-app-包与固定-git-输入技术合同)解释，不是产品Git交付的追加步骤。

跨仓第一阶段可按[Core 续作入口](https://github.com/peanut-business/peanut-admin-code/blob/main/docs/development/core-release.md#第一阶段跨仓续作入口)使用 `scripts/continue-product-release`：默认只读计划；`--apply` 才建立任务输出根的状态并调用现有工具。候选依次核公开 Core 身份、原生锁、生产者依赖和库存，经审核提交最终源码封存后，在外部 `scaffold-templates/` 构建双 Edition；不再把生成清单/P0-E 投影提交回源码形成第二个产品提交。制品和资格绑定该最终 commit/tree，部分输出或身份漂移拒绝重放。`--core-web-package-evidence` 仍只替代六包元数据读取，原注释 tag、提交、成功 Action、GitHub Release 和固定摘要检查保留。

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
