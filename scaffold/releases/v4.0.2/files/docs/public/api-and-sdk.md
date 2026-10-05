# API 合同与 SDK

HTTP 合同的源由实际路由清单、应用 metadata 和各模块的 `api/metadata/openapi.php` 共同组成。生成物包括：

- `server/generated/openapi.json`：OpenAPI 3.0.3 合同；
- `server/generated/api-catalog.json`：路由、负责人、授权、错误和合同完整度目录；
- `web/src/generated/openapi.d.ts`：全应用 TypeScript 类型；
- `web/src/modules/<module>/generated/openapi.ts`：模块自己的类型化调用入口。

OpenAPI 的 `info.version` 从同一源码树的 `release-versions.json#source_product_version` 生成。已安装的旧版本应阅读其发行包内合同；开发分支的最新合同不会把旧实例文档自动判为过期。

安装 `server/composer.lock` 与 `web/pnpm-lock.yaml` 的依赖后，刷新合同：

```shell
php scripts/generate-api-contracts.php
```

只检查结构化源与真实路由，不写生成物：

```shell
php scripts/generate-api-contracts.php --check
```

重新生成到隔离目录并与版本化产物比较：

```shell
./scripts/check-openapi
```

接口源变化而未刷新、缺少派生文件、模块接口删除后遗留旧 SDK，都会使检查失败。生成失败时不得继续发布旧产物，也不能只改时间戳或版本字符串冒充刷新。

模块可以保留真实动态语义的字典，例如扩展属性或配置映射。固定返回对象应列出字段、类型、nullable、枚举和说明。公开合同不得包含数据库凭据、签名密钥、完整 Webhook 请求载荷、远端响应体或内部异常文本。

OpenAPI JSON 可交给支持 OpenAPI 3.0.3 的阅读器构建可读页面；发行站点和 SDK 必须使用同一份生成物及同一产品候选。
