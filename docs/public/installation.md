# 新实例安装与配置

本页适用于实际取得并核验的APP server-only包，不宣称开发分支已正式发行。先核APP独立版本、上游来源、Edition、清单、可信SHA-256和公开可取得的固定依赖。已有实例执行[更新流程](application-release.md)，不通过首次安装覆盖数据。

## 1. 准备独立实例

归档仅含server/。server/.peanut/release-identity.json绑定程序、版本和来源；后端插件锁和server/composer.lock来自同次发行。浏览器成品位于server/public/{admin,platform,pc,mobile}，生产无需前端开发依赖，当前PC使用SPA。

按该版本准备Docker Compose、Python、OpenSSL、shell和固定PHP/Nginx镜像。PHP及扩展须满足Composer锁。Compose挂载实例源码、vendor、配置与持久目录；普通更新不构建应用镜像，环境变化另行准备和验证。

server/.env仅存后端配置，包括DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS；server/docker/.env仅管编排、端口和镜像。配置使用实际owner、普通单链接和私有权限，拒绝越界链接。初始管理员密码不写入这些环境文件，也不进Git或产品包。

## 2. 数据库与资源

APP拥有自己的数据库、域名、端口、存储及服务登记，不回退维护者环境。database_source=bundled使用实例MySQL；registered使用已获准外部数据库，通过原生配置与compose.registered-database.yaml只启动PHP/Nginx。实例选择和凭据由原生配置发布器处理，不能修改不可变发行投影绕过身份验证。

操作前核实际目标、连接、权限、空库和安装身份。未知数据、已有APP库或partial库不得因测试需要就清空。开发源码、内部候选和正式server身份不同，开发模式不能带入生产。

## 3. 安装

安装向导依次处理协议、环境检查、实例配置、数据库与权限、执行和完成登录。状态含stage/reason/remedy/writes/safe_retry。网络错误不证明没有写入；未知结果先查询，不重放安装。

已经按原生配方准备依赖与配置的受权控制台可执行：

```sh
php server/database/install.php --preflight
PEANUT_INSTALLATION_ENV_FILE=/absolute/private/initial-admin.env php server/database/install.php
php server/database/install.php --status
```

仅preflight明确ready才执行。一次性0600文件提供ADMIN_INITIAL_EMAIL/ADMIN_INITIAL_PASSWORD；多租户还需独立PLATFORM_INITIAL_EMAIL/PLATFORM_INITIAL_PASSWORD。成功核对后销毁一次性文件，不保存或输出秘密。

依赖按server/composer.lock准备。首次vendor缺失由原入口在独立目录安装、service discovery/vendor publish及平台校验后采用；失败不改原vendor或启动业务。已安装实例vendor损坏走停写维护，不在线覆盖。

## 4. 完成、重复安装和失败

server/private/installation/installed.json是完成回执和防重装身份。已有installed、baseline、executing或partial须按真实状态处理。重复安装拒绝；--skip-if-installed只跳过已完成状态，不清库或重置管理员。

安装完成后安装页面应关闭。数据库为空、不可达或回执损坏不产生重装授权；不得删锁、删账本、手填成功或用旧空库备份替代当前partial保全。受限partial恢复只适用于其限定旧现场，不是新安装常规步骤。

旧runtime/installation状态须经支持该版本的维护迁移、双目录保全与逐文件核对；改目录名不代表升级完成。源码维护工具及其授权不因新包存在而自动取得。

安装状态、服务启动、管理员登录、权限和业务分别验证。静态检查不替代运行结果；已有实例的更新、备份和回退转到[APP发行与更新](application-release.md)。
