# Peanut Admin Code

This repository is the Peanut Admin product application source repository.

A1 将完整账号组织、权限管理、文件、任务等通用业务及各端业务页面归入本仓官方模块；PHP/Web Core 只保留技术核心和公共设施。当前实施、迁移映射和真实验收由 Project 的 `docs/development/deep-convergence-a1.md` 记录，文件移动不等于全部能力已验收。

Generated applications use the public source, lock files and documentation in this repository; they do not require a private Project checkout. Maintainer-only release governance is intentionally kept outside generated applications. The normal integration branch is `dev`.

`main` is release-only. Runtime/build manifests, lock files, source code, tests, deployment definitions, scaffold sources and distribution/legal artifacts remain in this repository.

Public consumer documentation starts at [docs/public/README.md](docs/public/README.md). It describes the current candidate channel, API/SDK refresh workflow and Module delivery boundaries without requiring maintainer-only paths.
