# Changelog

All notable Peanut Admin application changes are recorded here. The application
and the two public core packages have independent version histories.

## [Unreleased]

### Changed

- Relicensed the current Peanut Admin source repository and its application
  components under Apache-2.0. Existing immutable tags and Releases keep the
  license files originally published with those artifacts.

## [4.0.0-dev] - 未发布

### Changed

- A1 将账号、组织、权限管理、设置、字典、文件、任务、通知、集成和运营业务迁入 Code 模块；保留既有数据编号和历史迁移身份。
- PHP Core 使用固定真实 Git 提交的 Composer 分支身份；Web Core 改为 client、vue、ui-vue、nuxt、uniapp、testing 六个公共包，业务页面与 SDK 由对应模块负责。
- 模块前后端源码继续分别开发；现有清单支持按模块汇集四类客户端贡献。开发者中心读取同一模块目录及生成 API 元数据。
- 新版本下载链接使用签名 token，旧 expires/signature 链接不再接受；file_key 不迁号。
- 当前为开发检查点。部分数据库、权限撤销、跨来源 Scope、HTTP、固定候选安装及合成升级资格尚未通过，不表示已发布或可投入生产。

## [3.1.0] - 2026-09-11

### Changed

- Adopted the qualified, publicly consumable Peanut Admin Core PHP and Web
  packages at the unified product version `3.1.0` across Server, Web, Platform,
  PC and UniApp.
- Moved the product root to the V2 release identity contract, separating the
  `source_product_version` from customer-owned `instance_version` while keeping
  Module versions independent.
- Prepared deterministic Standalone and Multi-tenant scaffold inputs from one
  Application source identity; final Application qualification and publication
  remain separate CR03/Release gates.

### Fixed

- Preserved customer-owned application files during managed scaffold adoption
  and added a signed-package-only, exact-path ownership adoption plan for the 25
  approved v3.0.14 shared Host paths. Adoption records the authentic old baseline
  without replacing customized bytes; real three-way conflicts remain blocked.
- Remediated the Core build-chain high advisory without weakening supply-chain,
  license, candidate identity or public compatibility gates.

### Delivery

- Core `3.1.0` source, Composer split, npm package and Packagist package are
  published and clean Registry consumers resolve their immutable identities.
- This Application line is not yet a published or qualified Consumer RC; full
  fixed-candidate P0-E remains owned by CR03.

## [3.0.14] - 2026-09-09

### Changed

- Adopted the qualified Peanut Admin Core Alpha.13 Composer and npm packages across all clients,
  including the single Core implementation of Local, Aliyun OSS, Tencent COS and Qiniu drivers.
- Defined fail-closed Module source-package publication states and content rules; all official
  Modules, including Rich Text, are discovered from `plugins.lock` for deterministic packaging.
- Converged the reviewed Gemini refactor as incremental application changes while preserving the
  ThinkPHP application boundary and isolating the rejected mechanical rewrite.

### Fixed

- Replaced temporary or privilege-dependent test paths with executable administrator, import/export,
  member upload, scaffold, SMS reservation and package crash-recovery gates.
- Rejected environment files, private keys, dependency trees, build output and workspace state from
  Module source packages.
- Preserved current-session cleanup, article lifecycle guards, file-delete compensation and storage
  provider error handling from the independent source audit.

### Delivery

- This release requires an L2 fixed-candidate P0-E qualification because it changes Core identities,
  dependency locks, authentication and Tenant-sensitive Runtime paths.

## [3.0.13] - 2026-09-06

### Added

- Added deterministic Standalone and Multi-tenant installers from one source identity, with explicit
  Edition, Schema and checksum manifests.
- Added signed same-Edition upgrade packages and a business-readable preflight/apply/verify/recover
  flow that preserves application-owned files, secrets and third-party Modules.

### Changed

- Adopted the qualified Peanut Admin Core Alpha.12 Composer and npm packages across every client.
- Demo deployment now consumes the formal Multi-tenant installer and a seed-only overlay instead of
  copying product Runtime files from a moving source checkout.

### Fixed

- Fixed initial configuration, operation-log export permissions, readiness translations and global
  action icons found by the real-browser Demo audit.

### Delivery

