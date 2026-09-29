# Core 独立发行：`main` tag 自动触发

本文是 PHP Core 与 Web Core 的维护者发行入口。它只处理核心库；后续产品发行在确认目标 Core 固定版本确实可从公共渠道取得后，另行更新产品锁、制包和验收。源码进入 `main` 是发行前的独立集成工作；实际触发发行时只推送 tag。版本、`main`、tag 与发行均须有该次任务的明确授权，本文不代替授权或产品资格。

## 本次验证结果与适用范围

2026-09-29 的独立演练使用 SemVer 预发行号，而非 npm 不接受的第四个数字段：Git tag 为 `vX.Y.Z-rc.N`，Web 六包版本为 `X.Y.Z-rc.N`。公开演练版本不可复用，也不进入正式产品锁。

| 渠道 | tag 与固定提交 | 自动结果 |
| --- | --- | --- |
| PHP Core | [`v4.0.0-rc.3`](https://github.com/peanut-business/peanut-admin-core-php/releases/tag/v4.0.0-rc.3) → `752476a811a5d16ea816d5206e3c03814a81fe6a` | [tag Action](https://github.com/peanut-business/peanut-admin-core-php/actions/runs/36563793321) 成功；Packagist `peanut-admin/core` 的同版 `source.reference` 匹配；GitHub 预发行版由工作流创建。 |
| Web Core | [`v4.0.0-rc.4`](https://github.com/peanut-business/peanut-admin-core-web/releases/tag/v4.0.0-rc.4) → `e7e00110b999d5f91ecf7b5861415810a5fcb14c` | [tag Action](https://github.com/peanut-business/peanut-admin-core-web/actions/runs/36565142424) 一次成功；六包由 npm 可信发布者自动发布，公开版本、tarball integrity、来源证明与 `rc` dist-tag 已核对；GitHub 预发行版由工作流创建。 |

此结果证明两仓当前工作流的 tag 自动发行路径；没有发布稳定 `v4.0.0`，也没有完成产品依赖锁、安装、升级或生产部署资格。

## 一次性渠道设置

### PHP Core：Packagist 自动索引

`peanut-admin/core` 的 Packagist 来源须是 `https://github.com/peanut-business/peanut-admin-core-php`。该 GitHub 仓库的 Packagist webhook 接收 `push` 事件，Payload URL 为 `https://packagist.org/api/github?username=<Packagist 用户名>`，Content Type 为 `application/json`，Secret 使用 Packagist 账户的 Safe API Token。令牌只填入 GitHub Secret 字段，不放入源码、命令输出或文档。配置后核 webhook 有效，再以 Packagist 包页面和 `https://repo.packagist.org/p2/peanut-admin/core.json` 核版本、来源仓和提交。参见 [Packagist 官方 hook 说明](https://packagist.org/about)。

### Web Core：六包各自绑定 npm 可信发布者

六包为 `@peanut-admin/client`、`@peanut-admin/vue`、`@peanut-admin/ui-vue`、`@peanut-admin/nuxt`、`@peanut-admin/uniapp`、`@peanut-admin/testing`。每个包必须已存在于公开 npm、由有写权限且启用 2FA 的账户管理，然后分别绑定 GitHub 仓库 `peanut-business/peanut-admin-core-web` 与**文件名** `release.yml`，允许直接 `npm publish`。npm 不接受给尚不存在的包预先绑定可信发布者；本次先以单独的 `4.0.0-rc.0` 手动建包，再逐包用 `npm trust github <包名> --repo peanut-business/peanut-admin-core-web --file release.yml --allow-publish --yes` 绑定并以 `npm trust list <包名>` 核对。这是一次性建包/账户设置，不是自动发行验收。具体通用操作与版本要求见 [npm 官方说明](https://docs.npmjs.com/trusted-publishers/)；不要把本次 bootstrap 当作今后的常规发包动作。

Web 工作流需 `id-token: write`、满足 npm 可信发布要求的 Node/npm 版本，以及精确匹配该 GitHub 仓库的六包 `repository.url`。发包任务不用 `NPM_TOKEN` 或占位 `NODE_AUTH_TOKEN`。预发行使用 npm `--tag rc`，稳定版才走默认 `latest`。首次建包另核 `latest`：截至本次核对，六包的 `latest` 仍错误指向 bootstrap `4.0.0-rc.0`；移除需 npm 账户二次验证，**尚未清理完成**。清理前不得用无版本的 `npm install @peanut-admin/...` 取得正式依赖。

## 发 tag 前核对

分别在 PHP Core、Web Core 仓库操作。先确认当前工作树、远端仓库、远端 `dev/main` 与拟发行源码，完成该次授权的源码集成和必要包级资格。`main` 的当前提交应包含所属仓库 `.github/workflows/release.yml`；PHP 的 `composer.json` 必须声明 `peanut-admin/core` 且不嵌入版本，Web 根清单、六包清单、包间依赖和运行版本常量都必须与目标 tag 去掉 `v` 后一致。发行渠道的 hook/可信发布者设置已核实。任何检查失败，只停受影响渠道，不给旧提交打新版本 tag。

对批准的版本，将下例 `vX.Y.Z` 换成实际 tag（单独批准的演练可用 `vX.Y.Z-rc.N`），从目标仓库执行；其中唯一向外触发发行的命令是最后的 tag push：

```sh
TAG=vX.Y.Z
git fetch origin main --tags
git cat-file -e origin/main:.github/workflows/release.yml
test -z "$(git ls-remote origin "refs/tags/$TAG")"
MAIN_COMMIT=$(git rev-parse origin/main)
test "$MAIN_COMMIT" = "$(git ls-remote origin refs/heads/main | cut -f1)"
git tag -a "$TAG" "$MAIN_COMMIT" -m "Core release $TAG"
test "$MAIN_COMMIT" = "$(git ls-remote origin refs/heads/main | cut -f1)"
git push origin "refs/tags/$TAG"
```

不得覆写已有 tag 或强推 `main`。两个 Core 可按各自已批准版本分别发行，不要求演练编号相同。

## 工作流行为与完成判据

- 两仓工作流只响应 `v*` tag；在校验前重新获取远端 tag 对象，以抵消 `actions/checkout` 在 tag 事件中把本地引用临时指向提交的行为。随后校验注释 tag 指向**当时当前** `main` 与 Actions 的提交，版本/包身份正确，再运行各自包级资格。
- PHP Core 等待 Packagist 自动索引，严格比较带 `v` 的 tag 版本、`source.url` 和 `source.reference`；全匹配后才创建 GitHub Release。预发行 tag 创建 prerelease。
- Web Core 按 `client → vue → ui-vue → nuxt → uniapp → testing` 打包发布。`npm pack` 使用 `./packages/<name>` 本地路径；发布后通过 npm 精确版本元数据核 tarball integrity，允许登记异步传播约五分钟。六包全部核对后才创建 GitHub Release。每包的公开来源证明与 `rc`/`latest` 标签应另行核对。
- Action 成功、对应 Packagist/npm 公开版本和提交/摘要一致、GitHub Release 存在，三者都成立才算渠道发行完成；只看到 tag、Action 启动或部分包公开均不算完成。

## 失败与续跑

Web 六包发行不是原子事务。若任务失败，先读该 Action 的失败步骤和已发布清单；不得手动覆写、撤销或重新发布同一个版本。若同一 tag 的 `main` 仍是原提交，工作流可重跑：它会把已经公开且 tarball integrity 完全相同的版本视为完成，继续剩余包。若 `main` 已前进，或工作流/源码修复需要新提交，旧 tag 保留原样；修复先进入 `dev`、再按独立授权同步 `main`，使用新的版本/tag 验证。公开 npm 包名与版本即使弃用也不可复用。

本次操作顺序和已修正的问题：

1. 手动建立六包 `rc.0` 的公开身份、逐包绑定 npm 可信发布者，同时配置 Packagist GitHub hook。这些是首次渠道设置。
2. 两仓 `rc.1` 的 [PHP](https://github.com/peanut-business/peanut-admin-core-php/actions/runs/36563268137) / [Web](https://github.com/peanut-business/peanut-admin-core-web/actions/runs/36563327445) 工作流被 `actions/checkout` 临时改写的本地 tag 引用误导，已改为先重新获取远端注释 tag。
3. PHP `rc.2` 发现 Packagist `version` 保留 `v` 前缀，在超时前取消并修正比较；Web `rc.2` 的 `npm pack packages/client` 被当作远端 Git 包，已改为 `./packages/client`，同时去掉占位 npm token 配置。
4. PHP `rc.3` 自动发行成功。Web `rc.3` [两次运行](https://github.com/peanut-business/peanut-admin-core-web/actions/runs/36563916223) 验证了同 tag 摘要匹配后的续发，也暴露两分钟 registry 等待不足；公开的部分 `rc.3` 包保留原样。Web `rc.4` 将等待延长后自动发行成功。

真实产品发行时重新核对实时工作流与渠道状态，不把本次演练结果当成未来版本的资格证明。
