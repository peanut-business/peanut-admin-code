<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** 无数据库、无网络地验证公开应用 API 产物检查的只读和隔离边界。 */
final class PublicApiArtifactCheckTest extends TestCase
{
    private string $sourceRoot;
    private string $temporaryRoot;
    private string $applicationRoot;

    protected function setUp(): void
    {
        $this->sourceRoot = dirname(__DIR__, 3);
        $temporaryBase = realpath(sys_get_temp_dir());
        self::assertNotFalse($temporaryBase);
        $this->temporaryRoot = $temporaryBase . '/peanut-public-api-check-' . bin2hex(random_bytes(8));
        $this->applicationRoot = $this->temporaryRoot . '/application';
        self::createDirectory($this->applicationRoot);
        $this->createApplicationFixture();

        $result = $this->runProcess([
            PHP_BINARY,
            $this->applicationRoot . '/scripts/generate-api-contracts.php',
        ], $this->applicationRoot);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertStringContainsString('API-GENERATION-001 passed', $result['stdout']);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->temporaryRoot);
    }

    public function testDefaultCheckIsProjectIndependentReadOnlyAndKeepsCompleteGeneratedCode(): void
    {
        $before = $this->artifactContents();
        $result = $this->runCheck();

        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertStringContainsString('OPENAPI-CHECK-001 passed: 4 generated artifacts', $result['stdout']);
        self::assertSame($before, $this->artifactContents());

        $openApi = json_decode(
            (string)file_get_contents($this->applicationRoot . '/server/generated/openapi.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('9.8.7-test', $openApi['info']['version']);
        self::assertSame('2', $openApi['info']['x-peanut-contract-generator']['version']);

        $catalog = json_decode(
            (string)file_get_contents($this->applicationRoot . '/server/generated/api-catalog.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            [
                ['file' => 'server/app/api/route/app.php', 'line' => 7],
                ['file' => 'server/app/modules/official/sample/route/app.php', 'line' => 9],
            ],
            array_column($catalog['endpoints'], 'source'),
        );
        $moduleTypes = (string)file_get_contents(
            $this->applicationRoot . '/web/src/modules/official-sample/generated/openapi.ts',
        );
        self::assertStringContainsString('export interface ModuleApiOperations {', $moduleTypes);
        self::assertStringContainsString("getSample: paths['/api/sample']['get'];", $moduleTypes);
        self::assertStringContainsString('export function createModuleApi(client: Client<paths>) {', $moduleTypes);
        self::assertStringContainsString('export function createModuleClient(options?: ClientOptions) {', $moduleTypes);
    }

    public function testStaleAndMissingArtifactsAreReportedWithoutRepairingManagedFiles(): void
    {
        $catalog = $this->applicationRoot . '/server/generated/api-catalog.json';
        file_put_contents($catalog, "\n", FILE_APPEND);
        $stale = $this->artifactContents();

        $result = $this->runCheck();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('generated artifact drift: ' . $catalog, $result['stderr']);
        self::assertSame($stale, $this->artifactContents());

        $types = $this->applicationRoot . '/web/src/generated/openapi.d.ts';
        self::assertTrue(unlink($types));
        $withoutTypes = $this->artifactContents();
        $result = $this->runCheck();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('missing generated artifact: ' . $types, $result['stderr']);
        self::assertFileDoesNotExist($types);
        self::assertSame($withoutTypes, $this->artifactContents());
    }

    public function testChangedContractMustRefreshAndDeletedOperationLeavesNoGeneratedResidue(): void
    {
        $moduleMetadata = $this->applicationRoot . '/server/app/modules/official/sample/api/metadata/openapi.php';
        $metadata = (string)file_get_contents($moduleMetadata);
        self::assertNotSame($metadata, $changed = str_replace(
            "'description' => 'sample'",
            "'description' => 'sample v2'",
            $metadata,
        ));
        self::assertNotFalse(file_put_contents($moduleMetadata, $changed));

        $result = $this->runCheck();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('generated artifact drift:', $result['stderr']);

        $result = $this->runProcess([
            PHP_BINARY,
            $this->applicationRoot . '/scripts/generate-api-contracts.php',
        ], $this->applicationRoot);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame(0, $this->runCheck()['status']);

        $registry = $this->applicationRoot . '/server/route/registry_source.php';
        $registrySource = (string)file_get_contents($registry);
        $withoutSample = preg_replace(
            '/\n\s*\/\/ SAMPLE_ENDPOINT_START.*?\/\/ SAMPLE_ENDPOINT_END\n/s',
            "\n",
            $registrySource,
            1,
            $replacementCount,
        );
        self::assertSame(1, $replacementCount);
        self::assertIsString($withoutSample);
        self::assertNotFalse(file_put_contents($registry, $withoutSample));
        self::assertTrue(unlink($moduleMetadata));

        $result = $this->runCheck();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('unexpected generated artifact:', $result['stderr']);

        $result = $this->runProcess([
            PHP_BINARY,
            $this->applicationRoot . '/scripts/generate-api-contracts.php',
        ], $this->applicationRoot);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame(0, $this->runCheck()['status']);

        $openApi = json_decode(
            (string)file_get_contents($this->applicationRoot . '/server/generated/openapi.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertArrayNotHasKey('/api/sample', $openApi['paths']);
        self::assertArrayNotHasKey('SamplePayload', $openApi['components']['schemas']);
        self::assertFileDoesNotExist(
            $this->applicationRoot . '/web/src/modules/official-sample/generated/openapi.ts',
        );
        $types = (string)file_get_contents($this->applicationRoot . '/web/src/generated/openapi.d.ts');
        self::assertStringNotContainsString('/api/sample', $types);
        self::assertStringNotContainsString('SamplePayload', $types);
    }

    public function testExplicitProjectMirrorIsReadOnlyAndIsolatedOutputRejectsUnsafeRoots(): void
    {
        $projectRoot = $this->temporaryRoot . '/maintainer-project';
        self::createDirectory($projectRoot . '/docs/api');
        $mirror = $projectRoot . '/docs/api/openapi.yaml';
        file_put_contents($mirror, "{}\n");
        $before = $this->artifactContents();

        $result = $this->runCheck(['--project-root=' . $projectRoot]);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Project OpenAPI mirror drift: ' . $mirror, $result['stderr']);
        self::assertSame("{}\n", file_get_contents($mirror));
        self::assertSame($before, $this->artifactContents());

        $unexpected = $this->applicationRoot . '/web/src/modules/retired/generated/openapi.ts';
        self::createDirectory(dirname($unexpected));
        self::assertTrue(symlink($mirror, $unexpected));
        $result = $this->runCheck();
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('unexpected generated artifact: ' . $unexpected, $result['stderr']);
        self::assertTrue(is_link($unexpected));

        $result = $this->runProcess([
            PHP_BINARY,
            $this->applicationRoot . '/scripts/generate-api-contracts.php',
            '--output-root=relative-output',
        ], $this->applicationRoot);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('--output-root must be an absolute path', $result['stderr']);

        $insideSource = $this->applicationRoot . '/isolated-output';
        self::createDirectory($insideSource);
        $result = $this->runProcess([
            PHP_BINARY,
            $this->applicationRoot . '/scripts/generate-api-contracts.php',
            '--output-root=' . $insideSource,
        ], $this->applicationRoot);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('--output-root must be separate from the source repository', $result['stderr']);

        $realOutput = $this->temporaryRoot . '/real-output';
        $linkedOutput = $this->temporaryRoot . '/linked-output';
        self::createDirectory($realOutput);
        self::assertTrue(symlink($realOutput, $linkedOutput));
        $result = $this->runProcess([
            PHP_BINARY,
            $this->applicationRoot . '/scripts/generate-api-contracts.php',
            '--output-root=' . $linkedOutput,
        ], $this->applicationRoot);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('existing regular directory, not a symlink', $result['stderr']);

        $result = $this->runCheck(['--project-root=']);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Project root must be an absolute existing directory', $result['stderr']);

        $result = $this->runProcess([
            PHP_BINARY,
            $this->applicationRoot . '/scripts/generate-api-contracts.php',
            '--project-root=',
        ], $this->applicationRoot);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('--project-root must not be empty', $result['stderr']);
    }

    private function createApplicationFixture(): void
    {
        self::writeFile(
            $this->applicationRoot . '/scripts/generate-api-contracts.php',
            (string)file_get_contents($this->sourceRoot . '/scripts/generate-api-contracts.php'),
        );
        self::writeFile(
            $this->applicationRoot . '/scripts/check-openapi',
            (string)file_get_contents($this->sourceRoot . '/scripts/check-openapi'),
        );
        self::writeFile($this->applicationRoot . '/release-versions.json', <<<'JSON'
{
  "source_product_version": "9.8.7-test"
}
JSON);

        self::writeFile($this->applicationRoot . '/server/route/registry_source.php', <<<'PHP'
<?php
declare(strict_types=1);

function peanut_route_endpoint_inventory(string $serverRoot): array
{
    return [
        'schema_version' => 1,
        'summary' => ['route_count' => 2],
        'endpoints' => [
            [
                'method' => 'GET',
                'path' => '/api/ping',
                'controller' => 'Fixture\\PingController',
                'action' => 'show',
                'source' => 'server/app/api/route/app.php',
                'line' => 7,
                'application' => 'api',
                'owner' => ['type' => 'application', 'key' => 'api'],
                'middleware' => [],
                'permission' => null,
            ],
            // SAMPLE_ENDPOINT_START
            [
                'method' => 'GET',
                'path' => '/api/sample',
                'controller' => 'Fixture\\SampleController',
                'action' => 'show',
                'source' => 'server/app/modules/official/sample/route/app.php',
                'line' => 9,
                'application' => 'api',
                'owner' => ['type' => 'module', 'key' => 'official.sample'],
                'middleware' => [],
                'permission' => null,
            ],
            // SAMPLE_ENDPOINT_END
        ],
    ];
}
PHP);
        self::writeFile($this->applicationRoot . '/server/app/api/route/app.php', <<<'PHP'
<?php
declare(strict_types=1);

// Complete fixture source block retained so catalog input identity is hashable.
return ['GET /api/ping'];
PHP);
        self::writeFile($this->applicationRoot . '/server/app/modules/official/sample/route/app.php', <<<'PHP'
<?php
declare(strict_types=1);

// Complete fixture source block retained so catalog input identity is hashable.
return ['GET /api/sample'];
PHP);
        self::writeFile($this->applicationRoot . '/server/config/admin_api_access.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'public' => [],
    'authenticated' => [],
    'platform_public' => [],
    'platform_authenticated' => [],
];
PHP);

        $contract = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Fixture API', 'version' => '1.0.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'PingPayload' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => ['message' => ['type' => 'string']],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
        self::writeFile(
            $this->applicationRoot . '/server/app/api/metadata/contracts/openapi.json',
            json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        self::writeFile($this->applicationRoot . '/server/app/api/metadata/application/shared.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['components' => []];
PHP);
        self::writeFile($this->applicationRoot . '/server/app/api/metadata/application/adminapi.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['paths' => [
    '/api/ping' => ['get' => [
        'operationId' => 'getPing',
        'responses' => ['200' => [
            'description' => 'pong',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PingPayload']]],
        ]],
    ]],
]];
PHP);
        foreach (['platform.php', 'installation.php', 'common.php'] as $filename) {
            self::writeFile(
                $this->applicationRoot . '/server/app/api/metadata/application/' . $filename,
                "<?php\ndeclare(strict_types=1);\n\nreturn [];\n",
            );
        }
        self::writeFile($this->applicationRoot . '/server/app/modules/official/sample/api/metadata/openapi.php', <<<'PHP'
<?php
declare(strict_types=1);

return ['paths' => [
    '/api/sample' => ['get' => [
        'operationId' => 'getSample',
        'responses' => ['200' => [
            'description' => 'sample',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SamplePayload']]],
        ]],
    ]],
], 'components' => ['schemas' => [
    'SamplePayload' => [
        'type' => 'object',
        'required' => ['id'],
        'properties' => ['id' => ['type' => 'integer']],
        'additionalProperties' => false,
    ],
]]];
PHP);

        self::createDirectory($this->applicationRoot . '/web/src/generated');
        self::createDirectory($this->applicationRoot . '/web/src/modules/official-sample');
        self::createDirectory($this->applicationRoot . '/web');
        self::assertTrue(symlink(
            $this->sourceRoot . '/web/node_modules',
            $this->applicationRoot . '/web/node_modules',
        ));
    }

    /** @param list<string> $arguments @return array{status:int,stdout:string,stderr:string} */
    private function runCheck(array $arguments = []): array
    {
        return $this->runProcess(
            ['/bin/bash', $this->applicationRoot . '/scripts/check-openapi', ...$arguments],
            $this->applicationRoot,
        );
    }

    /** @param list<string> $command @return array{status:int,stdout:string,stderr:string} */
    private function runProcess(array $command, string $workingDirectory): array
    {
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['PATH'] = dirname(PHP_BINARY) . PATH_SEPARATOR . ($environment['PATH'] ?? '');
        unset($environment['PEANUT_ADMIN_PROJECT_ROOT']);
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            $environment,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'status' => proc_close($process),
            'stdout' => (string)$stdout,
            'stderr' => (string)$stderr,
        ];
    }

    /** @return array<string,string> */
    private function artifactContents(): array
    {
        $paths = [
            $this->applicationRoot . '/server/generated/openapi.json',
            $this->applicationRoot . '/server/generated/api-catalog.json',
            $this->applicationRoot . '/web/src/generated/openapi.d.ts',
            $this->applicationRoot . '/web/src/modules/official-sample/generated/openapi.ts',
        ];
        $contents = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $contents[$path] = (string)file_get_contents($path);
            }
        }
        return $contents;
    }

    private static function writeFile(string $path, string $contents): void
    {
        self::createDirectory(dirname($path));
        self::assertNotFalse(file_put_contents($path, $contents));
    }

    private static function createDirectory(string $path): void
    {
        if (!is_dir($path)) {
            self::assertTrue(mkdir($path, 0775, true));
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    }
}
