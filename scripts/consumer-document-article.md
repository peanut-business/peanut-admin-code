# Ordinary Article / Document consumer

Run `python3 scripts/consumer-document-article --contract` for the entry. This is a
bounded consumer of an **existing, explicitly owned, unpublished solo Article**.
It does not create a tenant, Article, file, service, lease or backup, or log in.
It reuses the existing reviewed solo save/history/restore/archive evidence;
only missing owner-slot and negative CAS consumers are executed here.

```sh
python3 scripts/consumer-document-article --input /absolute/private-input.json
# After independent review and separate live resource admission only:
python3 scripts/consumer-document-article --input /absolute/private-input.json --execute
```

The first command reads local files and Git only. `--execute` verifies the existing
native resource lease before creating its journal and before every request. A
missing input or rejected initial grant produces no journal or network request.
The journal records request-start before HTTP; it contains hashes and statuses,
never tokens or response bodies. Existing output is refused. Any unknown result
stops; reconcile the original request IDs and actual rows instead of rerunning or
changing the prefix. There is no automatic retry, redirect, cleanup mutation or
restore. A successful run leaves its own current content at the new CAS seed A.

Supply a mode-0600 canonical input JSON with exactly these fields:

| Field | Required actual input |
| --- | --- |
| `runtime_root`, `source_commit` | Canonical clean Git runtime checkout and exact full current commit containing the reviewed consumer; matches native lease candidate/repository. This is not the historical source commit. |
| `endpoint`, `host` | Exact HTTP origin and tenant Host; HTTP allowed only on loopback, otherwise HTTPS with default TLS verification. No guessed ports or remote localhost. |
| `owner`, `thread`, `lease` | Current actual owner/thread and existing native lease ID. Native `show --verify-locks` must match these and ACTIVE status. No historical identity reuse. |
| `registry` | `{"path":"/absolute/current-registry.json","sha256":"actual hash"}`; also bound by native lease. |
| `login_response` | Canonical mode-0600 original successful login JSON, with `code=20000`, `data.access_token`, and `data.context` carrying audience `tenant`, tenant/account/member IDs. The existing session must remain valid. It is read in memory, never copied or printed. |
| `tenant_id`, `account_id`, `member_id` | Positive actual integer identity IDs matching that login context. |
| `article_id`, `document_id`, `expected_state_seq` | Existing owned unpublished Article/body and admitted current sequence. No historical foreign rows or fabricated fixture. First editor read verifies exact owner/body, active solo state and unpublished metadata. |
| `request_prefix` | Unique previously unused admitted operation prefix, at most 96 characters from letters, digits, colon, underscore or hyphen. Unknown previous results must be reconciled first. |
| `history` | `revision_id` plus `expected` containing exactly `structured_content`, `source_state_seq`, `digest`, `references`, `confirmed`. These come from an existing independently accepted immutable revision of this same document; `confirmed` must be true. |
| `protection` | Pinned canonical JSON reference (`path`, `sha256`) to the separately admitted protection binding described below. It is evidence input, not new authority. |
| `scope_resource` | Exact native `mysql-access=...` resource string, including `complete=true`, full current tables/rows and transaction closure. It must be present byte-for-byte in native verified resources; the script never claims/renews or escalates to schema exclusive. |
| `budget` | `{"remaining": actual remaining request budget, "max_started":9}`. Original budgets and unknowns carry forward; this input cannot reset them. |
| `output` | Absolute absent journal file inside an existing canonical, owner-controlled directory, outside the clean runtime checkout. No previous effect journal may be replaced. |

The separate protection binding must equal the input's `source_commit`,
`endpoint`, `tenant_id`, `article_id`, `document_id`, `expected_state_seq`, and
`scope_resource`. It also contains an unexpired Unix `expires_at`, nonempty
`backup_files` list of pinned file references, and a pinned `independent_review`
reference. These files are verified by hash; their bytes are not a new backup or
an independent review performed by this script. The live owner must consume the
actual approved backup/review and full table existence/FK/trigger/row closure
through the normal protection contract before supplying this binding. A local
validation success proves neither recoverability nor resource health or grant.

The nine requests are editor read, historical detail read, two different ordinary
Article document-ensure requests, one normal CAS seed save, a stale-sequence save
(409 `DOCUMENT_STATE_SEQ_CONFLICT`), changed-content reuse of the original request
ID (409 `DOCUMENT_IDEMPOTENCY_CONFLICT`), editor read proving A/sequence/digest
unchanged by the rejected saves, and historical detail proving immutable fields
unchanged. The two ensure results must both be `created=false` with the same
document ID. Historical summary sequence may advance; immutable payload, source
sequence, digest, references and confirmation must remain exact.

This proves single-owner/body uniqueness only. Current Article policy permits
only `body`; it cannot prove multiple slots. Multi-slot acceptance requires a
genuinely approved existing business owner/consumer with two declared slots and
its ordinary public command entry, policy and real owned rows. Do not invent a
second Article slot, register a fake owner or bypass the public API. Cross-tenant
negative requests, collaboration/service barriers, permissions, File bytes,
Workflow material consumers and full APP readiness are not executed or passed.
