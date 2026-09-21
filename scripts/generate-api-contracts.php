#!/usr/bin/env php
<?php
declare(strict_types=1);

$repositoryRoot = dirname(__DIR__);
$projectRoot = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--project-root=')) {
        $projectRoot = substr($argument, strlen('--project-root='));
    }
}
$projectRoot ??= $repositoryRoot . '/../peanut-admin-project';
$resolvedProjectRoot = realpath($projectRoot);
if ($resolvedProjectRoot === false) {
    fwrite(STDERR, "generate-api-contracts: Project root is unavailable: {$projectRoot}\n");
    exit(1);
}

require_once $repositoryRoot . '/server/route/registry_source.php';

try {
    $contract = loadContract($repositoryRoot);
    $inventory = peanut_route_endpoint_inventory($repositoryRoot . '/server');
    $routes = indexRoutes($inventory['endpoints']);
    $contract = applyRouteMetadata($contract, $routes);
    validateContract($contract, $routes);
    $catalog = buildCatalog($repositoryRoot, $contract, $inventory);

    writeJson($resolvedProjectRoot . '/docs/api/openapi.yaml', $contract);
    writeJson($repositoryRoot . '/server/generated/api-catalog.json', $catalog);
    writeModuleTypes($repositoryRoot, $contract, $routes);

    $command = sprintf(
        'pnpm --dir %s exec openapi-typescript %s --output %s',
        escapeshellarg($repositoryRoot . '/web'),
        escapeshellarg($resolvedProjectRoot . '/docs/api/openapi.yaml'),
        escapeshellarg($repositoryRoot . '/web/src/generated/openapi.d.ts'),
    );
    passthru($command, $status);
    if ($status !== 0) {
        throw new RuntimeException("openapi-typescript failed with exit {$status}");
    }

    printf(
        "API-GENERATION-001 passed: %d runtime routes, %d documented operations, %d owners\n",
        $catalog['summary']['runtime_routes'],
        $catalog['summary']['documented_operations'],
        $catalog['summary']['owners'],
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'generate-api-contracts: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** @return array<string,mixed> */
function loadContract(string $repositoryRoot): array
{
    $source = $repositoryRoot . '/server/app/api/metadata/contracts/openapi.json';
    $contract = json_decode((string)file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($contract)) {
        throw new RuntimeException('Structured OpenAPI source must be an object');
    }

    foreach (glob($repositoryRoot . '/server/app/modules/*/*/api/metadata/openapi.php') ?: [] as $fragmentPath) {
        $fragment = require $fragmentPath;
        if (!is_array($fragment)) {
            throw new RuntimeException('Module OpenAPI fragment must return an array: ' . $fragmentPath);
        }
        $paths = isset($fragment['paths']) && is_array($fragment['paths'])
            ? $fragment['paths']
            : $fragment;
        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                throw new RuntimeException('Module OpenAPI fragment path is invalid: ' . $fragmentPath);
            }
            foreach ($pathItem as $method => $operation) {
                $method = strtolower((string)$method);
                if (isset($contract['paths'][$path][$method])) {
                    throw new RuntimeException("Duplicate OpenAPI operation: {$method} {$path}");
                }
                $contract['paths'][$path][$method] = $operation;
            }
        }
        foreach (['schemas', 'responses'] as $componentType) {
            foreach (($fragment['components'][$componentType] ?? []) as $name => $component) {
                if (!is_string($name) || !is_array($component) || isset($contract['components'][$componentType][$name])) {
                    throw new RuntimeException('Module OpenAPI component is invalid or duplicated: ' . $fragmentPath . '#' . $componentType . '/' . (string)$name);
                }
                $contract['components'][$componentType][$name] = $component;
            }
        }
    }
    return $contract;
}

/** @param list<array<string,mixed>> $endpoints @return array<string,array<string,mixed>> */
function indexRoutes(array $endpoints): array
{
    $routes = [];
    foreach ($endpoints as $endpoint) {
        $key = routeKey((string)$endpoint['method'], (string)$endpoint['path']);
        if (isset($routes[$key])) {
            throw new RuntimeException('Duplicate runtime route: ' . $key);
        }
        $routes[$key] = $endpoint;
    }
    return $routes;
}

