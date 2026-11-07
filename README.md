# Peanut Admin Code

Peanut Admin产品源码：应用装配、官方业务模块、管理端/平台端/网站/移动端，以及创建、构建、安装和升级工具。PHP Core和Web Core只提供技术内核与公共设施。

## 使用和开发

- 部署者与APP开发者从[产品使用资料](docs/public/README.md)开始；APP自行维护Git源码、业务版本和资源，不依赖维护者私有Project。
- 二开开发者从[开发文档](docs/README.md)和[开发规范](docs/development/standard.md)进入；模块合同、源码、路由和生成API在本仓共同版本化。
- AI执行入口保留在`AGENTS.md`，工具与源码邻近的README保留在其所属目录。维护者源级工具说明见`docs/maintenance/`，不作为普通APP的安装前置。

脚手架经已配置的 `peanut release` 生成 ST/MT 模板，供 `create` / `upgrade` 使用。产品在自己的 Git `dev` 开发，审核定版后由生产采用固定 `main` 提交；首次安装与既有实例原目录更新分开。生产 Git 协调尚未完整接通，保护边界与真实缺口见[产品 Git 交付](docs/public/application-release.md)。

日常集成分支为`dev`；`main`、tag、正式发行和生产部署各需相应批准。源码版本、已发行版本、实例采用版本及实际验证是不同状态。旧版本应阅读自己的资料，当前分支不宣称全部产品能力已经验收。
