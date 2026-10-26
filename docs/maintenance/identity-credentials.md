# 本地身份凭据维护

先查当前实例的受保护凭据引用。数据库密码不是管理员密码；不得重装已有实例或伪造初始 grant binding。密码值不进入 argv、环境文件、Git、计划、结果或日志。

`identity:credential-recover` 默认 `--mode=initial`，仍只消费真实初始 binding、run-id 和 approved plan；原两个账号互异、从未登录及无历史会话等条件不变。

源码维护的 `--mode=current-existing-shared` 处理另一种明确状态：一个现存 active account/email credential 同时属于指定租户的唯一 active 内置 `core.tenant-owner` 和唯一 active `platform.bootstrap-owner`。它允许既往登录及额外租户 membership，保留账号、成员、角色、安装身份和 last_login_at。全部 membership、所涉租户和角色关系必须作为恢复目标明示绑定，不因指定一个租户而忽略同账号的其他身份。非共享、失活、锁定、过期或角色不明的目标仍拒绝，不隐式激活、解锁或另建账号。

未锁定账号的非负失败计数不阻止正常维护恢复；失败计数进入计划及业务审计前像。计数只在实际修改密码的同一事务内归零，不能先清除计数以取得准入，原失败安全事件保留。

此模式仅在干净、固定的源码运行环境使用正常原生 source admission。必须先正常采用当前 Identity canonical 内容；同一未发行开发版本的新内容通过原生 plugin upgrade/reconcile 接收，全部已 applied SQL 不重放。不得改写已发布版本。

本机 principal、真实 owner/thread/Session、源码、registry、DB endpoint/schema/UUID 和当前原生独占 DML 窗口均被绑定。租约还须覆盖 `server/private/credential-maintenance` 和 `server/private/credentials` 两个精确输出目录；不得借用读租约、DDL 计划、旧身份或旧窗口。

在 `server/private/credentials/` 准备实例 owner 所有、0600、普通单链接的单行密码文件。先不加 `--execute`：

```sh
php server/think identity:credential-recover --mode=current-existing-shared \
  --owner=<actual-owner> --thread=<actual-thread> --session=<actual-session> \
  --gate=<registered-gate> --operation-id=<new-fixed-operation> \
  --tenant-code=<verified-code> --account-id=<verified-account> --credential-id=<verified-credential> \
  --approval-ref=<actual-current-authorization> \
  --password-file=server/private/credentials/<protected-file>
```

预检生成独占创建的 0600 `<operation>.plan.json`，绑定全部 membership、租户及角色的稳定 ID、状态和修订，以及平台 operator 和角色、当前 credential/security revision、失败计数、密码文件摘要及一小时内的有效期。各关系按稳定 ID 排序，`affected_tenant_ids` 明示全部受影响租户。operation 只接受 1—64 个小写 ASCII 字母、数字、点、下划线或连字符，首位为字母或数字，不截断审计身份。账号和凭据 ID 来自当前实例核对，不能默认使用 1。指定租户仍须恰有一个 active owner membership，额外 membership 既不授予新权限，也不被删除或修改。实际执行保持同一参数、来源和窗口，增加 `--execute --plan-sha256=<returned-sha256>`；逐字节重验计划、凭据文件及原生准入，在事务锁定后复核完整目标，提交前再次重验。任何 membership、租户、角色、修订或失败计数变化都使原计划失效，不能缩小快照后沿用批准。

一次操作只改一个 credential：正常密码策略与 hash、credential/security revision 各递增一次，失败计数归零，撤销该账号全部租户及平台 session/token 和 challenge，追加两个 audience 的安全事件及指定租户和平台两端业务审计。业务审计前像保留全部身份及原失败计数，元数据和结果明示全部受影响租户；不会为其他租户伪造登录 actor。额外租户既有 membership 和角色不变。成功不等于两端登录已经验证。

写入前先持久化独占 attempt，成功后持久化 result。已有 operation 文件拒绝覆盖。事务异常不手工更新 credential；提交、输出或文件写入 UNKNOWN 时停止重试，按同一 operation_id、计划 SHA、两个审计流及实际 revision 对账，再确认当前密码可用。保留私有计划、attempt、结果和必要前态；不自动回灌旧凭据、复活会话、恢复整库或启动第二次 reset。
