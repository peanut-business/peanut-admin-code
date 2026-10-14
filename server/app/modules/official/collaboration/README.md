# Document Collaboration

This optional module supplies native Hocuspocus 4.6 protocol 2 authentication,
connection authorization windows, special File reservations and batch custody.
Document owns durable content, immutable explicit save targets, versions and
committed receipts. Ordinary updates merge/broadcast before batch persistence;
a broadcast or native SyncStatus is not a database commit.

## Service authentication

The managed application configuration supplies the fixed instance/service/key,
TLS origins, secret and CA reference. No browser selects these values. Internal
calls reject ambient human/Task identity and authenticate only the service.

Transport envelope version is 2. Request HMAC-SHA256 input is UTF-8 lines
separated by LF, without a final LF:

```
peanut.collaboration.request.v2
instance_id
service_instance_id
key_id
holder_key
unix_timestamp
nonce
UPPERCASE_METHOD
exact_absolute_path_without_query
lowercase_sha256_of_exact_raw_body
```

Nonce/signature are 64 lowercase hexadecimal characters. The native unique nonce
is committed before business transactions; retries use a fresh nonce and the same
business/capture ID. Response domain is peanut.collaboration.response.v2, followed
by instance, service, key, holder, request nonce, method, path, request body SHA, response
timestamp, HTTP status and SHA of exact returned bytes. Both errors and successes
are rendered before signing. The original ServiceRequest object is process-local
proof and does not grant human authority.

The holder is the actual Node process UUIDv4, independently of stable deployment
service identity. Room/grant requests require the exact recorded holder. A new
process must explicitly recover after the previous Room and write grants expire.
Authorize returns signed RECOVERY_REQUIRED binding details only after current
ticket/full actor authorization, without consuming that ticket. Native recovery
retries the same token once and loads the actual baseline; protocol 1 requires
the explicit stopped/drained migration first. Recovery never unlocks unknown work.

## Authorization and batch persistence

Human-issued single-use tickets retain the original verified TicketIdentity.
Authorize/check revalidate the real Identity session, current function/Document
owner permission and module state. Connection grants record immutable identity,
room, epoch, revision and issued/expires milliseconds. Renewal cannot turn a read
presence check into an unverified write grant. Content state_seq is a persistence
CAS counter; it is excluded from authorization_revision.

Native beforeSync checks the cached short write grant before merging. A frozen
batch carries the exact source connection/revision and actual acceptance time,
sampled after special File authorization. Store validates those recorded windows
and signed service custody; it does not impersonate or reauthorize contributors
whose already-lawful changes are held by the service. Document/epoch/status/CAS,
projection, binary and File reference checks remain transaction-bound.

The internal routes under /adminapi/internal/collaboration/v1 are authorize,
check, load, store, receipt, references, target/prepare, target, recovery, revoke
and signed lifecycle readiness, paired_probe, activation_state and drain_state. There is no production per-update admission/journal
write or journal recovery replay. Existing journal/history tables remain intact.

Load returns only an actual locked durable baseline. Existing Yjs states lacking
a protocol 2 receipt receive a metadata-only immutable baseline receipt; no draft
rewrite occurs. Initial Yjs bytes must commit before a room joins. Store uses a
fixed capture ID and full tuple, commits content/File references/receipt together,
and returns only after the transaction commits. Receipt returns found:false or
the exact committed receipt. A new capture never replaces an unknown original.

Special newly introduced File keys require fresh original actor permission and
a real File-owned reservation before broadcast. A batch consumes only matching
source reservations whose complete key set appears in that captured projection.
Later, absent and partially captured grants remain pending until a real epoch
fence. Reservations do not grant preview URLs; only persisted Document references
can inherit normal non-sensitive preview. File retains sensitive/status checks.

## Explicit business targets and controls

An explicit save freezes click-time bytes/projection. Native saveMutex queues
target/prepare first, pinning its real current or before-broadcast File evidence,
then persists a covering capture and confirms target. The signed native service
attests delete-aware yjs.snapshotContainsUpdate/13.6.32 coverage; PHP verifies the
actual receipt, bounded hashes/projection, original actor and pinned File evidence.
Registering a target never rewrites the draft. The request ID, frozen business
fields and original member are immutable; a fresh connection of that same person
may continue them. A later autosave cannot change the explicit target.

Document version.create consumes that same request ID into an immutable version.
It does not drain/release the room or advance its epoch. Public target query
reports prepared/confirmed/consumed/canceled without exposing content or session
references. Explicit cancellation releases only that target's pin and refuses
any already-started formal operation. Pending targets and grants apply bounded
backpressure; TTLs do not erase unknown explicit operations.

