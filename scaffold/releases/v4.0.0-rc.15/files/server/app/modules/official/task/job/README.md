# Official task execution ledger

`official.task` owns the `pa_task_job`, `pa_task_job_attempt`, and
`pa_task_job_event` tables and the local persistent worker. Core owns only the
signed async envelope and authorization revalidation protocol.

Handlers receive `JobExecution`. They must call `checkpoint()` before every
bounded batch or side effect and stop when it throws. After an external call,
they must call `assertLeaseOwned()` before recording a local result. External
effects use `jobKey` (or a stable value derived from it) as their idempotency
key. The worker also fences completion by tenant, attempt, token, and unexpired
lease; an old holder cannot report success or failure after recovery.

Only registered submission providers can build handler payloads. Public HTTP
inputs never select handler classes or private payloads.
