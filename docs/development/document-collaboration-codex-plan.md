# 037 → 039 标准文档、多格式资源与协同：Codex 实施任务书

- 状态：**目标任务书，未执行**；2026-10-12。详细公开合同以 [标准文档与协同专题](../architecture/document-collaboration-standard.md) 为唯一技术正文，本任务书仅规定实际差距、顺序、交付和验收。
- 起点核验：Code 先前核对过 `4d3532fa`，升级器合并后采用 `0a0a5572` 作为本轮 039 文档的起始基线；Project 资源方案已确认于 `c087cf53`。任何实施均需重新固定当时合法 HEAD、工作树/Job 和实际输入；上述提交不代表未来可执行版本。
- 停止线：**本任务书并不授权**测试、安装/迁移真实数据、生产、外部资源操作、部署、发行、main/tag、破坏其他 worktree；这些动作需按项目现行规则及当前用户授权单独办理。设计文件在交接包中≠已进入仓库。

## A. 差距归类与落地责任

| 能力 | 现有可信入口/实际状况 | 处理 | 主要归属 |
| --- | --- | --- | --- |
| 富文本结构/组件 | `server/app/modules/official/rich_text`，`web/src/modules/official-rich-text`；Tiptap、Yjs、客户端 HocuspocusProvider，`peanut.richtext/1` | **复用/改造**，保持既有公开组件入口，拆分编辑控件与业务文档管理 | Code |
| 单人 CRUD / 软删 | `pa_rich_text_document` 及 `RichTextDocumentService`；单表 JSON+CRDT blob+revision | **保留兼容迁移**，新增正式文档模型/所有权，不改写历史迁移 | Code |
| 连接短票据 | `RichTextDocumentService::collaboration` HMAC/300s/read-write；没有完成 Node 端事实核验 | **替换并加固**业务准入、服务认证、时限、重放、撤权 | Code |
| 二进制资源/素材 | `official.file` 的 `file_key`、现有 `FileAssetSelector.vue` 等 | **复用/补适配**；权限/引用/CDN 按 Project 资源方案实现，不在 037 再建一套 | Code |
| 标准业务文档 | 尚无 `official.document` 正式业务归属/版本/归档/保存屏障 | **新建**，业务对象唯一 owner/多个 slot | Code |
| PHP 协同边界 | 未见标准 Node↔PHP authorize/load/store/revoke/barrier 及可靠持久化合同 | **新建** `official.collaboration` 受限用例/服务入口 | Code |
| Node 服务 | 未见完整 Hocuspocus Server、容器镜像/健康/持久化实现 | **新建可选服务**，默认不启用 | Code |
| 双 Edition、APP 升级、CLI | 已有 Compose、独立 CLI create/upgrade、standalone/multi-tenant 分离 | **扩展复用**可选 profile/服务计划/诊断；不能复制升级引擎 | Code + CLI |
| 多格式展示/编辑适配 | 仅有富文本和局部文件选择/媒体展示，缺 MIME→编辑器/预览器统一合同 | **新增 B3**：内容类型、能力注册/查询、文件型业务文档绑定、格式分发及安全降级；Office 在线引擎按可选能力单独接入 | Code Document/File + Vue |
| 高级 ACL、跨租户共同编辑、Office 专用协同引擎、Redis 多节点、离线合并 | 尚无 V1 必需业务场景 | 后置，但 **多格式处理注册合同并不后置**；详见 [适配标准](../architecture/content-surface-and-format-adapters.md) | 后续立项 |

## B. 分阶段实施图（编号和前置依赖为实施入口）

以下每张任务卡包含代码写集，精确类名与 migration 文件名以仓库现行格式由实施者固定，禁止直接覆盖现有并行任务。各任务按一个独立能力批次实施，不为每个文件反复全站构建。

