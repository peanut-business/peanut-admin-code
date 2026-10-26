# ThinkPHP 原生迁移合同

数据库结构唯一入口为 `server/database/migrations`，使用锁定的 topthink/think-migration 官方 Service、Migrator 和 Seeder。命令为 `php server/think migrate:create <Name>`、`migrate:run`、`migrate:status`、`migrate:rollback`、`seed:create`、`seed:run`；不传旧租约/基线劫持参数。实际操作数据库前仍须核对目标实例、资源准入及恢复边界。

本次初始结构压平到 `20261101000000_initial_baseline.php` 和 `schema/baseline-schema.sql`。官方账本默认 `pa_migrations`，记录 timestamp version/name/时间，不提供旧自研 checksum/coverage 状态。初始基线不可逆，rollback 明确失败；重装只能由 owner 另行指定空库，代码不自动清库。后续变更新增原生 PHP 迁移，不修改已经应用的版本。

安装业务保留空库及重复拒绝、动态租户/管理员、权限和模块目录、安装锁、健康与完成身份。先官方结构迁移，再初始化动态身份，然后 `seed:run --seed=InitialSeeder`。种子核实际默认租户及 owner，拒绝非空或部分完成。由于权限目录 repository 使用框架连接，native seed 初始事务提交后才同步目录，再执行后续数据事务；失败可能留下部分初始化，不能重放或声称整段原子。

模块 schema 随应用版本在主目录交付，不再使用模块 SQL 目录、backend.migrations、自研 module migration 台账或旧 proof/adoption。模块安装/升级复用原生 pending 迁移，并验证 manifest 自有表存在；不满足结构则拒绝，不能仅复制模块目录后成功。卸载保留全局原生版本历史；清除表后重新启用须通过新的应用迁移显式恢复结构。

旧数据库 baseline 清单、kernel-migrations、覆盖版本账本、schema:application 和历史 partial continuation 已删除。保留旧现场及冻结证据不代表现行程序能续跑它们，也不授权清空数据。源码升级三方 baseline、安装身份和业务幂等是独立合同，继续保留。

本次静态/入口检查不能证明真实 MySQL 安装、回滚或生产更新通过；实际运行、测试和发行资格按各自授权记录。
