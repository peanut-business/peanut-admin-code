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
];