/** @param array<string,mixed> $contract @param array<string,array<string,mixed>> $routes @return array<string,mixed> */
function applyRouteMetadata(array $contract, array $routes): array
{
    $tagNames = [];
    foreach ($contract['tags'] ?? [] as $tag) {
        if (is_array($tag) && is_string($tag['name'] ?? null)) {
            $tagNames[$tag['name']] = true;
        }
    }
    foreach ($contract['paths'] as $path => &$pathItem) {
        foreach ($pathItem as $method => &$operation) {
            if (!is_array($operation) || !isset($operation['operationId'])) {
                continue;
            }
            $key = routeKey((string)$method, (string)$path);
            if (!isset($routes[$key])) {
                continue;
            }
            $route = $routes[$key];
            [$auth, $permissions] = accessMetadata($route, null);
            if (!in_array($auth['mode'], ['public', 'public_tenant_module'], true)) {
                $operation['security'] ??= [['bearerAuth' => []]];
            }
            $operation['x-peanut-route'] = [
                'application' => $route['application'],
                'owner' => $route['owner'],
                'controller' => $route['controller'],
                'action' => $route['action'],
                'permission' => $route['permission'] ?? null,
                'source' => ['file' => $route['source'], 'line' => $route['line']],
            ];
            $operation['x-peanut-permissions'] = $permissions;
            foreach ($operation['tags'] ?? [] as $tagName) {
                if (is_string($tagName) && !isset($tagNames[$tagName])) {
                    $contract['tags'][] = ['name' => $tagName];
                    $tagNames[$tagName] = true;
                }
            }
        }
        unset($operation);
    }
    unset($pathItem);
    return $contract;
}

/** @param array<string,mixed> $contract @param array<string,array<string,mixed>> $routes */
function validateContract(array $contract, array $routes): void
{
    if (($contract['openapi'] ?? null) !== '3.0.3') {
        throw new RuntimeException('OpenAPI target must be exactly 3.0.3');
    }
    $operationIds = [];
    foreach (($contract['paths'] ?? []) as $path => $pathItem) {
        if (!is_array($pathItem)) {
            throw new RuntimeException('OpenAPI path item must be an object: ' . (string)$path);
        }
        foreach ($pathItem as $method => $operation) {
            if (!in_array(strtolower((string)$method), ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            $key = routeKey((string)$method, (string)$path);
            if (!isset($routes[$key])) {
                throw new RuntimeException('OpenAPI operation has no runtime route: ' . $key);
            }
            $operationId = $operation['operationId'] ?? null;
            if (!is_string($operationId)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $operationId) !== 1
                || isset($operationIds[$operationId])) {
                throw new RuntimeException('OpenAPI operationId is missing or duplicated: ' . $key);
            }
            $operationIds[$operationId] = true;
            if (!is_array($operation['responses'] ?? null) || $operation['responses'] === []) {
                throw new RuntimeException('OpenAPI responses are missing: ' . $key);
            }
        }
    }
    validateSchemas($contract['components']['schemas'] ?? []);
    validateReferences($contract, $contract);
}

/** @param mixed $schemas */
function validateSchemas(mixed $schemas): void
{
    if (!is_array($schemas) || $schemas === []) {
        throw new RuntimeException('OpenAPI components.schemas must not be empty');
    }
    foreach ($schemas as $name => $schema) {
        if (!is_array($schema) || $schema === []) {
            throw new RuntimeException('OpenAPI schema must not be empty: ' . (string)$name);
        }
        validateSchemaNode($schema, '#/components/schemas/' . (string)$name);
    }
}

/** @param array<string|int,mixed> $node */
function validateSchemaNode(array $node, string $pointer): void
{
    foreach ($node as $key => $value) {
        if (!is_array($value)) {
            continue;
        }
        $child = $pointer . '/' . (string)$key;
        if ($value === []) {
            throw new RuntimeException('OpenAPI contains an empty schema node: ' . $child);
        }
        validateSchemaNode($value, $child);
    }
}

/** @param mixed $node @param array<string,mixed> $root */
function validateReferences(mixed $node, array $root): void
{
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/')) {
            $cursor = $root;
            foreach (explode('/', substr($value, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    throw new RuntimeException('Unresolved OpenAPI reference: ' . $value);
                }
                $cursor = $cursor[$segment];
            }
        }
        validateReferences($value, $root);
    }
}

