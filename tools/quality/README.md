# 开发质量工具

本目录仅管理开发检查工具，不是业务运行依赖。[开发规范](../../docs/development/standard.md)是规则正文；这里说明实际命令。PHP 工具由本目录 Composer 锁维护，JavaScript 测试工具由本目录 pnpm 锁维护，不覆盖各客户端的生产依赖锁和编译配置。

## 准备

从 Code 根目录执行；使用受支持的 PHP 8.3 与 Node 22（至少 22.12）。任务的 TMPDIR、包管理器临时目录和专用缓存应放在本工作树的 `.local/tmp/<任务>`，不使用其他人的目录。

```sh
scripts/project-composer prepare
scripts/project-composer install --working-dir=tools/quality --no-interaction
corepack pnpm --dir tools/quality install --frozen-lockfile --strict-peer-dependencies
```

JavaScript 检查工具复用与 Web Core 相同的 TypeScript 5.9.3、Vitest 4.1.10 和 pnpm 10.15.0；完整依赖解析以本目录原生锁为准。它们不替换 Platform/Web/PC/uni-app 各自锁定的应用构建工具。缺依赖应明确失败，不临时改 lock、忽略 peer 冲突或使用未记录的全局版本。

## PHP 格式

PHP-CS-Fixer 固定为 3.95.27，配置仅启用 PER-CS 2.0 非风险规则。下面的路径参数换为本次实际变更文件；先检查，再按同配置修复，不为每次改动重跑全仓格式化。

```sh
php tools/quality/vendor/bin/php-cs-fixer fix --config=tools/quality/php-cs-fixer.php --path-mode=intersection --dry-run --diff server/app/Example.php
```

检查其他已授权源码仓时，显式提供 `PEANUT_FORMAT_ROOT`；不得使 Finder 越出该仓，也不格式化第三方依赖、历史发行快照、已应用迁移或故意无效的测试输入。前端使用所属工程现行 Prettier 配置，不另加一套竞争格式规则。

## 保留的可选治理代码

Platform 的可选治理生产源码进入默认严格类型检查；只将 Vitest 测试交给专用测试入口，不再排除整个可选运行时。下面三个入口分别验证生产源码真实覆盖、测试代码类型和行为：

```sh
npm --prefix platform run type:check:governance
npm --prefix platform run type:check:governance-tests
npm --prefix platform run test:governance
```

生产类型入口同时核对真实配置包含公开入口、查询/目录/审计源码及生成 API 类型；没有文件或重新排除源码必须失败。Vitest 不允许空测试成功，原有 origin/audience、刷新隔离、防非幂等重放、权限/模块可见性、修订及审计脱敏断言保留。

Core 依赖必须来自本次实际选定组合。测试依赖安装在本仓 `tools/quality`，不能以另一个维护者的 node_modules 路径作为产品使用前置；测试源码通过公开包接口调用 Core，不用内部路径或兼容别名。

## PC 请求适配器的源码维护回归

源码仓可运行 `node --test scripts/tests/pc-request-options-test.mjs`。显式设置 `PC_DEPENDENCY_ROOT` 为实际安装了 Nuxt/Nitro 依赖的 PC 工程根，`PC_WEB_CORE_ROOT` 为本次选定的 Web Core 源码根；不使用硬编码维护者目录或悄悄回退其他版本。脚本使用本目录锁定的 TypeScript，类型验证读取真实 Nitro 声明和 Core 公开源码入口；行为验证执行实际 composable 中的 fetch 适配器，网络与外部组合为显式测试替身。

该回归检查双重断言、请求方法和记录形状、查询/请求体/头保留，以及 API 错误和网络错误传播。它不是 Nuxt 整站构建、真实 SSR/浏览器或原生产品锁安装证明。此项维护测试属于源码仓，发行包不因本说明被要求附带 source-only 测试或额外的 Core 源码仓。

## 外部响应类型收窄的源码回归

从Code根执行 `node --test scripts/tests/installation-response-test.mjs`。依赖本目录锁定的TypeScript与当前web工程实际安装的Axios声明；缺依赖明确失败，不伪造声明。脚本执行真实 `web/src/api/installation.ts`，只替换Axios传输响应，检查合法状态、可选字段、数组/对象伪装、模块选项、错误传播以及一次性令牌与显式提交字段。不会执行后端安装、联网或访问数据库。

这是响应边界的严格类型和合成行为验证，不证明浏览器/后端安装或全站构建。命令文档不表示已执行通过；维护者执行状态由对应回执单独记录，不能用格式检查代替行为结果。