| ID | 任务与范围 | 直接前置 | 主要写集（候选） | 完成标准与最小证据 |
| --- | --- | --- | --- | --- |
| G0 | **执行隔离与接口基线**：核 037 /资源设计、仓库 origin/HEAD/状态、已存在 Jobs/worktrees、消费者/API/迁移、锁定的 Tiptap/Yjs/Hocuspocus；完成规范文档入仓且无竞争正文 | 当前任务，工程硬门槛解除 | 先只读；批准后 `docs/architecture/document-collaboration-standard.md`、本任务书及 docs 索引 | 记录差距矩阵、原始 HEAD/锁、已有调用者；文档链接/合同一致，工作树未越权新增 |
| B1 | **文档业务模型**：`official.document` 模块登记、富文本/文件型内容 `content_kind`、文档/状态/文件资源绑定/冻结版本/引用表增量迁移、`DocumentOwnerPolicy`、只读 Query/受控 Commands；tenant/owner/slot 一致性 | G0 | `server/app/modules/official/document/**`、相应测试、模块清单 | 归属、类型、唯一 slot、隔离及授权拒绝；不改历史 `pa_rich_text_document` 迁移 |
| B2 | **正文与文件合同**：保留 `RichTextEditor` 导出，定义 ProseMirror/Yjs/JSON 单一权威边界；素材 `file_key` 节点/引用索引、File preview/grant 与选择器适配 | G0，需和 B1 对齐公开 DTO/owner | `web/src/modules/official-rich-text/**`、`web/src/modules/official-file/**`、`server/app/modules/official/file/**`（不改资源批量可见性主任务） | 同文档/资源权限区别、无持久 URL、恶意粘贴/URL 拒绝；既有消费者可正常加载；资源权限工作线写集不得冲突 |
| B3 | **多格式编辑/预览能力**：`content_kind`、服务端可信 MIME 与 `file_key`、`DocumentViewer`/`DocumentEditor` 分发、Office/PDF/图像/音视频适配注册、安全降级和文件型固定版本 | G0；与 B1 固定 DTO 和迁移范围 | Code `official.document`/`official.file`/Vue；与 B1/B2 重叠文件不得并行写 | 没有真实引擎时不返回编辑可用；显示/编辑/下载各核授权；不把 Office 塞进 Yjs |
| C1 | **内部安全协议**：`official.collaboration` PHP 内网 authorize/check/load/store/barrier/revoke，服务鉴权/防重/幂等 CAS；绑定业务 owner 策略和模块/tenant 状态 | B1 完整公开合同 | `server/app/modules/official/collaboration/**`，必要 `official.document` 公共接口、小范围 `server/config/**` | 伪票据/跨租户/失权/重放/错误凭据拒绝；写入授权复核与单活租约；不能接触公开 HTTP 旁路 |
| C2 | **Hocuspocus Node 服务**：锁定 `@hocuspocus/server` 与 `@hocuspocus/provider` 兼容版本；Room、hooks、PHP client、重试背压、存储反馈、健康、退出/drain | C1 内部协议与 B1 二进制格式确定 | `services/collaboration/**`，服务协议类型/受限日志 | 两人编辑/断线重连/宕机恢复；Node 不连业务 DB、不漏 Secret；仅单实例拓扑 |
| D1 | **自动保存/归档/冻结版本**：CAS、幂等 `store_id`、`state_seq`/业务 `revision_no`，暂停写屏障、不可变送审/发布版本、归档联动/恢复 | B1、C1、C2（先可使用服务替身做单元验证） | `server/app/modules/official/document/**`、`official/collaboration/**`、owner 业务模块的受控接入代码 | 成功确认=持久化；重复提交不多版本；锁/撤权竞争安全；业务归档不删文件 |
| D2 | **Vue 业务封装**：`DocumentEditor`/`DocumentViewer` 多格式分发、文件选择/动态解析、错误/未保存/撤权 UI，保留单人模式 | B1、B2、B3、C2（无 C2 可先做静态路径） | `web/src/modules/official-document/**`、`official-rich-text/**`、依赖业务调用页面 | 组件 props/emits、类型/SDK、订阅销毁，身份切换/撤权/重连与数据竞态；只读和编辑权限分别验收 |
| E1 | **APP Docker/Edition 交付**：可选 Node image、Compose profile/受管 env、代理 WSS、内部 API 隔离、健康/日志/资源、升级兼容/回退预案 | C2、C1；G0 交付 baseline | `server/docker/**`、`deploy/**`、`scaffold` 当前可变模板/manifest 生成源、交付说明 | 默认不启 Node，启用后受控服务连接；单多租户分别隔离；容器无直接公网服务端口；不改固定发行快照 |
| E2 | **Peanut CLI 编排**：`service collaboration plan/enable/status/disable`，消费 Code 原生 profile/生命周期入口；CLI 测试和向导说明 | E1 原生配置协议；G0 单/多形态选择事实 | **独立** `peanut-cli/lib/**`、`bin/**`、`test/**`、README | plan 只读，enable 不暗中启动/部署，status 不猜健康，disable drain/保留数据；与 `peanut create/upgrade` 已有版本/Edition 兼容 |
| F1 | **业务示范与旧数据迁移**：选择一个用户已批准的业务消费模块演示（`official.article` 可候选但不自动更改发布流程）；迁移旧独立 rich-text 数据、未知 owner 隔离、旧 API 兼容期 | D1、D2、B2 | 选定业务模块、分步迁移、升级脚本/兼容层、升级说明 | 一对象多文档，一个文档单 owner，归档可读/不可改，旧消费者行为清晰；备份/幂等/回滚边界明确 |
| Q1 | **聚焦集成与资格证据**：先静态/契约、再 PHP+MySQL、Node+WebSocket 双人、多租户、Docker/CLI、升级；逐条件复用旧有效证据 | 已获相应测试/环境授权，B1—F1 就绪 | 所属仓库原生测试、QA 回执与受限治理文档 | 场景矩阵全部真实通过方能称 V1 可用；不拿 mock 代替后端 auth/保存/隔离；正式发行/生产单独 Gate |

