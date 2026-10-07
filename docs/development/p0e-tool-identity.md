# Independent P0-E qualification tool identity

A correction to qualification tooling does not create a new product candidate.
The independent tool checkout must be clean at the exact
`--qualification-tool-commit`. Its differences from `--candidate` may contain
only the runner, consumer chain, Plugin fixture, and this document. The explicit
`--source-root` must remain clean at the exact product candidate. Both checkout
roots and commits are checked; dirty or mismatched sources are rejected.

`--qualification-tool-files-sha256` is SHA-256 of the three tool paths, sorted
lexically, each encoded as `path + NUL + file SHA-256 + LF`. The tool binding also
records the individual hashes and tool tree. The product source tree, inventories,
package hashes, resource registry, lease, group source files and original plan
remain subject to their existing checks. Production commands, fixture module
bytes, generation and packages still come from the product source.

Use `bind-tools` with the original fixed runner arguments and the three explicit
tool identity arguments before `resume`. This bounded operation verifies the
native lease and original plan, without invoking database or runtime operations.
It saves the exact original bytes as `plan.before-tool-binding.json` and
`checkpoint.before-tool-binding.json`, and writes
`qualification-tool-binding.json`. It marks only the changed Plugin fixture or
consumer chain groups stale; an unchanged Plugin fixture keeps its original pass.
The original generated
application and both fresh-install records remain intact. An existing different
binding or changed original snapshot fails closed. The revised checkpoint binds
the new record by SHA-256; the original plan is never rewritten.

When the Plugin fixture changes, the correction requires `--service-gates-receipt` and
`--service-gates-receipt-sha256`: a frozen receipt for the two already completed
Member and Task gates, carrying candidate/tree/run/lease, the original plan hash,
original Plugin group inputs, service source hashes, frozen log hash and exact
completion markers. These two service gates are reused with their original
identity. The rejected fixture is never accepted through this receipt.

The Plugin fixture executes in the generated application, using its bootstrap,
production modules, App container and registered database environment. Its
corrected runner alone comes from the tool checkout. This invocation must return
zero and emit `PLUGIN-MODULE-LIFECYCLE-DB-001 passed`; cleanup output is not a
completion signal. The same App resolves ExecutionContextStore,
CurrentExecutionContext and DeliveryRecordCommands.

The consumer chain receives the same explicit tool identity and authenticates its
binding against the original plan and checkpoint. After package and application
identity checks, it prepares installation and configuration private directories,
`private/resources/pending`, and the seven writable `runtime` directories required
by native installation preflight, with mode 0700 for each owned application.
The fixed runner prepares the same directories for its generated applications.
Native installation owns
all installation state and initialization. Independent tool binding does not
authorize a run, release, deployment, new candidate, or broader qualification.
