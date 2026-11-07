# 资格 Module 样本

本目录保存 `fixture.delivery-record`（作者、生命周期、HTTP 与 Generator 资格）和 `fixture.workflow-business`（Workflow transport 资格）的后端源码。配对 Web 样本位于 `web/tests/fixtures/modules/`。

这些样本由 Composer `autoload-dev` 提供命名空间，生产 autoload 和 workspace 不挂载它们，模板交付排除两个样本存储目录。需要原生 Module 布局的资格调用者须使用自己的隔离临时 APP，将选定后端复制到 `server/app/modules/fixture/<name>`、Web 复制到 `web/src/modules/fixture-<name>`，再执行既有 Module 作者、安装或生命周期入口。清单前端路径表示这个目标布局，不表示本目录可直接作为生产模块注册。

Generator 资格通过 `server/tests/Support/GeneratorFixtureApplication.php` 为当前测试的独立临时 APP 准备输入，并显式传递 `sourceRoot`；从不向当前 Code 的 `server/app/modules` 或 `web/src/modules` 写回样本。该 helper 仅复制输入，不安装模块、不连接数据库、不应用生成预览。
