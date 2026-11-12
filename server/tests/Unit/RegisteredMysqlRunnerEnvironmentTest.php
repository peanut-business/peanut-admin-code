<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/** No-DB checks for the runner/bootstrap environment handoff. */
final class RegisteredMysqlRunnerEnvironmentTest extends TestCase
{
    private const SECRET = 'runner-secret-must-not-be-printed-74d11';

    public function testSuiteRequiresPositiveEvidenceAndChecksEmptyStateAfterFailure(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['passed' => 0, 'missing' => 1, 'skipped' => 1, 'dirty' => 9, 'failed-dirty' => 17,
            'menu-passed' => 0, 'menu-zero' => 1, 'menu-prefixed' => 1, 'menu-trailing' => 1] as $case => $expected) {
            $process = proc_open([
                $this->bashBinary(), '-c', <<<'BASH'
source "$1"
mkdir -p "$ROOT/.local/tmp"
RUNNER_TEMP=$(mktemp -d "$ROOT/.local/tmp/mysql-runner-evidence.XXXXXX")
logs=()
trap 'rm -f -- "${logs[@]}"; rmdir "$RUNNER_TEMP"' EXIT
php_binary="$3"
environment_file="$RUNNER_TEMP/synthetic.env"
case_name="$2"
run_php_with_backend_environment() {
  [[ " $* " == *' --fail-on-skipped '* ]] || return 41
  case "$case_name" in
    passed|dirty) echo 'NATIVE-ADMIN-RBAC-TENANT-ISOLATION-001 passed; assertions=40' ;;
    menu-passed) echo '{"status":"passed","assertions":224,"scenarios":[{"scenario":"multi_tenant_fresh"},{"scenario":"module_lifecycle"}]}' ;;
    menu-zero) echo '{"status":"passed","assertions":0,"scenarios":[{}]}' ;;
    menu-prefixed) echo 'diagnostic: {"status":"passed","assertions":224,"scenarios":[{}]}' ;;
    menu-trailing) echo '{"status":"passed","assertions":224,"scenarios":[{}]} trailing' ;;
    missing) echo 'ThinkPHP handled an exception without a successful assertion marker' ;;
    skipped) echo 'Tests: 1, Assertions: 0, Skipped: 1'; return 1 ;;
    failed-dirty) echo 'actual child failure'; return 17 ;;
  esac
}
preflight() {
  echo POST_PREFLIGHT_EXECUTED
  case "$case_name" in dirty|failed-dirty) return 9 ;; esac
  return 0
}
target=server/tests/Multitenancy/AdminRbacTenantIsolationTest.php
if [[ "$case_name" == menu-* ]]; then target=server/tests/Productization/MenuGroupingTest.php; fi
if run_suite "$target"; then result=0; else result=$?; fi
exit "$result"
BASH,
                'mysql-runner-evidence-fixture', $root . '/scripts/tests/run-registered-mysql-tests', $case, PHP_BINARY,
            ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame($expected, proc_close($process), $case . ': ' . $output);
            self::assertSame(1, substr_count($output, 'POST_PREFLIGHT_EXECUTED'), $case . ': ' . $output);
        }
    }

    public function testMatchingFileLoadsNativeEnvAndKeepsAuthorizationMetadata(): void
    {
        $result = $this->runHelper($this->environmentLines(), $this->ambient(), true);

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('PROBE:native-env-and-authorization-retained', $result['output']);
        $this->assertSecretIsNotPrinted($result['output']);
    }

    public function testWrongConnectionMetadataIsRejectedWithoutPrintingValues(): void
    {
        $ambient = $this->ambient();
        $ambient['DB_HOST'] = '127.0.0.2';
        $result = $this->runHelper($this->environmentLines(), $ambient);

        self::assertSame(2, $result['status']);
        self::assertStringContainsString('REGISTERED_MYSQL_ENV_FILE_MISMATCH:DB_HOST', $result['output']);
        $this->assertSecretIsNotPrinted($result['output']);
    }

    public function testWrongResourceMetadataIsRejected(): void
    {
        $ambient = $this->ambient();
        $ambient['PEANUT_DATABASE_RESOURCE_ID'] = 'registered-mysql-other-resource';
        $result = $this->runHelper($this->environmentLines(), $ambient);

        self::assertSame(2, $result['status']);
        self::assertStringContainsString(
            'REGISTERED_MYSQL_ENV_FILE_MISMATCH:PEANUT_DATABASE_RESOURCE_ID',
            $result['output'],
        );
        $this->assertSecretIsNotPrinted($result['output']);
    }

    public function testMissingFileMetadataIsRejected(): void
    {
        $lines = array_values(array_filter(
            $this->environmentLines(),
            static fn(string $line): bool => !str_starts_with($line, 'PEANUT_DATABASE_ENDPOINT_ID='),
        ));
        $result = $this->runHelper($lines, $this->ambient());

        self::assertSame(2, $result['status']);
        self::assertStringContainsString(
            'REGISTERED_MYSQL_ENV_FILE_REQUIRED:PEANUT_DATABASE_ENDPOINT_ID',
            $result['output'],
        );
        $this->assertSecretIsNotPrinted($result['output']);
    }

    public function testDuplicateFileMetadataIsRejectedAsAmbiguous(): void
    {
        $lines = $this->environmentLines();
        $lines[] = 'DB_PORT=49124';
        $result = $this->runHelper($lines, $this->ambient());

        self::assertSame(2, $result['status']);
        self::assertStringContainsString('REGISTERED_MYSQL_ENV_FILE_AMBIGUOUS:DB_PORT', $result['output']);
        $this->assertSecretIsNotPrinted($result['output']);
    }

    public function testCiServiceModeStillRequiresTheRealGitHubActionsContext(): void
    {
        $result = $this->runHelper(
            $this->environmentLines(),
            $this->ambient(),
            false,
            '--ci-service',
        );

        self::assertSame(2, $result['status']);
        self::assertStringContainsString(
            'temporary CI resource registration is restricted to this GitHub Actions checkout',
            $result['output'],
        );
        $this->assertSecretIsNotPrinted($result['output']);
    }

    /** @return list<string> */
    private function environmentLines(): array
    {
        return [
            'APP_ENV=development',
            'APP_DEBUG=false',
            'PEANUT_DEPLOYMENT_TARGET=local-development',
            'DEPLOYMENT_MODE=multi-tenant',
            'DB_HOST=127.0.0.1',
            'DB_PORT=49123',
            'DB_NAME=registered_mysql_runner_test',
            'DB_USER=runner_test',
            'DB_PASS=' . self::SECRET,
            'DB_PREFIX=pa_',
            'PEANUT_DATABASE_RESOURCE_ID=registered-mysql-resource',
            'PEANUT_DATABASE_ENDPOINT_ID=registered-mysql-endpoint',
            'PEANUT_DATABASE_CONSUMER=host',
        ];
    }

    /** @return array<string,string> */
    private function ambient(): array
    {
        return [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '49123',
            'DB_NAME' => 'registered_mysql_runner_test',
            'PEANUT_DATABASE_RESOURCE_ID' => 'registered-mysql-resource',
            'PEANUT_DATABASE_ENDPOINT_ID' => 'registered-mysql-endpoint',
            'PEANUT_DATABASE_CONSUMER' => 'host',
            'PEANUT_DATABASE_ENVIRONMENT' => 'continuous-integration',
            'PEANUT_DATABASE_RESOURCE_SCOPE' => 'runner-test-scope',
            'PEANUT_DATABASE_LEASE_ID' => 'runner-test-lease',
            'PEANUT_DATABASE_LEASE_OWNER' => 'runner-test-owner',
            'PEANUT_DATABASE_LEASE_GATE' => 'real-mysql',
        ];
    }

    /**
     * @param list<string> $lines
     * @param array<string,string> $ambient
     * @return array{status:int,output:string}
     */
    private function runHelper(
        array $lines,
        array $ambient,
        bool $probe = false,
        string $runnerMode = '--registered',
    ): array {
        $root = dirname(__DIR__, 3);
        $path = $root . '/server/.env.test-' . bin2hex(random_bytes(8));
        $previousUmask = umask(0077);
        try {
            $handle = fopen($path, 'x');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('Cannot create runner environment fixture');
        }
        try {
            $contents = implode("\n", $lines) . "\n";
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Cannot write runner environment fixture');
            }
        } finally {
            fclose($handle);
        }

        try {
            self::assertSame(0600, fileperms($path) & 0777);
            $command = [
                $this->bashBinary(),
                '-c',
                <<<'BASH'
if [[ "$4" != probe ]]; then
  export PHP_BINARY="$2"
  exec "$BASH" "$1" "$6" --env-file "$3"
fi
source "$1"
environment_file="$3"
export PEANUT_SERVER_ENV_FILE="$environment_file"
validate_environment_file "$2" "$environment_file"
run_php_with_backend_environment "$2" -r "$5" "$ROOT/server/tests/Support/CiBootstrap.php"
BASH,
                'registered-mysql-runner-test',
                $root . '/scripts/tests/run-registered-mysql-tests',
                PHP_BINARY,
                $path,
                $probe ? 'probe' : 'validate',
                <<<'PHP'
$ambient = getenv();
$lease = [
    "PEANUT_DATABASE_ENVIRONMENT" => "continuous-integration",
    "PEANUT_DATABASE_RESOURCE_SCOPE" => "runner-test-scope",
    "PEANUT_DATABASE_LEASE_ID" => "runner-test-lease",
    "PEANUT_DATABASE_LEASE_OWNER" => "runner-test-owner",
    "PEANUT_DATABASE_LEASE_GATE" => "real-mysql",
];
foreach ($lease as $key => $value) {
    if (getenv($key) !== $value) {
        throw new RuntimeException("PROBE_LEASE_METADATA_MISSING:" . $key);
    }
}
require $argv[1];
$loaded = [
    "DB_HOST" => "127.0.0.1",
    "DB_PORT" => "49123",
    "DB_NAME" => "registered_mysql_runner_test",
    "PEANUT_DATABASE_RESOURCE_ID" => "registered-mysql-resource",
    "PEANUT_DATABASE_ENDPOINT_ID" => "registered-mysql-endpoint",
    "PEANUT_DATABASE_CONSUMER" => "host",
];
foreach ($loaded as $key => $value) {
    if (env($key) !== $value) {
        throw new RuntimeException("PROBE_ENV_FILE_VALUE_MISSING:" . $key);
    }
}
if (env('DB_PASS') !== "runner-secret-must-not-be-printed-74d11"
    || env('APP_DEBUG') !== false || getenv('DB_PASS') !== false) {
    throw new RuntimeException("PROBE_NATIVE_ENV_REQUIRED");
}
if (getenv() !== $ambient) {
    throw new RuntimeException("PROBE_PROCESS_ENVIRONMENT_CHANGED");
}
foreach ($lease as $key => $value) {
    if (getenv($key) !== $value) {
        throw new RuntimeException("PROBE_LEASE_METADATA_CHANGED:" . $key);
    }
}
require __DIR__ . '/server/tests/Support/IsolatedBackendEnvironment.php';
require __DIR__ . '/server/tests/Support/RegisteredMysqlTestResource.php';
$isolated = IsolatedBackendEnvironment::activate(['DB_NAME' => 'registered_mysql_isolated_test']);
if (env('DB_NAME') !== 'registered_mysql_isolated_test'
    || RegisteredMysqlTestResource::configuredDatabaseName() !== 'registered_mysql_isolated_test'
    || getenv('DB_NAME') !== $loaded['DB_NAME']) {
    throw new RuntimeException("PROBE_ISOLATED_NATIVE_ENV_REQUIRED");
}
$app = new \think\App(__DIR__ . '/server');
$app->loadEnv((string) $app->env->get('ENV_NAME', ''));
if ($app->env->get('DB_NAME') !== 'registered_mysql_isolated_test') {
    throw new RuntimeException("PROBE_NATIVE_ENV_SELECTOR_REQUIRED");
}
IsolatedBackendEnvironment::cleanup();
if (is_file($isolated)) {
    throw new RuntimeException("PROBE_ISOLATED_ENV_CLEANUP_FAILED");
}
fwrite(STDOUT, "PROBE:native-env-and-authorization-retained\n");
PHP,
                $runnerMode,
            ];
            $environment = $ambient + [
                'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
                'TMPDIR' => sys_get_temp_dir(),
            ];
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                $environment,
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start runner environment helper');
            }
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return ['status' => proc_close($process), 'output' => $output];
        } finally {
            if (is_file($path) && !is_link($path)) {
                unlink($path);
            }
        }
    }

    private function bashBinary(): string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bash';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('Bash is unavailable');
    }

    private function assertSecretIsNotPrinted(string $output): void
    {
        self::assertStringNotContainsString(self::SECRET, $output);
    }
}
