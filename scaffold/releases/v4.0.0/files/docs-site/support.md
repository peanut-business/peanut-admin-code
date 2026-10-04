# Support and issue reporting

Use this page to provide a reproducible, privacy-preserving report. The [reference index](/reference) defines command boundaries; the root `SECURITY.md` defines private security contact.

## Choose the channel

- An ordinary defect, documentation error, compatibility question, or feature request belongs in the project's public Issue tracker.
- Unauthorized access, Tenant boundary bypass, identity or RBAC bypass, sensitive-data exposure, arbitrary file or command execution, and signature/checksum bypass are security issues. Do not publish their details.
- Real provider credentials, messages, payments, OAuth, or storage operations remain the responsibility of the relevant provider owner; support cannot safely run them against your production account.

## Include version and a minimum reproduction

For an ordinary issue, include the exact annotated tag or Release, application version, source commit/tree from `.peanut/application-manifest.json`, deployment mode, operating system, PHP/Node versions, affected client or Module key/version, shortest reproduction, expected and actual result, stable error code, and command exit code. State whether it reproduces in a fresh independent application; if not, describe only the minimum app-owned change.

Do not upload a database dump, private source, credentials, Tenant records, cookies, tokens, raw request headers, or absolute paths. Remove them from terminal excerpts.

## Redacted diagnostic bundle

An authorized operator may attach a bounded diagnostic bundle when the deployment provides that capability. Check its checksum and inspect it before sharing. It may contain version identity, deployment mode, debug state, runtime status, installed Module summary, bounded failed-task codes, and structured audit messages. It must exclude raw logs, secrets, tokens, cookies, absolute paths, personal information, Tenant business records, resource registries, deployment tasks, recovery pointers, database dumps, environment files, and signing keys.

## Private security contact

Use the repository's **Security → Report a vulnerability** private form when available. If it is unavailable, create a public issue titled `Security contact request` containing only the affected release/tag and a private way to contact the maintainers. Do not include the component, attack steps, impact, proof of concept, credentials, diagnostic bundle, or screenshots. Wait for a private channel before sending sensitive details.

Only test systems, data, accounts, and providers that you own or are explicitly authorized to use.
