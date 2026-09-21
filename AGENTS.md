# Peanut Admin code repository bootstrap

This repository contains the Peanut Admin product application implementation.

The authoritative project rules, architecture, plans, product status, release governance and documentation live in the sibling repository `peanut-business/peanut-admin-project`.

For normal development, use the workspace layout where `peanut-admin-project` and `peanut-admin-code` are checked out side by side. Read `../peanut-admin-project/AGENTS.md` before changing this repository.

Branch policy: develop and integrate on `dev`; use feature/fix/refactor branches only as temporary work branches merged back into `dev`; `main` is release-only and accepts changes from `dev` through the release process.

## Mandatory documented rules

Before source edits, resolve the actual authorized Project checkout and read its `AGENTS.md`, `project-rules/document-execution.md`, `project-rules/execution.md` and `project-rules/rule-index.json`; read the source sections applicable to the write set. A linked worktree is not necessarily adjacent to Project: verify the common Git directory/workspace mapping rather than blindly using `../`.

Run the Project `scripts/docs-governance check` and `plan` entry, map the applicable rules to the task, and verify the final receipt as described there. Do not copy private rules into this repository. If Project is unavailable, report it and limit work to safe read-only inspection rather than guessing requirements.

An effective rule that seems incorrect or conflicts with implementation must be reported to the user BEFORE changing its meaning or implementing a conflicting result. Pause only affected work; never weaken tests or rewrite requirements to make code appear compliant. `reviewed_not_rejected` is not implementation approval. Cleanup candidates require the user's specific confirmation. Existing authorized low-risk implementation work remains allowed within effective rules.

Current declaration-style `xxClass` proposals do not by themselves prove a runtime resolver exists. Preserve verified Controller, namespace and isolation behavior until a corresponding implementation slice is authorized and validated.
