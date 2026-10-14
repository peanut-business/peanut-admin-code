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
        // Ticket issuance always revalidates the actual Session and compiled Document owner policy.
        'POST adminapi/official.collaboration.ticket',
        // File resolves its operation and current Document/File policy inside the use case.
        'POST adminapi/api/v1/files/resolve',
        // Document contextual operations must pass live owner/slot/state authorization in the use case.
        'GET adminapi/official.document.detail',
        'GET adminapi/official.document.capabilities',
        'GET adminapi/official.document.operation',
        'GET adminapi/official.document.target',
        'POST adminapi/official.document.target.cancel',
        'POST adminapi/official.document.solo.save',
        'POST adminapi/official.document.resource.bind',
        'POST adminapi/official.document.version.create',
        'POST adminapi/official.document.version.restore',
        'POST adminapi/official.document.archive',
        'POST adminapi/official.document.restore',
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
