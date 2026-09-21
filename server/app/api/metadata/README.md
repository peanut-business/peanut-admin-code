# HTTP API metadata

`contracts/openapi.json` is the structured HTTP contract source. Every declared
method/path pair must match the route inventory built by
`server/route/registry_source.php`; the generator rejects declarations for
routes that do not exist.

Module owners may add a `api/metadata/openapi.php` file below their module. The
file returns either an OpenAPI `paths` fragment keyed by concrete runtime path,
or `{paths, components: {schemas}}` when it owns reusable schemas. The generator
merges these fragments and rejects duplicate operations or schema names,
unknown routes, unresolved schema references and empty schemas.

For example, the owner of `official.task` adds only its own file at
`server/app/modules/official/task/api/metadata/openapi.php`:

```php
<?php
declare(strict_types=1);

return [
    '/adminapi/official.task/jobs' => [
        'get' => [
            'operationId' => 'listTaskJobs',
            'tags' => ['Task'],
            'responses' => [
                '200' => ['$ref' => '#/components/responses/ApiResponse'],
                '401' => ['$ref' => '#/components/responses/ApiResponse'],
                '403' => ['$ref' => '#/components/responses/ApiResponse'],
            ],
        ],
    ],
];
```

The path and method must already exist in the route inventory. Owners must add
real parameters, request bodies, response schemas and business errors for the
implemented controller contract; the generator never guesses them from method
names. Shared component schemas stay in the central structured source until a
component-fragment contract is introduced.

Run `php scripts/generate-api-contracts.php --project-root=../peanut-admin-project`
to produce the Project OpenAPI 3.0.3 document, the complete route/access/error
catalog at `server/generated/api-catalog.json`, module-local TypeScript path
maps and typed SDK factories, and `web/src/generated/openapi.d.ts`.
