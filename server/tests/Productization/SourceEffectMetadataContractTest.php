<?php

declare(strict_types=1);

use app\common\services\upgrade\SourceApplicationBackup;
use app\common\services\upgrade\ApplicationSchemaHost;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function effectMetadataExpect(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}

$hostClass = new ReflectionClass(ApplicationSchemaHost::class);
$host = $hostClass->newInstanceWithoutConstructor();
$readScope = $hostClass->getMethod('sourceReadScopeCovered');
$readTables = $hostClass->getConstant('SOURCE_READ_TABLES');
$backupTables = SourceApplicationBackup::applicationTables(array_keys($hostClass->getConstant('PRESTATE_SQL')));
$extraReads = array_values(array_diff($readTables, $backupTables));
effectMetadataExpect(count($readTables) === 14 && $extraReads === ['pa_platform_role_permission', 'pa_resource_operation_permission', 'pa_resource_operation_target_type'], 'Business read closure and backup scope became conflated.');
$schema = 'selected-host:3306/selected_db';
$access = ['schema' => $schema, 'operation' => 'read', 'complete' => true,
    'tables' => array_map(static fn(string $table): array => ['table' => $table, 'access' => 'read', 'rows' => '*'], $readTables)];
$covered = static fn(array $value, array $tables = []): bool => $readScope->invoke($host,
    ['mysql-access' => [json_encode($value, JSON_THROW_ON_ERROR)]], $schema, $tables === [] ? $readTables : $tables);
effectMetadataExpect($covered($access), 'Complete fourteen-table read scope refused.');
effectMetadataExpect($covered(['schema' => $schema, 'operation' => 'ddl', 'complete' => true, 'tables' => []]), 'Native DDL scope lost its stronger coverage.');
effectMetadataExpect($covered(['schema' => $schema, 'operation' => 'read', 'complete' => true, 'tables' => [['table' => 'pa_schema_migration', 'access' => 'read', 'rows' => '*']]], ['pa_schema_migration']), 'Receipt-only reader requires unrelated business tables.');
foreach ($readTables as $missing) {
    $changed = $access; $changed['tables'] = array_values(array_filter($access['tables'], static fn(array $row): bool => $row['table'] !== $missing));
    effectMetadataExpect(!$covered($changed), 'Missing business read acquired admission: ' . $missing);
}
$invalidReads = [];
$changed = $access; $changed['schema'] = 'other-schema'; $invalidReads[] = $changed;
$changed = $access; $changed['complete'] = false; $invalidReads[] = $changed;
$changed = $access; $changed['tables'][0]['rows'] = [['1']]; $invalidReads[] = $changed;
$changed = $access; $changed['tables'][0]['access'] = 'unknown'; $invalidReads[] = $changed;
foreach ($invalidReads as $changed) { effectMetadataExpect(!$covered($changed), 'Incomplete/foreign read scope acquired admission.'); }

final class EffectStatsStatement extends PDOStatement
{
    public function __construct(private int $expiry) {}
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return ['expiry' => $this->expiry]; }
    public function closeCursor(): bool { return true; }
}
final class EffectStatsPdo extends PDO
{
    public array $commands = [];
    public function __construct(public int $expiry) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== 'SELECT @@SESSION.information_schema_stats_expiry AS expiry') { throw new LogicException('Unexpected query.'); }
        return new EffectStatsStatement($this->expiry);
    }
    public function exec(string $statement): int|false
    {
        if (preg_match('/\ASET SESSION information_schema_stats_expiry=([0-9]+)\z/D', $statement, $match) !== 1) { throw new LogicException('Unexpected write.'); }
        $this->commands[] = $statement; $this->expiry = (int) $match[1]; return 0;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        effectMetadataExpect($this->expiry === 0, 'Ordinary observation used cached statistics.');
        throw new RuntimeException('TABLE_READ_FAILED');
    }
}
$observe = new ReflectionMethod(SourceApplicationBackup::class, 'observe');
foreach ([0, 86400] as $expiry) {
    $pdo = new EffectStatsPdo($expiry); $observe->invoke(null, $pdo, [], true);
    effectMetadataExpect($pdo->expiry === $expiry && count($pdo->commands) === 2, 'Successful observation did not restore its session.');
}
foreach (['pa_existing' => 'TABLE_READ_FAILED', 'invalid-table' => 'APPLICATION_SCHEMA_BACKUP_TABLE_UNSUPPORTED'] as $table => $reason) {
    $pdo = new EffectStatsPdo(86400);
    try { $observe->invoke(null, $pdo, [$table], true); throw new LogicException('Expected refusal.'); }
    catch (RuntimeException $error) { effectMetadataExpect($error->getMessage() === $reason, 'Wrong refusal.'); }
    effectMetadataExpect($pdo->expiry === 86400 && count($pdo->commands) === 2, 'Failed observation did not restore its session.');
}

