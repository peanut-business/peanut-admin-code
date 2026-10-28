# ThinkPHP 原生迁移合同

数据库结构唯一入口为 `server/database/migrations`，使用锁定的 topthink/think-migration 官方 Service、Migrator 和 Seeder。命令为 `php server/think migrate:create <Name>`、`migrate:run`、`migrate:status`、`migrate:rollback`、`seed:create`、`seed:run`；不传旧租约/基线劫持参数。实际操作数据库前仍须核对目标实例、资源准入及恢复边界。

本次初始结构压平到 `20261101000000_initial_baseline.php` 和 `schema/baseline-schema.sql`。结构文件只含交付产品的表定义，不带开发库记录、历史自研账本和仅供验证的 fixture 表；既有产品表采用已核实的原生 `SHOW CREATE TABLE` 定义，去掉随数据变化的表级 AUTO_INCREMENT 计数。官方账本默认 `pa_migrations`，记录 timestamp version/name/时间，不提供旧自研 checksum/coverage 状态。初始基线不可逆，rollback 明确失败；重装只能由 owner 另行指定空库，代码不自动清库。后续变更新增原生 PHP 迁移，不修改已经应用的版本。

首次安装仍要求空库。既有开发库采用这份初始结构前，必须完成完整数据库备份、核实际资源和停写边界。原生 InitialBaseline 先对所有已存在的产品表逐字比较完整原生定义（只忽略表级 AUTO_INCREMENT 计数）；任何差异在执行缺失产品表 DDL 前拒绝。原生 adapter 可能已先建立自己的空迁移账本，这不表示产品结构已应用。通过后只创建缺失表，保留已有表、数据和旧账本，由 think-migration 记录本次真实执行。不得直接填充 applied version、改版本号绕过执行或用 `IF NOT EXISTS` 忽略未知结构差异。该核对不代替代码与模块的结构审阅；新定义应先按当前实际数据库核实，不能用此入口强行接纳不匹配的旧库。DDL 或记账中断时保留实际现场，先核真实定义和账本再决定后续，不承诺任意部分失败都能直接重跑。

安装业务保留空库及重复拒绝、动态租户/管理员、权限和模块目录、安装锁、健康与完成身份。先官方结构迁移，再初始化动态身份，然后 `seed:run --seed=InitialSeeder`。种子核实际默认租户及 owner，拒绝非空或部分完成。由于权限目录 repository 使用框架连接，native seed 初始事务提交后才同步目录，再执行后续数据事务；失败可能留下部分初始化，不能重放或声称整段原子。

`schema/baseline-data.sql` 只承载必要的系统初始数据，与结构文件独立维护。默认 `PEANUT_DEMO_MODE=disabled`；正常安装不调用示例数据脚本。管理员、平台账号和安装身份由当次安装输入建立，开发库账号、凭据、会话和业务记录不作为安装种子。完整开发库 dump 是独立恢复备份，不能替换这份初始数据或进入模板载荷。

模块 schema 随应用版本在主目录交付，不再使用模块 SQL 目录、backend.migrations、自研 module migration 台账或旧 proof/adoption。模块安装/升级复用原生 pending 迁移，并验证 manifest 自有表存在；不满足结构则拒绝，不能仅复制模块目录后成功。卸载保留全局原生版本历史；清除表后重新启用须通过新的应用迁移显式恢复结构。

旧数据库 baseline 清单、kernel-migrations、覆盖版本账本、schema:application 和历史 partial continuation 已删除。保留旧现场及冻结证据不代表现行程序能续跑它们，也不授权清空数据。源码升级三方 baseline、安装身份和业务幂等是独立合同，继续保留。

本次静态/入口检查不能证明真实 MySQL 安装、回滚或生产更新通过；实际运行、测试和发行资格按各自授权记录。
