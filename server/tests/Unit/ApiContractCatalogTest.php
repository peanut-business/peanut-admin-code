<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** 无数据库地核对路由清单、结构化合同质量和 SDK 消费入口。 */
final class ApiContractCatalogTest extends TestCase
{
    public function testCatalogSeparatesRoutesGeneratedOperationsAndCompleteContracts(): void
    {
        $root = dirname(__DIR__, 3);
        $catalog = sys_get_temp_dir() . '/peanut-api-catalog-' . bin2hex(random_bytes(8)) . '.json';
        $command = [
            PHP_BINARY,
            $root . '/scripts/generate-api-contracts.php',
            '--check',
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
            self::assertSame(0, $status, (string)$stderr);
            self::assertStringContainsString('API-CONTRACT-CHECK-001 passed', (string)$stdout);
            $document = json_decode((string)file_get_contents($catalog), true, 512, JSON_THROW_ON_ERROR);
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
            // All current owners have completed their contracts; do not freeze the
            // obsolete partial-delivery snapshot or accept missing routes as success.
            self::assertSame(336, $summary['route_count']);
            self::assertSame(336, $summary['generated_api_operations']);
            self::assertSame(336, $summary['complete_operations']);
            self::assertSame(0, $summary['partial_operations']);
            self::assertSame(0, $summary['route_only_operations']);

            $operationIds = [];
            foreach ($endpoints as $endpoint) {
                if ($endpoint['documented'] !== true) {
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
                    self::assertSame('partial', $endpoint['contract_quality']);
                    self::assertNotSame([], $endpoint['documentation']['requires_owner_contract']);
                }
            }

            self::assertOwnerCoverage($endpoints, 'official.article', 21, 21);
            self::assertOwnerCoverage($endpoints, 'official.member', 22, 22);
            self::assertOwnerCoverage($endpoints, 'official.file', 14, 14);
            self::assertOwnerCoverage($endpoints, 'official.task', 10, 10);
            self::assertApplicationCoverage($endpoints, 'adminapi', 91);
            self::assertApplicationCoverage($endpoints, 'platform', 58);
            self::assertApplicationCoverage($endpoints, 'installation', 2);

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
            self::assertSame('complete', $byRoute['GET /adminapi/api/v1/files/{fileKey}/content']['contract_quality']);
        } finally {
            if (is_file($catalog)) unlink($catalog);
        }
    }

    public function testApplicationMetadataKeepsPreciseSecurityTransportAndProjectionSchemas(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = $root . '/server/app/api/metadata/application';
        $shared = require $directory . '/shared.php';
        $admin = require $directory . '/adminapi.php';
        $platform = require $directory . '/platform.php';
        $installation = require $directory . '/installation.php';

        self::assertSame(91, self::operationCount($admin));
        self::assertSame(58, self::operationCount($platform));
        self::assertSame(2, self::operationCount($installation));

        $schemes = $shared['components']['securitySchemes'];
        self::assertSame('Host', $schemes['platformHost']['name']);
        self::assertSame('__Host-pa_platform_refresh', $schemes['platformRefreshCookie']['name']);
        self::assertSame('__Host-pa_tenant_refresh_admin-web', $schemes['tenantRefreshCookie']['name']);
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

    public function testIntegrationDeliveryContractsMatchPublicRecordSerialization(): void
    {
        $root = dirname(__DIR__, 3);
        $fragment = require $root . '/server/app/modules/official/integration/api/metadata/openapi.php';
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
    private static function assertApplicationCoverage(array $endpoints, string $application, int $routes): void
    {
        $owned = array_values(array_filter(
            $endpoints,
            static fn(array $endpoint): bool => ($endpoint['owner']['type'] ?? null) === 'application'
                && ($endpoint['owner']['key'] ?? null) === $application,
        ));
        self::assertCount($routes, $owned, $application);
        self::assertCount($routes, array_filter(
            $owned,
            static fn(array $endpoint): bool => $endpoint['contract_quality'] === 'complete'
                && $endpoint['documentation']['requires_owner_contract'] === [],
        ), $application);
    }

    /** @param array<string,mixed> $fragment */
    private static function operationCount(array $fragment): int
    {
        $count = 0;
        foreach (['paths', 'overrides'] as $section) {
            foreach ($fragment[$section] ?? [] as $pathItem) {
                foreach (['get', 'post', 'put', 'delete', 'patch'] as $method) {
                    if (isset($pathItem[$method])) $count++;
                }
            }
        }
        return $count;
    }
}
