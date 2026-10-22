# APP发行与server-only交付

## 1. 产物、源码和版本

APP开发源码归APP自己的Git仓库。`scripts/package-release.sh`的普通入口默认且仅生成server-only部署内容；`--server-only`只是同一默认行为的显式写法。上游维护者的`--generated-template`和已冻结内部候选属于不同输入合同，不是APP日常全源码发行选项。

`scripts/deploy-release`只接收独立APP自己的server Release及已有APP数据库权限投影。临时模板来源部署和空数据库权限投影在计划阶段即拒绝，不发布目录或启动服务；其他模板工具和历史制品保持其各自合同。

脚手架模板版本、APP独立版本、Core依赖及CLI版本分别记录。APP吸收上游后须正常提交和提升自己的发行版本，再生成部署包；不能用上游号覆盖APP号。已有输出拒绝覆盖，同名版本内容变化须形成新版本，而不是覆盖已发布附件。

## 2. 制包入口与输出

在已准备工具链的APP中执行：

```sh
bash scripts/package-release.sh --application-root=/absolute/workspace/my-app
# CI或维护者可显式指定同一个输出合同：
bash scripts/package-release.sh /absolute/workspace/release/apps/my-app/1.2.3/my-app-1.2.3-standalone-server --application-root=/absolute/workspace/my-app
```

省略输出时，路径为`<APP父目录>/release/apps/<APP slug>/<APP版本>/<slug>-<version>-<edition>-server`。输出包含装配目录、同名`.tar.gz`和`.tar.gz.manifest.json`；它们是同次产物，不再向另一个artifacts目录复制。固定维护工作区或CI应显式选择其登记输出根；未迁入统一工作区的APP不能靠目录名自动推断位置。

脚本检查APP身份、干净已提交的Git源码、受管baseline及固定已公开依赖；在输出父目录的私有暂存中完成原生依赖安装、Web/Platform/PC SPA/UniApp H5构建，核源码未被构建改变，再装配server。临时目录正常结束清理；原实例不修改、不安装数据库，也不自动发布或部署。生成失败保留真实错误，不能使用未完成目录当成功包。

本地和CI必须调用同一版本的脚本、依赖锁和制包合同。GitHub Release附件与本地输出属于同一产品类型；只有同一输入及实际SHA-256比对相等时，才能声称字节完全相同。CI生成成功也不等于已获准正式发布。

## 3. 包内与保护对象

| 类别 | 内容和处理 |
| --- | --- |
| 受管程序 | 后端PHP、原生安装/升级工具、版本化迁移、模块声明和后端插件投影；按发行清单更新 |
| 浏览器成品 | `server/public/admin`、`platform`、`pc`、`mobile`；来自同次固定源码构建，PC当前使用SPA |
| 发行元数据 | `server/.peanut/release-identity.json`、版本及合法告知、`server/composer.lock`；原生生成，不手填摘要 |
| 配置与安装身份 | `.env`、Docker私有环境、安装baseline/完成锁、实例资源和服务秘密；不得以新包覆盖 |
| 持久数据 | 数据库、上传、公共/私有存储、未完成操作、运行记录和恢复计划；不进入包、不被升级清空 |
| 开发内容 | `.git`、四端开发工程、scaffold baseline、node_modules/vendor、维护者Project及历史Evidence；不进入server-only包 |

Composer依赖不直接附带已安装vendor；目标实例按同一锁在升级workspace准备并核验，再按原生命周期采用。Git ignore不是包的安全边界；真正边界是制包白名单、受管清单和实例保护合同。

## 4. 三种不同操作

**新建APP**只创建源码工程，不部署、不初始化数据库。**吸收脚手架升级**在现有APP中作三方比较，保留APP身份、业务和定制；具体入口见[渠道说明](release-channels.md)。**生产更新**消费该APP自己的server包，不能拿模板包替换已部署APP。

前端代码或依赖变化必须重新构建受影响客户端；正式制包由当前脚本统一构建四端。生产采用包内已构建内容，不在服务器临时构建未固定前端。没有相关变化时，开发验证可按输入身份复用，不能凭“版本相同”复用不同内容。

## 5. 已安装实例更新与恢复

使用实例已经安装的可信更新器。宿主必须先将`PEANUT_UPGRADE_PHP`设置为已登记、普通可执行文件的绝对路径，解释器满足PHP 8.3及该APP实际依赖锁；不能用PATH猜测或执行目标包提供的解释器。`workspace`必须是已存在、canonical绝对路径、无符号链接且归实际维护者所有的私有目录。第一次plan使用新建空目录；apply/verify/recover保持同一目录，不能重建或清空原journal。

