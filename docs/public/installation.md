# 新实例安装与配置

本页区分产品开发首次安装与生产首次安装。先核产品独立版本、上游来源、Edition、固定程序来源及实际依赖锁；开发源码不等于正式发行。已有实例执行[原目录更新合同](application-release.md)，不通过首次安装覆盖数据。

## 产品源码开发的首次创建

产品源码首次创建按[产品开发流程](README.md#产品开发与定版)执行：目标源码目录和产品数据库必须是新建空对象，使用 Peanut CLI `create` 生成项目。它不安装依赖、不建库、不启动服务，也不初始化产品 Git。随后按锁准备依赖、开发环境及必要构建，启动并通过安装页面向导安装，确认访问后同步自己的产品仓库，在 `dev` 继续开发。既有产品在原目录和专库上继续，不默认重建。

生产首次安装从产品已定版 `main` 的固定提交取得源码，在全新空目录、空库准备依赖、适用前端构建和生产配置，启动后走同一安装页面向导。程序更新入口及运行验收边界见[交付合同](application-release.md#5-执行入口与失败边界)。

## 1. 准备独立实例

核对所采用产品的程序身份、后端插件锁及 `server/composer.lock`。客户端按各自 manifest 和锁准备，构建结果与后端来自同一固定来源；浏览器资源沿实际入口装配到 `server/public/{admin,platform,pc,mobile}`，当前 PC 使用 SPA。不能将旧 server-only 包已携带静态文件的条件套用于 Git 源码首装。

按该版本准备Docker Compose、Python、OpenSSL、shell和固定PHP/Nginx镜像。PHP及扩展须满足Composer锁。Compose挂载实例源码、vendor、配置与持久目录；普通更新不构建应用镜像，环境变化另行准备和验证。

server/.env存后端配置，包括DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS；ThinkPHP原生加载该文件，config中的env()优先读取环境值并使用固定默认值。原生PHP_ENV_NAME选择server/.env.<name>；其PHP_<KEY>进程变量只在文件未提供该键时生效。server/docker/.env仅管编排、端口和镜像。配置使用实际owner、普通单链接和私有权限，拒绝越界链接。初始管理员密码不写入这些环境文件，也不进Git或产品包。

## 2. 数据库与资源

普通安装与运行使用 ThinkPHP 数据库连接配置。安装首配的 `app_env` 写入既有 `APP_ENV`；`database_source=bundled` 使用实例 MySQL，`existing` 直接填写主机、端口、库名、普通账号和密码。已有数据库不由安装配置器创建或修改账号权限。Compose 使用已有数据库时通过 `compose.registered-database.yaml` 只启动 PHP/Nginx；来源选择保存在私有配置回执中。

缓存、会话和日志的运行标识由 `APP_ENV` 与默认连接的类型、主机、端口、库名和表前缀稳定派生，不包含账号、密码、目录或维护资源 ID。改变这些配置会使既有缓存和会话失效。MySQL 业务锁使用真实 schema 和表前缀，同库同表通过不同连接地址访问仍互斥。DB_NAME、DB_USER、DB_PASS没有可连接的默认值，缺失或空值明确拒绝；host/port/type/charset/prefix使用config中的固定默认值，容器连接须按实际服务覆盖host。

多租户首配只要求 `PLATFORM_HOSTS` 标识平台入口；租户站点入口在数据库中绑定。资源登记、endpoint、consumer 和租约证明属于需要它们的维护命令准入，普通运行与首次安装不强制填写维护资源身份。

操作前核实际目标、连接、权限、空库和安装身份。未知数据、已有 APP 库或 partial 库不得清空。开发源码、内部候选和正式实例身份不同，开发模式不能带入生产；具体身份适配尚未完成时停止相应安装路径，不手改清单或回执。

## 3. 安装

安装向导依次处理协议、环境检查、实例配置、数据库与权限、执行和完成登录。状态含stage/reason/remedy/writes/safe_retry。网络错误不证明没有写入；未知结果先查询，不重放安装。

网页向导是常规首次安装入口。已有原生控制台安装器供明确采用该合同、已准备依赖与配置的维护场景使用，不是在网页安装后再执行一次的步骤：

```sh
php server/database/install.php --preflight
PEANUT_INSTALLATION_ENV_FILE=/absolute/private/initial-admin.env php server/database/install.php
php server/database/install.php --status
```

仅preflight明确ready才执行。一次性0600文件提供ADMIN_INITIAL_EMAIL/ADMIN_INITIAL_PASSWORD；多租户还需独立PLATFORM_INITIAL_EMAIL/PLATFORM_INITIAL_PASSWORD。安装器直接将其解析为一次性身份输入，不同步到全局环境。成功核对后销毁一次性文件，不保存或输出秘密。

依赖按 `server/composer.lock` 准备。已有原生安装入口处理首次 vendor 缺失时，会在独立目录安装并完成 service discovery/vendor publish 和平台校验后采用；失败不改原 vendor 或启动业务。这项组件能力不代替 Git 首装链的依赖和构建接通。已安装实例 vendor 损坏走停写维护，不在线覆盖。

## 4. 完成、重复安装和失败

server/private/installation/installed.json是完成回执和防重装身份。已有 installed、executing、旧安装身份或 partial 数据须按真实状态处理。重复安装拒绝；--skip-if-installed只跳过已完成状态，不清库或重置管理员。

安装完成后安装页面应关闭。数据库为空、不可达或回执损坏不产生重装授权；不得删锁、删账本、手填成功或用旧空库备份替代当前partial保全。旧 partial continuation 已退役；保留现场并核对实际失败阶段，不重放首次初始化。

旧安装状态不提供自动兼容、迁移或重装；需要处理旧现场时单独核定来源、数据和恢复边界。

安装状态、服务启动、管理员登录、权限和业务分别确认。静态检查不替代运行结果；已有实例的更新、备份和回退见[产品 Git 交付](application-release.md)。

结构使用官方 `migrate:run`，动态管理员和默认租户初始化后由官方 `seed:run --seed=InitialSeeder` 完成数据与权限目录。不要在网页安装成功后再执行 seed。初始迁移不支持回滚；种子发生部分失败后明确拒绝重复执行。原生版本表默认 pa_migrations；旧数据库基线和自研账本不再参与安装。
