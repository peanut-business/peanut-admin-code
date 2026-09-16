---
title: 部署与升级
description: 为同一 Edition 完成预检查、备份、冲突计划、迁移、构建、验证和恢复。
---

# 部署与升级

这页面向已经拿到正式包的应用 owner。首次安装请先看[快速开始](/getting-started)；已经部署的应用只使用与当前 Edition 相同的升级包。

[产品与实例版本](/guide/version-identity)是两个对象：产品/Core/双 Edition 共享产品号，客户 Instance 发布自己的版本，并记录源产品版本。升级产品来源不会自动改变客户业务发布号；部署时同时核对实例 Release 与产品来源，不能只比较一个通用版本数字。

> 首个正确的双 Edition Release 只建立安装基线，没有合格的旧 Edition 可以升级，因此不会提供
> 升级包。请先用该版本的安装包建立 Standalone 或 Multi-tenant 应用；从下一补丁版本开始，才按
> Release 声明的兼容范围执行本页流程。缺少升级附件时不能用完整安装包覆盖。

## 先确认升级边界

| 当前 Edition | 只能使用的升级包 | 适用范围 |
| --- | --- | --- |
| Standalone | `peanut-admin-X.Y.Z-standalone-upgrade.tar.gz` | 同一 Standalone 应用在 Release 声明的版本范围内升级 |
| Multi-tenant | `peanut-admin-X.Y.Z-multi-tenant-upgrade.tar.gz` | 同一 Multi-tenant 应用在 Release 声明的版本范围内升级 |

