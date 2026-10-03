# 默认导航分类与菜单定制

租户后台保留工作台独立入口，其余默认入口按真实用途进入六类：组织与权限、会员与财务、内容与资源、应用与集成、系统设置、运维与审计。平台后台保留总览，按租户运营、组织与权限、运维与审计组织原有操作入口。分类只是导航节点，不产生业务权限；页面的 URL、route name、组件注册和权限码保持原身份。

模块继续通过原有 `module.json` 引用的 `resources/menus.json` 声明菜单。租户 page 必须明确写 `parent_key`，选择产品装配提供的 `core.organization`、`core.business`、`core.content`、`core.applications`、`core.system`、`core.operations`，或本模块声明的同 scope 分类。确有高频独立入口时显式写 `parent_key: null`；遗漏字段会被同步器拒绝。禁止另建分类 manifest 或在安装后手工修改数据库。通常采用分类和页面两层；分类必须有可见页面才显示。

首次安装在正式应用迁移完成后，由 Identity 的 `MenuCatalogSynchronizer` 同步分类与模块贡献。旧实例通过同一应用迁移账本执行 `20261004-group-default-navigation.sql`，再由正常升级的 `plugin:reconcile --release-locked` 同步。无需重新初始化权限、删除菜单或更改产品版本。迁移保留旧菜单 ID、目录 key、角色权限绑定和原页面地址；重复迁移、同步不会重复创建分类。

菜单表中的 `upstream_defaults_json` 保存上游默认比较值，`menu_conflict_json` 保存冲突原因。历史记录使用提交中固定的旧默认快照比较，不把现场值当作默认。只有全部受管字段仍等于默认的节点才自动采用新默认；名称、父级、排序、图标、地址、隐藏、缓存、禁用或权限字段被定制时保留整个节点。旧目录被定制时，保留原子页位置。无法识别的记录保留现状；目录同步记录 `MENU_DEFAULT_CONFLICT`，数据库冲突列可供维护者检查。用户新增菜单不因目录发现消失而被删除或退役。

`is_show` 控制导航显示；菜单禁用及定制限制影响菜单投影。API 与静态路由授权仍由正式业务权限、模块部署、租户开通和 Scope 决定，菜单编辑本身不会赋予或撤销业务权限。前端只映射已注册的静态页面，绝不执行数据库中的任意组件路径。尚未交付静态页面的目录声明不会生成可点击入口。

正式停用/卸载和重新安装沿原模块生命周期执行；其受管状态同时更新默认比较值，以免重新安装被误判为人工定制。发布与实际下游升级仍由正常产品流程完成，源码合入不代表实例已升级。

直接验证入口：`php server/tests/Productization/MenuGroupingTest.php`（必须提供已登记、已租约授权的三个空 MySQL 8.4 schema）；`node --test scripts/tests/menu-grouping-navigation-test.mjs`。数据库入口使用现有 `RegisteredMysqlTestResource`，拒绝未知、非空或其他任务的资源，仅清理本次拥有的合成数据。
