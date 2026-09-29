# 取得发行包并首次安装

本页适用于已公开发布、带固定依赖的完整产品发行包。当前 `4.0.0-dev.14` 是开发源码身份，尚无可按本页安装的正式 v4 GitHub Release；在对应正式包与 Core 依赖发布前，不要把开发 ZIP、CI 产物或维护者工作树当作正式交付。

## 取得与核对

从项目公布的 GitHub Release 页面选择同一产品版本、同一 Edition（`standalone` 或 `multi-tenant`）的 `peanut-admin-<version>-<edition>.tar.gz` 及同名 `.manifest.json`，同时取得 `SHA256SUMS`、`RELEASE_MANIFEST.json`。以 Release 说明中公布的 SHA-256 核对 `RELEASE_MANIFEST.json`，再核对它列出的附件摘要、`SHA256SUMS` 和 Edition 清单中的版本、来源、文件名、归档摘要。任一身份或摘要不符、依赖尚未公开发布时停止。安装包本身当前使用这些清单和摘要，不要把升级包的 Ed25519 签名说成安装包签名。不要从仓库源码 ZIP 自行拼装缺失的发行附件；本开发候选尚不能提供可执行的正式下载示例。

安全提取到独立的实例目录。下文的“产品根目录”是包含 `release-versions.json`、`server/`、`scripts/`、各客户端目录与 `.peanut/application-manifest.json` 的提取后目录，不是 `server/`、维护者 Code 仓库或私有 Project。先核对包内 `README.md`、发行清单、许可证、`server/composer.lock` 和客户端原生锁均齐全。

按包内锁及对应公开 registry 安装已发布的 Composer、npm 依赖，保留锁定版本；不得以邻仓路径、内部 Core ZIP、未公开 `.tgz`、复制来的 `vendor/` 或无约束更新代替。发行包不附带已安装依赖。包内 `scripts/project-composer` 可在产品根目录执行 `prepare`，然后执行 `scripts/project-composer install --working-dir=server --no-interaction --no-scripts`；客户端使用各自包内锁及包管理器。若该版本锁仍指向内部候选或本地 tarball，停止正式安装，等待固定依赖公开发布并由发行方重建完整包。

## 独立实例与首次安装

为本实例单独登记数据库、缓存、队列、存储、外部服务、域名和端口的身份、负责人、地址、凭据引用与健康状态。确认目标数据库为空、资源归本实例使用，并核验实际连接；不能套用别的项目、示例或旧实例资源。根据该包的 `server/.env.example` 配置本实例 `server/.env`，尤其是 `DEPLOYMENT_MODE`、数据库和应用密钥；该文件必须是普通单链接文件且权限为 `0600`。根目录 Compose `.env` 与后端配置用途不同。环境文件仅保存运行配置，**不得写入初始管理员邮箱或密码**。

自动首次安装所需 `ADMIN_INITIAL_EMAIL` 和强密码 `ADMIN_INITIAL_PASSWORD` 写入单独的临时文件；多租户模式还需不同的 `PLATFORM_INITIAL_EMAIL` 与 `PLATFORM_INITIAL_PASSWORD`。该文件必须是绝对路径、普通单链接文件、权限 `0600`，仅由安装命令的 `PEANUT_INSTALLATION_ENV_FILE` 指定，不复制到 `server/.env` 或产品包。按包内实际配置完成数据库连接和服务发现后，从产品根目录执行：

```sh
cd /absolute/path/to/product-root/server
php think service:discover
cd ..
php server/database/install.php --preflight
PEANUT_INSTALLATION_ENV_FILE=/absolute/private/installation.env php server/database/install.php
```

仅当预检返回 `ready` 才执行下一行安装命令。安装结束后移除这份一次性凭据文件，并按包内入口检查安装状态和运行环境。`php server/database/install.php --status` 可查询状态；普通重复安装会拒绝，`--skip-if-installed` 只跳过已完成的安装，不清库或重置管理员。不要对现有客户库使用首次安装或 `--fresh`。后续升级使用已安装实例可信的 `scripts/upgrade`，见[发行渠道与版本身份](release-channels.md)。

如果正式 Release 尚未给出该版本的完整附件清单与摘要、已公开固定依赖或明确的目标 Edition 配置，本页只能作为安装合同，不能宣称该版本可正式安装。
