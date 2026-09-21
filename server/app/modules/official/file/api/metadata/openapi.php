<?php
declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ErrorResponse'];
$typedError = $error;
$uploadBody = [
    'required' => true,
    'content' => ['multipart/form-data' => ['schema' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['file'],
        'properties' => [
            'file' => ['type' => 'string', 'format' => 'binary'],
            'cid' => ['$ref' => '#/components/schemas/FileNonNegativeIntegerInput'],
        ],
    ]]],
];
$uploadResponse = [
    'description' => '上传成功',
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileUploadResponse']]],
];
$mutationResponse = [
    'description' => '操作成功',
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileMutationResponse']]],
];

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
        '/adminapi/official.file.upload.image' => ['post' => [
            'tags' => ['File'], 'operationId' => 'uploadAdminImage',
            'requestBody' => $uploadBody,
            'responses' => ['200' => $uploadResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
            'x-peanut-errors' => ['UPLOAD_FILE_REQUIRED'],
        ]],
        '/adminapi/official.file.upload.video' => ['post' => [
            'tags' => ['File'], 'operationId' => 'uploadAdminVideo',
            'requestBody' => $uploadBody,
            'responses' => ['200' => $uploadResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
            'x-peanut-errors' => ['UPLOAD_FILE_REQUIRED'],
        ]],
        '/api/upload/image' => ['post' => [
            'tags' => ['File'], 'operationId' => 'uploadMemberImage',
            'requestBody' => $uploadBody,
            'responses' => ['200' => $uploadResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
            'x-peanut-errors' => ['UPLOAD_FILE_REQUIRED'],
        ]],
        '/adminapi/official.file.move' => ['post' => [
            'tags' => ['File'], 'operationId' => 'moveFiles',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileMoveRequest']]]],
            'responses' => ['200' => $mutationResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
        ]],
        '/adminapi/official.file.rename' => ['post' => [
            'tags' => ['File'], 'operationId' => 'renameFile',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileRenameRequest']]]],
            'responses' => ['200' => $mutationResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
            'x-peanut-errors' => ['FILE_NAME_REQUIRED', 'FILE_NAME_TOO_LONG'],
        ]],
        '/adminapi/official.file.delete' => ['post' => [
            'tags' => ['File'], 'operationId' => 'deleteFiles',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileIdsRequest']]]],
            'responses' => [
                '200' => ['description' => '素材及对应存储对象删除计数', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileDeleteResponse']]]],
                '400' => $typedError, '401' => $typedError, '403' => $typedError,
            ],
        ]],
        '/adminapi/official.file.category.list' => ['get' => [
            'tags' => ['File'], 'operationId' => 'listFileCategories',
            'parameters' => [[
                'name' => 'type', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'integer', 'enum' => [10, 20, 30], 'default' => 10],
            ]],
            'responses' => [
                '200' => [
                    'description' => '当前租户的文件分类树；每个节点保留 ORM 行的全部已声明字段。',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileCategoryListResponse']]],
                ],
                '400' => $error, '401' => $error, '403' => $error,
            ],
        ]],
        '/adminapi/official.file.category.add' => ['post' => [
            'tags' => ['File'], 'operationId' => 'createFileCategory',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileCategoryCreateRequest']]]],
            'responses' => ['200' => $mutationResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
        ]],
        '/adminapi/official.file.category.edit' => ['post' => [
            'tags' => ['File'], 'operationId' => 'updateFileCategory',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileCategoryUpdateRequest']]]],
            'responses' => ['200' => $mutationResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
        ]],
        '/adminapi/official.file.category.delete' => ['post' => [
            'tags' => ['File'], 'operationId' => 'deleteFileCategory',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileIdentifierRequest']]]],
            'responses' => [
                '200' => ['description' => '分类子树、素材和存储对象删除计数', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileCategoryDeleteResponse']]]],
                '400' => $typedError, '401' => $typedError, '403' => $typedError,
            ],
        ]],
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
        'FileNonNegativeIntegerInput' => [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 0],
                ['type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]*)$'],
            ],
        ],
        'FilePositiveIntegerInput' => [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
            ],
        ],
        'FileIdentifierRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id'],
            'properties' => ['id' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput']],
        ],
        'FileIdsRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['ids'],
            'properties' => ['ids' => [
                'type' => 'array', 'minItems' => 1, 'uniqueItems' => true,
                'items' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput'],
            ]],
        ],
        'FileMoveRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['ids', 'cid'],
            'properties' => [
                'ids' => ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput']],
                'cid' => ['$ref' => '#/components/schemas/FileNonNegativeIntegerInput'],
            ],
        ],
        'FileRenameRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name'],
            'properties' => [
                'id' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            ],
        ],
        'FileCategoryCreateRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'name'],
            'properties' => [
                'pid' => ['$ref' => '#/components/schemas/FileNonNegativeIntegerInput'],
                'type' => ['type' => 'integer', 'enum' => [10, 20, 30]],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            ],
        ],
        'FileCategoryUpdateRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name'],
            'properties' => [
                'id' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            ],
        ],
        'FileCategoryRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'pid', 'type', 'name', 'create_time', 'update_time', 'delete_time', 'tenant_id', 'children'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'pid' => ['type' => 'integer', 'minimum' => 0],
                'type' => ['type' => 'integer', 'enum' => [10, 20, 30]],
                'name' => ['type' => 'string', 'maxLength' => 64],
                'create_time' => ['type' => 'integer', 'minimum' => 0],
                'update_time' => ['type' => 'integer', 'minimum' => 0],
                'delete_time' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true],
                'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileCategoryRecord']],
            ],
        ],
        'FileListRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'id', 'cid', 'source_id', 'source', 'type', 'name', 'create_time',
                'update_time', 'delete_time', 'tenant_id', 'file_key', 'url',
            ],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'cid' => ['type' => 'integer', 'minimum' => 0],
                'source_id' => ['type' => 'integer', 'minimum' => 0],
                'source' => ['type' => 'integer', 'enum' => [0, 1]],
                'type' => ['type' => 'integer', 'enum' => [10, 20, 30]],
                'name' => ['type' => 'string'],
                'create_time' => ['oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'string']]],
                'update_time' => ['oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'string']]],
                'delete_time' => ['nullable' => true, 'oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'string']]],
                'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'url' => ['type' => 'string'],
            ],
        ],
        'FileListPage' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['lists', 'count', 'pageNo', 'pageSize'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileListRecord']],
                'count' => ['type' => 'integer', 'minimum' => 0],
                'pageNo' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ],
        'FileListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]],
                'msg' => ['type' => 'string'],
                'data' => ['$ref' => '#/components/schemas/FileListPage'],
            ],
        ],
        'FileUploadResult' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'cid', 'type', 'name', 'file_key', 'uri', 'url'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1], 'cid' => ['type' => 'integer', 'minimum' => 0],
                'type' => ['type' => 'integer', 'enum' => [10, 20, 30]], 'name' => ['type' => 'string'],
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'uri' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'], 'url' => ['type' => 'string'],
            ],
        ],
        'FileDeleteResult' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['files_deleted', 'storage_deleted'],
            'properties' => ['files_deleted' => ['type' => 'integer', 'minimum' => 0], 'storage_deleted' => ['type' => 'integer', 'minimum' => 0]],
        ],
        'FileCategoryDeleteResult' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['categories_deleted', 'files_deleted', 'storage_deleted'],
            'properties' => [
                'categories_deleted' => ['type' => 'integer', 'minimum' => 1],
                'files_deleted' => ['type' => 'integer', 'minimum' => 0],
                'storage_deleted' => ['type' => 'integer', 'minimum' => 0],
            ],
        ],
        'FileMutationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['type' => 'array', 'maxItems' => 0]],
        ],
        'FileUploadResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/FileUploadResult']],
        ],
        'FileDeleteResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/FileDeleteResult']],
        ],
        'FileCategoryDeleteResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/FileCategoryDeleteResult']],
        ],
        'FileCategoryListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]],
                'msg' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileCategoryRecord']],
            ],
        ],
    ]],
];
