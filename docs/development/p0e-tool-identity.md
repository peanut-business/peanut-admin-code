# Independent P0-E qualification tool identity

A correction to qualification tooling does not create a new product candidate.
Keep the exact product source, template archives, inventories and original
upgrade evidence unchanged. The corrected tool checkout has its own commit and
tree.

## Source and tool binding

Use the existing `--source-root`, `--qualification-tool-commit` and
`--qualification-tool-files-sha256` arguments together. Both checkouts must be
clean and match their full commit hashes. The tool commit must descend from the
candidate, and its net changes may contain only these tool files and this
document:

- `scripts/p0e-runtime-qualification`
- `scripts/consumer-module-reference-chain`
- `scripts/p0e-browser-smoke`
- `.github/workflows/scaffold-release.yml`
- `server/fixtures/module-lifecycle/run.php`
- `server/tests/Productization/ModuleLifecycleMySqlTest.php`
- `server/tests/Productization/ModuleFailureRecoveryMySqlTest.php`
- `server/tests/Productization/ModuleRuntimeGovernanceTest.php`

The tool inventory digest is SHA-256 of those paths in lexical order, each
encoded as `path + NUL + file SHA-256 + LF`. Product source, native generators,
archives, dependency locks and application identities retain their original
bindings. Tool binding does not authorize qualification, publication or an
application upgrade.

The installed Member upload and Task/ImportExport consumers are loaded from the
reviewed Module application source pinned in the qualification input.

## Existing applications and CLI creation

The consumer does not create or remove temporary applications. Candidate and
lifecycle inputs can explicitly reference an original native candidate-stage
receipt with `cli_creation: {path, sha256}`. Reuse requires the exact original
candidate/tree, CLI, template archives, producer and both Edition assertions.
An upgraded application's current manifest does not prove how it was created.

A missing creation receipt remains `not_run`. Template checks and the existing
application source-build component are recorded separately; neither can turn
the missing CLI assertion or the complete generated-application group into a
pass. A future candidate without original creation evidence still needs a
separately authorized legitimate producer target.

## Installation, source upgrade and generated outputs

Historical installation facts come directly from the application's
`InstallationExecutionHost::verifiedInstallationFacts()`. This consumes the
native receipt.v2 source and migration-chain contract without synthesizing
receipt.v1 or a private baseline. Historical installation remains distinct from
a fresh installation of the selected candidate.

The original source plan and apply/verify ledger remain immutable. Current
APP-owned files and non-generated managed files keep their byte and mode
protection. API and static outputs may change only when the separately recorded
native generated-application component binds the final inputs, producer and
outputs; absent evidence is `not_run`, never a blanket byte-check exemption.

Generated-application records the existing API generator and native frontend
build destinations, together with the source and output snapshots. The web
build uses the selected Edition and `server/public/admin`; the MT platform
build uses `server/public/platform`. PC and UniApp retain their original group
scope. These commands execute only when the qualification group is explicitly
authorized; modifying this consumer does not execute them.

## Development HTTP and production Compose

Development browser routes bind the actual registered PSM resources, access
URLs, application identity and backend/database association. One application's
routes may belong to several registered services. A missing beta, platform or
documentation route is not replaced with a fabricated route.

Production Compose still requires its own registered deployment, environment,
configuration digest, persistent mounts and database binding. PSM development
processes do not satisfy those assertions. This change does not add a browser
wrapper, install tooling, expose ports or claim compatibility with a different
browser environment.

## Incomplete qualification

All eight groups remain required for full qualification. Explicit group
selection can use the groups whose prerequisites exist; missing creation,
fresh-install, Compose or recovery evidence remains visible for its own scope.
Candidate runs with missing evidence and incomplete runtime runs return nonzero.
Failures and unknown operations retain their original evidence and retry
budget. No database reset, installation replay, ledger rewrite or automatic
recovery supplies missing evidence.

This change is delivered with static review and syntax validation. Product
qualification, runtime checks, builds and tests are separate operations.