### B.1 独立并行条件

可在主控单一集成者下，最多两个无冲突的 **Codex 工程任务**进行有限并行：

- **G0** 必须先完成。**B1**（后端文档模型）与 **B2-UI 部分**（只写富文本类型/UI）可并行，但业务 DTO 和 `file_key` 节点契约先固定；B2 如涉及 `official.file` 写入，则应等现有资源可见性任务写集空闲并核冲突。
- **B3** 的格式适配注册和只读 UI 可在 G0 后并行准备，但文件绑定迁移、Doc Query 与 B1 的共享代码由单一 owner 集成；Office 服务实现必须单独验收版本、格式保真和授权，不能列为富文本协作通过。
- **C1**（PHP 内部授权/持久化）需 B1；**C2** 的 Node 服务骨架、锁与健康可与 C1 并行做无副作用准备，但连接真实 PHP 的部分须在 C1 合同冻结后集成；**D2** 的静态业务组件可与 C1 的内部端点并行，不能在 API 确定前假造运行成功。
- **E1**（部署设计/模板准备）与 **D1/D2** 可并行做不冲突的文件级准备，前提 C2 协议/版本已选定；**E2** 仅在 E1 原生服务计划/生命周期返回契约冻结后开始，CLI 不得先编造运行入口。
- **Q1** 聚焦合同测试可以随各批按当前授权增量执行；真实双人/DB/Compose/Edition/升级只在集成关口运行。禁止两名执行者同时修改同一主 dev、同一 SQL/manifest/lock/服务端接口，不复用不同任务的可变资源。

### B.2 集成主线（一次集成一批）

`G0 → (B1 + B2非重叠项 + B3格式接口) → C1 → C2 → (D1 + D2/B3集成) → E1 → E2 → F1 → Q1`。

每次集成先检查源仓 `dev` 是否前进、现有 worktree owner、Git 依赖/冲突；在合规隔离场地验证差异，固定真实来源/回执，按现有仓库交付规则正常合入并推送 `dev`，不要随意清理陌生 worktree、强推 `main` 或绕过发行 Gate。`official.file` 的资源句柄/私有化设计已有独立权威文档和工作线，**仅引用或在双方接口稳定后对接**，不能让 037 并发重写其批量权限实现。

## C. 接口/权限/状态的最低测试清单（Codex 实施时落为原生测试）

1. Document：`(tenant, owner, slot)` 唯一；业务 owner 缺失/软删/归档、同名异租户、跨模块注册、role deny 优先、冻结版本引用不可变、审计主体分离；请求伪造 owner/tenant 拒绝。
2. PHP 协同：短票据到期、签名/HMAC/nonce/replay、防伪 room、安全域、租户/租户开通与账号失权；PHP 服务身份丢失/断线即拒绝新写；已连用户在撤权后不能继续写入/新广播。
3. 保存：两个客户端交错操作、全量 Yjs 二进制读取、每次成功 `state_seq`+1、同 store_id 幂等、摘要不匹配拒绝、CAS 冲突、Node 宕机及旧 lease_epoch 拒绝、网络未知结果查询恢复、backpressure/断电后状态提示。
4. 版本/归档：保存屏障成功才可冻结/归档，超时不得标为 completed；发布/审批引用 revisionId 而非 mutable current；业务归档同步阻断 WebSocket；恢复受新身份/业务状态约束，不删除共享文件。
5. File/Vue：`file_key` 引用正确且不存 URL、预览与原件下载/管理区别、受限文件不可被文档链接套取；组件切租户丢弃旧响应；富文本 HTML/URL 消毒；原富文本示例和已有消费者兼容。
6. Edition/部署/升级：不启用时旧 Compose 健康不变；Standalone 默认隐藏租户但后端 tenant-bound；Multi-tenant 相同业务数字 ID 不交叉；WSS 代理受控，Node 不直连公网/PHP DB；CLI `plan/status` 真实状态/Edition/版本绑定，禁用或升级后数据存在。

最低证据是明确运行的退出码、测试数/断言和源身份；**文档/计划完成不等于这些用例已存在或运行**。工作树和 CI 授权受当前项目治理与任务协议限制。

## D. 实施前阻塞清单及恢复路径

历史工作树一度 `temporary=13` 超额，随后已按规则收敛。2026-10-12 本批核验：升级器 `b2685f8f` 已合入 Code `dev`，最近工作树审计为 1 个干净已并入 `dev` 的历史现场；现阶段可采用受保护的独立工作树落档。以后以实时 HEAD/审计/Job/owner 为准，不复制旧阻断结论。

039 作为 037 后续主控：设计期不再按每个模块另开对话；实现期先锁接口与写集，最多两个不写同一文件/可变资源的执行任务。`official.file` 的原资源可见性工作线和 038 工作流各保留权威 owner。文档落档不代表 B1—Q1 已实现。