- This is the first correct dual-Edition installation baseline. It intentionally publishes no upgrade
  package from older releases; the next release establishes the first supported same-Edition upgrade.

## [3.0.12] - 2026-08-28

### Fixed

- Production PHP images now include the release identity and Plugin schema required by installation
  preflight and lock validation.
- `deploy-release` validates the exact candidate image and Plugin lock before target replacement,
  then reconciles locked official Plugins after migrations.
- Demo overlays now follow the current Module/service paths, include the installation preflight Host
  and strip macOS metadata from their archives.

### Delivery

- The application and scaffold candidate share version `3.0.12`; the final commit/tree and
  qualification identity remain external until the fixed-candidate qualification completes.

## [3.0.11] - 2026-08-28

### Added

- Added an explicit signed Module package v1→v2 update path, deployment-owned update/retire/Purge
  operations and a two-independent-application consumer reference flow.
- Added a public consumer task guide, compatibility/command index, redacted diagnostics workflow
  and separate ordinary/security issue paths.
- Added an eighth fixed-candidate qualification group that consumes the formal scaffold release and
  proves the complete Module lifecycle without source-private fixtures.

### Security

- Tenant suspension now fails closed across management, API, PC/H5, public content, asynchronous work
  and Tenant file delivery, while reactivation restores only valid access.

### Delivery

- The application and scaffold candidate share version `3.0.11`; the final commit/tree and qualification
  identity remain external until CR31 completes.

## [3.0.5] - 2026-08-21

### Fixed

- Demo overlays and local demo scripts now use `peanut1234`; the shorter credential is accepted
  only while `PEANUT_DEMO_MODE=enabled`, while normal account passwords retain Core policy.
- Admin login validation now matches the Core email contract through 255 characters.
- Release, `create-app`, independent application, and scaffold-upgrade documentation now describe
  the main/tag/manual Release flow and the separate application lifecycle.

## [3.0.4] - 2026-08-21

### Fixed

- Shared Admin Hosts now receive only the `member-api` default-Tenant binding; `admin-web`
  remains unbound so Tenant-owner login can select an active Tenant membership.

## [3.0.3] - 2026-08-21

### Fixed

- Multi-tenant Demo seeding now binds the registered shared Admin Hosts to the default Tenant
  for both `admin-web` and `member-api`, in addition to the Tenant A/B bindings.

## [3.0.2] - 2026-08-21

### Fixed

- Multi-tenant Demo seeding now creates both `admin-web` and `member-api` Host bindings for
  Tenant A and Tenant B, so anonymous configuration and member API resolution use the same
  published Tenant entry.

## [3.0.1] - 2026-08-21

### Fixed

- Unified the disposable Demo password as `peanut1234`. This ten-character credential is
  accepted only by the Demo overlay; normal accounts continue to use Core's stronger policy.

## [3.0.0] - 2026-08-20


### Changed

- Established the fresh-only application baseline with a single version contract,
  fixed Composer/npm lockfiles and profile-aware application tooling.
- Local development may link the adjacent Core worktree through `scripts/core-dev`;
  production builds install the pinned Registry packages with `--prefer-dist` and
  `--frozen-lockfile` and never include local links.
- Application release identity is independent from the Peanut Admin Core package
  release. Core Alpha.6 is not a prerequisite for the 3.0.0 application release;
  the scaffold release is sealed and published as a separate subsequent artifact.

### Release boundaries

- T15 (tenant global fail-closed for static/public delivery) and T16 (partial or
  multi-refund expansion) remain deferred and are not part of 3.0.0.
- The application continues to consume the fixed `0.1.0-alpha.5` Core packages
  until a later dependency update is explicitly qualified.

## [2.1.5] - 2026-08-18

### Fixed

- Closed the release-consistency gate and resealed the generated application
  scaffold from the same frozen source identity.

### Upgrade notes

- Existing `v2.0.1` and `v2.1.4` installations can upgrade directly to
  `v2.1.5`; the paired backup and strict additive migration contract remain
  required.
- `v2.1.4` remains immutable and is not rewritten.

## [2.1.4] - 2026-08-18

### Fixed

- Sealed the scaffold release artifact, source inventory and release metadata
  from one immutable source tree.

### Upgrade notes