```sh
# 使用已核实的真实解释器和本次新空目录，勿直接复制占位路径。
export PEANUT_UPGRADE_PHP=/absolute/trusted/php
umask 077
mkdir -m 700 /absolute/private/upgrade-workspace
server/docker/scripts/update.sh plan --archive=/absolute/release/app-server.tar.gz --expected-sha256=TRUSTED_SHA256 --workspace=/absolute/private/upgrade-workspace
server/docker/scripts/update.sh apply --workspace=/absolute/private/upgrade-workspace
server/docker/scripts/update.sh verify --workspace=/absolute/private/upgrade-workspace
```

以上只展示入口顺序，不自动取得生产/数据库操作权限。首次workspace创建失败先核归属，不加强制删除参数。占位摘要须换成可信来源的实际64位SHA-256。plan固定包、APP身份、受管差异、依赖及迁移；apply前完成必要备份和实际停写。只替换受管程序与静态内容，删除仅限目标版本明确移除且未被合法定制的受管文件；未知冲突拒绝，不能整目录`rm`、盲覆盖或清空public上传。

失败保持原计划、journal、原错误和已发生效果。激活前按原workspace使用`recover`完成配对恢复并核旧运行；激活后不自动回灌旧数据库。代码与静态文件回退只有在数据库/模块状态兼容时才能作为业务回退；数据库DDL不因Git回退而撤销。首次安装、重复安装拒绝和partial恢复是不同入口。

### 固定Git目录生产者

`scripts/prepare-fixed-git-release`只准备一个可供后续接纳的目录，不操作已安装实例。它从显式审阅的**本地Git仓或mirror**读取固定对象，核对该仓唯一的`origin`与`--allowed-origin`完全相同，再在不存在的独立`--checkout`克隆，并从同一本地locator显式获取精确`--commit`后核对`--tree`、检出。仅由tag保留的提交同样按完整SHA取得；tag不是选择器。它不联网fetch、不拉取活动APP、不采用仓内未提交文件，也不以分支或tag替代完整SHA。仓、checkout和output必须是互不嵌套的canonical绝对路径；checkout/output的父目录须已存在，目标须不存在。

```sh
python3 scripts/prepare-fixed-git-release \
  --repository=/absolute/reviewed-app-repository \
  --allowed-origin=https://github.com/example/my-app.git \
  --commit=REVIEWED_40_HEX_COMMIT --tree=REVIEWED_40_HEX_TREE \
  --checkout=/absolute/private/new-checkout --output=/absolute/private/new-program \
  --build-inputs=/absolute/private/reviewed-build-inputs.json \
  --expected-build-inputs-sha256=REVIEWED_64_HEX_SHA256
```

占位值必须换成已审阅的精确来源。批准文件是普通JSON对象：`schema_version=1`、`protocol=peanut.fixed-git-build-approval.v1`、显式工具搜索`path`、`tools`及`inputs`。`tools`恰好包含`git/bash/python3/node/npm/pnpm/php`，每项记录最终解析到的绝对普通可执行文件`path`及文件`sha256`；PATH的解析结果和字节必须匹配。`inputs`恰好记录以下16个已提交文件的SHA-256：原`.peanut/application-manifest.json`、`release-versions.json`、`scripts/package-release.sh`、`scripts/package-release-files.py`、`scripts/release-dependency-locks.mjs`、`scripts/project-composer`、`server/composer.json`/`composer.lock`，以及`web/platform/pc/uniapp`各自的`package.json`与现有锁（Web为`pnpm-lock.yaml`，其余为`package-lock.json`）。批准文件摘要、APP commit/tree和allowed origin共同构成执行前的明确选择，不自动导入信任或凭据。

入口先逐项核对checkout的全部tracked路径、普通文件类型、Git blob字节和mode，以及批准输入。随后仅调用**该APP自己的**`package-release.sh OUTPUT --application-root=CHECKOUT --directory-only --server-only`，复用其固定依赖安装、四端静态构建及canonical程序清单。构建日志转到stderr，stdout仅返回本入口的JSON结果。构建使用新建私有HOME/TMPDIR和批准PATH，不继承调用者的凭据、Git配置或构建环境覆盖；执行已批准源码仍是信任决定，本入口不是进程沙箱，也不证明宿主全部依赖可复现。`--check-only`仅取得并校验新checkout，不运行构建或发布output；它不能报告完整制品通过。

