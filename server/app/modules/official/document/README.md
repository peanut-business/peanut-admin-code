# Business documents

`official.document` owns business-bound document identities, solo projections,
committed versions and controlled references. A selected business module declares
its dependency on Document and contributes a `DocumentOwnerPolicy` through its
compiled provider. Article contributes only its existing `article/body` slot;
its publication and approval behavior remains owned by Article.

The public `DocumentOwnerRegistry` is an application-composition contract. The
composition root constructs it from the selected, compiled providers' owner
policy declarations and registers that instance in the native ThinkPHP container.
Request fields cannot contribute policy classes. Registry lookup selects the
policy; the policy and Document still perform current authorization, owner and
slot validation. This export does not expose other Document services or Models.

Every operation revalidates the durable Identity session, installation and tenant
module state, the current business owner and the requested action. Unknown owners
and slots are rejected. Creation and update metadata do not grant an actor ACL.
Document's HTTP entry exposes contextual commands and queries; it has no anonymous
or owner-free create endpoint. Article's `official.article.document` command uses
the server-fixed owner and body slot and does not convert the legacy Article body.

Solo rich text accepts the public `RichTextProjection` schema owned by RichText.
It stores a validated JSON projection with CAS and durable request receipts. It
does not construct Yjs state. Once collaboration is enabled, solo save, freeze,
restore and lifecycle changes fail closed until the real collaboration persistence
and save barrier are connected.

`DocumentQueries::versionSnapshot()` returns only an already committed immutable
version and refuses an outer transaction. `confirmed` means committed; it does not
mean publication or approval. Rich-text version digests hash the JSON-encoded tuple
`[contentKind, sourceStateSeq, canonicalProjectionJson, yjsSnapshotSha256|null,
immutableFileKey|null, immutableFileSha256|null]`. The canonical projection is
produced by `RichTextProjection`, independently of MySQL's JSON formatting. Reading
a version returns its own references, not the current draft's reference set.
Restoring a version appends another version.

File versions capture the independently authorized original into a new private
File object before the owner transaction. Capture binds the source revision and
SHA-256, verifies MIME and actual copied bytes, and refuses an existing outer
transaction. The owner transaction then repeats authorization/CAS, writes persistent
references and claims the capture on the same ThinkORM connection. File references
and deletion use the same object lock. Sensitive sources retain the File-owned
`document.snapshot-sensitive` purpose and cannot inherit Document preview permission.
Preview never grants original download or file management permission.

Same-request failure compensates only its unclaimed new capture. A process crash
can leave a durable pending object or an unclaimed capture; it is not reported as
cleaned up. File's management-only pending-snapshot query lists old recoverable
objects, and its recovery command retries the original account/space configuration.
Real provider failure leaves `cleanup_pending`. Claimed or referenced history is
protected from recovery and deletion. Document V1 retains versions and prevents an
owner's permanent deletion while its protected documents remain.

Apply the new Document and File snapshot migrations through the native module
installation/upgrade lifecycle. Existing RichText records, tables and migrations
are preserved. Single/Multi Edition qualification, collaborative Yjs persistence,
browser editors/viewers and external-storage migration are separate integration
checks; this module's solo persistence implementation does not prove those paths.
