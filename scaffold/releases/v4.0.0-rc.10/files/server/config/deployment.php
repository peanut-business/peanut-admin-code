<?php

declare(strict_types=1);

return [
    // Instance-wide developer and maintenance tools require an explicit mode.
    // Missing or unknown values remain closed instead of becoming standalone.
    'mode' => env('DEPLOYMENT_MODE'),
    // Multi-tenant entry boundaries. Production multi-tenant deployments must
    // declare both lists; tenant-bound hosts remain dynamic database records.
    'platform_hosts' => env('PLATFORM_HOSTS', ''),
    'tenant_admin_hosts' => env('TENANT_ADMIN_HOSTS', ''),
    // Standalone may expose its default Tenant on an unbound public Host.
    // Operators can disable that compatibility entry without changing mode;
    // multi-tenant mode always ignores this value and fails closed.
    'public_default_tenant_fallback' => env('PUBLIC_DEFAULT_TENANT_FALLBACK', true),
];
