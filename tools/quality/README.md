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

## 统一前端格式

Prettier固定为本目录原生锁中的2.8.8；Code根 `.prettierrc.cjs` 是四端及维护脚本的唯一格式正文，Web旧入口仅转引它。使用空格、二空格缩进、LF、分号、单引号与既定Vue块缩进；不让编辑器自选另一份配置或升级formatter。Web Core使用自己仓内的同值配置，不依赖维护者绝对路径。四仓 `.editorconfig` 约束编码、换行和缩进，PHP为四空格，Project的Python为四空格。

```sh
node scripts/format-source.mjs --check
node scripts/format-source.mjs --check web/src/utils/is.ts
node scripts/format-source.mjs --write web/src/utils/is.ts
node scripts/format-source.mjs --check --root="<Web Core Git根>"
node --test scripts/tests/format-configuration-test.mjs
```

格式入口只调用锁定原生Prettier，支持Git已登记的JS/TS/Vue与样式源码，以及明确自有的package.json、composer.json、tsconfig系列、模块根module.json和VS Code配置；指定文件必须在所选仓内，空范围、缺配置、错版本及工具失败均失败。生成JSON/清单、API生成输出、锁文件、历史发行、第三方和故意无效fixture仍由原生成器或其原始字节负责，不以手工格式化改变摘要。PHP另用下节原生入口。新文件先按规则暂存后检查；本入口不自行提交、安装依赖或修改Git配置。

以Code根打开VS Code时，仓内设置为上述前端语言选择Prettier并启用保存格式化，且明确使用本目录安装版本；须已安装相应扩展，不能据设置文件声称用户编辑器已经启用。PHP自动格式化不猜测扩展，按下节命令执行。其他编辑器也必须读取仓内配置。配置测试只证明规则发现和合成样例的幂等性，不证明全仓已格式化。首次统一应先做全范围只读基线，再将纯格式归一化独立提交；以后检查受影响文件，不把全仓格式变更混入每个业务提交。统一格式减少无意义差异，不消除同一业务逻辑的并发冲突。Git hooks与远程CI是否实际执行另按真实配置记录。

JSON按配置角色纳入，不按扩展名盲扫所有机器数据。模块声明格式变化后由原工具重建受影响插件摘要、插件锁及应用清单，不手改摘要；package/Composer只允许格式差异，不升级版本或重写原生依赖锁。可运行 `node --test scripts/tests/format-json-policy-test.mjs` 验证自有配置与生成字节的边界，实际源码仍须走 `format-source.mjs --check`。

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

## uni-app 页面生命周期与原生入口

运行 `node --test uniapp/tests/*.test.mjs` 核对首页、资讯和个人中心的迟到响应隔离、隐藏/卸载清理、会话替换、加载/失败/重试以及原生页面和精确导入路径。行为测试执行真实SFC脚本、Vue和相关Pinia状态，网络、原生导航及页面生命周期触发使用明确替身；另用 `npm --prefix uniapp run type-check` 和 `npm --prefix uniapp run build:h5` 核完整原生类型/模板构建。记录实际Core来源，锁内包或开发源码映射分别说明；这些检查不等于真机、小程序、浏览器或后端验收。

## PHP 源码布局与模块边界

从Code根执行 `php scripts/check-source-layout.php --root=<Code根> --root=<PHP Core根> --root=<Web Core根>`，按Git登记的精确路径及Composer映射核生产类型声明和已知类型引用大小写；显式加载的独立升级运行时不冒充PSR-4类型。执行 `php scripts/check-module-boundaries.php` 前按准备步骤安装本目录明确锁定的nikic/php-parser；检查使用当前module.json的公开类型、表归属和模块自己的Composer源码映射，PHP AST只解析不执行业务，检查跨模块静态类型与字面表访问，不维护另一份手工归属清单。重复归属、缺失源码映射和真实违规均失败；语法树不能证明任意拼接SQL、动态类名或运行授权。

检查器自身的正反例见 `server/tests/Unit/ModuleBoundaryInventoryTest.php`，其中源文本包含副作用也不得被执行，别名不能隐藏私有类型，同一行多处违规分别计数，租户条件不使私表变成公开能力。`scripts/check-test-integrity` 另核测试是否有真实失败路径。旧TPQ历史问题队列的闭合状态不适用于当前迁移；以上检查只证明各自范围，不替代其他仍有效的框架行为、安全测试或人工代码审阅。

增加 `--include-host` 时，同一检查器还读取 `server/composer.json` 的原生 `app\\` 映射，覆盖宿主 PHP；不将模块迁移脚本算作宿主源码，也不重复扫描模块。应用组合根 `server/app/AppService.php` 的类型装配单独计数，但组合根不因此获得任意私表访问豁免；其他宿主发现的未公开类型和私表访问仍须逐项审阅，不能把原模块-only结果称为宿主已经覆盖。检查器保持语法树只读、不执行扫描源码；缺失映射或越出宿主目录不能空集合通过。

旧 TPQ 的模型路径按当前 `server/composer.json` 最长 PSR-4 前缀解析，核精确大小写、路径边界和重复映射；已公开的业务类以 `module.json` 声明识别，不仅凭 `Service` 目录名判为内部。对应正反例执行 `python3 scripts/tests/thinkphp-source-resolution-test.py`。这些修复不自动补齐历史模型/表登记，也不更新旧问题清单的验收状态；CLI 的登记失败与纯规则/框架行为通过须分别记录。

模型发现包含模块 `Model/` 下的嵌套目录；所有者自己的表访问只按当前 Composer 解析得到的精确源文件识别，不按命名空间拼接猜测路径。缺失映射不授予例外，歧义映射仍失败。执行 `python3 scripts/tests/thinkphp-ownership-registration-test.py` 验证嵌套发现、原生映射、自身与其他消费者的区别，以及登记失败时不扫描、不刷新历史问题状态。该测试只使用本工作树临时文件，不执行被扫描的 PHP 或访问数据库。

