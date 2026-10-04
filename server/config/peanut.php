<?php

declare(strict_types=1);

return [
    // 稳定 override key => 实现 AdminPermissionPolicy 的应用类。
    'overrides' => [],
    'environment' => env('APP_ENV', 'production'),
    'demo' => [
        'enabled' => env('PEANUT_DEMO_MODE', 'disabled') === 'enabled',
        'admin_email' => env('PEANUT_DEMO_ADMIN_EMAIL', ''),
        'platform_email' => env('PEANUT_DEMO_PLATFORM_EMAIL', ''),
        'tenant_a_email' => env('PEANUT_DEMO_TENANT_A_EMAIL', ''),
        'tenant_b_email' => env('PEANUT_DEMO_TENANT_B_EMAIL', ''),
        'tenant_a_host' => env('PEANUT_DEMO_TENANT_A_HOST', ''),
        'tenant_b_host' => env('PEANUT_DEMO_TENANT_B_HOST', ''),
        'shared_password' => env('PEANUT_DEMO_SHARED_PASSWORD', ''),
    ],
    'settings_secrets' => [
        'keys' => env('PEANUT_SETTINGS_SECRET_KEYS', ''),
        'active_key_id' => env('PEANUT_SETTINGS_ACTIVE_SECRET_KEY_ID', ''),
    ],
    'storage_credential_master_key' => env('PEANUT_STORAGE_CREDENTIAL_MASTER_KEY', ''),
    'rich_text' => [
        'collaboration_url' => env('RICH_TEXT_COLLABORATION_URL', ''),
        'collaboration_secret' => env('RICH_TEXT_COLLABORATION_SECRET', ''),
    ],
    'installation' => [
        'mode' => env('PEANUT_INSTALLATION_MODE', 'automatic'),
        'setup_token' => env('PEANUT_INSTALLATION_SETUP_TOKEN', ''),
    ],
];
