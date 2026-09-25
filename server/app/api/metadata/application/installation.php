<?php

declare(strict_types=1);

$h = require __DIR__ . '/common.php';
extract($h, EXTR_SKIP);

$dynamicMap = ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue')];
$nullableDynamicMap = ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'), 'nullable' => true];
$officialModule = [
    'type' => 'object', 'additionalProperties' => false,
    'required' => ['key', 'label', 'description', 'required', 'default'],
    'properties' => [
        'key' => ['type' => 'string', 'pattern' => '^official\.[a-z][a-z0-9_-]*$'],
        'label' => ['type' => 'string'], 'description' => ['type' => 'string'],
        'required' => ['type' => 'boolean'], 'default' => ['type' => 'boolean'],
    ],
];
$schemas = [
    'InstallationStatus' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['mode', 'deployment_mode', 'tenant_bootstrap', 'preflight', 'official_modules', 'state', 'code', 'retryable', 'health'],
        'properties' => [
            'mode' => ['type' => 'string', 'enum' => ['guided', 'automatic']],
            'deployment_mode' => ['type' => 'string', 'enum' => ['standalone', 'multi-tenant']],
            'tenant_bootstrap' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['kind', 'code', 'tenant_identity', 'rbac', 'execution_context', 'module_lifecycle'],
                'properties' => array_fill_keys(['kind', 'code', 'tenant_identity', 'rbac', 'execution_context', 'module_lifecycle'], ['type' => 'string']),
            ],
            'preflight' => [
                'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
                'required' => ['status', 'code', 'checks'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['ready', 'blocked']],
                    'code' => ['type' => 'string'],
                    'checks' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
                        'required' => ['status', 'code'],
                        'properties' => ['status' => ['type' => 'string'], 'code' => ['type' => 'string'], 'message' => ['type' => 'string']],
                    ]],
                ],
            ],
            'official_modules' => ['type' => 'array', 'items' => $officialModule],
            'state' => ['type' => 'string', 'enum' => ['blocked', 'uninstalled', 'installed']],
            'code' => ['type' => 'string'], 'retryable' => ['type' => 'boolean'],
            'health' => $nullableDynamicMap,
        ],
    ],
    'InstallationExecuteRequest' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['admin_email', 'admin_password'],
        'properties' => [
            'admin_email' => ['type' => 'string', 'format' => 'email'],
            'admin_password' => ['type' => 'string', 'minLength' => 12],
            'platform_email' => ['type' => 'string', 'format' => 'email'],
            'platform_password' => ['type' => 'string', 'minLength' => 10],
            'official_modules' => ['type' => 'array', 'uniqueItems' => true, 'items' => ['type' => 'string', 'pattern' => '^official\.[a-z][a-z0-9_-]*$']],
        ],
        'description' => 'multi-tenant 部署还要求 platform_email/platform_password；standalone 部署禁止提供这两个字段。',
    ],
    'InstallationResult' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['state', 'code', 'deployment_mode', 'baseline', 'migration', 'modules', 'health'],
        'properties' => [
            'state' => ['type' => 'string', 'enum' => ['installed']],
            'code' => ['type' => 'string', 'enum' => ['INSTALL_COMPLETED']],
            'deployment_mode' => ['type' => 'string', 'enum' => ['standalone', 'multi-tenant']],
            'baseline' => $dynamicMap, 'migration' => $dynamicMap,
            'modules' => [
                'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
                'required' => ['operations', 'profile'],
                'properties' => [
                    'operations' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'operation'], 'properties' => ['key' => ['type' => 'string'], 'operation' => ['type' => 'string']]]],
                    'profile' => $dynamicMap,
                ],
            ],
            'health' => $dynamicMap,
        ],
    ],
];

$paths = [
    '/installapi/status' => ['get' => $operation(
        'getInstallationStatus',
        'Installation',
        ['200' => $success($ref('InstallationStatus')), '503' => $error],
        description: '只读安装状态与 preflight；不会执行数据库安装。',
    )],
    '/installapi/execute' => ['post' => $operation(
        'executeGuidedInstallation',
        'Installation',
        ['200' => $success($ref('InstallationResult')), '403' => $error, '409' => $error, '422' => $error, '503' => $error],
        requestBody: $jsonBody($ref('InstallationExecuteRequest')),
        errors: [
            'INSTALL_REQUEST_ORIGIN_INVALID', 'INSTALL_GUIDED_MODE_DISABLED', 'INSTALL_SETUP_TOKEN_INVALID',
            'INSTALL_ALREADY_COMPLETED', 'INSTALL_PREFLIGHT_BLOCKED', 'INSTALL_DATABASE_UNAVAILABLE',
            'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD', 'INSTALL_EXECUTION_FAILED', 'INSTALL_STATE_UNAVAILABLE',
            'INSTALL_EXECUTION_IN_PROGRESS', 'INSTALL_INPUT_INVALID', 'INSTALL_MODULE_SELECTION_INVALID',
            'INSTALL_IDENTITY_INVALID',
        ],
        description: '仅同源 guided 安装；Authorization Bearer 是一次性部署 setup token，不是 Platform/Tenant 会话。',
    )],
];

return ['paths' => $paths, 'components' => ['schemas' => $schemas]];
