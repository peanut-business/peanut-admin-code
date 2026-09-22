# 发行渠道与版本身份

当前源码身份由根目录 `release-versions.json` 的 `source_product_version` 定义。`4.0.0-dev` 是开发候选，不是正式 tag、Release 或已发布公共包；取得源码分支不能替代取得固定发行包。

当前候选的 PHP Core 由 Composer 锁到 `peanut-admin/core` 的明确 Git 提交。Web Core 的六个拆分包由候选发行材料中的本地 `.tgz` 和 SHA-256 固定；截至本候选，它们尚未发布到公共 npm registry。不要把本地候选包描述成已经公开发布，也不要用无范围的 `composer update` 或 `npm install` 改写目标依赖。

正式使用路径是：

1. 取得带清单和摘要的固定发行包。
2. 核验发行身份、依赖锁和所选形态。
3. 部署到独立目录，在包内运行 `php server/database/install.php` 完成首次安装。
4. 后续版本从稳定的升级工具位置依次运行 `scripts/upgrade plan`、`apply` 和 `verify`；
   `recover` 只按已生成的计划和现场证据处理失败恢复。不要把首次安装入口当成升级命令。

升级命令以 `--instance-root` 指定现有实例，以 `--package` 指定已经提取并通过签名核验的
升级包，并使用 `--signature-key-id` 与权限为 0600 的 `--env-file`。`apply`、`verify` 和
`recover` 还必须使用 `plan` 输出的 `--plan`。计划、执行、验证和恢复是不同阶段；代码恢复
与数据库恢复分别判断，未知结果先检查现场，不能用简单重跑冒充幂等。

具体可用渠道以该发行包的清单为准。开发分支、维护者工作树、私有 Project、临时 Core 压缩包和 CI 产物都不自动成为正式发布渠道。
