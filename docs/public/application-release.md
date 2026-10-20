# APP发行与server-only交付

## 1. 产物、源码和版本

APP开发源码归APP自己的Git仓库。`scripts/package-release.sh`的普通入口默认且仅生成server-only部署内容；`--server-only`只是同一默认行为的显式写法。上游维护者的`--generated-template`和已冻结内部候选属于不同输入合同，不是APP日常全源码发行选项。

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

**固定Git来源更新是已确定的产品目标，当前不能据此宣称普通生产`git pull`已安全接通。** 在其取源、干净固定提交、已构建静态、部署清单、配置保护和同一恢复链完成验收前，生产继续使用包更新。不得把忽略文件规则、`git reset --hard`或版本标签当作完整恢复机制。

## 6. 验证口径

脚本默认值及离线文件边界测试，不代表四端实际构建、公开附件、安装、数据库迁移、生产更新或回退已通过。记录精确源码、包摘要、已执行断言、NOT_RUN和失败；不为验证打包参数生成新的真实测试APP。