Archive/rollback/recovery fence writes, persist native fixed captures where
online, and advance the actual Document/Room epoch. PHP control uses registered
HTTPS /internal/collaboration/control/v1/business-barrier with protocol 2
drain/release identity (holder_key,operation_id,input_sha256,room_key,lease_epoch). HMAC domains
are peanut.collaboration.control.request.v2 and response.v2. Their canonical order
adds holder after instance/service/key, as does the service transport. Success
data must name the same holder; CONTROL_HOLDER_CHANGED is a rejection and cannot
supply an offline drain. No SQL lock spans
network I/O. Offline drain is accepted only against PHP's real locked baseline.
Release closes old connections; rollback creates a fresh Y.Doc, never merges old
bytes into the live document.

Only restore of an actually archived Document with a closed, expired Room, expired
write grants and no pending business uses the local closed_persisted plan. The
same-PDO preparation fences its real baseline; finalization/release remain in PHP
and never claim a Node drain. It advances epoch without extending the old lease.
The next authorized join must claim its own holder through explicit recovery.

Protocol 2 crash recovery requires expired Room/all write grants and no unknown
business barrier. It preserves the actual committed baseline and exact request
receipt, advances epochs and closes old connections. Uncommitted broadcasts may
be lost on a crash. Explicit target pins remain independently queryable.

An expired closed Room with an archived Document may claim a new technical holder
only with the same no-pending-work guards. It remains closed/archived and grants
no join/edit permission. Ordinary authorization does not invoke this archived path.

The migration defaults existing rooms to protocol 1. The top-level owner command
collaboration:migrate-native --drain-id <original-id> requires old
runtime stopped/durably-drained evidence, closed expired rooms, no active
connections, unsettled journal or business barrier, and matching actual baseline.
It preserves old data/history and does not run automatically. Old CLI batch
drain commands require native B artifact adoption and currently fail closed.
The operator URL remains `/internal/collaboration/operator/v1/lifecycle`, with
direction-separated operator request/response HMAC v2 and a verified current
holder. Node starts with editor admission closed. Its signed paired probe records
one holder/probe candidate in prepared RuntimeState; same-holder probes renew the
configured process lease without repeating full schema checks. Full initial and
owner probes verify modules, schema and File dependencies. A new holder requires
the prior process/Room/write leases to expire and no unknown runtime/business work.
PHP activation uses the same immutable candidate/activation CAS. Node opens only
after it verifies signed PHP activation_state for that plan. Unknown replies reuse
the original activation identity. `/ready` reports paired prepared dependencies and
listener health; HTTP 200 does not open editor admission.

Instance begin freezes a bounded protocol-2 room manifest and closes PHP admission.
Native instance drain fences frames, joins official pending-message/loading hooks,
and commits fixed captures through saveMutex. The bounded response contains real
store metadata and a signed delete-aware coverage attestation with Snapshot hashes;
PHP validates actual locked database receipts, without claiming to execute Yjs.
Offline rooms are explicitly verified from persistent baselines. An uninitialized
offline room reports its stored projection with null Yjs store/binary/Snapshot
proof, and cannot supply a formal version commit. Unknown captures keep the original
fence/plan. The owner records durable completion before separately stopping the
registered process; an HTTP drain reply is never process-stop evidence.

The existing APP configure owner binds exact container/image/Compose labels and
readonly service mount in `begin --owner-container=<canonical-base64>`. After
no-force Compose stop, fresh inspection must confirm that same container exited
normally without OOM/paused/restarting state. Its existing managed service key
attests `peanut.collaboration.native.stop-proof.v2` over instance/service/key/holder
and the unsigned proof SHA. `stopped --stop-proof=<canonical-base64>` accepts only
that original plan/config/targets/container, at most 16 KiB, on the top-level owner
command. Evidence canonical is recursively sorted compact UTF-8 JSON with unescaped
Unicode/slashes and no floats; it is separate from existing business tuple hashing.
Same-proof retry is idempotent. Only confirmed stopped permits desired disable.
The public drain summary has exactly protocol/drain_id/plan_sha256/targets_sha256/
durably_drained/room_count; host paths and private stop evidence are not published.

Protocol-1 migration is a top-level CLI data cutover for desired disabled state,
after normal disable has confirmed the actual old stopped RuntimeState and original
drain receipt. The managed loader keeps the old identity/revision/hash while disabled;
HTTP service authentication and editor activation remain closed. Run the authorized
migration under that original bound revision/hash, with the existing expired-room
and zero-pending-work guards, before enabling or preparing the new image/configuration.
It grants no HTTP or activation authority. `prepare` of the new configuration
must not erase the old stop evidence before migration; configuration checks remain
strict. Unresolved drains are resumed under the original holder/plan, not restarted
into a fresh process that pretends to inherit them.
Source, syntax and metadata alone do not prove SQL migrations, real WSS, storage,
multi-user operation, installation/recovery qualification or production readiness.
