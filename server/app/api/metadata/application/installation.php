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
    'InstallationApplicationIdentity' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['slug', 'edition', 'version', 'package_identity', 'name'],
        'properties' => [
            'slug' => ['type' => 'string'],
            'edition' => ['type' => 'string', 'enum' => ['standalone', 'multi-tenant']],
            'version' => ['type' => 'string'],
            'package_identity' => ['type' => 'string'],
            'name' => ['type' => 'string'],
        ],
    ],
    'InstallationConfigurationStatus' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['state', 'code', 'configured', 'application'],
        'properties' => [
            'state' => ['type' => 'string', 'enum' => ['unconfigured', 'pending', 'configured', 'blocked', 'installed']],
            'code' => ['type' => 'string'],
            'configured' => ['type' => 'boolean'],
            'application' => $ref('InstallationApplicationIdentity'),
            'deployment_targets' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ],
    'InstallationConfigureRequest' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['deployment_target'],
        'properties' => [
            'deployment_target' => ['type' => 'string', 'enum' => ['local-production-preview', 'production', 'production-candidate']],
            'database_name' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_]{1,64}$'],
            'platform_hosts' => ['type' => 'string'],
            'tenant_admin_hosts' => ['type' => 'string'],
        ],
        'description' => '数据库业务账号和应用密钥由安装配置器生成；多租户部署必须提供 Platform/Tenant Admin Host。',
    ],
    'InstallationConfigureResult' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['state', 'code', 'restart_required', 'application', 'deployment_target', 'database_resource_id', 'database_name'],
        'properties' => [
            'state' => ['type' => 'string', 'enum' => ['pending', 'configured']],
            'code' => ['type' => 'string', 'enum' => ['INSTALL_CONFIGURATION_PENDING', 'INSTALL_CONFIGURATION_COMPLETED']],
            'restart_required' => ['type' => 'boolean'],
            'application' => $ref('InstallationApplicationIdentity'),
            'deployment_target' => ['type' => 'string'],
            'database_resource_id' => ['type' => 'string'],
            'database_name' => ['type' => 'string'],
        ],
    ],
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
    'InstallationEntryStatus' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['installed', 'deployment_mode'],
        'properties' => [
            'installed' => ['type' => 'boolean'],
            'deployment_mode' => ['type' => 'string', 'enum' => ['standalone', 'multi-tenant']],
        ],
    ],
    'InstallationExecuteRequest' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['admin_email', 'admin_password'],
        'properties' => [
            'admin_email' => ['type' => 'string', 'format' => 'email'],
            'admin_password' => ['type' => 'string', 'description' => 'New password: effective UTF-8 byte bounds from getPasswordPolicy.'],
            'platform_email' => ['type' => 'string', 'format' => 'email'],
            'platform_password' => ['type' => 'string', 'description' => 'New password: effective UTF-8 byte bounds from getPasswordPolicy.'],
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
    '/installapi/password-policy' => ['get' => $operation(
        'getPasswordPolicy',
        'Installation',
        ['200' => $success([
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['minimum_length', 'maximum_length', 'length_unit'],
            'properties' => [
                'minimum_length' => ['type' => 'integer', 'minimum' => 1],
                'maximum_length' => ['type' => 'integer', 'minimum' => 1],
                'length_unit' => ['type' => 'string', 'enum' => ['utf8_bytes']],
            ],
        ])],
        description: 'Public effective password limits from the native container binding; available before and after installation. No credentials or instance configuration are returned.',
    )],
    '/installapi/configuration' => ['get' => $operation(
        'getInstallationConfiguration',
        'Installation',
        ['200' => $success($ref('InstallationConfigurationStatus')), '503' => $error],
        description: '只读首次实例配置状态；不会写配置、数据库或安装身份。',
    )],
    '/installapi/configure' => ['post' => $operation(
        'configureInstallation',
        'Installation',
        ['200' => $success($ref('InstallationConfigureResult')), '403' => $error, '409' => $error, '422' => $error, '503' => $error],
        requestBody: $jsonBody($ref('InstallationConfigureRequest')),
        errors: [
            'INSTALL_REQUEST_ORIGIN_INVALID', 'INSTALL_SETUP_TOKEN_INVALID',
            'INSTALL_CONFIGURATION_INPUT_INVALID', 'INSTALL_CONFIGURATION_TARGET_INVALID',
            'INSTALL_CONFIGURATION_DATABASE_INVALID', 'INSTALL_CONFIGURATION_HOSTS_REQUIRED',
            'INSTALL_CONFIGURATION_HOSTS_INVALID', 'INSTALL_CONFIGURATION_STATE_UNAVAILABLE',
            'INSTALL_CONFIGURATION_IN_PROGRESS', 'INSTALL_CONFIGURATION_ALREADY_PRESENT',
        ],
        description: '仅同源首次配置；Authorization Bearer 使用一次性 setup token。生成受保护实例 registry 与 server/.env，不修改发行 identity。',
    )],
    '/installapi/status' => ['get' => $operation(
        'getInstallationStatus',
        'Installation',
        ['200' => $success($ref('InstallationStatus')), '503' => $error],
        description: '只读安装状态与 preflight；不会执行数据库安装。',
    )],
    '/installapi/entry-status' => ['get' => $operation(
        'getInstallationEntryStatus',
        'Installation',
        ['200' => $success($ref('InstallationEntryStatus'))],
        description: '只读取物理安装完成锁和部署模式，供正常前端入口判断；不运行安装预检。',
    )],
    '/installapi/execute' => ['post' => $operation(
        'executeGuidedInstallation',
        'Installation',
        ['200' => $success($ref('InstallationResult')), '403' => $error, '409' => $error, '422' => $error, '503' => $error],
        requestBody: $jsonBody($ref('InstallationExecuteRequest')),
        errors: [
            'INSTALL_REQUEST_ORIGIN_INVALID', 'INSTALL_GUIDED_MODE_DISABLED', 'INSTALL_SETUP_TOKEN_INVALID',
            'INSTALL_ALREADY_COMPLETED', 'INSTALL_PREFLIGHT_BLOCKED', 'INSTALL_DATABASE_UNAVAILABLE',
            'INSTALL_COMPLETION_LOCK_INVALID', 'INSTALL_COMPLETION_LOCK_MISSING',
            'INSTALL_LOCKED_DATABASE_UNAVAILABLE', 'INSTALL_LOCKED_DATABASE_MISMATCH',
            'INSTALL_STATE_MIGRATION_PENDING', 'INSTALL_STATE_MIGRATION_REQUIRED',
            'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD', 'INSTALL_EXECUTION_FAILED', 'INSTALL_STATE_UNAVAILABLE',
            'INSTALL_EXECUTION_IN_PROGRESS', 'INSTALL_INPUT_INVALID', 'INSTALL_MODULE_SELECTION_INVALID',
            'INSTALL_IDENTITY_INVALID',
        ],
        description: '仅同源 guided 安装；Authorization Bearer 是一次性部署 setup token，不是 Platform/Tenant 会话。',
    )],
];

return ['paths' => $paths, 'components' => ['schemas' => $schemas]];
