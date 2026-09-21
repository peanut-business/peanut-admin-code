<?php
declare(strict_types=1);

/*
 * Article HTTP contract source.
 *
 * Request fields come from the controller CRUD_INPUT_FIELDS declarations and
 * ArticleValidate / ArticleCateValidate scenes. Response fields and stable
 * business error codes come from the two administration services. Keep this
 * fragment beside the Module; scripts/generate-api-contracts.php merges it
 * with the application contract and runtime route inventory.
 */

$schemaRef = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
$responseRef = static fn(string $name): array => ['$ref' => '#/components/responses/' . $name];

$jsonBody = static fn(string $schema): array => [
    'required' => true,
    'content' => [
        'application/json' => [
            'schema' => ['$ref' => '#/components/schemas/' . $schema],
        ],
    ],
];

$successResponse = static fn(string $schema, string $description): array => [
    'description' => $description,
    'content' => [
        'application/json' => [
            'schema' => ['$ref' => '#/components/schemas/' . $schema],
        ],
    ],
];

$operation = static function (
    string $operationId,
    string $summary,
    string $responseSchema,
    array $parameters = [],
    ?string $requestSchema = null,
    array $businessErrors = [],
    array $errorStatuses = [],
) use ($jsonBody, $successResponse, $responseRef): array {
    $value = [
        'tags' => ['Article'],
        'operationId' => $operationId,
        'summary' => $summary,
        'parameters' => $parameters,
        'responses' => [
            '200' => $successResponse($responseSchema, $summary . '成功'),
            '401' => $responseRef('ArticleErrorResponse'),
            '403' => $responseRef('ArticleErrorResponse'),
        ],
    ];
    if ($requestSchema !== null) {
        $value['requestBody'] = $jsonBody($requestSchema);
    }
    foreach ($errorStatuses as $status) {
        $value['responses'][(string)$status] = $responseRef('ArticleErrorResponse');
    }
    if ($businessErrors !== []) {
        $value['x-peanut-errors'] = $businessErrors;
    }
    return $value;
};

$positiveIntegerInput = $schemaRef('ArticlePositiveIntegerInput');
$identifierParameter = static fn(string $description): array => [
    'in' => 'query',
    'name' => 'id',
    'required' => true,
    'description' => $description,
    'schema' => ['$ref' => '#/components/schemas/ArticlePositiveIntegerInput'],
    'example' => 12,
];