- Existing `v2.0.1` installations can upgrade directly to `v2.1.4`; the paired
  backup and strict additive migration contract remain required.
- `v2.1.2` and `v2.1.3` remain immutable pre-release candidates and are not
  rewritten.

## [2.1.3] - 2026-08-18

### Fixed

- Synchronized the application-template inventory with the direct 2.x release
  transition registry so scaffold sealing and upgrade deployment consume the
  same immutable source identity.

### Upgrade notes

- Existing `v2.0.1` installations can upgrade directly to `v2.1.3`; the paired
  backup and strict additive migration contract remain required.
- `v2.1.2` remains an immutable pre-release candidate and is not rewritten.

## [2.1.2] - 2026-08-18

### Fixed

- The additive official Module permission ownership migration now passes the
  migration runner's strict safety gate without allowing arbitrary UPDATE
  statements.

### Upgrade notes

- Existing `v2.0.1` installations can upgrade directly to `v2.1.2`; the same
  paired backup and additive migration contract remains required.
- `v2.1.1` remains an immutable historical release and is not rewritten.

## [2.1.1] - 2026-08-18

### Fixed

- Restored the immutable 2.0.1 `init.sql` baseline checksum. The official Module
  permission ownership change remains in its additive migration, so existing 2.x
  databases can upgrade without rewriting their canonical baseline identity.

### Upgrade notes

- Existing `v2.0.1` installations must upgrade directly to `v2.1.1`; do not use
  the `v2.1.0` transition, which is rejected by the baseline checksum guard.
- Fresh `v2.1.0` installations may upgrade to `v2.1.1` without another schema
  change. Both supported paths still require the registered paired backup gate.

### Release boundaries

- This patch does not change the seven official Module Runtime contracts, publish
  new Core packages, or add DCS/SaaS capabilities.

## [2.1.0] - 2026-08-18

### Added

- File and media, notification, OAuth and channels, payment, member CRM, task
  scheduling, and import/export are now first-class official optional Modules.
- Each Module ships an immutable Plugin manifest, backend provider, owned menu and
  permission catalog, Admin contribution, dependency declaration, and TenantModule
  lifecycle contract.

### Security

- Module availability is enforced after trusted Tenant resolution for Admin,
  member, anonymous Host-bound, scheduled, worker, payment, OAuth, and official
  account callback entry points.
- Disabling a Module for one Tenant rejects new operations without granting access
  to another Tenant or disabling shared Core engines for unrelated Modules.

### Changed

- Module-owned routes moved beside their backend providers; the application route
  file remains the bootstrap entry and no longer owns those domain routes directly.
- Existing permission IDs and role grants are preserved while permission ownership
  moves from the application host to the seven official Module keys.
- Article declares its file-and-media dependency explicitly.

### Upgrade notes

- `v2.0.1` installations may move to `v2.1.0` with `scripts/deploy-release v2.1.0
  --target <target> --upgrade --apply`. Back up persistent data first; the additive
  migration updates permission ownership without replacing permission IDs or role
  grants.
- Version 2.1.0 remains fresh-only from the 2.x canonical baseline and does not add
  a 1.x-to-2.x database adoption path.

### Release boundaries

- Shared file, scheduling, task, and import/export engines remain Core
  infrastructure; the official Modules own product entry points and TenantModule
  lifecycle, not duplicate engines.
- No DCS domain Module, SaaS billing control plane, prebuilt production image, or
  new public Core package is included.

## [2.0.1] - 2026-08-18

### Added

- A registered, unattended release command deploys an immutable annotated tag to
  either the standalone production instance or the isolated multi-tenant candidate.
- The same command supports destructive fresh installation with explicit confirmation
  and preserved-data upgrades with migration and health verification.

### Fixed

- Tenant owner activation now initializes required application-owned data before the
  new owner enters the management client.
- Empty or failed user avatar images fall back to a local placeholder or user initial,
  while the accessible user menu remains usable.
- Tenant-bound management pages follow the active Tenant title, including shared-host
  Tenant switches and dedicated Tenant domains.
- Disposable multi-tenant demos provision distinct Platform, default owner, Tenant A
  and Tenant B identities without weakening normal production password policy.

### Upgrade notes

