# Workflow development baseline

The development module requires the fixed Core source capability that validates selected `optional_dependencies`. The published Core v5.0.1 source does not yet provide that capability; a local development source must have its real Git identity and independent development lock, without changing the public package identity. Unqualified generated applications must not be described as compatible with the published lock.

Service-task definitions require an available `official.task` module and a registered trusted publisher. Notification definitions require the actual Notification producer and Task capabilities; absent capabilities reject publication. No replacement notification implementation is supplied here. Artifact revisions are accessed through the selected scenario's exported query contract.

The optional `fixture.workflow-business` package contributes only the `receipt` scenario and requires Task for its receipt worker. It is excluded from generated product applications; selecting it in a development source does not enable a tenant or create data.

This baseline includes runtime and design API routing, managed definitions, native schema declarations and upgrade preflight. Existing databases are not migrated by source integration. Source checks and a client build do not qualify business execution, installation, upgrade or production use.

Before replacing Notification execution source in an existing APP, the native upgrader requires a fresh `module:check official.notification --runtime-data` report from that APP. Missing query support, unavailable state or a pending legacy outbox rejects the switch before managed files change. The maintenance owner must first establish the existing exclusive runtime-safe window; this read-only guard does not stop services or drain jobs. The later migration guard is an additional check. No historical actor/session rows are synthesized and no compatibility publisher is supplied.