`python3 scripts/check-thinkphp-architecture --json` 在归属登记不一致时返回 `status=registration_failed`、具体 `registration_errors` 和退出码2；此时 `finding_count=null` 表示源码规则扫描尚未执行，不表示零问题。默认文本模式继续输出 `REGISTRATION_ERROR`。修正已迁移类的登记位置不自动批准新增模型的数据范围、补全所有表或关闭历史问题，现行缺项须按对应源与安全合同逐项核实。

固定源码归属检查使用 `python3 scripts/check-thinkphp-architecture --ownership-only --source-ref=<已推送的完整提交SHA> --json`，在当前检查器工作树的登记上核对该提交的原始源码；只读取模型、模块、Schema和Composer声明，不读取或刷新历史问题/例外。快照必须与Git树和原始blob一致，不能用移动分支名、export-ignore或替换对象隐去源码。退出2表示配置或登记未闭合，`business_scan=not_run`、`finding_count=null` 不是业务零问题；受限模型字面量和PHP Schema解析不证明运行授权或真实数据库。对应回归为 `python3 scripts/tests/thinkphp-current-ownership-test.py`；既有历史流程及产品资格分别处理。

## PC 原生 SSR HTTP 隔离

`pc/tests/Productization/ssr-multitenant.test.mjs` 启动明确指定的原生 hybrid Node 构建产物，与独立回环 HTTP 合成上游通信；不修改 Nitro、复制后修改依赖或替换全局 fetch。Node 使用当前工程工具链。先核候选源码、已安装 Core 和 `.output` 身份，再按 `resources/project-resources.json` 领取 `pc-ssr-qualification` 所有者、`pc-ssr-http-qualification` 门槛的独占租约，包含两个登记 listener、其端口、当前 Code worktree 和输出目录。

从 Code 根执行：

```sh
PC_SSR_PROJECT_ROOT="<已核验的原生 hybrid PC 工程绝对路径>" \
PC_SSR_LEASE_ID="<本轮有效租约>" \
PC_SSR_EVIDENCE_DIR="$PWD/.local/evidence/<本轮任务空目录>" \
TMPDIR="$PWD/.local/tmp/<本轮已有任务目录>" \
node --test pc/tests/Productization/ssr-multitenant.test.mjs
```

测试检查交替/并发相同文章 ID 的 Host 隔离、私人 Cookie/Authorization 不转发、富文本 SSR 安全输出、私有路由 CSR/禁缓存/禁索引、未信任 Host 拒绝以及 404/服务故障/配置故障的区别。合成上游按已安装 ThinkPHP 的 forwarded-host 优先合同解释域名，不能把网络连接 Host 等同于租户域名；这不证明真实反向代理可信设置已验收。

运行前租约、资源和空端口必须通过；缺构建或前置直接失败，不 skip。结束保留原始上游记录、Node 日志、结果及产物前后摘要，终止本测试自己的进程并关闭上游；调用者随后释放租约。它证明真实 Node SSR HTTP 链和原生产产物不可变，不证明 PHP、数据库、浏览器交互或完整多租户业务。

## PC 原生浏览器接管与匿名导航

`pc/tests/Productization/browser-hydration.test.mjs` 单独补真实 Chromium 的 hydration、双 Host 正文、富文本安全、匿名收藏跳转与非法文章 ID。复用输入未变且摘要一致的原生 hybrid 产物，不运行已经通过的 SSR HTTP 套件或重新构建。页面 HTML、脚本及 DOM 来自未修改的应用；仅浏览器 API 请求明确路由到内存合成 HTTP 上游，不代表 PHP/MySQL、真实登录、实际反向代理或完整 M5 通过。

领取上述 SSR 所有者/gate 的新独占租约，还须包含 `tooling=peanut-pc-browser-qualification`、本次空证据目录和任务临时目录；不能借其他模块浏览器会话。使用资源登记所列固定 Playwright 版本和已存在的 Chromium，开启浏览器 sandbox、仅创建本任务 profile，不隐式下载、连接日常浏览器或修改系统 DNS。浏览器进程局部映射两个合成 Host 到登记的回环端口，禁止访问其他业务主机。

```sh
PC_SSR_PROJECT_ROOT="<已核验的原生 hybrid PC 工程绝对路径>" \
PC_SSR_LEASE_ID="<本轮有效租约>" \
PC_BROWSER_OUTPUT_SHA256="<同一产物按测试 outputDigest 算法核验的摘要>" \
PC_BROWSER_PLAYWRIGHT_ROOT="<符合登记版本的已安装 playwright 包绝对目录>" \
PC_BROWSER_EVIDENCE_DIR="$PWD/.local/evidence/<本轮任务空目录>" \
TMPDIR="$PWD/.local/tmp/<本轮已有任务目录>" \
node --test pc/tests/Productization/browser-hydration.test.mjs
```

缺浏览器、来源摘要、租约或端口条件时失败，不 skip 或改用模拟 DOM。输出包含版本、页面错误/警告、请求、截图和产物前后摘要；测试关闭自己的 Chromium、Node与上游，调用者核对 profile/端口后释放本轮租约。后端身份和数据库验证保持独立状态，不因此重跑未知是否已执行的任务。

## 结果范围

类型、单元行为、构建、原生包消费与真实 HTTP/数据库/浏览器是不同证据。必要时继续运行各工程原生 build、后端行为、生成器和交付检查；仅记录实际运行的命令、退出码与源码/依赖身份。质量工具成功不表示正式发布、租户隔离或业务升级恢复已经完成。
