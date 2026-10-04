# Peanut Admin 运行版本范围

## 生产运行时

| 组件 | 支持范围 | 默认版本 |
|---|---|---|
| PHP | 8.3.x | Docker `php:8.3-fpm-bookworm` |
| MySQL | 8.0.36+、8.4.x | Docker `mysql:8.4` |
| Nginx | 1.24+ | Docker `nginx:1.28.0-alpine` |
| Redis | 7.x，可选 | 默认不启动 |

PHP 8.1.33 是历史业务验收使用的本地版本，但 2026 年已经不适合作为新生产环境默认值。PHP 8.2 在补充发布 CI 矩阵前不列为正式生产支持版本。

## 构建环境

| 组件 | 支持范围 |
|---|---|
| Node.js | >=22.12、<23 |
| pnpm | 9.x |
| Composer | 2.8.x |

Node.js、pnpm 和 Composer 不需要安装到生产宿主机。Docker 构建阶段使用 Node.js 22 构建管理端、PC 端和 H5。默认 `spa` 模式把完整 PC 静态产物放在 `server/public/pc`，由 Nginx 在网站根路径提供，无需 PC Node 服务；选择 `hybrid` 时须启用 Compose `ssr` profile，Nginx 将根路径交 Nuxt/Nitro，只有登记的展示页 SSR。PHP 和 Composer 运行在后端容器中。

版本范围扩大前必须在对应运行时完成一次安装、后端启动和最低充分业务 smoke。仅有 Composer 约束不代表该版本已经获得生产支持。
