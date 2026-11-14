<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/RegisteredMysqlTestResource.php';

final class RegisteredMysqlTestResourceTest extends TestCase
{
    public function testExplicitMaintainerSourceRejectsForeignRegistry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'registry-source-');
        self::assertIsString($path);
        file_put_contents($path, '{}');
        $this->assertSourceRejected($path, (string) hash_file('sha256', $path));
        unlink($path);
    }

    public function testExplicitMaintainerSourceRejectsWrongDigest(): void
    {
        $this->assertSourceRejected(dirname(__DIR__, 3) . '/resources/project-resources.json', str_repeat('0', 64));
    }

    private function assertSourceRejected(string $path, string $digest): void
    {
        $root = dirname(__DIR__, 3);
        $values = ['PEANUT_DATABASE_REGISTRY_FILE' => $path,
            'PEANUT_DATABASE_REGISTRY_TOOL' => $root . '/scripts/project-resource-registry',
            'PEANUT_DATABASE_REGISTRY_SHA256' => $digest];
        $previous = [];
        foreach ($values as $key => $value) { $previous[$key] = getenv($key); putenv($key . '=' . $value); }
        try {
            try {
                (new \ReflectionMethod(\RegisteredMysqlTestResource::class, 'registrySource'))->invoke(null, $root);
                self::fail('Foreign or changed registry accepted');
            } catch (\RuntimeException $error) {
                self::assertSame('REGISTERED_MYSQL_REGISTRY_SOURCE_INVALID', $error->getMessage());
            }
        } finally {
            foreach ($previous as $key => $value) { putenv($value === false ? $key : $key . '=' . $value); }
        }
    }

    public function testExactRegisteredContractIsAccepted(): void
    {
        [$registry, $environment] = $this->fixture();

        $resolved = \RegisteredMysqlTestResource::validateContract($registry, $environment);

        self::assertSame($environment['DB_NAME'], $resolved['database']);
        self::assertSame($environment['DB_PORT'], (string) $resolved['port']);
        self::assertSame($environment['PEANUT_DATABASE_RESOURCE_ID'], $resolved['resource_id']);
    }

    public function testContractAcceptsMinimalCiEnvironmentWithoutOptionalScope(): void
    {
        [$registry, $environment] = $this->fixture();
        unset($environment['PEANUT_DATABASE_RESOURCE_SCOPE']);

        $resolved = \RegisteredMysqlTestResource::validateContract($registry, $environment);

        self::assertSame($environment['DB_NAME'], $resolved['database']);
    }

    public function testUnknownResourceIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[1]['PEANUT_DATABASE_RESOURCE_ID'] = 'unknown-resource';
        $this->assertRejected('REGISTERED_MYSQL_RESOURCE_MISSING', $fixture);
    }

    public function testExactP0eTemplateIsAccepted(): void
    {
        $fixture = $this->p0eFixture();
        $resolved = \RegisteredMysqlTestResource::validateContract(...$fixture);
        self::assertSame($fixture[1]['DB_NAME'], $resolved['database']);
        self::assertSame($fixture[1]['PEANUT_DATABASE_RESOURCE_ID'], $resolved['resource_id']);
    }

    public function testP0eForeignRunAndScenarioAreRejected(): void
    {
        foreach (['foreign_module_lifecycle', 'run1_unregistered', 'BAD_module_lifecycle'] as $suffix) {
            $fixture = $this->p0eFixture();
            $fixture[1]['DB_NAME'] = 'peanut_admin_development_p0e_' . $suffix;
            $this->assertRejected('REGISTERED_MYSQL_RESOURCE_MISMATCH', $fixture);
        }
        $fixture = $this->p0eFixture();
        $fixture[1]['PEANUT_DATABASE_RESOURCE_SCOPE'] = 'peanut_admin_development_p0e_foreign_';
        $this->assertRejected('REGISTERED_MYSQL_RESOURCE_MISMATCH', $fixture);
    }

    public function testOtherNonRuntimeResourcesRemainForbidden(): void
    {
        $fixture = $this->fixture();
        $fixture[0]['resources']['databases'][0]['application_runtime'] = false;
        $this->assertRejected('REGISTERED_MYSQL_RESOURCE_MISMATCH', $fixture);
    }

    public function testWrongEndpointIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[1]['PEANUT_DATABASE_ENDPOINT_ID'] = 'wrong-endpoint';
        $this->assertRejected('REGISTERED_MYSQL_ENDPOINT_MISMATCH', $fixture);
    }

    public function testDatabaseOutsideExactAllowlistIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[1]['DB_NAME'] = 'registered_mysql_other';
        $this->assertRejected('REGISTERED_MYSQL_RESOURCE_MISMATCH', $fixture);
    }

    public function testMismatchedResourceScopeIsRejected(): void
    {
        $fixture = $this->fixture();
        $fixture[1]['PEANUT_DATABASE_RESOURCE_SCOPE'] = 'foreign-scope';
        $this->assertRejected('REGISTERED_MYSQL_RESOURCE_MISMATCH', $fixture);
    }

    public function testMissingRequiredEnvironmentIsRejected(): void
    {
        $fixture = $this->fixture();
        unset($fixture[1]['PEANUT_DATABASE_ENVIRONMENT']);
        $this->assertRejected('REGISTERED_MYSQL_ENVIRONMENT_REQUIRED:PEANUT_DATABASE_ENVIRONMENT', $fixture);
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
                'resource_id' => 'registered-mysql-resource',
            ],
            'registered_mysql_test',
            true,
            ['resource_id' => 'other-mysql-resource'],
        );
    }

    /**
     * @return array{
     *   0:array<string,mixed>,
     *   1:array<string,string>
     * }
     */
    private function fixture(): array
    {
        $environment = [
            'DB_NAME' => 'registered_mysql_test',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '49123',
            'PEANUT_DATABASE_RESOURCE_ID' => 'registered-mysql-resource',
            'PEANUT_DATABASE_ENDPOINT_ID' => 'registered-mysql-endpoint',
            'PEANUT_DATABASE_CONSUMER' => 'host',
            'PEANUT_DATABASE_ENVIRONMENT' => 'continuous-integration',
            'PEANUT_DATABASE_RESOURCE_SCOPE' => 'ci-job-scope',
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
                'upstream_endpoint' => [
                    'endpoint_id' => $environment['PEANUT_DATABASE_ENDPOINT_ID'],
                    'host' => $environment['DB_HOST'],
                    'port' => (int) $environment['DB_PORT'],
                    'consumers' => [$environment['PEANUT_DATABASE_CONSUMER']],
                ],
            ]]],
        ];

        return [$registry, $environment];
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function p0eFixture(): array
    {
        $fixture = $this->fixture();
        $environment = &$fixture[1];
        $environment['DB_NAME'] = 'peanut_admin_development_p0e_run1_module_lifecycle';
        $environment['PEANUT_DATABASE_RESOURCE_ID'] = 'peanut-admin-p0e-mysql84-gate';
        $environment['PEANUT_DATABASE_ENVIRONMENT'] = 'development';
        $environment['PEANUT_DATABASE_RESOURCE_SCOPE'] = 'peanut_admin_development_p0e_run1_';
        $fixture[0]['authority']['role'] = 'maintainer';
        $resource = &$fixture[0]['resources']['databases'][0];
        $resource['stable_resource_id'] = $environment['PEANUT_DATABASE_RESOURCE_ID'];
        $resource['environments'] = ['development'];
        $resource['application_runtime'] = false;
        $resource['database'] = 'peanut_admin_development_p0e_<run_id>_<scenario>';
        $resource['namespace'] = 'peanut_admin_development_p0e_<run_id>_';
        $resource['run_id_pattern'] = '^[a-z0-9]{1,11}$';
        $resource['database_name_max_length'] = 64;
        $resource['allowed_scenarios'] = ['module_lifecycle'];
        return $fixture;
    }

    /** @param array{0:array<string,mixed>,1:array<string,string>} $fixture */
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
