# 发行渠道与版本身份

当前源码身份由根目录 `release-versions.json` 的 `source_product_version` 定义。当前值 `4.0.0-dev.14` 是开发候选，不是正式 tag、Release 或已发布公共包；取得源码分支不能替代取得固定发行包。

`scripts/package-release.sh` 从已提交且干净的 APP Git 工作树默认装配完整开发源码包，保留 APP 自有登记、四端源码与 `.peanut/scaffold-baseline` 上游原始字节，供二开和现有源码升级入口使用。`--server-only` 另装配仅含 `server/` 的生产部署包。生产包 `server/.peanut/release-identity.json` 从同一次 APP 清单生成应用、上游来源、版本及实际 server 文件摘要；`server/plugins.lock` 是经原 APP 锁验证后生成的后端运行投影，原锁摘要与逐插件来源/派生摘要分别记录。APP 二开提交由其自己的 Git HEAD 表示。`build-edition-installers` 对同一初始模板生成开发源码和 server 两个包，明确标为 `generated-template`，没有独立 APP 提交。两类包都不含维护者登记、`.local`、Peanut scaffold 历史、实例安装记录或已安装依赖。

替换正式目录前，在隔离位置用可信渠道给出的归档摘要运行发行工具源码中的 `python3 scripts/package-release-files.py verify --archive=/absolute/server-package.tar.gz --expected-sha256=<trusted-64-hex>`。核验归档 SHA-256、仅含 server 的路径及包内逐文件清单；不能把同目录临时生成的摘要当作可信来源。当前开发候选的依赖仍未固定为正式发布版本，不能以此宣称已有正式生产包。

当前候选的 PHP Core 由 Composer 锁到 `peanut-admin/core` 的明确 Git 提交。Web Core 的六个拆分包由候选发行材料中的本地 `.tgz` 和 SHA-256 固定；公共渠道已有单独的 `4.0.0-rc.N` 发行演练，但这些预发行版没有进入本候选的正式产品锁，不等于正式稳定版已发布。不要把本地候选包描述成公共发行包，也不要用无范围的 `composer update` 或 `npm install` 改写目标依赖。正式包的取得、完整性核对、依赖与首次安装步骤见[安装指南](installation.md)。

本地非生产验证可以使用候选包内锁定的 Core ZIP。正式交付和生产安装必须从已发布渠道取得相应版本，并核对发行身份与依赖锁；不能把内部候选自带的 Core ZIP 当作正式发布版本。Composer 下载已发布版本时也可能使用 ZIP 分发格式，判定依据是发布来源与版本身份，不是文件扩展名。

正式使用路径是：

1. 取得带清单和摘要的固定发行包。
2. 核验发行身份、依赖锁和所选形态。
3. 部署到独立目录，在包内运行 `php server/database/install.php` 完成首次安装。
4. 完整开发源码实例的后续版本从实例中已安装的可信 `scripts/upgrade` 依次运行 `plan`、`apply` 和 `verify`；
   `recover` 只按已生成的计划和现场证据处理失败恢复。不要把首次安装入口当成升级命令。

server-only 生产包不含根目录 `scripts/upgrade`；下面的升级命令只适用于保留完整源码及该可信入口的既有实例。server-only 包的生产升级/恢复入口尚未在本制品中交付，不能用首次安装器或待验目标包中的脚本代替。

升级命令以 `--instance-root` 指定现有实例，以 `--package` 指定安全提取后的目标升级包。
由已安装入口验证目标包的 Ed25519 签名、完整文件清单和路径，再执行已验证的驱动；不要先运行待验签包中的程序。
`--signature-key-id` 选择可信密钥，权限为 0600 的 `--env-file` 只包含 `PEANUT_UPGRADE_TRUSTED_KEYS_JSON`。
该变量不得同时存在于进程环境。另以 `PEANUT_SERVER_ENV_FILE` 明确指定实例内权限 0600 的后端配置，实例根目录的 Compose `.env` 也必须存在。

```sh
unset PEANUT_UPGRADE_TRUSTED_KEYS_JSON
export PEANUT_SERVER_ENV_FILE=/srv/my-app/server/.env
php /srv/my-app/scripts/upgrade plan \
  --instance-root=/srv/my-app --package=/srv/upgrades/target \
  --signature-key-id=release-key --env-file=/srv/keys/upgrade-trust.env
```

确认 `plan` 退出成功并返回 `authenticated: true`；将返回的绝对 `plan_path` 作为 `--plan`，以相同参数调用 `apply`、`verify` 或 `recover`。
执行主机需要 PHP、jq、GNU 文件工具、curl、Docker 和 Compose，实例须能按 Compose 标签唯一识别；应用依赖和数据库操作在绑定容器内执行。

`apply` 准备目标依赖和前端，预检迁移，停写并备份数据库、公共文件卷、私有文件卷和安装身份卷，应用受管文件、应用及模块迁移，切换并在保持外部写入关闭时重载及验证业务，最后记录激活边界、恢复流量并提交完成状态。
完成必须同时具有健康和激活回执，进程退出成功不能替代这些绑定证据。

激活前可按同一计划恢复数据库、公共/私有文件、安装身份四项成对备份及受管代码，全部恢复后才启动旧运行时并验证。重复恢复只核验已恢复状态。
运维中心新建的成对备份清单与隔离恢复凭据使用 schema 3；备份集中包含 `database.sql.gz`、`php-storage.tar.gz`、`php-private-storage.tar.gz` 和 `php-installation.tar.gz`，四项均进入清单和摘要校验。旧 schema 2 备份保持原字节，但缺少安装身份归档，不能用于当前恢复验证或要求四项归档的 `--fresh` 部署；须重新创建并验证完整备份。
一旦开始开放目标写入，工具拒绝用旧备份自动回灌数据库，避免删除已经确认的新业务数据；此时先核现场，再制定数据恢复方案。
代码恢复与数据库恢复分别判断，不承诺所有 DDL 可逆；未知结果不能用简单重跑冒充幂等。

具体可用渠道以该发行包的清单为准。开发分支、维护者工作树、私有 Project、临时 Core 压缩包和 CI 产物都不自动成为正式发布渠道。
