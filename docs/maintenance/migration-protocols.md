# ThinkPHP 原生迁移合同

宿主结构入口为 `server/database/migrations`，模块结构由各模块 manifest 声明的 `database/migrations` 提供；均使用锁定的 topthink/think-migration 官方 Service、Migrator 和 Seeder。命令为 `php server/think migrate:create <Name>`、`migrate:run`、`migrate:status`、`migrate:rollback`、`seed:create`、`seed:run`；不传旧租约/基线劫持参数。实际操作数据库前仍须核对目标实例、资源准入及恢复边界。

当前宿主初始结构为 `20261101020000_initial_scaffold_baseline.php` 和 `schema/baseline-schema.sql`；基础模块自己的初始迁移先于宿主基线运行。宿主结构只含宿主表定义，不带开发库记录、历史自研账本和 fixture 表；既有表结构采用已核实的原生 `SHOW CREATE TABLE`，去掉随数据变化的表级 AUTO_INCREMENT 计数。官方账本默认 `pa_migrations`，记录 timestamp version/name/时间，不提供旧自研 checksum/coverage 状态。初始基线不可逆，rollback 明确失败；重装只能由 owner 另行指定空库，代码不自动清库。后续结构变更新增原生 PHP 迁移。

首次安装仍要求空库。采用初始结构前，安装器按模块声明及宿主引用核对所选表，模块迁移按原生版本顺序创建自己的表，再执行宿主基线；外键检查始终由数据库执行。宿主基线先对所有已存在的宿主表逐字比较完整原生定义（只忽略表级 AUTO_INCREMENT 计数），任何差异在创建缺失宿主表前拒绝。通过后只创建缺失表，由 think-migration 记录真实执行。不得直接填充 applied version 或用 `IF NOT EXISTS` 忽略未知结构差异。DDL 或记账中断时保留实际现场，先核真实定义和账本再决定后续，不承诺任意部分失败都能直接重跑。

安装业务保留空库及重复拒绝、动态租户/管理员、权限和模块目录、安装锁、健康与完成身份。先官方结构迁移，再初始化动态身份，然后 `seed:run --seed=InitialSeeder`。种子核实际默认租户及 owner，拒绝非空或部分完成。由于权限目录 repository 使用框架连接，native seed 初始事务提交后才同步目录，再执行后续数据事务；失败可能留下部分初始化，不能重放或声称整段原子。

`schema/baseline-data.sql` 只承载首次真实租户的中性设置；File 的本地存储账号、空间和路由由其 `backend.seeds` 声明的原生 Seeder 在模块首次安装时建立。权限与菜单目录由其所属模块同步，拆出业务模块的默认数据由其模块提供。关联默认租户使用安装实际身份。默认 `PEANUT_DEMO_MODE=disabled`；正常安装不调用示例数据脚本。管理员、平台账号和安装身份由当次安装输入建立，开发库账号、域名、凭据、会话和业务记录不作为安装种子。完整开发库 dump 是独立恢复备份，不能替换这份初始数据或进入模板载荷。四类数据边界与显式示例用法见[安装说明](../public/installation.md#5-初始数据与可选示例)。

首次初始化数据仅由安装器在动态身份建立后调用 `InitialSeeder`；两个 Edition 不另建种子或伪造 applied 账本。[锁定的官方 seed runner](https://github.com/top-think/think-migration/blob/v3.1.1/src/command/seed/Run.php) 不记录种子版本，基线结构 applied 不等于种子或安装已完成，完成身份仍由安装业务管理。后续结构及必要系统数据变更使用新的原生 Migration，不能通过重跑种子覆盖已安装实例的设置、权限或身份。可选合成业务数据继续使用已有显式入口，不加入 Migration 或默认 Seeder。

模块安装/升级发现 manifest 中声明的 `backend.migrations`，复用原生 pending 迁移并验证自有表存在；不满足结构则拒绝，不能仅复制模块目录后成功。卸载保留全局原生版本历史；清除表后重新启用须通过未执行过的原生迁移提供结构，不能伪填账本。

模块 `backend.seeds` 使用既有 canonical `database/seeds` 目录和 ThinkPHP 原生 Seeder。沿官方全局类名合同，Seeder 使用含模块前缀的唯一 PascalCase 类名。正式安装在结构确认后、目录登记和激活前执行；已激活的模块、升级和目录重同步不会重跑初始种子。不新增 seed 账本；失败发生在种子提交后时，沿原失败安装入口恢复，模块种子必须幂等且保留现有值，不能清业务数据或覆盖实例配置。后续必要数据变化仍新增原生 Migration，不靠重跑初始种子升级。Task 的定时计划初始为空；Host `generator:cleanup` 未登记为租户定时执行命令，不自动预置其计划。种子只提供该模块自有的必需中性默认，不创建租户、成员、角色授权、许可证或样例业务。

旧数据库 baseline 清单、kernel-migrations、覆盖版本账本、schema:application 和历史 partial continuation 已删除。保留旧现场及冻结证据不代表现行程序能续跑它们，也不授权清空数据。源码升级三方 baseline、安装身份和业务幂等是独立合同，继续保留。

本次静态/入口检查不能证明真实 MySQL 安装、回滚或生产更新通过；实际运行、测试和发行资格按各自授权记录。