/** @return array<string,mixed> */
function buildCatalog(string $repositoryRoot, array $contract, array $inventory): array
{
    $documented = [];
    foreach ($contract['paths'] as $path => $pathItem) {
        foreach ($pathItem as $method => $operation) {
            if (is_array($operation) && isset($operation['operationId'])) {
                $documented[routeKey((string)$method, (string)$path)] = $operation;
            }
        }
    }

    $access = require $repositoryRoot . '/server/config/admin_api_access.php';
    $exceptions = [];
    foreach (['public', 'authenticated', 'platform_public', 'platform_authenticated'] as $class) {
        foreach ($access[$class] ?? [] as $route) {
            $parts = preg_split('/\s+/', trim((string)$route), 2);
            if (count($parts) === 2) {
                $exceptions[routeKey($parts[0], $parts[1])] = $class;
            }
        }
    }

    $endpoints = [];
    $owners = [];
    $documentationGaps = [];
    foreach ($inventory['endpoints'] as $endpoint) {
        $key = routeKey((string)$endpoint['method'], (string)$endpoint['path']);
        $operation = $documented[$key] ?? null;
        [$auth, $permissions] = accessMetadata($endpoint, $exceptions[$key] ?? null);
        $errors = inferredErrors($auth, $permissions);
        if (is_array($operation)) {
            foreach (array_keys($operation['responses'] ?? []) as $status) {
                if (ctype_digit((string)$status) && (int)$status >= 400) {
                    $errors[] = (int)$status;
                }
            }
        }
        $errors = array_values(array_unique($errors));
        sort($errors);
        $owner = (string)$endpoint['owner']['type'] . ':' . (string)$endpoint['owner']['key'];
        $owners[$owner] = ($owners[$owner] ?? 0) + 1;
        $documentation = $operation !== null
            ? [
                'status' => 'documented',
                'automatic_metadata' => ['route', 'owner', 'controller', 'middleware', 'auth', 'permissions', 'source'],
                'requires_owner_contract' => [],
            ]
            : [
                'status' => 'route_only',
                'automatic_metadata' => ['route', 'owner', 'controller', 'middleware', 'auth', 'permissions', 'source'],
                'requires_owner_contract' => ['operationId', 'parameters_or_request_body', 'response_schemas', 'business_errors'],
            ];
        if ($operation === null) {
            $moduleFragment = null;
            if (($endpoint['owner']['type'] ?? null) === 'module'
                && preg_match('#^(server/app/modules/[^/]+/[^/]+)/#', (string)$endpoint['source'], $moduleRoot) === 1) {
                $moduleFragment = $moduleRoot[1] . '/api/metadata/openapi.php';
            }
            $documentationGaps[] = [
                'method' => $endpoint['method'],
                'path' => openApiPath((string)$endpoint['path']),
                'owner' => $endpoint['owner'],
                'source' => ['file' => $endpoint['source'], 'line' => $endpoint['line']],
                'reason' => 'Runtime metadata is known; request, response and business-error contracts require the route owner.',
                'module_fragment' => $moduleFragment,
            ];
        }
        $endpoints[] = [
            'method' => $endpoint['method'],
            'path' => openApiPath((string)$endpoint['path']),
            'runtime_path' => $endpoint['path'],
            'application' => $endpoint['application'],
            'owner' => $endpoint['owner'],
            'controller' => $endpoint['controller'],
            'action' => $endpoint['action'],
            'auth' => $auth,
            'permissions' => $permissions,
            'errors' => $errors,
            'middleware' => $endpoint['middleware'],
            'source' => ['file' => $endpoint['source'], 'line' => $endpoint['line']],
            'documented' => $operation !== null,
            'openapi_operation_id' => $operation['operationId'] ?? null,
            'documentation' => $documentation,
        ];
    }
    ksort($owners);

    return [
        'schema_version' => 1,
        'openapi_version' => $contract['openapi'],
        'inputs' => catalogInputs($repositoryRoot, $inventory),
        'generated_from' => [
            'routes' => 'server/route/registry_source.php::peanut_route_endpoint_inventory',
            'contracts' => 'server/app/api/metadata/contracts/openapi.json',
        ],
        'summary' => [
            'runtime_routes' => count($endpoints),
            'documented_operations' => count($documented),
            'undocumented_routes' => count($documentationGaps),
            'automatic_route_metadata' => count($endpoints),
            'requires_owner_contract' => count($documentationGaps),
            'owners' => count($owners),
            'runtime_inventory' => $inventory['summary'],
        ],
        'owners' => $owners,
        'documentation_gaps' => $documentationGaps,
        'endpoints' => $endpoints,
    ];
}