$sql = new ReflectionMethod(SourceApplicationBackup::class, 'metadataSql');
$parse = new ReflectionMethod(SourceApplicationBackup::class, 'parseMetadata');
$session = ['sql_mode' => 'STRICT_TRANS_TABLES', 'quote_names' => 1];
$nonce = str_repeat('b', 32);
$whole = $sql->invoke(null, ['pa_existing'], $session, $nonce);
$selected = $sql->invoke(null, ['pa_existing'], $session, $nonce, ['pa_existing', 'pa_future']);
effectMetadataExpect(str_contains($selected, 'SET SESSION information_schema_stats_expiry=0;')
    && str_contains($selected, "'stats_expiry',@@SESSION.information_schema_stats_expiry"), 'Native observer did not bind uncached session statistics.');
effectMetadataExpect(!str_contains($whole, 'TABLE_NAME IN'), 'Existing whole-schema partial reader changed.');
foreach (["TABLE_NAME IN ('pa_existing','pa_future')", "k.TABLE_NAME IN ('pa_existing','pa_future')",
    "k.REFERENCED_TABLE_NAME IN ('pa_existing','pa_future')", "EVENT_OBJECT_TABLE IN ('pa_existing','pa_future')"] as $predicate) {
    effectMetadataExpect(str_contains($selected, $predicate), 'Selected metadata omitted a fixed scope predicate.');
}
effectMetadataExpect(substr_count($selected, 'SHOW CREATE TABLE') === 1 && !str_contains($selected, 'SHOW CREATE TABLE `pa_future`'), 'Absent objects acquired invented SHOW CREATE.');
effectMetadataExpect(strpos($selected, 'SHOW CREATE TABLE') < strpos($selected, "SELECT HEX(JSON_OBJECT('tables'")
    && str_contains($selected, 'DDL_BEFORE_STATISTICS_' . $nonce), 'Native statistics preceded the InnoDB allocator-opening DDL read.');
effectMetadataExpect(str_contains($selected, 'k.REFERENCED_TABLE_SCHEMA=DATABASE()') && str_contains($selected, 'ROLLBACK;'), 'Cross-schema incoming FK or read-only close was lost.');

$directory = dirname(__DIR__, 3) . '/.local/tmp/source-effect-metadata-contract';
effectMetadataExpect(!file_exists($directory) && !is_link($directory), 'Contract temporary directory already exists.');
if (!is_dir(dirname($directory))) { mkdir(dirname($directory), 0700, true); }
mkdir($directory, 0700);
$path = $directory . '/metadata.txt';
$binding = ['database' => ['server_uuid' => 'uuid-one', 'server_hostname' => 'db-host', 'server_port' => 3306, 'database' => 'selected_db']];
$identity = $binding['database'] + ['version' => '8.4.10', 'partial_revokes' => 0, 'stats_expiry' => 0];
$fk = ['CONSTRAINT_SCHEMA' => 'outside_db', 'CONSTRAINT_NAME' => 'incoming', 'TABLE_SCHEMA' => 'outside_db',
    'TABLE_NAME' => 'foreign_child', 'COLUMN_NAME' => 'parent_id', 'ORDINAL_POSITION' => 1,
    'REFERENCED_TABLE_SCHEMA' => 'selected_db', 'REFERENCED_TABLE_NAME' => 'pa_existing', 'REFERENCED_COLUMN_NAME' => 'id',
    'UPDATE_RULE' => 'CASCADE', 'DELETE_RULE' => 'RESTRICT', 'IS_LOCAL_PARENT' => 1];
$data = ['tables' => [['name' => 'pa_existing', 'type' => 'BASE TABLE', 'engine' => 'InnoDB', 'auto_increment' => '4']],
    'foreign_keys' => [$fk], 'triggers' => []];
