<?php
declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ApiResponse'];
$success = static fn(string $schema): array => [
    'description' => 'Successful notification-inbox response.',
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]],
];
$messageKey = ['name' => 'messageKey', 'in' => 'path', 'required' => true, 'schema' => [
    'type' => 'string', 'pattern' => '^notice_[0-9a-f]{32}$',
]];

return [
    'paths' => [
        '/adminapi/api/v1/notifications' => [
            'get' => [
                'tags' => ['Notice'], 'operationId' => 'listInboxNotifications',
                'parameters' => [
                    ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'all', 'enum' => ['all', 'unread', 'read', 'archived']]],
                    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 20]],
                ],
                'responses' => ['200' => $success('NotificationListResponse'), '401' => $error, '403' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/notifications/{messageKey}/read' => [
            'post' => [
                'tags' => ['Notice'], 'operationId' => 'markInboxNotificationRead',
                'parameters' => [$messageKey, [
                    'name' => 'If-Match', 'in' => 'header', 'required' => true,
                    'schema' => ['type' => 'string', 'pattern' => '^"rev-[1-9][0-9]*"$'],
                ]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                ]]]],
                'responses' => ['200' => $success('NotificationResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/notifications/bulk' => [
            'post' => [
                'tags' => ['Notice'], 'operationId' => 'bulkUpdateInboxNotifications',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['message_keys', 'action'],
                    'properties' => [
                        'message_keys' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'pattern' => '^notice_[0-9a-f]{32}$']],
                        'action' => ['type' => 'string', 'enum' => ['read', 'archive']],
                    ],
                ]]]],
                'responses' => ['200' => $success('NotificationBulkResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'NotificationAttachment' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['file_key', 'original_name', 'media_type', 'size_bytes', 'sha256'],
            'properties' => [
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'original_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'media_type' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$'],
                'size_bytes' => ['type' => 'integer', 'minimum' => 0],
                'sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            ],
        ],
        'NotificationMessage' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['message_key', 'template_key', 'template_revision', 'subject', 'body', 'status', 'revision', 'created_at', 'read_at', 'archived_at', 'attachments'],
            'properties' => [
                'message_key' => ['type' => 'string', 'pattern' => '^notice_[0-9a-f]{32}$'],
                'template_key' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$'],
                'template_revision' => ['type' => 'integer', 'minimum' => 1],
                'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10000],
                'status' => ['type' => 'string', 'enum' => ['unread', 'read', 'archived']],
                'revision' => ['type' => 'integer', 'minimum' => 1],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'read_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'archived_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'attachments' => ['type' => 'array', 'maxItems' => 10, 'items' => ['$ref' => '#/components/schemas/NotificationAttachment']],
            ],
        ],
        'NotificationMeta' => ['type' => 'object', 'required' => ['request_id'], 'properties' => [
            'request_id' => ['type' => 'string', 'minLength' => 1],
        ]],
        'NotificationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => ['data' => ['$ref' => '#/components/schemas/NotificationMessage'], 'meta' => ['$ref' => '#/components/schemas/NotificationMeta']],
        ],
        'NotificationListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NotificationMessage']],
                ]],
                'meta' => ['allOf' => [
                    ['$ref' => '#/components/schemas/NotificationMeta'],
                    ['type' => 'object', 'required' => ['page', 'page_size', 'total'], 'properties' => [
                        'page' => ['type' => 'integer', 'minimum' => 1], 'page_size' => ['type' => 'integer', 'minimum' => 1], 'total' => ['type' => 'integer', 'minimum' => 0],
                    ]],
                ]],
            ],
        ],
        'NotificationBulkResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['changed'], 'properties' => ['changed' => ['type' => 'integer', 'minimum' => 0]]],
                'meta' => ['$ref' => '#/components/schemas/NotificationMeta'],
            ],
        ],
    ]],
];
