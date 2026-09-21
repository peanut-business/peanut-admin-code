# Official module qualification

A module is usable only when its Plugin artifact is installed, the Tenant has the module enabled, and the current TenantMember has the required RBAC/data permission. Each module owns its schema and public contracts; it must not read or write another module's private tables.

Before declaring a module available, add its Tenant isolation, disabled-module and authorization checks. External providers such as payment, notifications and OAuth require their own production configuration and verification.
