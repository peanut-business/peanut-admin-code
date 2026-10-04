<?php

declare(strict_types=1);

/**
 * Versioned exceptions to the default-deny Tenant Admin API policy.
 *
 * Permission-protected adminapi routes are deliberately not listed here:
 * their exact normalized path must exist as an enabled pa_system_menu.perms
 * node. Keep both exception sets small and method-specific.
 */
return [
    'version' => 1,
    'public' => [
        'POST adminapi/user/login',
        'POST adminapi/user/logout',
        'POST adminapi/tenant/session/login',
        'POST adminapi/tenant/session/select',
        'POST adminapi/tenant/session/switch',
        'POST adminapi/tenant/session/logout',
    ],
    'authenticated' => [
        'POST adminapi/user/info',
        'POST adminapi/user/menu',
        'GET adminapi/login/info',
        'GET adminapi/menu/route',
        'GET adminapi/admin/self',
        'POST adminapi/admin/editself',
    ],
    // Platform credentials and permission keys stay in their own audience.
    'platform_public' => [
        'POST platformapi/session/login',
        'POST platformapi/session/refresh',
        'POST platformapi/session/logout',
    ],
    'platform_authenticated' => [
        'GET platformapi/session/info',
    ],
];
