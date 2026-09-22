# 发行渠道与版本身份

当前源码身份由根目录 `release-versions.json` 的 `source_product_version` 定义。`4.0.0-dev` 是开发候选，不是正式 tag、Release 或已发布公共包；取得源码分支不能替代取得固定发行包。

当前候选的 PHP Core 由 Composer 锁到 `peanut-admin/core` 的明确 Git 提交。Web Core 的六个拆分包由候选发行材料中的本地 `.tgz` 和 SHA-256 固定；截至本候选，它们尚未发布到公共 npm registry。不要把本地候选包描述成已经公开发布，也不要用无范围的 `composer update` 或 `npm install` 改写目标依赖。

正式使用路径是：

1. 取得带清单和摘要的固定发行包。
2. 核验发行身份、依赖锁和所选形态。
3. 部署到独立目录，在包内运行 `php server/database/install.php` 完成首次安装。
4. 后续版本从实例中已安装的可信 `scripts/upgrade` 依次运行 `plan`、`apply` 和 `verify`；
   `recover` 只按已生成的计划和现场证据处理失败恢复。不要把首次安装入口当成升级命令。

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

`apply` 准备目标依赖和前端，预检迁移，停写并备份数据库与公共/私有文件卷，应用受管文件、应用及模块迁移，切换并在保持外部写入关闭时重载及验证业务，最后记录激活边界、恢复流量并提交完成状态。
完成必须同时具有健康和激活回执，进程退出成功不能替代这些绑定证据。

激活前可按同一计划恢复成对数据库/文件备份及受管代码，全部恢复后才启动旧运行时并验证。重复恢复只核验已恢复状态。
一旦开始开放目标写入，工具拒绝用旧备份自动回灌数据库，避免删除已经确认的新业务数据；此时先核现场，再制定数据恢复方案。
代码恢复与数据库恢复分别判断，不承诺所有 DDL 可逆；未知结果不能用简单重跑冒充幂等。

具体可用渠道以该发行包的清单为准。开发分支、维护者工作树、私有 Project、临时 Core 压缩包和 CI 产物都不自动成为正式发布渠道。