构建后重新核对源码、批准文件和工具字节；逐项比对真实server目录与`server/.peanut/release-identity.json`的文件、mode、摘要和APP身份，并要求四个浏览器入口及plugin投影存在。仅全部通过后以exclusive mkdir取得output，发布server目录及最后的0444非秘密`fixed-git-source.json`。描述符协议为`peanut.fixed-git-source.v1`，`source.kind=fixed-git`，绑定allowed repository、APP commit/tree、原APP manifest与tracked清单摘要、Edition/profile/APP版本、批准输入与工具、native build entry及生产者摘要、canonical identity/files摘要和四端静态投影；stdout返回描述符SHA-256。程序文件维持原canonical mode，后续消费者仍须用可信外部摘要重验描述符与目录，不能把只读描述符当文件系统防篡改能力。

缺失/改变的身份或不完整输出拒绝；旧checkout/output不覆盖。失败的新checkout和私有staging保留给实际owner诊断；若最终发布遇到文件系统错误，没有完整描述符的目录不算已接受，不能作为更新输入或自动清空后重试。成功仅清理自己的构建暂存，精确checkout保留。此入口不生成tgz，不把Git来源伪装成归档，不改写任何安装baseline或完成身份。

**固定Git来源的原生更新接纳仍未接通。** 现有`update.sh`仅消费包；后续须在同一原生coordinator接纳typed描述符、核对真实原安装身份和当前deployment head，并沿用同一保护/恢复链。生产继续使用已获准的包更新；生产者完成不等于安装、升级、恢复、生产或EX8接受。不得把普通`git pull`、忽略文件规则、`git reset --hard`或版本标签当作完整恢复机制。

## 6. 验证口径

`scripts/deploy-release`从所选部署登记的`required_non_secret_environment.PEANUT_DATABASE_SOURCE`选择`bundled`或`registered`；省略时沿用现有`bundled`语义，未知值或与显式`database_source`冲突的值拒绝执行。部署与APP的`database_resource_id`必须指向同一精确数据库；归档中的APP身份和应用所有的资源投影须一致，不能从宿主endpoint推测容器权限。

`bundled`保留同宿主、同Compose、`mysql:3306`、本地MySQL目录以及PHP/Nginx/MySQL三枚不可变镜像的限制。`registered`要求数据库明确允许`container`消费者、固定`endpoint_id`/host/整数port、数据库namespace等于database，并允许当前Edition；登记的`PEANUT_DATABASE_CONSUMER=container`、`PEANUT_DATABASE_ENDPOINT_ID`、`DB_HOST`、`DB_PORT`和`DB_NAME`须精确相符。它仅接受PHP/Nginx两枚不可变镜像，不接受启用bundled数据库的Compose profile，也不要求外部数据库属于应用Compose。

计划固定模式及endpoint；新实例按同一模式调用原生runtime配置，registered首次启动显式传递官方`compose.registered-database.yaml`，仅启动PHP/Nginx。更新还核对实例现有私有环境的模式及数据库绑定；模式变更须单独处理，不能在部署中隐式切换。外部数据库的凭据、实例资源发布和连通性仍由原生配置/安装流程处理，包装器不生成外部凭据、不启动外部数据库。Docker模板中的镜像必须与登记一致，包装器不会重写镜像来掩盖不一致。

首次启动前，包装器以owner所有、0444的非秘密`server/private/mounted-deployment-receipt.json`发布同一固定计划。原生首次配置同时核对回执的APP身份、部署目标、模式、数据库和容器端点，以及APP本身的登记权限；界面固定该实例的选择，直接伪造配置请求同样被拒绝。HTTP暂存配置的v3 journal固定回执字节摘要，owner最终发布前重新核对摘要并执行相同校验。旧v2 pending保留并拒绝自动发布，需要原owner处理；缺少回执的普通安装维持自身选择，存在但不安全或漂移的回执不得视为缺失。

bundled同样须先从APP自己的登记解析唯一的资源、数据库和容器端点权限，再采用部署回执中的固定ID；回执不能生成新的权限。完成身份存在时，配置状态按当前可信APP身份返回已安装/锁定，不再将原首次安装回执作为新版本身份约束，因此正常的原生`update.sh`更新无需改写原安装历史；未完成的首次配置仍严格校验回执。

脚本默认值及离线文件边界测试，不代表四端实际构建、公开附件、安装、数据库迁移、生产更新或回退已通过。记录精确源码、包摘要、已执行断言、NOT_RUN和失败；不为验证打包参数生成新的真实测试APP。
