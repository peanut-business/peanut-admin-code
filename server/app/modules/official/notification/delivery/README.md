# Optional notification delivery engine

This first-party implementation was moved from PHP Core into
`official.notification`. It supplies inbox/outbox, rendered template snapshot,
attachment reference, SMS provider, rate-limit, and task-handler behavior.

It is intentionally **not enabled by default**. The existing notification
routes and contracts, including the D01 verification-code ledger and failure
limits, remain authoritative. A product may enable this delivery engine only
after the module owner supplies an additive migration, current-tenant recipient
and attachment resolvers, the official task registrations, and an explicit SMS
provider. Missing provider configuration fails closed; no real channel or local
development fallback is selected implicitly.

The optional engine's schema is not part of the module manifest until that
adoption is complete, so it does not create a second active notification
ledger.