/** @return list<array{path:string,sha256:string}> */
function catalogInputs(string $repositoryRoot, array $inventory): array
{
    $paths = [
        'server/app/api/metadata/contracts/openapi.json' => true,
        'server/config/admin_api_access.php' => true,
        'server/route/registry_source.php' => true,
    ];
    foreach ($inventory['endpoints'] as $endpoint) {
        $paths[(string)$endpoint['source']] = true;
    }
    foreach (glob($repositoryRoot . '/server/app/modules/*/*/api/metadata/openapi.php') ?: [] as $absolutePath) {
        $paths[substr($absolutePath, strlen($repositoryRoot) + 1)] = true;
    }
    ksort($paths, SORT_STRING);

    $inputs = [];
    foreach (array_keys($paths) as $relativePath) {
        if ($relativePath === ''
            || str_starts_with($relativePath, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $relativePath) === 1) {
            throw new RuntimeException('Catalog input must be repository-relative: ' . $relativePath);
        }
        $absolutePath = $repositoryRoot . '/' . $relativePath;
        if (!is_file($absolutePath) || is_link($absolutePath)) {
            throw new RuntimeException('Catalog input must be a regular non-symlink file: ' . $relativePath);
        }
        $sha256 = hash_file('sha256', $absolutePath);
        if ($sha256 === false) {
            throw new RuntimeException('Cannot hash catalog input: ' . $relativePath);
        }
        $inputs[] = ['path' => $relativePath, 'sha256' => $sha256];
    }
    return $inputs;
}

/** @return array{array<string,mixed>,list<array<string,string>>} */
function accessMetadata(array $endpoint, ?string $exception): array
{
    $classes = [];
    $permissions = [];
    foreach ($endpoint['middleware'] as $middleware) {
        $class = (string)$middleware['class'];
        $classes[$class] = $middleware['arguments'];
        if (str_ends_with($class, '\\PlatformPermissionMiddleware') && isset($middleware['arguments'][0])) {
            $permissions[] = ['kind' => 'platform_action', 'key' => (string)$middleware['arguments'][0]];
        }
        if (str_ends_with($class, '\\OfficialModuleMiddleware') && isset($middleware['arguments'][0])) {
            $permissions[] = ['kind' => 'module_capability', 'key' => (string)$middleware['arguments'][0]];
        }
        if (str_ends_with($class, '\\PublicTenantModuleMiddleware')) {
            if (isset($middleware['arguments'][0])) {
                $permissions[] = ['kind' => 'public_capability', 'key' => (string)$middleware['arguments'][0]];
            }
            if (isset($middleware['arguments'][1])) {
                $permissions[] = ['kind' => 'module_capability', 'key' => (string)$middleware['arguments'][1]];
            }
        }
    }

    $mode = match ($exception) {
        'public', 'platform_public' => 'public',
        'authenticated', 'platform_authenticated' => 'authenticated',
        default => 'public',
    };
    if (hasMiddlewareSuffix($classes, '\\PlatformPermissionMiddleware')) {
        $mode = 'platform_permission';
    } elseif (hasMiddlewareSuffix($classes, '\\PlatformLoginMiddleware')) {
        $mode = 'platform_session';
    } elseif (hasMiddlewareSuffix($classes, '\\AuthMiddleware')) {
        $mode = 'tenant_permission';
        $permissions[] = [
            'kind' => 'tenant_action',
            'key' => is_string($endpoint['permission'] ?? null) && $endpoint['permission'] !== ''
                ? $endpoint['permission']
                : preg_replace('#^/adminapi/#', '', (string)$endpoint['path']),
        ];
    } elseif (hasMiddlewareSuffix($classes, '\\LoginMiddleware')) {
        $mode = 'tenant_session';
    } elseif (hasMiddlewareSuffix($classes, '\\CheckTokenMiddleware')) {
        $mode = 'member_session';
    } elseif (hasMiddlewareSuffix($classes, '\\PublicTenantModuleMiddleware')) {
        $mode = 'public_tenant_module';
    }

    return [[
        'mode' => $mode,
        'exception_registration' => $exception,
    ], $permissions];
}

