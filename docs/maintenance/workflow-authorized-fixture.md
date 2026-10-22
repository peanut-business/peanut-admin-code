# Authorized Workflow identity fixture consumer

`scripts/workflow-authorized-fixture.py` executes a bounded ordinary API chain for
the human-node Workflow consumers. It creates new namespaced roles, data policies,
departments and accounts/members, verifies nonRoot logins and binds department
leaders. It returns the real canonical role keys, department codes and member IDs
for the downstream designer/runtime consumer. It does not publish a graph, start
an instance, freeze a candidate collection, enqueue/drain a worker or claim a
Workflow business assertion.

The existing `scripts/tests/installed-business-http.py` `Session.request` owns HTTP
transport and normal JSON envelope handling. This consumer does not execute that
script's installed-APP main chain. The historic private Workflow drivers are not
used: their Root identities, old schema, exclusive windows and prior budgets are
not authorization for this operation.

## Runtime entry

After actual independent protection/review, normal native resource admission and
an explicit allocation from the original task's remaining budget:

```sh
python3 /absolute/fixed/runtime/scripts/workflow-authorized-fixture.py run \
  --input /absolute/private/runtime-input.json --input-sha256 <exact-sha256>
```

There is no output-directory, force, reset, seed, mock, automatic retry, grant or
worker option. `runtime_root` must be this executable's checkout, with clean Git
and the exact admitted `runtime_commit`. The runtime checkout must have normally
adopted the reviewed source and its affected canonical manifests. Do not execute
from a source-only candidate with stale inventory.

Every HTTP request first verifies the native `project-resource-lease show --lease
... --verify-locks`, owner/thread/source/worktree/gate/expiration, registry and
exact declared resources. Inserts require complete whole-table scope for native
unique checks and side effects; a namespace is not row isolation. The script
checks a minimum Identity mutation closure, including session/audit/revocation
tables. The runtime owner and independent reviewer must additionally cover all
actual current reads, writes, locking reads, FK/catalog/config/file effects. This
execution declaration is not a SQL sandbox or a replacement for native refusal.
Do not fabricate aliases, use Root or change the schema/network to obtain a grant.

## Immutable input

All input, credentials and review/allocation files are canonical regular files
owned by the executing OS user, mode `0600`, one link. Evidence references use
`{"path": "/absolute/file", "sha256": "..."}`. Credentials are private JSON
objects keyed by alias, with `email` and `password`; token, password and cookie
values are never persisted by this consumer. New account emails start with the
namespace and have distinct strong initial passwords. Existing global credential
reuse is rejected by the normal `MemberAdminService` initial-password contract;
there is no enable, password-reset or existing-member mutation path.

The input has these fields:

- `schema: "workflow-authorized-fixture/v1"`, `execute: true`, `namespace` (8–48
  lowercase canonical characters), actual `owner`, `thread`, independent
  `reviewer`, `runtime_root`, `runtime_commit`, `driver_sha256`,
  `http_driver_sha256`, `lease`, `gate`, `registry`, `resources`, `credentials`,
  `authority`, `tenants`.
- `resources` is the exact native list of `[type,value]` pairs. It includes the
  registered `port=20180`, registry SHA, the fixed private ledger parent as
  `output-dir`, and one native `mysql-schema` plus its complete transaction
  `mysql-access` JSON. Use actual table identities; never fabricate a missing table
  registration. The entire batch must be conflict-free under native admission.
- Each of at most two `tenants` has actual `id`, `code`, registered `host`, an
  existing delegated nonRoot `operator_member_id`/`operator_account_id` and its
  `operator_credentials` alias. The consumer checks actual login context and
  `/adminapi/user/info.root == 0`; an owner/root login is not a substitute.
- Each tenant has 1–8 `roles`, each `{alias, permissions, policies}`. Policies are
  `{resource_key, operation, condition_key}` for registered
  `fixture.workflow-business.*` resources and `core.self`, `core.tenant_all` or
  `core.own_department`. The ordinary server catalog/authorization decides whether
  each operation/condition is actually allowed. No role is assigned by display
  name or a guessed built-in key: `/role/permissions` returns the canonical key.
- Each tenant has 1–4 `departments`, each `{alias, leader_actor}` (nullable actor
  alias). Code/name use the admitted namespace plus alias, not built-in scene
  department keys. The normal canonical-code producer accepts them without a
  special privilege. Same-tenant leaders may serve multiple departments; primary
  department membership and department-scoped role resolution stay separate.
