<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

/** 真正加载安装器，但每次子进程只给无数据库凭据的明确配置；不污染测试父进程。 */
final class MigrationTargetIdentityTest extends TestCase
{
    public function testDevelopmentTargetIsExactlyTheAdoptedScaffoldIdentity(): void
    {
        $result = $this->installerCall(
            '$versions = applicationReleaseVersions($server);'
            . '$target = applicationMigrationTargetVersion($server, $versions);'
            . 'echo json_encode([$versions["scaffold_template"], validatedMigrationTargetVersion($server, $target, $versions)], JSON_THROW_ON_ERROR);',
        );
        self::assertSame('4.0.0-dev', $result[0]);
        self::assertSame($result[0], $result[1]);
    }

    public function testForeignAndMalformedTargetsFailBeforeAnyDatabaseAccess(): void
    {
        $result = $this->installerCall(
            '$errors = []; foreach (["", "../schema", "latest", "4.0.0-dev; DROP TABLE x", "4.0.0-dev ", "3.1.0"] as $wrong) {'
            . 'try { migrateDatabase($server, $wrong, true); $errors[] = "unexpected-success"; }'
            . 'catch (RuntimeException $exception) { $errors[] = $exception->getMessage(); }}'
            . 'echo json_encode($errors, JSON_THROW_ON_ERROR);',
        );
        self::assertCount(6, $result);
        foreach ($result as $error) {
            self::assertSame('MIGRATION_TARGET_CONTRACT_MISMATCH', $error);
        }
    }

    public function testPhpCandidateIdentityMatchesItsBranchAndExactComposerLock(): void
    {
        $result = $this->installerCall(
            '$versions = \\app\\common\\value\\installation\\ApplicationReleaseVersions::load(dirname($server) . "/release-versions.json")->toArray();'
            . '$lock = json_decode(file_get_contents($server . "/composer.lock"), true, 512, JSON_THROW_ON_ERROR);'
            . '$manifest = json_decode(file_get_contents($server . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);'
            . '$packages = array_values(array_filter($lock["packages"], static fn(array $package): bool => $package["name"] === "peanut-admin/core"));'
            . 'echo json_encode([$versions["core_php"], $manifest["require"]["peanut-admin/core"], $packages], JSON_THROW_ON_ERROR);',
        );
        [$identity, $constraint, $packages] = $result;
        self::assertSame($constraint, $identity['constraint']);
        self::assertSame($identity['constraint'], $identity['resolved_version']);
        self::assertCount(1, $packages);
        self::assertSame($identity['resolved_version'], $packages[0]['version']);
        self::assertSame($identity['source_type'], $packages[0]['source']['type']);
        self::assertSame($identity['source_url'], $packages[0]['source']['url']);
        self::assertSame($identity['source_reference'], $packages[0]['source']['reference']);
    }

    public function testMalformedPhpCandidateIdentityIsRejectedBeforeInstallation(): void
    {
        $result = $this->installerCall(<<<'PHP'
$versions = json_decode(file_get_contents(dirname($server) . '/release-versions.json'), true, 512, JSON_THROW_ON_ERROR);
$path = tempnam(sys_get_temp_dir(), 'peanut-version-identity-');
if ($path === false) throw new RuntimeException('TEST_TEMP_FILE_UNAVAILABLE');
$errors = [];
try {
    foreach ([
        ['constraint' => 'dev-other'],
        ['constraint' => 'dev-dev#' . $versions['core_php']['source_reference']],
        ['resolved_version' => 'dev-other'],
        ['source_reference' => str_repeat('a', 39)],
        ['source_reference' => 'latest'],
        ['source_type' => 'path'],
        ['source_url' => 'file:///tmp/untrusted-core'],
        ['package' => 'untrusted/core'],
    ] as $changes) {
        $candidate = $versions;
        $candidate['core_php'] = array_replace($candidate['core_php'], $changes);
        file_put_contents($path, json_encode($candidate, JSON_THROW_ON_ERROR));
        try {
            \app\common\value\installation\ApplicationReleaseVersions::load($path);
            $errors[] = 'unexpected-success';
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
} finally {
    unlink($path);
}
echo json_encode($errors, JSON_THROW_ON_ERROR);
PHP);
        self::assertCount(8, $result);
        foreach ($result as $error) {
            self::assertSame('APPLICATION_RELEASE_VERSIONS_CORE_PHP_INVALID', $error);
        }
    }

    public function testFreshInstallerHasNoUpgradeMode(): void
    {
        $result = $this->installerCall(
            '$installer = file_get_contents($server . "/database/install.php");'
            . '$upgrade = file_get_contents(dirname($server) . "/scripts/upgrade");'
            . 'echo json_encode([!str_contains($installer, "--migrate"), str_contains($upgrade, "product-upgrade-plan")], JSON_THROW_ON_ERROR);',
        );
        self::assertSame([true, true], $result);
    }

    private function installerCall(string $body): array
    {
        $server = dirname(__DIR__, 2);
        $path = $server . '/.env.migration-test-' . bin2hex(random_bytes(8));
        $file = fopen($path, 'x');
        self::assertIsResource($file);
        chmod($path, 0600);
        fwrite($file, "APP_ENV=development\nAPP_DEBUG=false\n");
        fclose($file);
        try {
            $source = '$server = ' . var_export($server, true) . '; require $server . "/vendor/autoload.php"; require $server . "/database/install.php"; ' . $body;
            $process = proc_open([PHP_BINARY, '-r', $source], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($server), [
                'PATH' => (string) getenv('PATH'), 'PEANUT_SERVER_ENV_FILE' => $path,
            ]);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error . $output);
            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            unlink($path);
        }
    }
}
