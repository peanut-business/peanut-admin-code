<?php
declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ApiResponse'];
$json = static fn(string $schema): array => [
    'description' => 'Successful task-job response.',
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]],
];
$jobKey = ['name' => 'jobKey', 'in' => 'path', 'required' => true, 'schema' => [
    'type' => 'string', 'pattern' => '^job_[0-9a-f]{32}$',
]];
$revisionBody = [
    'required' => true,
    'content' => ['application/json' => ['schema' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['revision'],
        'properties' => ['revision' => ['type' => 'integer', 'minimum' => 1]],
    ]]],
];

return [
    'paths' => [
        '/adminapi/api/v1/tasks/jobs' => [
            'get' => [
                'tags' => ['Task'], 'operationId' => 'listTaskJobs',
                'parameters' => [
                    ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'queued', 'enum' => ['queued', 'running', 'succeeded', 'dead', 'cancelled']]],
                    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 20]],
                ],
                'responses' => ['200' => $json('TaskJobListResponse'), '401' => $error, '403' => $error, '422' => $error, '503' => $error],
            ],
        ],
        '/adminapi/api/v1/tasks/jobs/{jobKey}/cancel' => [
            'post' => [
                'tags' => ['Task'], 'operationId' => 'cancelTaskJob', 'parameters' => [$jobKey],
                'requestBody' => $revisionBody,
                'responses' => ['200' => $json('TaskJobResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/tasks/jobs/{jobKey}/retry' => [
            'post' => [
                'tags' => ['Task'], 'operationId' => 'retryTaskJob', 'parameters' => [$jobKey],
                'requestBody' => $revisionBody,
                'responses' => ['200' => $json('TaskJobResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error, '503' => $error],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'TaskJob' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['job_key', 'task_type', 'status', 'attempt_count', 'max_attempts', 'revision', 'last_error_code', 'available_at', 'created_at', 'updated_at', 'completed_at'],
            'properties' => [
                'job_key' => ['type' => 'string', 'pattern' => '^job_[0-9a-f]{32}$'],
                'task_type' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$'],
                'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'succeeded', 'dead', 'cancelled']],
                'attempt_count' => ['type' => 'integer', 'minimum' => 0],
                'max_attempts' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                'revision' => ['type' => 'integer', 'minimum' => 1],
                'last_error_code' => ['type' => 'string', 'nullable' => true, 'pattern' => '^[A-Z][A-Z0-9_]{2,63}$'],
                'available_at' => ['type' => 'string', 'format' => 'date-time'],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                'completed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'TaskJobResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['$ref' => '#/components/schemas/TaskJob'],
                'meta' => ['$ref' => '#/components/schemas/TaskRequestMeta'],
            ],
        ],
        'TaskJobListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TaskJob']],
                ]],
                'meta' => ['allOf' => [
                    ['$ref' => '#/components/schemas/TaskRequestMeta'],
                    ['type' => 'object', 'required' => ['page', 'page_size', 'total'], 'properties' => [
                        'page' => ['type' => 'integer', 'minimum' => 1],
                        'page_size' => ['type' => 'integer', 'minimum' => 1],
                        'total' => ['type' => 'integer', 'minimum' => 0],
                    ]],
                ]],
            ],
        ],
        'TaskRequestMeta' => [
            'type' => 'object', 'required' => ['request_id'],
            'properties' => ['request_id' => ['type' => 'string', 'minLength' => 1]],
        ],
    ]],
];