$listParameters = static function (string $textField, bool $withCategory = false): array {
    $parameters = [
        ['$ref' => '#/components/parameters/PageNo'],
        ['$ref' => '#/components/parameters/PageSize'],
        ['in' => 'query', 'name' => 'page_start', 'schema' => ['$ref' => '#/components/schemas/ArticlePositiveIntegerInput']],
        ['in' => 'query', 'name' => 'page_end', 'schema' => ['$ref' => '#/components/schemas/ArticlePositiveIntegerInput']],
        ['in' => 'query', 'name' => 'page_type', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
        ['in' => 'query', 'name' => 'order_by', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']]],
        ['in' => 'query', 'name' => 'field', 'schema' => ['type' => 'string', 'enum' => ['create_time', 'id']]],
        ['in' => 'query', 'name' => $textField, 'schema' => ['type' => 'string']],
        ['in' => 'query', 'name' => 'is_show', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
        ['in' => 'query', 'name' => 'start_time', 'schema' => ['type' => 'string'], 'example' => '2026-09-01 00:00:00'],
        ['in' => 'query', 'name' => 'end_time', 'schema' => ['type' => 'string'], 'example' => '2026-09-30 23:59:59'],
        ['in' => 'query', 'name' => 'start', 'schema' => ['type' => 'number']],
        ['in' => 'query', 'name' => 'end', 'schema' => ['type' => 'number']],
    ];
    if ($withCategory) {
        $parameters[] = ['in' => 'query', 'name' => 'cid', 'schema' => ['$ref' => '#/components/schemas/ArticlePositiveIntegerInput']];
    }
    return $parameters;
};

$categoryListParameters = $listParameters('name');
$articleListParameters = $listParameters('title', true);
$categoryListParameters[] = ['in' => 'query', 'name' => 'export', 'schema' => ['type' => 'integer', 'enum' => [1, 2]]];
$articleListParameters[] = ['in' => 'query', 'name' => 'export', 'schema' => ['type' => 'integer', 'enum' => [1, 2]]];

$categoryPermissionError = ['ARTICLE_CATEGORY_ADMIN_PERMISSION_DENIED'];
$articlePermissionError = ['ARTICLE_ADMIN_PERMISSION_DENIED'];

return [
    'paths' => [
        '/adminapi/official.article.category.list' => [
            'get' => $operation(
                'listArticleCategories',
                '查询资讯分类',
                'ArticleCategoryPageResponse',
                $categoryListParameters,
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_EXPORT_UNSUPPORTED'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/official.article.category.all' => [
            'get' => $operation(
                'listEnabledArticleCategories',
                '查询全部启用资讯分类',
                'ArticleCategoryCollectionResponse',
                businessErrors: $categoryPermissionError,
            ),
        ],
        '/adminapi/official.article.category.detail' => [
            'get' => $operation(
                'getArticleCategory',
                '查询资讯分类详情',
                'ArticleCategoryDetailResponse',
                [$identifierParameter('资讯分类 ID')],
                businessErrors: $categoryPermissionError,
            ),
        ],
        '/adminapi/official.article.category.add' => [
            'post' => $operation(
                'createArticleCategory',
                '创建资讯分类',
                'ArticleMutationResponse',
                requestSchema: 'ArticleCategoryCreateRequest',
                businessErrors: $categoryPermissionError,
                errorStatuses: [400],
            ),
        ],
        '/adminapi/official.article.category.edit' => [
            'post' => $operation(
                'updateArticleCategory',
                '更新资讯分类',
                'ArticleMutationResponse',
                requestSchema: 'ArticleCategoryUpdateRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_NOT_FOUND'],
                errorStatuses: [400, 404],
            ),
        ],
        '/adminapi/official.article.category.delete' => [
            'post' => $operation(
                'deleteArticleCategory',
                '软删除资讯分类',
                'ArticleMutationResponse',
                requestSchema: 'ArticleIdentifierRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_NOT_FOUND', 'ARTICLE_CATEGORY_IN_USE'],
                errorStatuses: [400, 404, 409],
            ),
        ],
        '/adminapi/official.article.category.update-status' => [
            'post' => $operation(
                'updateArticleCategoryStatus',
                '更新资讯分类启用状态',
                'ArticleMutationResponse',
                requestSchema: 'ArticleStatusRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_NOT_FOUND'],
                errorStatuses: [400, 404],
            ),
        ],
        '/adminapi/official.article.category.recycle.list' => [
            'get' => $operation(
                'listRecycledArticleCategories',
                '查询已删除资讯分类',
                'ArticleCategoryPageResponse',
                array_values(array_filter($categoryListParameters, static fn(array $parameter): bool => ($parameter['name'] ?? null) !== 'export')),
                businessErrors: $categoryPermissionError,
            ),
        ],
        '/adminapi/official.article.category.recycle.detail' => [
            'get' => $operation(
                'getRecycledArticleCategory',
                '查询已删除资讯分类详情',
                'ArticleCategoryDetailResponse',
                [$identifierParameter('已删除资讯分类 ID')],
                businessErrors: $categoryPermissionError,
            ),
        ],
        '/adminapi/official.article.category.restore' => [
            'post' => $operation(
                'restoreArticleCategories',
                '恢复已删除资讯分类',
                'ArticleBatchMutationResponse',
                requestSchema: 'ArticleBatchIdentifierRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_BATCH_IDS_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/official.article.category.force-delete' => [
            'post' => $operation(
                'forceDeleteArticleCategories',
                '永久删除资讯分类',
                'ArticleBatchMutationResponse',
                requestSchema: 'ArticleBatchIdentifierRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_BATCH_IDS_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/official.article.list' => [
            'get' => $operation(
                'listAdminArticles',
                '查询资讯',
                'ArticlePageResponse',
                $articleListParameters,
                businessErrors: [...$articlePermissionError, 'ARTICLE_EXPORT_UNSUPPORTED'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/official.article.detail' => [
            'get' => $operation(
                'getAdminArticle',
                '查询资讯详情',
                'ArticleDetailResponse',
                [$identifierParameter('资讯 ID')],
                businessErrors: $articlePermissionError,
            ),
        ],
        '/adminapi/official.article.add' => [
            'post' => $operation(
                'createArticle',
                '创建资讯',
                'ArticleMutationResponse',
                requestSchema: 'ArticleCreateRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_CATEGORY_UNAVAILABLE'],
                errorStatuses: [400, 409],
            ),
        ],
        '/adminapi/official.article.edit' => [
            'post' => $operation(
                'updateArticle',
                '更新资讯',
                'ArticleMutationResponse',
                requestSchema: 'ArticleUpdateRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_NOT_FOUND', 'ARTICLE_CATEGORY_UNAVAILABLE'],
                errorStatuses: [400, 404, 409],
            ),
        ],
        '/adminapi/official.article.delete' => [
            'post' => $operation(
                'deleteArticle',
                '软删除资讯',
                'ArticleMutationResponse',
                requestSchema: 'ArticleIdentifierRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_NOT_FOUND'],
                errorStatuses: [400, 404],
            ),
        ],
        '/adminapi/official.article.update-status' => [
            'post' => $operation(
                'updateArticleStatus',
                '更新资讯展示状态',
                'ArticleMutationResponse',
                requestSchema: 'ArticleStatusRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_NOT_FOUND'],
                errorStatuses: [400, 404],
            ),
        ],
        '/adminapi/official.article.recycle.list' => [
            'get' => $operation(
                'listRecycledArticles',
                '查询已删除资讯',
                'ArticlePageResponse',
                array_values(array_filter($articleListParameters, static fn(array $parameter): bool => ($parameter['name'] ?? null) !== 'export')),
                businessErrors: $articlePermissionError,
            ),
        ],
        '/adminapi/official.article.recycle.detail' => [
            'get' => $operation(
                'getRecycledArticle',
                '查询已删除资讯详情',
                'ArticleDetailResponse',
                [$identifierParameter('已删除资讯 ID')],
                businessErrors: $articlePermissionError,
            ),
        ],
        '/adminapi/official.article.restore' => [
            'post' => $operation(
                'restoreArticles',
                '恢复已删除资讯',
                'ArticleBatchMutationResponse',
                requestSchema: 'ArticleBatchIdentifierRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_BATCH_IDS_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/official.article.force-delete' => [
            'post' => $operation(
                'forceDeleteArticles',
                '永久删除资讯',
                'ArticleBatchMutationResponse',
                requestSchema: 'ArticleBatchIdentifierRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_BATCH_IDS_INVALID'],
                errorStatuses: [400],
            ),
        ],
    ],
    'components' => [
        'schemas' => [
            'ArticlePositiveIntegerInput' => [
                'description' => '服务端接受正整数或仅含十进制数字的字符串。',
                'oneOf' => [
                    ['type' => 'integer', 'minimum' => 1],
                    ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
                ],
            ],
            'ArticleIdentifierRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id'],
                'properties' => ['id' => $positiveIntegerInput],
                'example' => ['id' => 12],
            ],
            'ArticleBatchIdentifierRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'id' => $positiveIntegerInput,
                    'ids' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 100,
                        'uniqueItems' => true,
                        'items' => $positiveIntegerInput,
                    ],
                ],
                'oneOf' => [
                    ['required' => ['id'], 'not' => ['required' => ['ids']]],
                    ['required' => ['ids'], 'not' => ['required' => ['id']]],
                ],
                'example' => ['ids' => [12, 13]],
            ],
            'ArticleStatusRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'is_show'],
                'properties' => [
                    'id' => $positiveIntegerInput,
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                ],
                'example' => ['id' => 12, 'is_show' => 1],
            ],
            'ArticleCategoryCreateRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['name', 'is_show'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 90],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                ],
                'example' => ['name' => '产品动态', 'is_show' => 1, 'sort' => 10],
            ],
            'ArticleCategoryUpdateRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'name', 'is_show'],
                'properties' => [
                    'id' => $positiveIntegerInput,
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 90],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ArticleCreateRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['title', 'cid', 'is_show'],
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'cid' => $positiveIntegerInput,
                    'desc' => ['type' => 'string', 'maxLength' => 255],
                    'abstract' => ['type' => 'string', 'maxLength' => 10000],
                    'image' => ['type' => 'string', 'maxLength' => 2048],
                    'author' => ['type' => 'string', 'maxLength' => 255],
                    'content' => ['type' => 'string', 'maxLength' => 1000000],
                    'click_virtual' => ['type' => 'integer', 'minimum' => 0],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                ],
                'example' => [
                    'title' => '多租户产品动态',
                    'cid' => 3,
                    'abstract' => '资讯摘要',
                    'content' => '<p>正文</p>',
                    'is_show' => 1,
                    'sort' => 10,
                ],
            ],
            'ArticleUpdateRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'title', 'cid', 'is_show'],
                'properties' => [
                    'id' => $positiveIntegerInput,
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'cid' => $positiveIntegerInput,
                    'desc' => ['type' => 'string', 'maxLength' => 255],
                    'abstract' => ['type' => 'string', 'maxLength' => 10000],
                    'image' => ['type' => 'string', 'maxLength' => 2048],
                    'author' => ['type' => 'string', 'maxLength' => 255],
                    'content' => ['type' => 'string', 'maxLength' => 1000000],
                    'click_virtual' => ['type' => 'integer', 'minimum' => 0],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ArticleCategoryRecord' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'name', 'sort', 'is_show', 'create_time', 'update_time', 'delete_time'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'name' => ['type' => 'string'],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'create_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'update_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'delete_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'article_count' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ArticleRecord' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'id', 'cid', 'title', 'desc', 'abstract', 'image', 'author', 'content',
                    'click_virtual', 'click_actual', 'click', 'is_show', 'sort', 'cate_name',
                    'create_time', 'update_time', 'delete_time',
                ],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'cid' => ['type' => 'integer', 'minimum' => 1],
                    'title' => ['type' => 'string'],
                    'desc' => ['type' => 'string'],
                    'abstract' => ['type' => 'string'],
                    'image' => ['type' => 'string'],
                    'author' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'click_virtual' => ['type' => 'integer', 'minimum' => 0],
                    'click_actual' => ['type' => 'integer', 'minimum' => 0],
                    'click' => ['type' => 'integer', 'minimum' => 0],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                    'cate_name' => ['type' => 'string'],
                    'create_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'update_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'delete_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                ],
            ],
            'ArticleTimestamp' => [
                'type' => 'string',
                'description' => '空字符串或服务端格式化的本地日期时间。',
                'pattern' => '^$|^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$',
            ],
            'ArticlePage' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $schemaRef('ArticleRecord')],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'ArticleCategoryPage' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $schemaRef('ArticleCategoryRecord')],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'ArticleBatchFailure' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'code', 'message'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'code' => ['type' => 'string', 'pattern' => '^ARTICLE_[A-Z0-9_]+$'],
                    'message' => ['type' => 'string'],
                ],
            ],
            'ArticleBatchMutationResult' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['requested', 'already_active', 'failed'],
                'properties' => [
                    'requested' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'restored' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'deleted' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'already_active' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'failed' => ['type' => 'array', 'items' => $schemaRef('ArticleBatchFailure')],
                ],
            ],
            'ArticleMutationResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string'],
                    'data' => ['type' => 'array', 'maxItems' => 0],
                ],
            ],
            'ArticlePageResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => $schemaRef('ArticlePage'),
                ],
            ],
            'ArticleCategoryPageResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => $schemaRef('ArticleCategoryPage'),
                ],
            ],
            'ArticleDetailResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => [
                        'oneOf' => [
                            $schemaRef('ArticleRecord'),
                            ['type' => 'array', 'maxItems' => 0],
                        ],
                    ],
                ],
            ],
            'ArticleCategoryDetailResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => [
                        'oneOf' => [
                            $schemaRef('ArticleCategoryRecord'),
                            ['type' => 'array', 'maxItems' => 0],
                        ],
                    ],
                ],
            ],
            'ArticleCategoryCollectionResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => ['type' => 'array', 'items' => $schemaRef('ArticleCategoryRecord')],
                ],
            ],
            'ArticleBatchMutationResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => $schemaRef('ArticleBatchMutationResult'),
                ],
            ],
            'ArticleErrorEnvelope' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'minimum' => 40000, 'maximum' => 59999],
                    'msg' => ['type' => 'string'],
                    'data' => [
                        'type' => 'object',
                        'required' => ['error_code'],
                        'properties' => [
                            'error_code' => ['type' => 'string', 'pattern' => '^[A-Z][A-Z0-9_]+$'],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
                'example' => [
                    'code' => 40900,
                    'msg' => '仅回收站资讯可永久删除',
                    'data' => ['error_code' => 'ARTICLE_FORCE_DELETE_REQUIRES_TRASHED'],
                ],
            ],
        ],
        'responses' => [
            'ArticleErrorResponse' => [
                'description' => '统一 API 错误；X-Request-Id 响应头用于追踪。',
                'headers' => [
                    'X-Request-Id' => [
                        'description' => '请求追踪 ID',
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'content' => [
                    'application/json' => [
                        'schema' => $schemaRef('ArticleErrorEnvelope'),
                    ],
                ],
            ],
        ],
    ],
];
