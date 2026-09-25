# Reference

This is the generated application's command, version, configuration and recovery index. Start with [Create an application and deliver a Module](/guide/application-module-lifecycle), then use [Support and issue reporting](/support) for a redacted diagnostic bundle.

## Where to look

| Need | Start here |
| --- | --- |
| Application and Module commands | `scripts/`, `server/think`, and each Module's public contract |
| Version identity | candidate manifest, application manifest, source commit/tree, Package digest and lock files |
| HTTP and configuration | `server/route/`, controllers, `server/.env.example`, and the configuration loader |
| Tenant and RBAC rules | trusted Tenant context, TenantModule governance, roles, permissions and data scopes |
| Product upgrade | the installed trusted `scripts/upgrade`, verified package, explicit instance root and recorded product plan |
| Diagnostics and support | [support guide](/support) and the root `SECURITY.md` |

Sealing and `create-app` commands are maintainer-source tools, not prerequisites shipped to every product consumer. Product consumers install dependencies, configure their own authorized instance, then use `server/database/install.php`; they do not obtain the private Project repository.

## Command index

| Command | Boundary |
| --- | --- |
| `php scripts/build-scaffold-release --version=<semver> --source-commit=<40-hex> --output=<absolute-directory>` | Seal an exact source commit as a candidate; it does not publish a Release. |
| `php scripts/create-app --name=<name> --slug=<slug> --package=<vendor/name> --target=<absolute-path> --edition=standalone\|multi-tenant --scaffold-manifest=<absolute-manifest> [--application-version=<semver>] [--profile=minimal\|standard\|full]` | Create a fresh application from that candidate. It does not open a Tenant. |
| `scripts/project-composer install --working-dir=server --no-interaction --no-scripts` | Install this application's locked PHP graph before environment setup; discover services through the framework after valid instance configuration. Never copy another application's `vendor/`. |
| `php think module:create <module.key> [--vendor=<vendor>] [--client=none\|admin-web]` | Generate a backend-only skeleton by default; explicitly select a client contribution. No invented Commands or automatic business qualification. |
| `php think module:check <module.key> [--kernel-version=<semver>] [--package=<tar>] [--sha256=<hash>]` | Run the read-only author checks; it does not mutate a database. |
| `php think module:pack <module.key> [--output=<tar>] [--signing-key-id=<id> --signing-secret-key-file=<file>]` | Produce a deterministic archive and optional Ed25519 signature. |
| `php think module:adopt-package <tar> --sha256=<hash> --signature-key-id=<id>` | Development-only source adoption for a later complete application candidate; no Runtime or Tenant mutation. |
| `php think module:install-package <tar> [--sha256=<hash>] [--signature-key-id=<id>]` | Local development/debug Runtime install in a known Standalone or multi-tenant Edition; no Tenant creation, enablement or RBAC grant. |
| `php think module:update-package <tar> [--sha256=<hash>] [--signature-key-id=<id>] [--dry-run]` | Plan or apply a strictly higher immutable Package update. |
| `php think module:disable-package <module-or-package-key>` | Disable a Package after TenantModules and dependants are stopped. |
| `php think module:uninstall-package MODULE_OR_PACKAGE_KEY [--purge] [--confirm-plan-file=PLAN_JSON --confirm-plan-digest=PLAN_SHA256]` | Preview or double-confirm retire/Purge; never edit a plan to bypass a blocker. |
| `php think ops-module:request preview\|prepare --operation=update\|retire\|purge ...` | Queue only a deployment-owned opaque update, retire or Purge task. |
| `scripts/ops-module-worker --once` | Production worker for update, retire and Purge; no initial install, disable or reactivate entry. |
| `php scripts/upgrade plan\|apply\|verify\|recover ...` | Trusted product orchestration: verify package first, protect owned files, coordinate dependencies and migrations, and enforce recovery direction. See the lifecycle guide for the two distinct environment files. |

The four Package mutation commands above use only the trusted local console context in development/debug. Platform HTTP remains Standalone-only, and production HTTP cannot upload a Package, choose a path or URL, issue a command, select a target, or supply credentials. Production update/retire/Purge still goes through opaque ops tasks. Frontend contributions still require the owning client build and deployment.

## Errors and recovery

Commands return structured output with a stable error code. Preserve Package state, maintenance state, backups and recovery pointers after failure. Correct the named precondition, then repeat the corresponding check, dry-run, preview or recovery action. Never delete source, tables, locks or migration ledgers as ad hoc recovery, and never describe source restoration as database rollback.

## Validation

Verify the exact candidate and Package identities, exercise the affected operation in an isolated application and database, and record what was not run. A filesystem-only run cannot claim database migrations or data preservation passed.