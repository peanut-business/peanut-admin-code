---
title: 支持与问题提交
description: 收集版本身份、脱敏诊断包和最小复现，并把普通缺陷与安全问题提交到正确渠道。
---

# 支持与问题提交

## 先判断问题类型

- 普通缺陷、文档错误、兼容疑问和功能建议可以提交公开 GitHub Issue。
- 涉及未授权访问、Tenant 越界、身份/权限绕过、敏感信息泄露、任意文件/命令、签名或 checksum
  绕过的内容属于安全问题，不得公开提交。
- 第三方 Provider 的真实短信、邮件、支付、OAuth 或存储资格由该 Provider owner 单独处理；
  通用问题报告不能要求维护者发送真实消息、扣款或访问你的生产凭据。

## 收集版本与最小复现

公开 Issue 至少包含：

1. Peanut Admin annotated tag/GitHub Release；派生应用再附 `scripts/create-app` 输出中的 template
   version、application version 和 source commit。
2. Standalone 或 Multi-tenant、操作系统、PHP/Node 版本，以及受影响的客户端或 Module key/version。
3. 最短重现步骤、期望结果、实际结果、稳定错误码和命令退出码。
4. 是否能在全新独立应用重现；若不能，列出最小 app-owned 变更，不上传整库或私有源码。
5. 只附脱敏后的终端片段或诊断包 SHA-256。删除绝对路径、账号、Tenant 数据、Cookie、token、
   密钥、数据库地址和原始请求头。

提交入口：[创建普通 Issue](https://github.com/peanut-business/peanut-admin-code/issues/new)。先搜索已有
Issue，避免把同一稳定错误码拆成多个报告。

## 下载脱敏诊断包

具备 Platform 运维与日志权限的操作员可在 **Platform → 运行与维护** 下载最近 1 小时的 JSON
诊断包。固定 API 还只允许 60、360 或 1440 分钟窗口；不能指定文件路径、日志路径或任意查询。

下载客户端会核对响应 `X-Diagnostic-SHA256` 与实际文件；包内另有 `payload_sha256`。诊断包最多
1 MiB，并限制 Module、失败任务分组和结构化日志分组数量。它包含：

- schema/checksum、生成时间和有界窗口；
- deployment mode、debug 状态、PHP/Core 版本；
- Runtime 状态、已安装 Module 的安全投影；
- 失败任务的类型、稳定错误码、次数和最后时间；
- Platform audit 的结构化安全消息。
- 最近 Operation Log 的 Tenant、request/operation trace、路由、结果、稳定原因码和时间。

它明确排除原始日志文件/消息、凭据与 token、请求头/Cookie、绝对路径、个人信息和 Tenant
业务记录；Operation Log 的参数、操作人身份、IP 和原始 metadata 也不会进入诊断包。即使如此，
提交前仍应人工检查文件，并只把它发到与问题敏感度相符的渠道。不要把
项目资源登记、部署控制任务、恢复指针、数据库转储、`.env` 或签名私钥当成诊断附件。

## 安全问题

遵循仓库 [Security Policy](https://github.com/peanut-business/peanut-admin-code/security/policy)：

1. 如果 GitHub **Security → Report a vulnerability** 私密表单可用，通过该表单提交。
2. 如果私密表单不可用，只创建标题为 `Security contact request` 的公开联系 Issue，内容仅含
   受影响 Release 与私下联系方式。
3. 不要在联系 Issue 中写组件、攻击步骤、影响、PoC、凭据、诊断包或截图；等待维护者建立私密
   渠道后再发送。

安全测试只能针对你拥有或明确获准的系统、数据、账号和 Provider。生产数据覆盖、真实资金/
消息、破坏性未知 migration 与 Git 历史重写不属于问题复现授权。

## 维护者如何处理

维护者会先按版本身份和稳定错误码去重，再判断是代码、合同、环境、外部服务还是发布身份问题。
普通问题只要求受影响路径的一次最低充分验证；安全、Tenant、权限、Schema、数据损坏和发布身份
问题会进入对应停止线。没有真实执行的 GitHub Actions 状态不会被当作修复证据。
