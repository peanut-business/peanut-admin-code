<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** 无数据库地核对路由清单、结构化合同质量和 SDK 消费入口。 */
final class ApiContractCatalogTest extends TestCase
{
    public function testAccessExceptionsUseCanonicalPathsWithoutRemovingSessionHostBoundaries(): void
    {
        $root = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3));
        $catalog = sys_get_temp_dir() . '/peanut-api-access-' . bin2hex(random_bytes(8)) . '.json';
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__, 3) . '/scripts/generate-api-contracts.php', '--application-root=' . $root, '--check', '--catalog-output=' . $catalog,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        try {
            self::assertSame(0, $status, $error);
            $endpoints = json_decode((string) file_get_contents($catalog), true, 512, JSON_THROW_ON_ERROR)['endpoints'];
            $routes = [];
            foreach ($endpoints as $endpoint) {
                $routes[$endpoint['method'] . ' ' . $endpoint['path']] = $endpoint;
            }
            $authenticated = ['GET /adminapi/login/info', 'GET /adminapi/menu/route', 'POST /adminapi/admin/editSelf'];
            if (isset(self::runtimeInventory($root)['modules']['peanut.file'])) {
                $authenticated[] = 'POST /adminapi/api/v1/files/resolve';
            }
            foreach ($authenticated as $path) {
                self::assertSame('authenticated', $routes[$path]['auth']['mode']);
                self::assertSame('authenticated', $routes[$path]['auth']['exception_registration']);
                self::assertSame([], array_values(array_filter(
                    $routes[$path]['permissions'],
                    static fn(array $permission): bool => in_array($permission['kind'], ['tenant_action', 'platform_action'], true),
                )));
            }
            self::assertSame('tenant_host', $routes['POST /adminapi/tenant/session/login']['auth']['mode']);
            self::assertSame('tenant_bearer', $routes['POST /adminapi/tenant/session/logout']['auth']['mode']);
            self::assertSame('platform_host', $routes['POST /platformapi/session/login']['auth']['mode']);
            self::assertSame('platform_refresh', $routes['POST /platformapi/session/refresh']['auth']['mode']);
            self::assertSame('tenant_permission', $routes['POST /adminapi/admin/add']['auth']['mode']);
            self::assertContains(['kind' => 'tenant_action', 'key' => 'core.member.create'], $routes['POST /adminapi/admin/add']['permissions']);
            self::assertContains(['kind' => 'tenant_action', 'key' => 'core.member.update'], $routes['POST /adminapi/admin/edit']['permissions']);
            self::assertContains(['kind' => 'tenant_action', 'key' => 'core.member.leave'], $routes['POST /adminapi/admin/delete']['permissions']);
            self::assertSame('complete', $routes['POST /platformapi/instance-tools/modules/update']['contract_quality']);
            self::assertSame('complete', $routes['GET /platformapi/instance-tools/modules/tasks/{task_key}']['contract_quality']);
        } finally {
            if (is_file($catalog)) {
                unlink($catalog);
            }
        }
    }

    public function testCatalogSeparatesRoutesGeneratedOperationsAndCompleteContracts(): void
    {
        $root = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3));
        $catalog = sys_get_temp_dir() . '/peanut-api-catalog-' . bin2hex(random_bytes(8)) . '.json';
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 3) . '/scripts/generate-api-contracts.php',
            '--application-root=' . $root, '--check',
            '--catalog-output=' . $catalog,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        try {
            self::assertSame(0, $status, (string) $stderr);
            self::assertStringContainsString('API-CONTRACT-CHECK-001 passed', (string) $stdout);
            $document = json_decode((string) file_get_contents($catalog), true, 512, JSON_THROW_ON_ERROR);
            $endpoints = $document['endpoints'];
            $summary = $document['summary'];

            self::assertSame(count($endpoints), $summary['route_count']);
            self::assertSame(
                count(array_filter($endpoints, static fn(array $endpoint): bool => $endpoint['documented'] === true)),
                $summary['generated_api_operations'],
            );
            self::assertSame(
                $summary['route_count'],
                $summary['complete_operations'] + $summary['partial_operations'] + $summary['route_only_operations'],
            );
            $inventory = self::runtimeInventory($root);
            self::assertSame(self::routeIdentities($inventory['endpoints']), self::routeIdentities($endpoints));
            $applicationRouteOnly = isset($inventory['modules']['peanut.reference-codes']) ? 0 : 14;
            $moduleRouteOnly = array_intersect_key(
                ['peanut.workflow' => 25, 'peanut.article' => 1],
                $inventory['modules'],
            );
            $routeOnly = $applicationRouteOnly + array_sum($moduleRouteOnly);
            $partial = isset($inventory['modules']['custom.ai-access']) ? 5 : 0;
            self::assertSame($summary['route_count'] - $routeOnly, $summary['generated_api_operations']);
            self::assertSame($summary['generated_api_operations'] - $partial, $summary['complete_operations']);
            self::assertSame($partial, $summary['partial_operations']);
            self::assertSame($routeOnly, $summary['route_only_operations']);

            $operationIds = [];
            foreach ($endpoints as $endpoint) {
                if ($endpoint['documented'] !== true) {
                    if ($endpoint['owner']['type'] === 'application') {
                        self::assertStringStartsWith('/adminapi/dict/', $endpoint['path']);
                    } else {
                        self::assertArrayHasKey($endpoint['owner']['key'], $moduleRouteOnly);
                    }
                    self::assertSame('route_only', $endpoint['contract_quality']);
                    self::assertSame(
                        ['operationId', 'parameters_or_request_body', 'response_schemas', 'business_errors'],
                        $endpoint['documentation']['requires_owner_contract'],
                    );
                    continue;
                }
                $operationId = $endpoint['openapi_operation_id'];
                self::assertIsString($operationId);
                self::assertArrayNotHasKey($operationId, $operationIds, 'duplicate operationId: ' . $operationId);
                $operationIds[$operationId] = true;
                self::assertContains('web/src/generated/openapi.d.ts', $endpoint['sdk_consumers']);
                if ($endpoint['contract_quality'] === 'complete') {
                    self::assertSame([], $endpoint['documentation']['requires_owner_contract']);
                } else {
                    self::assertSame('custom.ai-access', $endpoint['owner']['key']);
                    self::assertSame('partial', $endpoint['contract_quality']);
                    self::assertNotSame([], $endpoint['documentation']['requires_owner_contract']);
                }
            }

            foreach ($inventory['summary']['owners'] as $owner => $count) {
                [$type, $key] = explode(':', $owner, 2);
                if ($type === 'module') {
                    self::assertOwnerCoverage($endpoints, $key, $count, $count - ($moduleRouteOnly[$key] ?? 0));
                } else {
                    self::assertApplicationCoverage($endpoints, $key, $count, $key === 'adminapi' ? $applicationRouteOnly : 0);
                }
            }

            $byRoute = [];
            foreach ($endpoints as $endpoint) {
                $byRoute[$endpoint['method'] . ' ' . $endpoint['path']] = $endpoint;
            }
            self::assertSame('platform_host', $byRoute['POST /platformapi/session/login']['auth']['mode']);
            self::assertSame('platform_refresh', $byRoute['POST /platformapi/session/refresh']['auth']['mode']);
            self::assertSame('installation_setup', $byRoute['POST /installapi/execute']['auth']['mode']);
            self::assertSame('public', $byRoute['GET /installapi/status']['auth']['mode']);
            self::assertSame('tenant_refresh', $byRoute['POST /adminapi/tenant/session/refresh']['auth']['mode']);
            self::assertSame('complete', $byRoute['POST /adminapi/tenant/session/logout']['contract_quality']);
            $exportContent = 'GET /adminapi/api/v1/files/{fileKey}/content';
            if (isset($inventory['modules']['peanut.import-export'])) {
                self::assertSame('peanut.import-export', $byRoute[$exportContent]['owner']['key']);
                self::assertSame('tenant_permission', $byRoute[$exportContent]['auth']['mode']);
            } else {
                self::assertArrayNotHasKey($exportContent, $byRoute);
            }
            if (isset($inventory['modules']['peanut.file'])) {
                self::assertSame('complete', $byRoute['GET /api/storage/delivery']['contract_quality']);
            } else {
                self::assertArrayNotHasKey('GET /api/storage/delivery', $byRoute);
            }
        } finally {
            if (is_file($catalog)) {
                unlink($catalog);
            }
        }
    }

    public function testApplicationMetadataKeepsPreciseSecurityTransportAndProjectionSchemas(): void
    {
        $root = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3));
        $directory = $root . '/server/app/api/metadata/application';
        $shared = require $directory . '/shared.php';
        $admin = require $directory . '/adminapi.php';
        $platform = require $directory . '/platform.php';
        $installation = require $directory . '/installation.php';

        $inventory = self::runtimeInventory($root);
        $runtimeRoutes = self::routeIdentities($inventory['endpoints']);
        foreach ([$admin, $platform, $installation] as $fragment) {
            foreach (['paths', 'overrides'] as $section) {
                foreach ($fragment[$section] ?? [] as $path => $pathItem) {
                    if (array_diff($pathItem['x-peanut-required-modules'] ?? [], array_keys($inventory['modules'])) !== []) {
                        continue;
                    }
                    foreach (['get', 'post', 'put', 'delete', 'patch'] as $method) {
                        if (isset($pathItem[$method])) {
                            self::assertContains(strtoupper($method) . ' ' . $path, $runtimeRoutes);
                        }
                    }
                }
            }
        }

        $schemes = $shared['components']['securitySchemes'];
        self::assertSame('Host', $schemes['platformHost']['name']);
        foreach (['platformRefreshCookie' => '__Host-pa_platform_refresh', 'tenantRefreshCookie' => '__Host-pa_tenant_refresh_<client>'] as $scheme => $prefix) {
            self::assertSame('http', $schemes[$scheme]['type']);
            self::assertSame('bearer', $schemes[$scheme]['scheme']);
            self::assertStringContainsString($prefix, $schemes[$scheme]['description']);
            self::assertStringContainsString('SHA-256', $schemes[$scheme]['description']);
            self::assertArrayNotHasKey('name', $schemes[$scheme]);
        }
        self::assertSame('bearer', $schemes['installationSetupToken']['scheme']);

        $dynamic = $shared['components']['schemas']['ApplicationDynamicValue'];
        self::assertCount(6, $dynamic['oneOf']);
        self::assertSame(
            '#/components/schemas/ApplicationDynamicValue',
            $dynamic['oneOf'][5]['additionalProperties']['$ref'],
        );

        $installRequest = $platform['paths']['/platformapi/instance-tools/modules/install']['post']['requestBody'];
        self::assertArrayHasKey('multipart/form-data', $installRequest['content']);
        self::assertSame('binary', $installRequest['content']['multipart/form-data']['schema']['properties']['package']['format']);

        $logout = $admin['paths']['/adminapi/tenant/session/logout']['post']['responses']['204'];
        self::assertArrayNotHasKey('content', $logout);
        $task = $platform['overrides']['/platformapi/v1/ops/tasks/{task_key}']['get'];
        self::assertSame('task_key', $task['parameters'][0]['name']);
        self::assertTrue($task['parameters'][0]['required']);

        $diagnostics = $platform['paths']['/platformapi/v1/ops/diagnostics']['get']['responses']['200'];
        self::assertTrue($diagnostics['headers']['X-Diagnostic-SHA256']['required']);
        self::assertSame('object', $diagnostics['content']['application/json']['schema']['type']);
        self::assertSame(
            '#/components/schemas/ApplicationDynamicValue',
            $diagnostics['content']['application/json']['schema']['additionalProperties']['$ref'],
        );

        $tenantList = $platform['paths']['/platformapi/tenants']['get']['responses']['200'];
        self::assertSame(
            '#/components/schemas/PlatformTenant',
            $tenantList['content']['application/json']['schema']['properties']['data']['properties']['lists']['items']['$ref'],
        );
        self::assertSame(
            ['admin_email', 'admin_password'],
            $installation['components']['schemas']['InstallationExecuteRequest']['required'],
        );
    }

    public function testOfficialModuleContractsDistinguishSourceDiscoveryPreviewAndSubmission(): void
    {
        $platform = require dirname(__DIR__, 3) . '/server/app/api/metadata/application/platform.php';
        $paths = $platform['paths'];
        $schemas = $platform['components']['schemas'];
        $base = '/platformapi/instance-tools/modules/';
        $updates = $paths[$base . 'updates']['get'];

        self::assertSame('#/components/schemas/PlatformOfficialUpdateSnapshot',
            $updates['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref']);
        $snapshot = $schemas['PlatformOfficialUpdateSnapshot'];
        self::assertContains('catalog', $snapshot['required']);
        self::assertContains('withdrawal_policy', $snapshot['required']);
        self::assertSame(['verified-current', 'legacy-index'], $snapshot['properties']['withdrawal_policy']['enum']);
        self::assertTrue($schemas['PlatformOfficialModuleCatalogEntry']['properties']['source_version']['nullable']);

        [$preview, $submit] = $schemas['PlatformOfficialPackageRequest']['oneOf'];
        foreach ([$preview, $submit] as $input) {
            self::assertFalse($input['additionalProperties']);
            self::assertSame('boolean', $input['properties']['dry_run']['type']);
            self::assertSame('stable', $input['properties']['channel']['default']);
            self::assertNotContains('channel', $input['required']);
            self::assertArrayNotHasKey('operation', $input['properties']);
            self::assertArrayNotHasKey('path', $input['properties']);
            self::assertArrayNotHasKey('url', $input['properties']);
        }
        self::assertSame([true], $preview['properties']['dry_run']['enum']);
        self::assertSame([false], $submit['properties']['dry_run']['enum']);
        self::assertNotContains('confirm_selection_sha256', $preview['required']);
        self::assertContains('confirm_selection_sha256', $submit['required']);
        self::assertSame(['dry_run', 'operation', 'plan', 'selection_sha256'], $schemas['PlatformOfficialPackagePreview']['required']);
        self::assertSame(['install', 'update'], $schemas['PlatformOfficialPackagePreview']['properties']['operation']['enum']);
        self::assertSame('#/components/schemas/PlatformOpsTask', $schemas['PlatformOfficialPackageResult']['oneOf'][1]['$ref']);
        foreach (['install', 'update'] as $operation) {
            $post = $paths[$base . 'official-' . $operation]['post'];
            self::assertSame($operation . 'OfficialInstanceModule', $post['operationId']);
            self::assertSame('#/components/schemas/PlatformOfficialPackageRequest', $post['requestBody']['content']['application/json']['schema']['$ref']);
            self::assertSame('Idempotency-Key', $post['parameters'][0]['name']);
            self::assertFalse($post['parameters'][0]['required']);
            foreach (['401', '403', '409', '422', '503'] as $status) {
                self::assertArrayHasKey($status, $post['responses']);
            }
        }
    }

    public function testIntegrationDeliveryContractsMatchPublicRecordSerialization(): void
    {
        if (!class_exists(\PeanutAdmin\Modules\Integration\ModuleProvider::class)) {
            self::markTestSkipped('Requires official module Integration.');
        }
        $root = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3));
        $inventory = self::runtimeInventory($root);
        self::assertArrayHasKey('peanut.integration', $inventory['modules']);
        $moduleRoot = $root . '/' . $inventory['modules']['peanut.integration'];
        $manifest = json_decode((string) file_get_contents($moduleRoot . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('peanut.integration', $manifest['key']);
        $fragment = require $moduleRoot . '/api/metadata/openapi.php';
        $schemas = $fragment['components']['schemas'];
        $delivery = $schemas['IntegrationDelivery'];
        $attempt = $schemas['IntegrationAttempt'];

        self::assertFalse($delivery['additionalProperties']);
        self::assertSame([
            'delivery_key', 'endpoint_key', 'event_type', 'status', 'attempt_count',
            'last_status_code', 'last_error_code', 'created_at', 'updated_at', 'delivered_at',
        ], $delivery['required']);
        self::assertSame(
            ['pending', 'delivering', 'retryable', 'delivered', 'permanent_failed'],
            $delivery['properties']['status']['enum'],
        );
        self::assertTrue($delivery['properties']['last_status_code']['nullable']);
        self::assertTrue($delivery['properties']['last_error_code']['nullable']);
        self::assertTrue($delivery['properties']['delivered_at']['nullable']);
        self::assertArrayNotHasKey('payload', $delivery['properties']);

        self::assertFalse($attempt['additionalProperties']);
        self::assertSame(
            ['attempt_number', 'outcome', 'response_status', 'error_code', 'duration_ms', 'attempted_at'],
            $attempt['required'],
        );
        self::assertSame(['delivered', 'retryable', 'permanent_failed'], $attempt['properties']['outcome']['enum']);
        self::assertSame(8, $attempt['properties']['attempt_number']['maximum']);
        self::assertSame(30000, $attempt['properties']['duration_ms']['maximum']);
        self::assertTrue($attempt['properties']['response_status']['nullable']);
        self::assertTrue($attempt['properties']['error_code']['nullable']);
        self::assertArrayNotHasKey('response_body', $attempt['properties']);
    }

    /** @param list<array<string,mixed>> $endpoints */
    private static function assertOwnerCoverage(array $endpoints, string $owner, int $routes, int $documented): void
    {
        $owned = array_values(array_filter(
            $endpoints,
            static fn(array $endpoint): bool => ($endpoint['owner']['key'] ?? null) === $owner,
        ));
        self::assertCount($routes, $owned, $owner);
        self::assertCount($documented, array_filter(
            $owned,
            static fn(array $endpoint): bool => $endpoint['documented'] === true,
        ), $owner);
    }

    /** @param list<array<string,mixed>> $endpoints */
    private static function assertApplicationCoverage(array $endpoints, string $application, int $routes, int $routeOnly): void
    {
        $owned = array_values(array_filter(
            $endpoints,
            static fn(array $endpoint): bool => ($endpoint['owner']['type'] ?? null) === 'application'
                && ($endpoint['owner']['key'] ?? null) === $application,
        ));
        self::assertCount($routes, $owned, $application);
        self::assertCount($routes - $routeOnly, array_filter(
            $owned,
            static fn(array $endpoint): bool => $endpoint['contract_quality'] === 'complete'
                && $endpoint['documentation']['requires_owner_contract'] === [],
        ), $application);
    }

    /** @param list<array<string,mixed>> $endpoints @return list<string> */
    private static function routeIdentities(array $endpoints): array
    {
        $identities = array_map(static fn(array $endpoint): string => $endpoint['method'] . ' '
            . preg_replace('/:([A-Za-z_][A-Za-z0-9_]*)/', '{$1}', $endpoint['path']), $endpoints);
        sort($identities, SORT_STRING);
        return $identities;
    }

    /** @return array<string,mixed> */
    private static function runtimeInventory(string $root): array
    {
        $process = proc_open([
            PHP_BINARY, '-r',
            'require $argv[1] . "/server/route/registry_source.php"; echo json_encode(peanut_route_endpoint_inventory($argv[1] . "/server"), JSON_THROW_ON_ERROR);',
            $root,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
