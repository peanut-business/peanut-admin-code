# Controlled Task/Notification consumer

`task-notification-consumer` invokes one existing tenant HTTP action with the real
session token. It does not create identities, install modules, issue SQL, start a
worker, inject faults, send SMS, or retry requests. It uses Python 3.9+ standard
library only. This is an operator consumer, not an application runtime dependency.

The explicit inputs are defined in `task-notification-consumer.schema.json`.
Select its `fixture` or `admission` definition for the corresponding file. There
are no working default tenant, records, credentials, origin, PHP, or permission.
Admission must come from the actual authorized runtime owner after current native
resource/protection admission. An operator-created JSON file does not grant native
permission; its pinned SHA only prevents changing the already admitted input.

## Commands

Use absolute paths for local inputs. The result parent must already exist, and the
exact admitted result leaf must be absent. The schema is a structural contract;
the driver additionally checks hashes, source/PHP identity, expiry and protected
task identities before making any request.

```sh
python3 scripts/task-notification-consumer plan \
  --step diagnostics --fixture /approved/fixture.json \
  --admission /approved/admission.json --admission-sha256 <exact-approved-sha256>

python3 scripts/task-notification-consumer execute \
  --step diagnostics --fixture /approved/fixture.json \
  --admission /approved/admission.json --admission-sha256 <exact-approved-sha256> \
  --credentials /private/session.json
```

`plan` is entirely offline and does not create a result file. `execute` sends
exactly one request, using a `0600`, caller-owned, regular JSON credentials file
containing only `access_token`. Its token SHA must match the exact session recorded
by the real fixture owner. Existing HTTP middleware revalidates that session and
current permissions. No login, token refresh, alternate account, redirect, proxy
or automatic retry is attempted. Only loopback allows unencrypted HTTP; HTTPS uses
the standard certificate validation. The origin and tenant Host binding must be
the exact registered application route.

| Step | Existing consumer | Required current authorization |
| --- | --- | --- |
| `diagnostics` | GET `/adminapi/api/v1/workflow/instances/{instance_key}/notifications` | Workflow diagnostics read, Task jobs read, object data scope |
| `inbox` | GET `/adminapi/api/v1/notifications` | Current tenant member's own inbox |
| `mark-read` | POST `/adminapi/api/v1/notifications/{message_key}/read` | Exact own message and `If-Match: "rev-N"` |
| `recover` | POST `/adminapi/api/v1/workflow/instances/{instance_key}/notifications/recover` | Workflow diagnostics retry, Task jobs manage, current producer/object authorization |

Each invocation belongs to an original case ID and consumes one HTTP from its
already reconciled remaining budget. Use a separate admission/result identity for
each **actually authorized** step. Do not create another admission to retry an
UNKNOWN, denied, or exhausted original operation. For denial assertions explicitly
set the expected HTTP status and assert the actual structured problem code.

`recover` sends the exact original event sequence, outbox, dead job revision and
strategy. An absent outbox is allowed only with null task and `reauthorize`, using
the existing committed-fact materialization path. For a concrete outbox the job
must be known dead, never running/unknown. `protected_job_ids` must include every
retained historical task ID and is checked before even a plan is admitted. Recovery
can enqueue notification work; it does not run that work or prove delivery. Current
server checks remain authoritative if state changes after fixture preparation.

The fixture's row-scope receipt must enumerate exact tenant/principal, original
request, object/instance/event/outbox/message/task IDs, and all table-row/FK/audit/
idempotency effects of that selected HTTP action. Inputs with unknown row scope,
current source/runtime identity, protection, or native resource permission must
not be admitted. Capture the installed source hashes for the nine required paths
listed in the script, including `server/composer.lock`. The stable PHP input is an
absolute existing PHP 8.3 binary, exact binary SHA, exact version, and the normal
service owner's runtime receipt plus SHA tying the running service to that binary
and installed source. The driver neither starts PHP nor infers a service's binary
from PATH.

`assertions` is a nonempty list of JSON Pointer / exact-value pairs on the actual
response. Include exact sequence/outbox/job/message identity, current status and
relevant errors; do not assert only HTTP 200 or acceptance. A successful invocation
records `ASSERTIONS_PASSED_THIS_HTTP_ONLY`; it never upgrades a Workflow, fault,
delivery, tenant isolation, or complete business requirement to PASS. Response
content and session credentials are not written to the receipt.

The result is reserved with exclusive creation before network dispatch and fsynced
as UNKNOWN. Timeout, transport loss, failed assertions and unexpected responses
remain UNKNOWN for normal reconciliation, with the response hash/status when
available. Reusing its result path fails closed. Preserve that result; never delete
it just to execute again. This driver is not a concurrent budget allocator: the
native owner must allocate each operation exactly once under the existing budget.

## Worker stage remains deferred

No worker entry exists here. `tenant-task:work` has only a tenant selector and
recovers all expired tasks for that tenant before claiming available work. A real
worker stage therefore requires either an already admitted tenant whose entire
queued/running/expired set belongs to the exact owner, or a future normally
implemented native selector that fences tenant **and** exact task IDs/handler set,
expired recovery domain, attempt/lease ownership and maximum processing count.
The present source offers no job/handler/queue selector. Do not invent CLI flags,
queue aliases, or direct-handler dispatch to bypass this. This deferral does not
prevent separately admitted synchronous steps above.
