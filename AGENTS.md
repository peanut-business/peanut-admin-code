# peanut-admin-code

Application composition, official/business modules, client pages and delivery; no private Project documents or duplicate kernel.

Read the public development standard in `docs/development/standard.md`, the relevant topics in `docs/README.md`, and the affected source before editing. Code is the single owner of public development contracts. Product users and downstream applications do not need the maintainer's private Project repository to install, develop Modules, or use the documented upgrade entry.

For an authorized Peanut maintainer task, also locate its private peanut-admin-project through Git common-dir/repos mapping, never by assuming adjacent worktrees. Read Project AGENTS, execution protocol, STATUS/PLAN and applicable approvals before dependent writes. Project owns internal governance and execution state, not another copy of public rules. Missing required private decisions block only operations that depend on them; they do not make private Project a downstream product dependency.

Use `tools/quality/README.md` and each affected project's native commands for checks; report the actual source, dependency and validation scope. Maintainer rule reads/checks must pass the explicit Code checkout and verified commit to Project's docs-governance with `--application-root` and `--application-ref`; `--content` must return real source text, not a pointer. Do not load entire handbook/API/migration data by default or file self-certified reading receipts. Uncertain rule semantics require evidence and the user's decision; do not weaken security or tests to obtain a pass.

Isolated worktrees; preserve concurrent work; normal validated integration/push to dev, verify remote refs; never force/rewrite history. main, formal publication/deployment, real customer/financial data, paid plans, visibility and license changes require applicable authorization. No private rules, credentials or customer data in public repos.

Absorb useful rules/open issues before deleting confirmed duplicates or unused one-time tools; no archive/redirect stubs. Preserve live tests, migrations, licenses and others' resources. Docs-only changes neither prove runtime acceptance nor require repinning packages or unrelated product tests.

Runtime resources are registered in `resources/project-resources.json`; validate the exact identity, health and exclusive lease before operating them. The source-only P0-E qualification mapping is `resources/p0e-runtime-qualification.json`; use it only for that qualification, never as an implicit runtime fallback.
