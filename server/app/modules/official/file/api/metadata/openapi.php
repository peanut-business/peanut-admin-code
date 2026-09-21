<?php
declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ApiResponse'];

return [
    'paths' => [
        '/adminapi/api/v1/files/assets' => [
            'get' => [
                'tags' => ['File'], 'operationId' => 'listFileAssets',
                'parameters' => [
                    ['name' => 'cid', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                    ['name' => 'name', 'in' => 'query', 'schema' => ['type' => 'string']],
                    ['name' => 'source', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
                    ['name' => 'page_no', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 20]],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Canonical Tenant image assets and derivatives.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileAssetListResponse']]],
                    ],
                    '401' => $error, '403' => $error, '422' => $error,
                ],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'FileImageVariant' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['variant_key', 'file_key', 'width', 'height', 'media_type', 'delivery_uri'],
            'properties' => [
                'variant_key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,31}$'],
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'width' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4096],
                'height' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4096],
                'media_type' => ['type' => 'string', 'enum' => ['image/jpeg', 'image/png']],
                'delivery_uri' => ['type' => 'string', 'nullable' => true],
            ],
        ],
        'FileAssetCandidate' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'file_key', 'original_name', 'media_type', 'width', 'height', 'preview_uri', 'variants'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'original_name' => ['type' => 'string', 'minLength' => 1],
                'media_type' => ['type' => 'string', 'minLength' => 1],
                'width' => ['type' => 'integer', 'minimum' => 1, 'nullable' => true],
                'height' => ['type' => 'integer', 'minimum' => 1, 'nullable' => true],
                'preview_uri' => ['type' => 'string', 'nullable' => true],
                'variants' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileImageVariant']],
            ],
        ],
        'FileAssetListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileAssetCandidate']],
                ]],
                'meta' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['request_id', 'page', 'page_size', 'total'], 'properties' => [
                    'request_id' => ['type' => 'string', 'minLength' => 1],
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'page_size' => ['type' => 'integer', 'minimum' => 1],
                    'total' => ['type' => 'integer', 'minimum' => 0],
                ]],
            ],
        ],
    ]],
];