- `v2.0.0` installations may move to `v2.0.1` with `scripts/deploy-release v2.0.1
  --target <target> --upgrade --apply`. The command preserves the selected database
  and persistent files, runs additive migrations, verifies `migrate.php --current`,
  then checks the deployed version and health endpoint.
- This patch adds no migration: the canonical baseline plus three 2.0 migrations
  remain unchanged. It still does not support upgrading a 1.x database to 2.x.

### Release boundaries

- No new public Core package, prebuilt production image, DCS business module or SaaS
  commercial control plane is included.

## [2.0.0] - 2026-08-17

### Security

- External channel bindings are created within the active Tenant context and
  receive an unpredictable callback routing key when first enabled; deterministic
  fresh-install placeholders are never retained for an enabled channel.

### Changed

- Management authentication and authorization now use the native
  Account/Credential/TenantMember/RBAC model exclusively.
- Fresh installations use one canonical application Schema plus the Core
  KernelSchema; post-baseline migrations must be additive.
- Fresh installations bootstrap the initial Tenant owner from explicit
  `ADMIN_INITIAL_EMAIL` and `ADMIN_INITIAL_PASSWORD` inputs; no shared username
  or default credential is shipped.
- All shipped official capabilities are required to enforce Tenant ownership
  and isolation. Multi-tenant behavior is no longer optional compatibility.

### Removed

- The 1.x Admin/Role/Department mapping tables, default-Tenant bootstrap ledger,
  legacy Admin Runtime, and member balance mirror columns.
- In-place database and scaffold upgrades from 1.x. Version 2.0.0 installs only
  into an empty database; historical tags and Release evidence remain unchanged.

## [1.1.5] - 2026-08-15

### Fixed

- The production PHP image now includes the managed
  `server/database/seed-demo-data.php` implementation as the stable
  `peanut-seed-demo-data` command; `scripts/seed-demo-data` remains a
  source-level compatibility wrapper.
- Demo seeding uses unique native PDO placeholders for every prepared statement,
  including separate insert and update timestamps and balance mirrors.

### Changed

- Scaffold `v1.1.9` adds the demo seeder implementation to the managed upgrade
  boundary, so applications created before the demo patch receive it during
  scaffold upgrade without replacing an app-owned compatibility wrapper.
- The release keeps 54 migrations and the existing public Core package versions;
  no prebuilt production image or new public package is published.

## [1.1.4] - 2026-08-15

### Added

- Production demo instances can opt into deterministic, tenant-scoped sample
  categories, articles, member tags and synthetic members with an idempotent
  seed command.
- Workbench support cards show and link the registered documentation and
  support addresses.

### Fixed

- The login page no longer repeats the product name beside the logo.
- Production resource registration now records the documentation domain and
  the Keychain-backed administrator credential reference without storing a
  secret.

## [1.1.3] - 2026-08-15

### Added

- Deterministic application creation now adopts immutable scaffold releases,
  records generation identity and preserves managed/app-owned boundaries.
- Scaffold upgrades provide preflight, apply, verify and recover workflows with
  conflict detection, application-version preservation and append-only evidence.
- Plugin and Module lifecycle support validates immutable locks, module-owned
  migrations, permissions, menus, settings and TenantModule enablement.
- Production deployment, database, application-container and paired-backup
  resources are explicitly registered with fail-closed selectors.

### Security

- Tenant Admin API routes fail closed and inherited role grants remain bounded
  by capabilities already authorized to the acting principal.
- Payment, OAuth and official-account callbacks resolve explicit Tenant-owned
  external bindings instead of falling back to a default Tenant.

### Changed

- The application migration ledger advances from 50 to 54 migrations.
- Generated applications use an independent initial version `0.1.0`; Peanut
  Admin product, scaffold and downstream application versions are distinct.
- Scaffold `v1.1.6` preserves the dual-mode administration bundles from
  `v1.1.5` and aligns the generated legal inventory with all five final locks.
- Scaffold `v1.1.7` reseals the current managed release inventory without
  changing the `v1.1.6` runtime behavior.
- The public runtime dependencies remain fixed at
  `peanut-admin/core@0.1.0-alpha.5` and `@peanut-admin/admin@0.1.0-alpha.5`.

### Upgrade notes