- Each tenant has 1–8 `actors`, each `{alias, credentials, roles, department}`.
  Aliases/roles/departments refer only to this plan. Actor aliases are globally
  unique across tenants. IDs are read back from real HTTP responses, followed by
  each new actor's login/context/nonRoot check; members 1–4 are never reused.

`authority` is a normalized immutable handoff from the actual protection/runtime
owner plus the genuine independent reviewer, not a new grant issuer. Its fields
are `approved`, `reviewer`, `owner`, `thread`, `parent_task: "EX00003"`,
`allocation_id`, `expires_at`, `scope_sha256`, `ledger`, `allocation_path`,
`allocation` (pinned reference), positive `http_limit`/`write_limit`,
`queue_consumption_allowed: false`, `existing_member_mutation_allowed: false`,
`complete_effects_reviewed: true`, and at least two `protection_evidence` references
pinning the actual native complete backup and independent protection/recovery
review. Those receipts retain their native schemas; this consumer does not invent
a backup PASS or create the approval files. Expired/missing protection is a STOP.

`scope_sha256` hashes UTF-8, sorted-key, compact JSON of exactly the input fields
`runtime_root,runtime_commit,driver_sha256,http_driver_sha256,namespace,owner,thread,
lease,gate,registry,resources,tenants,credentials`. `allocation` is the original
task's unique allocation record with `parent_task`, `allocation_id`, `ledger`,
`scope_sha256`, `http_limit`, `write_limit`. It must equal `allocation_path`, in
the ledger's existing mode-0700 private parent. A hash alone is not independent
review or authority: the normal runtime owner must obtain the actual approvals.

Every request, including login and readback, reserves one HTTP unit; every
non-GET reserves a write-attempt unit conservatively. These limits are allocated
from the original remaining budget, never a new/reset budget. Allow for fresh
operator C1 logins, bounded role/admin pagination and normal logout. An exhausted
allocation stops partial preparation; the driver never increases it.

## Durable request identity, output and reconciliation

The allocation is consumed once with an exclusive, fsynced
`<allocation_path>.consumed` marker before any HTTP. The fixed ledger is opened
exclusively; changing the output path or namespace cannot replay the same pinned
allocation. Do not remove the marker to resume. For each request an append-only,
fsynced `INTENT` records allocation/input binding, client `request_id`, exact
host/method/path, deterministic idempotency key and request SHA before opening a
socket. A response records its hash/server request ID. Returned role/member/dept
identities are separately persisted as `READBACK_BOUND` records. Errors stop with
`UNKNOWN_STOP`/`STOP_NO_REPLAY`; nothing automatically compensates or retries.

The terminal `<ledger>.result.json` contains real tenant/actor/role/department and
assignment-key mappings plus a pinned credentials reference, no credential values.
Its status is `IDENTITY_FIXTURE_READBACK_ONLY`; `workflow_instances`,
`frozen_candidates` and `jobs` remain empty. RW09 can use these inputs under its
own later legal graph/object/instance scope; RW10 still needs actual admitted job
ownership/queue isolation. Native `tenant-task:work` has no per-job selector, so
this identity output never authorizes it or old jobs 4–11.

Local read-only reconciliation does not open a socket or re-run a request:

```sh
python3 /absolute/fixed/runtime/scripts/workflow-authorized-fixture.py reconcile \
  --input /absolute/private/runtime-input.json --input-sha256 <same-sha256>
```

It lists unresolved original intents and the last durable state. The original
runtime owner must reconcile actual side effects through separately admitted
normal read APIs/native evidence before any new action. Failed requests may have
created sessions/rows; the script neither logs out all sessions nor rewrites
failure evidence. Normal success logs out only sessions it created.

## Existing prerequisite that this consumer cannot manufacture

The current ordinary APIs are sufficient for these namespaced human-graph
identity inputs. An already active **delegated nonRoot setup operator** with the
required permission-assignment/department/member capabilities must nevertheless
exist and be supplied through normal protection/admission. A current DB containing
only tenant owners and suspended/left members does not supply that actor. This is
a real runtime input gate, not permission to resurrect old members, impersonate
Root, add a special product API or call a service with a forged TenantContext.
Fixed built-in scene role keys are unnecessary here and are not manufactured.
