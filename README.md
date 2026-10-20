# Peanut Admin Code

Peanut Admin产品源码：应用装配、官方业务模块、管理端/平台端/网站/移动端，以及创建、构建、安装和升级工具。PHP Core和Web Core只提供技术内核与公共设施。

## 使用和开发

- 部署者与APP开发者从[产品使用资料](docs/public/README.md)开始；APP自行维护Git源码、业务版本和资源，不依赖维护者私有Project。
- 二开开发者从[开发文档](docs/README.md)和[开发规范](docs/development/standard.md)进入；模块合同、源码、路由和生成API在本仓共同版本化。
- AI执行入口保留在`AGENTS.md`，工具与源码邻近的README保留在其所属目录。维护者源级工具说明见`docs/maintenance/`，不作为普通APP的安装前置。

普通APP调用`scripts/package-release.sh`默认产生server-only部署包，后端代码与浏览器构建产物在`server/`内。APP完整开发源码由其Git仓维护，不再默认另制完整源码包。脚手架模板包是创建/上游升级的输入，不与APP部署包混用。

日常集成分支为`dev`；`main`、tag、正式发行和生产部署各需相应批准。源码版本、已发行版本、实例采用版本及实际验证是不同状态。旧版本应阅读自己的资料，当前分支不宣称全部产品能力已经验收。