- Require `PEANUT_DEPLOYMENT_TARGET=production` and the registered database
  resource ID before backup, migration or deployment.
- Capture and verify bundled MySQL and `php-storage` as one pair before moving
  the migration ledger from 50 to 54 entries.
- After a new migration begins, image-only rollback is unsafe; recovery requires
  the exact paired backup under an explicit production recovery gate.

### Release boundaries

- The normative artifact is the deterministic source archive attached to the
  annotated `v1.1.3` GitHub Release.
- No prebuilt production image, new public Core package, subscription billing,
  marketplace or cross-instance operations platform is included.

## [1.1.0] - 2026-08-13

### Added

- One release now supports both `standalone` and `multi-tenant` deployment modes.
- Multi-tenant mode includes an isolated PlatformOperator control plane for
  Tenant provision, activation, suspension, first-owner creation and
  TenantModule configuration.
- Tenant sessions support explicit selection and switching, revoke the previous
  context after a switch, and reject new login and existing-session writes after
  suspension.
- Representative SQL, cache/lock, file, task, import/export, audit and diagnostic
  paths now consume trusted Tenant context and fail closed when it is absent.

### Changed

- The public runtime dependencies are uniformly locked to
  `peanut-admin/core@0.1.0-alpha.5` and
  `@peanut-admin/admin@0.1.0-alpha.5` from their public registries.
- Existing `v1.0.0` installations move forward through the migration ledger from
  28 to 50 migrations; fresh standalone and multi-tenant installs use the same
  release and schema history.
- Legacy administrators, RBAC, departments and jobs are mapped into the default
  Tenant, while Article and representative business domains use Tenant-first
  reads, writes and integrity constraints.

### Upgrade notes

- Back up the database and `php-storage` together, build the new images, run
  `php server/database/migrate.php`, and only then switch application traffic.
- Set `DEPLOYMENT_MODE` explicitly to `standalone` or `multi-tenant`. Multi-tenant
  deployments must also provide the documented platform/Tenant identifier HMAC
  keys and initial PlatformOperator inputs.

### Release boundaries

- The normative artifact is the source release at annotated tag `v1.1.0`.
- This release is a stable multi-tenant application scaffold, not a complete
  SaaS commercial control plane: subscriptions, billing, trials, invoices,
  marketplace flows and cross-instance operations remain out of scope.
- No prebuilt PHP/Nginx image or new public core-package version is part of this
  application release.

## [1.0.0] - 2026-08-11

### Added

- A fresh-clone installer with explicit initial administrator credentials and a
  SHA-256 migration ledger covering 28 ordered migrations.
- Production Docker Compose builds for the ThinkPHP backend, Vue management
  client, Nuxt PC client and UniApp H5 client.
- A single configurable Peanut Admin brand source consumed by the backend,
  management client, PC client, UniApp/H5 and the official documentation portal.
- Root proprietary license, third-party notices and an SPDX 2.3 release SBOM.

### Changed

- The management client uses Element Plus and consumes the public
  `@peanut-admin/admin` package without copying core implementation.
- Permission evaluation, transport clients and override registration use the
  two public packages `peanut-admin/core` and `@peanut-admin/admin`; internal
  domain directories are not independent packages.
- Product domains remain application-owned single implementations, including
  member/finance, content/decoration, notification, payment and OAuth/channel
  behavior.

### Release boundaries

- The normative artifact is the source release at annotated tag `v1.0.0`.
- No prebuilt PHP/Nginx image, new core-package version or SaaS implementation is
  part of this release.
- Real SMS, payment and WeChat/OAuth production availability still depends on
  deployment-specific credentials, platform registration and a low-risk smoke.

[2.0.1]: https://github.com/peanut-business/peanut-admin/releases/tag/v2.0.1
[1.1.5]: https://github.com/peanut-business/peanut-admin/releases/tag/v1.1.5
[1.1.4]: https://github.com/peanut-business/peanut-admin/releases/tag/v1.1.4
[1.1.3]: https://github.com/peanut-business/peanut-admin/releases/tag/v1.1.3
[1.1.0]: https://github.com/peanut-business/peanut-admin/releases/tag/v1.1.0
[1.0.0]: https://github.com/peanut-business/peanut-admin/releases/tag/v1.0.0
