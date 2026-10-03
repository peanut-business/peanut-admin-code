# Identity and tenancy

Account/Credential is the login identity; TenantMember is that account's membership in one Tenant; Tenant Role/RBAC grants permissions only in that Tenant. Business customers, suppliers and contacts remain application business records rather than Account fields.

A PlatformOperator governs this application instance but does not become a TenantMember or gain arbitrary Tenant business-data access. Host-bound Tenant entry points restrict the session to the bound Tenant; only an explicitly configured shared Admin Host can offer Tenant switching to an account that has active memberships.