`X.Y.Z` 是版本占位符，正式版本和附件以 [GitHub Releases](https://github.com/peanut-business/peanut-admin-code/releases) 页面为准。完整安装包用于新建空环境，不能覆盖已有应用；另一个 Edition 的升级包也不能使用。Standalone 与 Multi-tenant 的转换不是普通版本升级，必须另行设计数据迁移、停机和回滚方案。

升级包的 manifest 会声明 Edition、源版本范围、目标版本、完整 migration chain、受管文件和恢复边界。源版本不在范围内、降级、跨大版本、Edition 不匹配或迁移链不完整时，必须停止；不要把版本号改到计划文件里绕过检查。

## 下载并校验升级包

正式升级包、外部 manifest 和 `SHA256SUMS.upgrades` 会随对应 Release 附件提供。按当前 Edition 只下载这一组文件；例如 Standalone：

```bash
gh release download vX.Y.Z \
  --repo peanut-business/peanut-admin-code \
  --pattern 'peanut-admin-X.Y.Z-standalone-upgrade.tar.gz' \
  --pattern 'peanut-admin-X.Y.Z-standalone-upgrade.tar.gz.manifest.json' \
  --pattern 'SHA256SUMS.upgrades'
```

Multi-tenant 只需把两个文件名中的 `standalone` 换成 `multi-tenant`。如果正式 Release 尚未提供对应 Edition 的附件，先停止，不要从移动分支、临时地址或另一 Edition 取得替代品。

下载后先比较摘要，再解压：

```bash
shasum -a 256 peanut-admin-X.Y.Z-standalone-upgrade.tar.gz
tar -xzf peanut-admin-X.Y.Z-standalone-upgrade.tar.gz
```

将输出与 Release 页面、同名 `.manifest.json` 的 `archive.sha256` 及 `SHA256SUMS.upgrades` 中对应行逐字比较。再确认 manifest 的 Edition、目标版本和升级包文件名一致。摘要、签名、manifest 或文件内容任一不一致，都保持现有应用不变并重新取得同一 Release 附件。用于验签的包外公钥必须来自正式维护者入口，不能信任包内自带的公钥。

## 同一 Edition 的完整升级流程

按下面顺序执行。每一步都有明确停止点；除 `apply` 和后续应用操作外，前置检查不会写入应用。

### 1. Preflight：先做只读预检查

解压升级包后，使用包内升级器：

```bash
export PEANUT_UPGRADE_TRUSTED_KEYS_JSON='{"<official-key-id>":"<base64-ed25519-public-key>"}'

php /path/to/extracted-upgrade/upgrader/scripts/scaffold-upgrade preflight \
  --project-root=/path/to/application \
  --package=/path/to/extracted-upgrade \
  --signature-key-id=<official-key-id>
```

预检查会核对当前应用的来源、Edition、源版本和目标版本、签名、checksum、依赖/Module lock、migration chain 以及文件所有权。只接受 `status=ready` 的结果，并记下它返回的唯一 plan 文件；任何 `blocked` 或 `error` 都保持应用不变并停在这里。

对于 3.x 应用，预检查还会核验当前 `plugins.lock`、能力包 manifest 以及对应的后端/前端源码根。
已安装能力包会作为完整边界保留，并以实例中经核验的现有字节建立下一版 baseline；升级包不会用
目标模板局部覆盖它。目标新增能力包或根目录拓扑不一致时会返回 `plugin_adoption_required`，需要
应用 owner 先完成独立采用再重新 preflight；不要编辑 lock、manifest 或 plan 绕过。

### 2. 备份：建立配对恢复点

在任何写入前，备份数据库、用户上传等持久文件，以及应用 owner 负责的配置和部署状态，并记录它们对应的当前版本。确认备份可被识别和恢复，再继续下一步。备份必须与这次升级使用同一个恢复点，不能用不明时间的旧副本代替。

### 3. 冲突计划：先看清会改什么

打开 preflight 生成的 plan，逐项检查：

- `will-change`：升级器将替换的 `managed` 或 `generated-managed` 文件；
- `will-preserve`：应用业务代码、第三方 Module 和秘密等明确保留的内容；
- `must-resolve`：需要 owner 决定的冲突、缺失或身份问题。

`must-resolve` 必须为零才能继续。不要手工编辑 plan 来隐藏冲突，也不要为了让 plan 通过而覆盖 app-owned 文件；无法解释的差异应交给应用 owner 处理后重新执行 preflight。

### 4. Apply：只应用同一份计划

确认备份和冲突计划后，使用同一个 `project-root`、同一个升级包和同一个 plan：

```bash
php /path/to/extracted-upgrade/upgrader/scripts/scaffold-upgrade apply \
  --project-root=/path/to/application \
  --plan=/path/to/application/.peanut/upgrades/plans/<candidate>.json
```

升级器只处理 manifest 声明的 `managed` / `generated-managed` 文件，不自动覆盖业务代码、第三方 Module 或秘密。不要将完整安装包解压到应用目录，也不要混用两个 Edition 的 plan。

### 5. Migration、依赖和构建：完成应用采用

文件 apply 成功后，按目标 Release 的锁文件安装依赖，执行完整 migration chain，再构建和重启应用：

```bash
composer install --working-dir=server --no-dev --prefer-dist
php server/database/install.php --migrate --target-version=<scaffold-template>
```

迁移目标读取应用采用的 `release-versions.json.scaffold_template`；应用自己的发布 tag 只控制应用
发布顺序，不筛选 Peanut SQL。前端和其他运行时也要按各自锁文件使用冻结安装命令；构建使用应用
部署方式的正式构建配置。不要手工删改 migration ledger，不要只替换文件而跳过数据库迁移、依赖
安装或构建。若 scaffold 目标是新大版本，按 Release 的 fresh/rebuild 方案执行，不把大版本重建
伪装成原地升级。

### 6. Verify：确认新版本真的可用

构建和重启后，至少检查：

1. 应用 manifest、Edition、目标版本和升级包身份仍一致；
2. Schema/migration ledger 已完整到目标版本，没有缺失、倒序或失败记录；
3. `app-owned`、第三方 Module 和秘密的内容仍由应用 owner 保持；
4. 服务健康、管理端登录和本次受影响的 API/页面正常。

验证未通过时，不要把“文件已经替换”写成升级成功；保留 plan、日志摘要和停止点，进入下一步恢复。

### 7. Recover：按失败类型恢复

如果 `apply` 或文件 `verify` 失败，使用同一个 plan 执行文件恢复：

```bash
php /path/to/extracted-upgrade/upgrader/scripts/scaffold-upgrade recover \
  --project-root=/path/to/application \
  --plan=/path/to/application/.peanut/upgrades/plans/<candidate>.json
```

`recover` 只恢复本次 scaffold 管理的文件，不能撤销 Composer/npm、数据库 migration、对象存储或外部服务的副作用。如果 migration、依赖、构建、启动或 smoke 失败，先停止流量切换，再按第二步建立的配对备份恢复数据库和持久文件，并由应用 owner 重新完成健康检查。不要只运行文件 `recover` 就宣称整个应用已经回滚。

## 常见停止点

| 现象 | 应用状态 | 处理 |
| --- | --- | --- |
| Release 没有对应 Edition 的正式附件，或 SHA-256/签名失败 | 未变化 | 停止；重新核对同一 Release 的附件和包外公钥 |
| Edition、源版本范围、目标版本或 migration chain 不匹配 | 未变化 | 停止；选择受支持的同 Edition 升级包，不改 manifest 或 plan |
| `must-resolve` 有冲突 | 未写入 | 由 owner 决定保留或采用，重新 preflight；不要编辑 plan 绕过 |
| Apply 或文件 verify 失败 | 可能只更新了受管文件 | 使用同一 plan 的 `recover`，再按停止点诊断 |
| Migration、依赖、构建、启动或 smoke 失败 | 可能部分采用 | 停止切流，按配对备份和恢复指针处理；不要只恢复文件 |

## 自动化升级入口

实例内的 Platform 升级中心也必须消费固定的目标和同一升级合同。浏览器不能提交路径、URL、命令、镜像、凭据或部署目标；部署 owner 负责准备目标、备份、维护窗口、迁移、构建、smoke 和恢复。自动化失败时保留已完成步骤和稳定停止点，不能静默改用其他版本。

## 验证与下一步

记录本次真实完成、未验证和恢复情况。源码 Release、资格候选、已部署实例和派生应用版本代表不同事实，详见[版本与发布](/releases)。