## uni-app 请求边界与消费者

从Code根执行 `node --test scripts/tests/uniapp-request-boundary-test.mjs`，显式设置 `UNIAPP_DEPENDENCY_ROOT` 为实际安装DCloud/Vue/Pinia的uni-app工程，`UNIAPP_WEB_CORE_ROOT` 为本次选定Web Core源码根。类型检查包含请求文件、当前全部API TypeScript文件及其真实导入；读取实际DCloud/框架声明，不创建替代声明或回退旧Core。行为执行本次请求层和选定Core的真实client/uniapp代码，仅原生网络、页面导航、提示及会话存储使用合成替身，无真实网络或支付。

检查外部响应的信封形状、合法空data、状态码、会话失效、网络/业务错误及原生请求前的数据收窄。信封校验不等于端点业务data已被验证；缺少配置和H5来源仍由Core拒绝，不能自动补地址。它不是H5/小程序整端构建、原生设备或真实后端验收。

## 通知渠道的响应与页面脚本

从Code根执行 `node --test scripts/tests/notification-channel-response-test.mjs`。使用本工程已安装的Vue公开 `vue/compiler-sfc` 入口解析现行SFC，分别以Web原生TypeScript与quality锁定TypeScript检查完整API和script setup正文；不代替模板、整站构建或浏览器检查。行为部分执行实际API模块，仅Axios传输用合成响应，核渠道声明字段、合法空密钥/脱敏哨兵、非法结构拒绝、错误传播和原保存参数，不联网、不发送短信、不修改真实配置。测试状态另据实际回执，不能因为脚本已存在就记为通过。

## Web 工具函数和设置补丁

从Code根执行 `node --test scripts/tests/web-standard-utilities-test.mjs`。复用Web与quality各自锁定的TypeScript，检查unknown收窄、原始类型与布尔返回合同、非浏览器环境、窗口参数及实际Pinia补丁行为；设置输入使用AppSettings，运行时菜单使用菜单动作。行为回归保留原生深层合并和订阅通知，非本范围的菜单请求仅使用测试替身。此项不是整站浏览器验证。

## Web 可选生产源码与独立测试

Web默认 `vue-tsc --noEmit --project web/tsconfig.json` 包含可选模块生产源码，不再排除整个optional-runtime；各模块配置只负责生产源码，测试独立进入 `web/tsconfig.optional-tests.json`。从Code根明确设置 `WEB_CORE_SOURCE_ROOT` 为选定Web Core源码根后执行：

```sh
node web/scripts/check-optional-types.mjs
node tools/quality/node_modules/vitest/vitest.mjs run --config web/vitest.optional.config.mjs
```

这两个入口覆盖file、import-export、notification、task、reference-codes、settings六个运行区域；前者拒绝空输入、生产源码重新被排除、测试文件遗漏或关闭strict，随后按quality锁的TypeScript核真实测试及导入。后者执行这些区域现行测试，显式绑定Core公开源码和同一Vue运行时，passWithNoTests=false；保留Happy DOM挂载、选择/禁用、租户清理、并发、修订冲突、秘密只写及错误状态断言。reference-codes/settings的路由测试针对package.json真正导出的contribution.ts和RuntimePage，不恢复旧/app路径及竞争装配。没有网络、数据库或真实短信/任务操作。

组件测试工具 `@vue/test-utils`、`happy-dom`及其Vue运行依赖只进入本目录原生锁，不写入产品客户端依赖锁。源码测试、虚拟DOM、开发类型映射与实际产品安装分别记录，不以这些入口成功声明默认产品Core链接已更新。

## 路由、装修编辑与原生移动适配

从Code根执行 `node --test scripts/tests/standard-remaining-boundaries-test.mjs`，显式提供 `WEB_CORE_SOURCE_ROOT` 与 `UNIAPP_DEPENDENCY_ROOT`。使用真实路由/装修API/页面脚本、实际Vue和原生DCloud声明；验证原始路由集合、合法PHP空对象表示、嵌套编辑与保存、非法响应、选择器输入及SDK参数。支付渠道仅映射到原生提供者标识，测试SDK为合成回调，不触发支付，后端仍负责确认资金终态。此项不代替模板完整渲染、真实浏览器或终端验收。

## 结果范围

类型、单元行为、构建、原生包消费与真实 HTTP/数据库/浏览器是不同证据。必要时继续运行各工程原生 build、后端行为、生成器和交付检查；仅记录实际运行的命令、退出码与源码/依赖身份。质量工具成功不表示正式发布、租户隔离或业务升级恢复已经完成。
