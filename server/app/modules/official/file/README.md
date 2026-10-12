# File composition

The public `FileDocumentReferenceRegistry` is an application-composition contract.
The composition root constructs it from the selected, compiled provider's
`FileDocumentReferenceContributor` declaration and registers that instance in the
native ThinkPHP container. Requests cannot supply or replace its policy class.
An absent contribution rejects document-reference authorization; registry lookup
does not grant preview, original download or management permission. The selected
Document policy and File services retain their current authorization checks.

This export supports the existing host composition boundary. Other File services
and Models remain private unless separately declared as public module contracts.

Document's prepared-version verifier uses
`FileReferenceCommands::assertDocumentReferencesReadable` inside its native owner
transaction. This checks stable keys and the current actor, locks File objects in
sorted order and repeats the existing independent or Document-inherited preview
authorization. It neither replaces the reference index nor issues delivery.
`FileDocumentSnapshots::assertDocumentSnapshotReadable` additionally checks the
private immutable object's claimed Document, capture ledger and SHA/MIME metadata
under those locks. Sensitive snapshots retain their independent File permission
requirement. Neither method reads provider bytes or proves current physical
storage integrity; current session/module checks and deletion protection remain.

`FileAccess::attachmentSnapshot` returns only ready source metadata (stable key,
original name, verified MIME, size and SHA-256) under the current human Admin
session, File module and independent preview permission. It creates no URL,
delivery grant, audit entry or provider IO, and does not confer original-download
permission or Document prepared proof. System workers cannot use it to acquire
human File authority. Malformed registered metadata is rejected; this read is not
a fresh physical byte inspection.

Available preview delivery receipts include the actually selected `variant_key`
(null for original bytes). A fixed callback selection must match that key and a
different derivative File object. Delivery MIME is checked against the server's
authorized Content Tools capability, so a verified PNG derivative of a JPEG
source is allowed; an original still must match its source MIME. No original or
alternate variant is substituted when the requested derivative is unavailable.
