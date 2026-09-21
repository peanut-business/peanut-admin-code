<?php
declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ApiResponse'];
$moduleKey = ['name' => 'moduleKey', 'in' => 'path', 'required' => true, 'schema' => [
    'type' => 'string', 'maxLength' => 96, 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$',
]];
$localKeySchema = ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$'];
$setKey = ['name' => 'setKey', 'in' => 'path', 'required' => true, 'schema' => $localKeySchema];
$code = ['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => $localKeySchema];
$idempotency = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => [
    'type' => 'string', 'minLength' => 16, 'maxLength' => 128, 'pattern' => '^[!-~]+$',
]];
$ifMatch = ['name' => 'If-Match', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^"rev-[1-9][0-9]*"$']];
$entryResponse = [
    'description' => 'Reference-code entry and strong ETag.',
    'headers' => ['ETag' => ['required' => true, 'schema' => ['type' => 'string', 'pattern' => '^"rev-[1-9][0-9]*"$']]],
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeEntryResponse']]],
];

return [
    'paths' => [
        '/adminapi/api/v1/reference-code-sets' => ['get' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'listReferenceCodeSets',
            'responses' => [
                '200' => ['description' => 'Registered reference-code sets.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeSetsResponse']]]],
                '401' => $error, '403' => $error,
            ],
        ]],
        '/adminapi/api/v1/reference-code-sets/{moduleKey}/{setKey}/codes' => [
            'get' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'listReferenceCodes',
                'parameters' => [$moduleKey, $setKey,
                    ['name' => 'as_of', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['name' => 'effective_status', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['active', 'inactive', 'all']]],
                    ['name' => 'include_retired', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'boolean']],
                    ['name' => 'page', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000]],
                    ['name' => 'page_size', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                ],
                'responses' => ['200' => ['description' => 'Reference-code snapshot.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeListResponse']]]], '401' => $error, '403' => $error, '404' => $error, '422' => $error],
            ],
            'post' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'createReferenceCode',
                'parameters' => [$moduleKey, $setKey, $idempotency, [
                    'name' => 'If-None-Match', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['*']],
                ]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeCreateRequest']]]],
                'responses' => ['200' => $entryResponse, '401' => $error, '403' => $error, '404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error, '500' => $error],
            ],
        ],
        '/adminapi/api/v1/reference-code-sets/{moduleKey}/{setKey}/codes/{code}' => [
            'get' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'getReferenceCode',
                'parameters' => [$moduleKey, $setKey, $code, ['name' => 'as_of', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date-time']]],
                'responses' => ['200' => $entryResponse, '401' => $error, '403' => $error, '404' => $error, '422' => $error],
            ],
            'put' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'replaceReferenceCode',
                'parameters' => [$moduleKey, $setKey, $code, $idempotency, $ifMatch],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeVersionRequest']]]],
                'responses' => ['200' => $entryResponse, '401' => $error, '403' => $error, '404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error, '500' => $error],
            ],
            'delete' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'retireReferenceCode',
                'parameters' => [$moduleKey, $setKey, $code, $idempotency, $ifMatch],
                'responses' => ['200' => $entryResponse, '401' => $error, '403' => $error, '404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error, '500' => $error],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'ReferenceCodeMetadata' => [
            'type' => 'object', 'maxProperties' => 32,
            'additionalProperties' => ['oneOf' => [
                ['type' => 'string', 'maxLength' => 500, 'nullable' => true], ['type' => 'number'], ['type' => 'boolean'],
            ]],
        ],
        'ReferenceCodeVersionRequest' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['label', 'metadata', 'status', 'sort_order', 'effective_at', 'expires_at'],
            'properties' => [
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'metadata' => ['$ref' => '#/components/schemas/ReferenceCodeMetadata'],
                'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                'sort_order' => ['type' => 'integer', 'minimum' => -1000000, 'maximum' => 1000000],
                'effective_at' => ['type' => 'string', 'format' => 'date-time'],
                'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'ReferenceCodeCreateRequest' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/ReferenceCodeVersionRequest'],
                ['type' => 'object', 'required' => ['code'], 'properties' => ['code' => $localKeySchema]],
            ],
        ],
        'ReferenceCodeSetSummary' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['module_key', 'set_key', 'name', 'description', 'definition_revision'],
            'properties' => [
                'module_key' => $moduleKey['schema'], 'set_key' => $localKeySchema,
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'definition_revision' => ['type' => 'integer', 'minimum' => 1],
            ],
        ],
        'ReferenceCodeEffectiveVersion' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['revision', 'label', 'metadata', 'status', 'sort_order', 'effective_at', 'expires_at'],
            'properties' => [
                'revision' => ['type' => 'integer', 'minimum' => 1], 'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'metadata' => ['$ref' => '#/components/schemas/ReferenceCodeMetadata'], 'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                'sort_order' => ['type' => 'integer', 'minimum' => -1000000, 'maximum' => 1000000],
                'effective_at' => ['type' => 'string', 'format' => 'date-time'], 'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'ReferenceCodeEntry' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['module_key', 'set_key', 'code', 'lifecycle', 'revision', 'etag', 'effective', 'created_at', 'updated_at', 'retired_at'],
            'properties' => [
                'module_key' => $moduleKey['schema'], 'set_key' => $localKeySchema, 'code' => $localKeySchema,
                'lifecycle' => ['type' => 'string', 'enum' => ['active', 'retired']], 'revision' => ['type' => 'integer', 'minimum' => 1],
                'etag' => ['type' => 'string', 'pattern' => '^"rev-[1-9][0-9]*"$'],
                'effective' => ['$ref' => '#/components/schemas/ReferenceCodeEffectiveVersion', 'nullable' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time'], 'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                'retired_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'ReferenceCodeMeta' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['request_id'], 'properties' => ['request_id' => ['type' => 'string', 'minLength' => 1]]],
        'ReferenceCodeSetsResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'], 'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReferenceCodeSetSummary']]]],
                'meta' => ['$ref' => '#/components/schemas/ReferenceCodeMeta'],
            ],
        ],
        'ReferenceCodeEntryResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => ['data' => ['$ref' => '#/components/schemas/ReferenceCodeEntry'], 'meta' => ['$ref' => '#/components/schemas/ReferenceCodeMeta']],
        ],
        'ReferenceCodeListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'], 'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items', 'as_of', 'page', 'page_size', 'total'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReferenceCodeEntry']],
                    'as_of' => ['type' => 'string', 'format' => 'date-time'], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                    'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'total' => ['type' => 'integer', 'minimum' => 0],
                ]],
                'meta' => ['$ref' => '#/components/schemas/ReferenceCodeMeta'],
            ],
        ],
    ]],
];
