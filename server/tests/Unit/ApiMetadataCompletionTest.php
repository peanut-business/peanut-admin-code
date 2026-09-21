<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Narrow completion gate for the remaining application/member API metadata slice. */
final class ApiMetadataCompletionTest extends TestCase
{
    public function testEveryRuntimeRouteHasACompleteOwnerContract(): void
    {
        $root = dirname(__DIR__, 3);
        $catalog = sys_get_temp_dir() . '/peanut-api-completion-' . bin2hex(random_bytes(8)) . '.json';
        $command = [
            PHP_BINARY,
            $root . '/scripts/generate-api-contracts.php',
            '--check',
            '--catalog-output=' . $catalog,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
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
            $summary = $document['summary'];
            self::assertSame($summary['route_count'], $summary['generated_api_operations']);
            self::assertSame($summary['route_count'], $summary['complete_operations']);
            self::assertSame(0, $summary['partial_operations']);
            self::assertSame(0, $summary['route_only_operations']);
            self::assertSame([], $document['documentation_gaps']);

            $applicationApi = array_values(array_filter(
                $document['endpoints'],
                static fn(array $endpoint): bool => $endpoint['owner'] === [
                    'type' => 'application',
                    'key' => 'api',
                ],
            ));
            self::assertCount(18, $applicationApi);
            foreach ($applicationApi as $endpoint) {
                self::assertSame('complete', $endpoint['contract_quality']);
                self::assertSame([], $endpoint['documentation']['requires_owner_contract']);
            }

            $accountLogs = array_values(array_filter(
                $document['endpoints'],
                static fn(array $endpoint): bool => $endpoint['method'] === 'GET'
                    && $endpoint['path'] === '/api/account_log/lists',
            ));
            self::assertCount(1, $accountLogs);
            self::assertSame(['type' => 'module', 'key' => 'official.member'], $accountLogs[0]['owner']);
            self::assertSame('complete', $accountLogs[0]['contract_quality']);
        } finally {
            if (is_file($catalog)) {
                unlink($catalog);
            }
        }
    }
}