$render = static function (array $identity, array $data, array $grants, string $marker, bool $existing = true): string {
    $hex = static fn(array $value): string => bin2hex(json_encode($value, JSON_THROW_ON_ERROR));
    $lines = [str_repeat('a', 64) . '  /usr/bin/mysql', '/usr/bin/mysql Ver 8.4.10 for Linux', $hex($identity),
        ...$grants, 'GRANTS_END_' . $marker, 'DDL_BEFORE_STATISTICS_' . $marker];
    if ($existing) { array_push($lines, 'pa_existing', 'pa_existing' . "\t" . 'CREATE TABLE `pa_existing` (`id` bigint unsigned NOT NULL)'); }
    $lines[] = $hex($data);
    return implode("\n", $lines) . "\n";
};
$grants = ['GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost`'];
$run = static function (string $bytes, array $tables = ['pa_existing']) use ($parse, $path, $nonce, $binding): array {
    file_put_contents($path, $bytes);
    return $parse->invoke(null, $path, $tables, $nonce, $binding, ['mysql_version' => '8.4.10'], '8.4.10');
};
try {
    $observed = $run($render($identity, $data, $grants, $nonce));
    effectMetadataExpect($observed['metadata']['foreign_keys'][0]['TABLE_SCHEMA'] === 'outside_db'
        && $observed['metadata']['foreign_keys'][0]['UPDATE_RULE'] === 'CASCADE', 'Incoming outside cascade was hidden.');
    effectMetadataExpect($observed['metadata']['objects']['pa_existing']['auto_increment'] === '4', 'Original table counter changed.');
    $fresh = $data; $fresh['tables'][0]['auto_increment'] = '1';
    effectMetadataExpect($run($render($identity, $fresh, $grants, $nonce))['metadata']['objects']['pa_existing']['auto_increment'] === '1', 'Counter 1 must be the actual native value even when DDL omits the table option.');
    $unknownCounter = $data; $unknownCounter['tables'][0]['auto_increment'] = null;
    effectMetadataExpect($run($render($identity, $unknownCounter, $grants, $nonce))['metadata']['objects']['pa_existing']['auto_increment'] === null, 'Unknown allocator was normalized to 1.');
    $empty = $data; $empty['tables'] = []; $empty['foreign_keys'] = [];
    effectMetadataExpect($run($render($identity, $empty, $grants, $nonce, false), [])['metadata']['objects'] === [], 'All-absent scope cannot be observed.');
    $wrongIdentity = $identity; $wrongIdentity['server_uuid'] = 'uuid-other';
    $hiddenTable = $data; $hiddenTable['tables'][0]['name'] = 'pa_unselected';
    $revoked = $identity; $revoked['partial_revokes'] = 1;
    $cached = $identity; $cached['stats_expiry'] = 86400;
    $unknownStats = $identity; unset($unknownStats['stats_expiry']);
    $oldOrder = explode("\n", rtrim($render($identity, $data, $grants, $nonce), "\n"));
    $aggregate = array_pop($oldOrder); array_splice($oldOrder, 5, 1, [$aggregate]); // Old statistics-first wire order.
    file_put_contents($path, implode("\n", $oldOrder) . "\n"); chmod($path, 0600);
    $historical = SourceApplicationBackup::historicalEffectMetadata($path,
        ['present' => ['pa_existing'], 'nonce' => $nonce, 'binding' => $binding],
        ['source_schema_backup' => ['mysql_version' => '8.4.10']], dirname(__DIR__, 2), 'statistics-before-ddl');
    effectMetadataExpect($historical['qualification'] === 'historical_failure_evidence_only'
        && $historical['metadata']['objects']['pa_existing']['auto_increment'] === '4'
        && $historical['stdout']['sha256'] === hash_file('sha256', $path), 'Original protected metadata acquired current qualification or lost its actual bytes.');
    foreach ([
        $render($wrongIdentity, $data, $grants, $nonce),
        $render($identity, $hiddenTable, $grants, $nonce),
        $render($identity, $data, ['GRANT SELECT, TRIGGER ON `selected_db`.* TO `limited`@`%`'], $nonce),
        $render($identity, $data, ['GRANT SELECT ON *.* TO `limited`@`%`'], $nonce),
        $render($identity, $data, $grants, str_repeat('c', 32)),
        $render($revoked, $data, $grants, $nonce),
        $render($cached, $data, $grants, $nonce),
        $render($unknownStats, $data, $grants, $nonce),
        implode("\n", $oldOrder) . "\n",
    ] as $invalid) {
        $rejected = false;
        try { $run($invalid); } catch (RuntimeException) { $rejected = true; }
        effectMetadataExpect($rejected, 'Invalid identity, visibility, coverage or nonce acquired native observation.');
    }
} finally {
    if (is_file($path)) { unlink($path); }
    rmdir($directory);
}
echo "SOURCE-EFFECT-METADATA-CONTRACT passed: parser/scope; full14, DDL and receipt reads accepted, 18 read-scope omissions refused; 4 session-restoration cases; native DDL-before-statistics, real counter1/NULL, old-order/cached/unknown stats refusal; native SSH/MySQL execution NOT_RUN\n";
