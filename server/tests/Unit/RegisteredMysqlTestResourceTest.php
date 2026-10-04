<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/RegisteredMysqlTestResource.php';

final class RegisteredMysqlTestResourceTest extends TestCase
{
    public function testExactRegisteredContractIsAccepted(): void
    {
        [$registry, $lease, $environment, $root, $candidate, $now] = $this->fixture();

        $resolved = \RegisteredMysqlTestResource::validateContract(
            $registry,
            $lease,
            $environment,
            $root,
            $candidate,
            $now,
        );

        self::assertSame($environment['DB_NAME'], $resolved['database']);
        self::assertSame($environment['DB_PORT'], (string) $resolved['port']);
        self::assertSame($environment['PEANUT_DATABASE_LEASE_OWNER'], $resolved['lease_owner']);
    }

    public function testUnknownResourceIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[2]['PEANUT_DATABASE_RESOURCE_ID'] = 'unknown-resource';
        $this->assertRejected('REGISTERED_MYSQL_RESOURCE_MISSING', $fixture);
    }

    public function testWrongEndpointIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[2]['PEANUT_DATABASE_ENDPOINT_ID'] = 'wrong-endpoint';
        $this->assertRejected('REGISTERED_MYSQL_ENDPOINT_MISMATCH', $fixture);
    }

    public function testDatabaseOutsideExactAllowlistIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[2]['DB_NAME'] = 'registered_mysql_other';
        $this->assertRejected('REGISTERED_MYSQL_RESOURCE_MISMATCH', $fixture);
    }

    public function testExpiredLeaseIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[1]['metadata']['expires_at'] = (string) ($fixture[5] - 1);
        $this->assertRejected('REGISTERED_MYSQL_LEASE_MISMATCH', $fixture);
    }

    public function testMismatchedWorktreeIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[1]['metadata']['worktree'] = $fixture[3] . '-foreign';
        $this->assertRejected('REGISTERED_MYSQL_LEASE_MISMATCH', $fixture);
    }

    public function testMismatchedCandidateIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[1]['metadata']['candidate'] = str_repeat('b', 40);
        $this->assertRejected('REGISTERED_MYSQL_LEASE_MISMATCH', $fixture);
    }

    public function testMismatchedResourceScopeIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[2]['PEANUT_DATABASE_RESOURCE_SCOPE'] = 'foreign-scope';
        $this->assertRejected('REGISTERED_MYSQL_RESOURCE_MISMATCH', $fixture);
    }

    public function testMissingExplicitLeaseOwnerIsRejected(): void
    {
        $fixture = $this->fixture();
        unset($fixture[2]['PEANUT_DATABASE_LEASE_OWNER']);
        $this->assertRejected('REGISTERED_MYSQL_ENVIRONMENT_REQUIRED:PEANUT_DATABASE_LEASE_OWNER', $fixture);
    }

    public function testNonEmptyDatabasePreflightIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REGISTERED_MYSQL_DATABASE_NOT_EMPTY');
        \RegisteredMysqlTestResource::assertEmptyTableCount(1);
    }

    public function testCleanupWithoutMatchingOwnershipIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REGISTERED_MYSQL_CLEANUP_NOT_OWNED');
        \RegisteredMysqlTestResource::assertCleanupOwnership(
            [
                'database' => 'registered_mysql_test',
                'created' => true,
                'lease_id' => 'lease-one',
                'lease_owner' => 'owner-one',
            ],
            'registered_mysql_test',
            true,
            ['lease_id' => 'lease-one', 'lease_owner' => 'owner-two'],
        );
    }

    /**
     * @return array{
     *   0:array<string,mixed>,
     *   1:array{metadata:array<string,string>,resources:list<array{0:string,1:string}>},
     *   2:array<string,string>,3:string,4:string,5:int
     * }
     */
    private function fixture(): array
    {
        $root = dirname(__DIR__, 3);
        $candidate = str_repeat('a', 40);
        $now = 1_800_000_000;
        $environment = [
            'DB_NAME' => 'registered_mysql_test',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '49123',
            'PEANUT_DATABASE_RESOURCE_ID' => 'registered-mysql-resource',
            'PEANUT_DATABASE_ENDPOINT_ID' => 'registered-mysql-endpoint',
            'PEANUT_DATABASE_CONSUMER' => 'host',
            'PEANUT_DATABASE_ENVIRONMENT' => 'continuous-integration',
            'PEANUT_DATABASE_RESOURCE_SCOPE' => 'ci-job-scope',
            'PEANUT_DATABASE_LEASE_ID' => 'lease-one',
            'PEANUT_DATABASE_LEASE_OWNER' => 'owner-one',
            'PEANUT_DATABASE_LEASE_GATE' => 'real-mysql',
        ];
        $registry = [
            'schema_version' => 1,
            'project_id' => 'peanut-admin',
            'resources' => ['databases' => [[
                'stable_resource_id' => $environment['PEANUT_DATABASE_RESOURCE_ID'],
                'environments' => [$environment['PEANUT_DATABASE_ENVIRONMENT']],
                'service_type' => 'mysql',
                'version' => '8.4.10',
                'database' => $environment['DB_NAME'],
                'namespace' => $environment['PEANUT_DATABASE_RESOURCE_SCOPE'],
                'fallback' => 'none',
                'application_runtime' => true,
                'lease_gate' => 'real-mysql',
                'upstream_endpoint' => [
                    'endpoint_id' => $environment['PEANUT_DATABASE_ENDPOINT_ID'],
                    'host' => $environment['DB_HOST'],
                    'port' => (int) $environment['DB_PORT'],
                    'consumers' => [$environment['PEANUT_DATABASE_CONSUMER']],
                ],
            ]]],
        ];
        $resources = [
            ['resource-id', $environment['PEANUT_DATABASE_RESOURCE_ID']],
            ['endpoint', $environment['PEANUT_DATABASE_ENDPOINT_ID']],
            ['consumer', $environment['PEANUT_DATABASE_CONSUMER']],
            ['environment', $environment['PEANUT_DATABASE_ENVIRONMENT']],
            ['host', $environment['DB_HOST']],
            ['port', $environment['DB_PORT']],
            ['mysql-db', $environment['DB_NAME']],
            ['database', $environment['DB_NAME']],
            ['resource-scope', $environment['PEANUT_DATABASE_RESOURCE_SCOPE']],
            ['version', '8.4.10'],
            ['gate', 'real-mysql'],
            ['worktree', $root],
        ];
        $lease = ['metadata' => [
            'lease' => $environment['PEANUT_DATABASE_LEASE_ID'],
            'owner' => $environment['PEANUT_DATABASE_LEASE_OWNER'],
            'candidate' => $candidate,
            'candidate_repository' => $root,
            'gate' => 'real-mysql',
            'worktree' => $root,
            'expires_at' => (string) ($now + 600),
            'status' => 'ACTIVE',
        ], 'resources' => $resources];

        return [$registry, $lease, $environment, $root, $candidate, $now];
    }

    /** @param array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,string>,3:string,4:string,5:int} $fixture */
    private function assertRejected(string $message, array $fixture): void
    {
        try {
            \RegisteredMysqlTestResource::validateContract(...$fixture);
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
            return;
        }
        self::fail('Contract unexpectedly accepted: ' . $message);
    }
}
