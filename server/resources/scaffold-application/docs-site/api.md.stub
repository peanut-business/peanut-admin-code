# HTTP API 与扩展入口

## 版本身份

`OpenAPI info.version` 来自生成该合同的源码树 `release-versions.json`，因此合同、SDK
与产品候选使用同一版本身份。应用自身的版本与来源仍以
`.peanut/application-manifest.json`、候选 manifest 和锁文件为准。旧应用应阅读其发行包
自带的合同；开发分支上的新合同不会使旧发行包内的文档自动失效。

## 可读目录

| 领域 | 典型入口 | 合同来源 |
| --- | --- | --- |
| 身份与 Tenant | `/adminapi/user/*`、`/adminapi/tenant/*` | `server/app/api/metadata/contracts/openapi.json` 与 identity 模块 |
| 业务内容 | `/api/article/*`、文件上传/投递 | article、file 模块的 `api/metadata/openapi.php` |
| 通知与配置 | `/api/sms/*`、管理配置 | notification、settings 模块的公开 metadata |
| Platform/Ops | `/platformapi/v1/ops/*` | `server/app/api/metadata/application/platform.php` |
| 安装入口 | installation routes | `server/app/api/metadata/application/installation.php` |

上表是阅读入口，不是从 Controller 注释猜出的合同。每个 method/path 必须在
`server/route/registry_source.php` 的运行时路由中存在；模块只声明自己的路径、参数、
请求体、响应和稳定业务错误，公共 envelope 与错误放在宿主合同中。

## 生成和下载

在当前仓库或包含这些工具的发行包根目录执行：

```shell
php scripts/generate-api-contracts.php --check
php scripts/generate-api-contracts.php
./scripts/check-openapi
```

第一个命令只读核对路由和合同；第二个命令从结构化 OpenAPI 源生成
`server/generated/openapi.json`、`server/generated/api-catalog.json`、模块化 TypeScript
路径/SDK 工厂和 `web/src/generated/openapi.d.ts`；最后一个命令在隔离目录重新生成并
比较版本化产物。部署方可将
`server/generated/openapi.json` 与 `server/generated/api-catalog.json` 作为下载附件，
不应把数据库、环境文件、私有 Project checkout 或 secret 放进文档站点。

接口源改变但未刷新、派生文件缺失，或接口删除后仍留有旧 SDK/目录条目时，一致性检查
会失败。生成失败后不得继续把旧产物标成最新。公开 schema 不包含签名密钥、完整
Webhook 请求载荷、远端响应体或内部异常文本；真实动态字典仍保留
`additionalProperties` 语义。

`complete`、`partial`、`route_only` 是不同扫描对象：运行时路由目录可以大于结构化
OpenAPI paths；不能用 route count 或空 schema 数量宣称全部 API 已有业务合同。生成器
会拒绝未知路由、重复 operation、未解析引用和空 schema；缺少真实参数/请求体/响应
schema 的 operation 必须保留明确的 owner gap。

## 宿主扩展

应用适配器和自有业务模块属于 app-owned。稳定扩展入口是
`server/config/peanut.php` 与 `web/src/peanut.overrides.ts`；官方模块通过自己的包
和 `plugins.lock` 管理。扩展不得直接导入其他模块的私有 Model，也不得把租户、RBAC
或会话边界降级为前端约定。
