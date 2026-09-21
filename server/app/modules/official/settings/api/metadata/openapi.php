<?php
declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ApiResponse'];
$moduleKey = ['name' => 'moduleKey', 'in' => 'path', 'required' => true, 'schema' => [
    'type' => 'string', 'maxLength' => 96, 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$',
]];
$settingKey = ['name' => 'settingKey', 'in' => 'path', 'required' => true, 'schema' => [
    'type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$',
]];
$idempotency = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => [
    'type' => 'string', 'minLength' => 16, 'maxLength' => 128, 'pattern' => '^[!-~]+$',
]];
$ifMatch = ['name' => 'If-Match', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^"[^"\\r\\n]+"$']];

return [
    'paths' => [
        '/adminapi/api/v1/settings' => ['get' => [
            'tags' => ['Settings'], 'operationId' => 'listModuleSettings',
            'responses' => [
                '200' => ['description' => 'Tenant-visible setting definitions and effective values.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SettingsListResponse']]]],
                '401' => $error, '403' => $error, '422' => $error, '503' => $error,
            ],
        ]],
        '/adminapi/api/v1/settings/{moduleKey}/{settingKey}' => [
            'put' => [
                'tags' => ['Settings'], 'operationId' => 'replaceModuleSetting',
                'description' => 'Create requires If-None-Match: *; replacement requires a strong If-Match. Exactly one precondition is accepted.',
                'parameters' => [$moduleKey, $settingKey, $idempotency, $ifMatch, [
                    'name' => 'If-None-Match', 'in' => 'header', 'required' => false,
                    'schema' => ['type' => 'string', 'enum' => ['*']],
                ]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['value'],
                    'properties' => ['value' => ['$ref' => '#/components/schemas/SettingValue']],
                ]]]],
                'responses' => [
                    '200' => ['$ref' => '#/components/responses/SettingRecordResponse'],
                    '401' => $error, '403' => $error, '404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error, '503' => $error,
                ],
            ],
            'delete' => [
                'tags' => ['Settings'], 'operationId' => 'unsetModuleSetting',
                'parameters' => [$moduleKey, $settingKey, $idempotency, array_replace($ifMatch, ['required' => true])],
                'responses' => [
                    '200' => ['$ref' => '#/components/responses/SettingRecordResponse'],
                    '401' => $error, '403' => $error, '404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error, '503' => $error,
                ],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'SettingValue' => [
            'nullable' => true,
            'oneOf' => [
                ['type' => 'string'], ['type' => 'number'], ['type' => 'integer'], ['type' => 'boolean'],
                ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/SettingValue']],
                ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SettingValue']],
            ],
        ],
        'SettingSchema' => [
            'type' => 'object', 'additionalProperties' => true, 'required' => ['type'],
            'properties' => ['type' => ['oneOf' => [
                ['type' => 'string', 'enum' => ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string']],
                ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => ['type' => 'string', 'enum' => ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string']]],
            ]]],
        ],
        'SettingRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['module_key', 'setting_key', 'name', 'description', 'schema', 'required', 'secret', 'configured', 'source_scope', 'effective_at', 'expires_at', 'revision', 'etag'],
            'properties' => [
                'module_key' => $moduleKey['schema'], 'setting_key' => $settingKey['schema'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'schema' => ['$ref' => '#/components/schemas/SettingSchema'],
                'required' => ['type' => 'boolean'], 'secret' => ['type' => 'boolean'], 'configured' => ['type' => 'boolean'],
                'source_scope' => ['type' => 'string', 'nullable' => true, 'enum' => ['deployment', 'tenant', 'default']],
                'value' => ['$ref' => '#/components/schemas/SettingValue'],
                'effective_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'revision' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
                'etag' => ['type' => 'string', 'nullable' => true, 'pattern' => '^"[^"\\r\\n]+"$'],
            ],
        ],
        'SettingsListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'request_id'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SettingRecord']],
                ]],
                'request_id' => ['type' => 'string', 'minLength' => 1],
            ],
        ],
        'SettingRecordResponseBody' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'request_id'],
            'properties' => ['data' => ['$ref' => '#/components/schemas/SettingRecord'], 'request_id' => ['type' => 'string', 'minLength' => 1]],
        ],
    ], 'responses' => [
        'SettingRecordResponse' => [
            'description' => 'Updated tenant setting with the resulting strong ETag.',
            'headers' => ['ETag' => ['required' => false, 'schema' => ['type' => 'string', 'pattern' => '^"[^"\\r\\n]+"$']]],
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SettingRecordResponseBody']]],
        ],
    ]],
];
