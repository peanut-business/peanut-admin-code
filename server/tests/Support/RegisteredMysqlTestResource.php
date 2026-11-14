<?php

declare(strict_types=1);

/**
 * Fail-closed access to a registry-backed MySQL 8.4 test database.
 *
 * Resource identity, endpoint, environment and exact database name are inputs
 * because they vary between local runs and CI. None of those inputs authorizes
 * itself: the resource registry and project-resource-registry tool must
 * independently contain and resolve the same values.
 */
final class RegisteredMysqlTestResource
{
    /** @var array<string,array{database:string,created:bool,resource_id:string}> */
    private static array $ownedDatabases = [];

    public static function configuredDatabaseName(): string
    {
        $database = self::required('DB_NAME');
        self::assertDatabaseNameSyntax($database);
        return $database;
    }

    /** Confirms the exact registered schema is absent or empty without creating it. */
    public static function preflightEmptyDatabase(): void
    {
        $database = self::configuredDatabaseName();
        $authorization = self::assertAuthorized($database);
        $pdo = self::connect($authorization);
        self::assertMysqlVersion($pdo, $authorization['version']);
        self::assertEmptyOrAbsent($pdo, $database);
    }

    /** @return array{0:PDO,1:bool} selected PDO and whether this process created the database */
    public static function openEmptyDatabase(string $database): array
    {
        $authorization = self::assertAuthorized($database);
        $pdo = self::connect($authorization);
        self::assertMysqlVersion($pdo, $authorization['version']);

        $exists = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$database]);
        $created = $exists->fetchColumn() === false;
        if ($created) {
            $pdo->exec(sprintf(
                'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
                $database,
            ));
        } else {
            self::assertEmptyOrAbsent($pdo, $database);
        }
        $pdo->exec('USE `' . $database . '`');
        self::assertSelectedDatabase($pdo, $database);
        self::$ownedDatabases[$database] = [
            'database' => $database,
            'created' => $created,
            'resource_id' => $authorization['resource_id'],
        ];

        return [$pdo, $created];
    }

    public static function assertSelectedDatabase(PDO $pdo, string $database): void
    {
        self::assertDatabaseNameSyntax($database);
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('REGISTERED_MYSQL_SELECTED_DATABASE_MISMATCH');
        }
    }

    public static function cleanup(PDO $pdo, string $database, bool $created): void
    {
        $authorization = self::assertAuthorized($database);
        self::assertCleanupOwnership(self::$ownedDatabases[$database] ?? null, $database, $created, $authorization);
        self::assertSelectedDatabase($pdo, $database);

        if ($created) {
            $pdo->exec('DROP DATABASE `' . $database . '`');
            unset(self::$ownedDatabases[$database]);
            return;
        }

        // Preflight proved this registered test schema was empty. Every object now
        // present was therefore created by this test after ownership was recorded.
        // Events and stored routines also make a schema non-empty. They are
        // removed only after the same resource and empty-baseline ownership checks.
        $events = $pdo->prepare('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?');
        $events->execute([$database]);
        foreach ($events->fetchAll(PDO::FETCH_COLUMN) as $name) {
            self::assertFixtureObjectName((string) $name);
            $pdo->exec('DROP EVENT `' . $database . '`.`' . $name . '`');
        }
        $routines = $pdo->prepare('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?');
        $routines->execute([$database]);
        foreach ($routines->fetchAll(PDO::FETCH_ASSOC) as $routine) {
            $name = (string) $routine['ROUTINE_NAME'];
            $type = (string) $routine['ROUTINE_TYPE'];
            self::assertFixtureObjectName($name);
            if (!in_array($type, ['PROCEDURE', 'FUNCTION'], true)) {
                throw new RuntimeException('REGISTERED_MYSQL_FIXTURE_OBJECT_INVALID');
            }
            $pdo->exec('DROP ' . $type . ' `' . $database . '`.`' . $name . '`');
        }
        $objects = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($objects as $row) {
                $name = (string) ($row[0] ?? '');
                $type = strtoupper((string) ($row[1] ?? ''));
                self::assertFixtureObjectName($name);
                $started = hrtime(true);
                try {
                    $pdo->exec(($type === 'VIEW' ? 'DROP VIEW `' : 'DROP TABLE `') . $name . '`');
                } catch (PDOException $exception) {
                    throw new RuntimeException(sprintf(
                        'REGISTERED_MYSQL_CLEANUP_DROP_FAILED object=%s elapsed_ms=%.3f',
                        $name,
                        (hrtime(true) - $started) / 1_000_000,
                    ), 0, $exception);
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        unset(self::$ownedDatabases[$database]);
    }

    private static function assertFixtureObjectName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1) {
            throw new RuntimeException('REGISTERED_MYSQL_FIXTURE_OBJECT_INVALID');
        }
    }

    /**
     * Pure contract validation used by the runtime and by unit-level negative cases.
     *
     * @param array<string,mixed> $registry
     * @param array<string,string> $environment
     * @return array{host:string,port:int,database:string,version:string,resource_id:string}
     */
    public static function validateContract(array $registry, array $environment): array
    {
        foreach ([
            'DB_NAME', 'DB_HOST', 'DB_PORT', 'PEANUT_DATABASE_RESOURCE_ID',
            'PEANUT_DATABASE_ENDPOINT_ID', 'PEANUT_DATABASE_CONSUMER',
            'PEANUT_DATABASE_ENVIRONMENT',
        ] as $key) {
            if (!isset($environment[$key]) || trim($environment[$key]) === '') {
                throw new RuntimeException('REGISTERED_MYSQL_ENVIRONMENT_REQUIRED:' . $key);
            }
            $environment[$key] = trim($environment[$key]);
        }
        self::assertDatabaseNameSyntax($environment['DB_NAME']);
        if (preg_match('/^[1-9][0-9]{0,4}$/D', $environment['DB_PORT']) !== 1
            || (int) $environment['DB_PORT'] > 65535) {
            throw new RuntimeException('REGISTERED_MYSQL_ENVIRONMENT_MISMATCH');
        }
        if (($registry['schema_version'] ?? null) !== 1 || ($registry['project_id'] ?? null) !== 'peanut-admin') {
            throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_INVALID');
        }

        $matches = [];
        foreach (($registry['resources']['databases'] ?? []) as $resource) {
            if (is_array($resource)
                && ($resource['stable_resource_id'] ?? null) === $environment['PEANUT_DATABASE_RESOURCE_ID']) {
                $matches[] = $resource;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('REGISTERED_MYSQL_RESOURCE_MISSING');
        }
        $resource = $matches[0];
        $consumer = $environment['PEANUT_DATABASE_CONSUMER'];
        $endpoint = null;
        foreach (['upstream_endpoint', 'container_endpoint'] as $key) {
            $candidateEndpoint = $resource[$key] ?? null;
            if (is_array($candidateEndpoint)
                && ($candidateEndpoint['endpoint_id'] ?? null) === $environment['PEANUT_DATABASE_ENDPOINT_ID']) {
                $endpoint = $candidateEndpoint;
            }
        }
        if ($endpoint === null) {
            throw new RuntimeException('REGISTERED_MYSQL_ENDPOINT_MISMATCH');
        }

        $allowedDatabases = [];
        $primary = $resource['database'] ?? null;
        if (is_string($primary) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $primary) === 1) {
            $allowedDatabases[] = $primary;
        }
        foreach (($resource['synthetic_databases'] ?? []) as $names) {
            if (!is_array($names)) {
                throw new RuntimeException('REGISTERED_MYSQL_RESOURCE_INVALID');
            }
            foreach ($names as $name) {
                if (is_string($name) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) === 1) {
                    $allowedDatabases[] = $name;
                }
            }
        }
        $allowedDatabases = array_values(array_unique($allowedDatabases));
        $p0e = ($resource['stable_resource_id'] ?? null) === 'peanut-admin-p0e-mysql84-gate';
        $runId = '';
        if ($p0e) {
            if (($registry['authority']['role'] ?? null) !== 'maintainer'
                || ($resource['application_runtime'] ?? null) !== false
                || ($resource['database'] ?? null) !== 'peanut_admin_development_p0e_<run_id>_<scenario>'
                || ($resource['namespace'] ?? null) !== 'peanut_admin_development_p0e_<run_id>_'
                || ($resource['run_id_pattern'] ?? null) !== '^[a-z0-9]{1,11}$'
                || ($resource['database_name_max_length'] ?? null) !== 64
                || !is_array($resource['allowed_scenarios'] ?? null)
                || preg_match('/^peanut_admin_development_p0e_([a-z0-9]{1,11})_([a-z_]+)$/D', $environment['DB_NAME'], $matches) !== 1
                || strlen($environment['DB_NAME']) > 64) {
                throw new RuntimeException('REGISTERED_MYSQL_RESOURCE_MISMATCH');
            }
            $runId = $matches[1];
            $allowedDatabases = [];
            foreach ($resource['allowed_scenarios'] ?? [] as $scenario) {
                if (!in_array($scenario, [
                    'standalone_fresh', 'multi_tenant_fresh', 'module_lifecycle',
                    'consumer_module_cycle', 'standalone_browser', 'multi_tenant_browser',
                ], true)) {
                    throw new RuntimeException('REGISTERED_MYSQL_RESOURCE_MISMATCH');
                }
                $allowedDatabases[] = 'peanut_admin_development_p0e_' . $runId . '_' . $scenario;
            }
        }
        if (isset($resource['resource_scope']) && is_string($resource['resource_scope'])) {
            $scope = $resource['resource_scope'];
        } elseif (isset($resource['container_name']) && is_string($resource['container_name'])) {
            $scope = $resource['container_name'];
        } else {
            $scope = $resource['namespace'] ?? null;
        }
        $version = $resource['version'] ?? null;
        if ($p0e) {
            $scope = str_replace('<run_id>', $runId, (string) $resource['namespace']);
        }
        $developmentTest = $environment['PEANUT_DATABASE_ENVIRONMENT'] === 'development-test'
            && ($resource['application_runtime'] ?? null) === false
            && ($resource['lifecycle'] ?? null) === 'ephemeral';
        if ((!$p0e && !$developmentTest && ($resource['application_runtime'] ?? null) !== true)
            || ($resource['service_type'] ?? null) !== 'mysql'
            || ($resource['fallback'] ?? null) !== 'none'
            || !is_string($version) || preg_match('/^8\.4(?:\.[0-9]+)?$/D', $version) !== 1
            || !in_array($environment['PEANUT_DATABASE_ENVIRONMENT'], $resource['environments'] ?? [], true)
            || !in_array($environment['DB_NAME'], $allowedDatabases, true)) {
            throw new RuntimeException('REGISTERED_MYSQL_RESOURCE_MISMATCH');
        }
        $providedScope = trim($environment['PEANUT_DATABASE_RESOURCE_SCOPE'] ?? '');
        if ($providedScope !== '' && (!is_string($scope) || !hash_equals($scope, $providedScope))) {
            throw new RuntimeException('REGISTERED_MYSQL_RESOURCE_MISMATCH');
        }
        if (($endpoint['host'] ?? null) !== $environment['DB_HOST']
            || (int) ($endpoint['port'] ?? 0) !== (int) $environment['DB_PORT']
            || !in_array($consumer, $endpoint['consumers'] ?? [], true)) {
            throw new RuntimeException('REGISTERED_MYSQL_ENDPOINT_MISMATCH');
        }

        return [
            'host' => $environment['DB_HOST'],
            'port' => (int) $environment['DB_PORT'],
            'database' => $environment['DB_NAME'],
            'version' => $version,
            'resource_id' => $environment['PEANUT_DATABASE_RESOURCE_ID'],
        ];
    }

    public static function assertEmptyTableCount(int $tableCount): void
    {
        if ($tableCount !== 0) {
            throw new RuntimeException('REGISTERED_MYSQL_DATABASE_NOT_EMPTY');
        }
    }

    /**
     * @param array{database:string,created:bool,resource_id:string}|null $ownership
     * @param array{resource_id:string} $authorization
     */
    public static function assertCleanupOwnership(
        ?array $ownership,
        string $database,
        bool $created,
        array $authorization,
    ): void {
        if ($ownership === null
            || ($ownership['database'] ?? null) !== $database
            || ($ownership['created'] ?? null) !== $created
            || ($ownership['resource_id'] ?? null) !== ($authorization['resource_id'] ?? null)) {
            throw new RuntimeException('REGISTERED_MYSQL_CLEANUP_NOT_OWNED');
        }
    }

    /** @return array{host:string,port:int,database:string,version:string,resource_id:string} */
    private static function assertAuthorized(string $database): array
    {
        if (!hash_equals(self::configuredDatabaseName(), $database)) {
            throw new RuntimeException('REGISTERED_MYSQL_DATABASE_FORBIDDEN');
        }
        $root = dirname(__DIR__, 3);
        [$registryPath, $registryTool] = self::registrySource($root);
        try {
            $registry = json_decode(
                (string) file_get_contents($registryPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_INVALID');
        }
        if (!is_array($registry)) {
            throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_INVALID');
        }
        $environment = self::contractEnvironment();
        $authorization = self::validateContract($registry, $environment);
        self::assertRegistryToolResolution($root, $registryTool, $registryPath, $environment);
        return $authorization;
    }

    /** @param array{host:string,port:int} $authorization */
    private static function connect(array $authorization): PDO
    {
        try {
            return new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $authorization['host'], $authorization['port']),
                self::required('DB_USER'),
                self::required('DB_PASS'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                ],
            );
        } catch (PDOException) {
            throw new RuntimeException('REGISTERED_MYSQL_CONNECTION_UNAVAILABLE');
        }
    }

    private static function assertMysqlVersion(PDO $pdo, string $expected): void
    {
        $actual = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (preg_match('/^8\.4(?:\.[0-9]+)?(?:[-+].*)?$/D', $actual) !== 1
            || !str_starts_with($actual, $expected)) {
            throw new RuntimeException('REGISTERED_MYSQL_VERSION_MISMATCH');
        }
    }

    private static function assertEmptyOrAbsent(PDO $pdo, string $database): void
    {
        foreach ([
            'TABLES' => 'TABLE_SCHEMA',
            'ROUTINES' => 'ROUTINE_SCHEMA',
            'EVENTS' => 'EVENT_SCHEMA',
        ] as $catalog => $schemaColumn) {
            $objects = $pdo->prepare('SELECT COUNT(*) FROM information_schema.' . $catalog . ' WHERE ' . $schemaColumn . ' = ?');
            $objects->execute([$database]);
            self::assertEmptyTableCount((int) $objects->fetchColumn());
        }
    }

    /** @return array<string,string> */
    private static function contractEnvironment(): array
    {
        $environment = [];
        foreach ([
            'DB_NAME', 'DB_HOST', 'DB_PORT', 'PEANUT_DATABASE_RESOURCE_ID',
            'PEANUT_DATABASE_ENDPOINT_ID', 'PEANUT_DATABASE_CONSUMER',
            'PEANUT_DATABASE_ENVIRONMENT',
        ] as $key) {
            $environment[$key] = self::required($key);
        }
        $scope = getenv('PEANUT_DATABASE_RESOURCE_SCOPE');
        if (is_string($scope) && trim($scope) !== '') {
            $environment['PEANUT_DATABASE_RESOURCE_SCOPE'] = trim($scope);
        }
        return $environment;
    }

    /** @return array{string,string,?string} registry path, matching tool, optional lease-bound digest */
    private static function registrySource(string $root): array
    {
        $canonicalRegistry = $root . '/resources/project-resources.json';
        $canonicalTool = $root . '/scripts/project-resource-registry';
        $configuredRegistry = getenv('PEANUT_DATABASE_REGISTRY_FILE');
        $configuredTool = getenv('PEANUT_DATABASE_REGISTRY_TOOL');
        $configuredSha256 = getenv('PEANUT_DATABASE_REGISTRY_SHA256');
        if ($configuredRegistry === false && $configuredTool === false && $configuredSha256 === false) {
            return [$canonicalRegistry, $canonicalTool, null];
        }
        if (!is_string($configuredRegistry) || !is_string($configuredTool) || !is_string($configuredSha256)
            || preg_match('/^[0-9a-f]{64}$/D', $configuredSha256) !== 1) {
            throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_SOURCE_INVALID');
        }
        $registryPath = realpath($configuredRegistry);
        $toolPath = realpath($configuredTool);
        $expectedRegistry = $toolPath === false
            ? false
            : realpath(dirname($toolPath, 2) . '/resources/project-resources.json');
        // P0-E explicitly binds the private maintainer registry digest to its
        // candidate lease and uses this candidate's unchanged native selector.
        $p0eRegistry = getenv('PEANUT_DATABASE_RESOURCE_ID') === 'peanut-admin-p0e-mysql84-gate';
        $maintainerRegistry = $toolPath === realpath($canonicalTool)
            && $registryPath !== realpath($canonicalRegistry);
        if ($p0eRegistry && !$maintainerRegistry) {
            throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_SOURCE_INVALID');
        }
        if ($registryPath === false || $toolPath === false || $expectedRegistry === false
            || (!$maintainerRegistry && $registryPath !== $expectedRegistry)
            || !is_file($registryPath) || is_link($registryPath)
            || !is_file($toolPath) || is_link($toolPath) || !is_executable($toolPath)
            || !hash_equals((string) hash_file('sha256', $canonicalTool), (string) hash_file('sha256', $toolPath))
            || !hash_equals($configuredSha256, (string) hash_file('sha256', $registryPath))) {
            throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_SOURCE_INVALID');
        }
        if ($maintainerRegistry) {
            if (is_link($configuredRegistry) || is_link($configuredTool)) {
                throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_SOURCE_INVALID');
            }
            [$status, $stdout, $stderr] = self::run([
                $toolPath, $p0eRegistry ? 'p0e-maintainer-source' : 'maintainer-source', '--sha256', $configuredSha256,
            ], $root, [
                'PATH' => (string) getenv('PATH'),
                'PEANUT_RESOURCE_REGISTRY' => $registryPath,
            ]);
            if ($status !== 0 || trim($stderr) !== '' || trim($stdout) !== $registryPath) {
                throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_SOURCE_INVALID');
            }
        }
        return [$registryPath, $toolPath, $configuredSha256];
    }

    /** @param array<string,string> $environment */
    private static function assertRegistryToolResolution(string $root, string $registryTool, string $registryPath, array $environment): void
    {
        $arguments = [
            $registryTool, 'database-env',
            '--deployment-target', $environment['PEANUT_DATABASE_ENVIRONMENT'],
            '--consumer', $environment['PEANUT_DATABASE_CONSUMER'],
            '--resource-id', $environment['PEANUT_DATABASE_RESOURCE_ID'],
        ];
        if ($environment['PEANUT_DATABASE_RESOURCE_ID'] === 'peanut-admin-p0e-mysql84-gate') {
            array_push($arguments, '--database-name', $environment['DB_NAME']);
        }
        $processEnvironment = ['PEANUT_RESOURCE_REGISTRY' => $registryPath];
        foreach (['PATH', 'HOME', 'TMPDIR', 'SystemRoot', 'COMSPEC', 'PATHEXT'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $processEnvironment[$key] = $value;
            }
        }
        [$status, $stdout, $stderr] = self::run($arguments, $root, $processEnvironment);
        if ($status !== 0 || trim($stderr) !== '') {
            throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_TOOL_UNAVAILABLE');
        }
        $resolved = [];
        foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $resolved[$parts[0]] = $parts[1];
            }
        }
        $resolvedKeys = [
            'PEANUT_DATABASE_RESOURCE_ID', 'PEANUT_DATABASE_ENDPOINT_ID',
            'PEANUT_DATABASE_CONSUMER', 'DB_HOST', 'DB_PORT',
        ];
        if ($environment['PEANUT_DATABASE_RESOURCE_ID'] === 'peanut-admin-p0e-mysql84-gate') {
            $resolvedKeys[] = 'DB_NAME';
        }
        foreach ($resolvedKeys as $key) {
            if (($resolved[$key] ?? null) !== $environment[$key]) {
                throw new RuntimeException('REGISTERED_MYSQL_REGISTRY_TOOL_MISMATCH');
            }
        }
    }

    /** @param list<string> $command @return array{int,string,string} */
    private static function run(array $command, string $root, ?array $environment = null): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        if (!is_resource($process)) {
            return [127, '', 'unavailable'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    private static function assertDatabaseNameSyntax(string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database) !== 1) {
            throw new RuntimeException('REGISTERED_MYSQL_DATABASE_FORBIDDEN');
        }
    }

    private static function required(string $key): string
    {
        $value = str_starts_with($key, 'DB_') ? env($key) : getenv($key);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('REGISTERED_MYSQL_ENVIRONMENT_REQUIRED:' . $key);
        }
        return trim($value);
    }
}