/** @param array<string,mixed> $classes */
function hasMiddlewareSuffix(array $classes, string $suffix): bool
{
    foreach (array_keys($classes) as $class) {
        if (str_ends_with($class, $suffix)) {
            return true;
        }
    }
    return false;
}

/** @param array<string,mixed> $auth @param list<array<string,string>> $permissions @return list<int> */
function inferredErrors(array $auth, array $permissions): array
{
    $errors = [];
    if (in_array($auth['mode'], ['authenticated', 'tenant_session', 'tenant_permission', 'member_session', 'platform_session', 'platform_permission'], true)) {
        $errors[] = 401;
    }
    if ($permissions !== [] || in_array($auth['mode'], ['tenant_permission', 'platform_permission', 'public_tenant_module'], true)) {
        $errors[] = 403;
    }
    return $errors;
}

/** @param array<string,mixed> $contract @param array<string,array<string,mixed>> $routes */
function writeModuleTypes(string $repositoryRoot, array $contract, array $routes): void
{
    $modules = [];
    foreach ($contract['paths'] as $path => $pathItem) {
        foreach ($pathItem as $method => $operation) {
            if (!is_array($operation) || !isset($operation['operationId'])) {
                continue;
            }
            $route = $routes[routeKey((string)$method, (string)$path)];
            if (($route['owner']['type'] ?? null) !== 'module') {
                continue;
            }
            $modules[(string)$route['owner']['key']][] = [
                'method' => strtolower((string)$method),
                'path' => openApiPath((string)$path),
                'operationId' => (string)$operation['operationId'],
            ];
        }
    }

    foreach ($modules as $moduleKey => $operations) {
        $moduleDirectory = str_replace('.', '-', $moduleKey);
        $targetDirectory = $repositoryRoot . '/web/src/modules/' . $moduleDirectory . '/generated';
        if (!is_dir(dirname($targetDirectory))) {
            continue;
        }
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Cannot create module generated directory: ' . $targetDirectory);
        }
        $lines = [
            "// Generated by scripts/generate-api-contracts.php. Do not edit.",
            "import createClient, { wrapAsPathBasedClient } from 'openapi-fetch';",
            "import type { Client, ClientOptions } from 'openapi-fetch';",
            "import type { paths } from '../../../generated/openapi';",
            '',
            'export interface ModuleApiOperations {',
        ];
        foreach ($operations as $operation) {
            $lines[] = sprintf(
                "  %s: paths[%s][%s];",
                $operation['operationId'],
                var_export($operation['path'], true),
                var_export($operation['method'], true),
            );
        }
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export function createModuleApi(client: Client<paths>) {';
        $lines[] = '  const pathClient = wrapAsPathBasedClient(client);';
        $lines[] = '  return {';
        foreach ($operations as $operation) {
            $lines[] = sprintf(
                "    %s: pathClient[%s].%s,",
                $operation['operationId'],
                var_export($operation['path'], true),
                strtoupper($operation['method']),
            );
        }
        $lines[] = '  };';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'export function createModuleClient(options?: ClientOptions) {';
        $lines[] = '  return createModuleApi(createClient<paths>(options));';
        $lines[] = '}';
        $lines[] = '';
        file_put_contents($targetDirectory . '/openapi.ts', implode("\n", $lines));
    }
}

function routeKey(string $method, string $path): string
{
    return strtoupper($method) . ' ' . openApiPath($path);
}

function openApiPath(string $path): string
{
    return preg_replace('/:([A-Za-z_][A-Za-z0-9_]*)/', '{$1}', $path) ?? $path;
}

/** @param array<string,mixed> $value */
function writeJson(string $path, array $value): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create generated directory: ' . $directory);
    }
    $json = json_encode(
        preserveJsonObjects($value),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Cannot write generated file: ' . $path);
    }
}

/**
 * PHP associative decoding cannot distinguish `{}` from `[]`. OpenAPI security
 * requirement values are deliberately empty objects, so restore that shape.
 */
function preserveJsonObjects(mixed $value, ?string $key = null): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if ($value === [] && $key === 'bearerAuth') {
        return (object)[];
    }
    $normalized = [];
    foreach ($value as $childKey => $childValue) {
        $normalized[$childKey] = preserveJsonObjects($childValue, (string)$childKey);
    }
    return $normalized;
}
