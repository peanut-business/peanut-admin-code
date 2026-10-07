# Peanut 开发文档

公开开发规范的总入口是[开发规范 v1.0](development/standard.md)。以下专题补充具体工程合同，不重复维护内部审批、项目状态或运行资源。

## 开发约定

- [应用开发约定](development/application-conventions.md)
- [项目工具登记、本机准备与可选 CI](development/project-toolchain.md)
- [Controller 与命名空间](development/controller-access-and-namespaces.md)
- [开发者中心与文档](development/developer-center-and-documentation.md)
- [PHP / ThinkPHP 开发规范](development/php-thinkphp-guidelines.md)
- [当前代码导读](development/current-code-lifecycle.md)
- [Core 独立发行：main tag 自动触发](development/core-release.md)
- [质量工具与检查命令](../tools/quality/README.md)

## 架构与交付

- [总体能力地图](architecture/overview.md)
- [默认交付与职责](architecture/default-delivery-and-ownership.md)
- [Core 能力、应用复用与实现替换](architecture/core-capabilities-and-application-overrides.md)
- [框架扩展、钩子与生命周期](architecture/framework-extension-and-lifecycle.md)
- [Peanut CLI 与可选 Recipes](architecture/cli-and-recipes.md)
- [身份、组织与执行信息](architecture/identity-organization-access.md)
- [租户模块授权与 Scope](architecture/tenant-modules-and-data-scopes.md)
- [模块开发与交付](architecture/module-development-delivery.md)
- [默认导航分类与菜单定制](development/menu-navigation.md)
- [平台服务与租户配置](architecture/service-bindings.md)
- [事件、任务与异步协作](architecture/events-and-tasks.md)

API、模块清单、依赖和生成资料以源码中的结构化声明及原生生成命令为准；本文档不替代实际构建、安装或运行验证。
