<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class AllocationStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function closeCursor(): bool { return true; }
}

final class AllocationPdo extends PDO
{
    public array $commands = [];
    public int $expiry = 86400;
    public bool $queryFailure = false;
    public function __construct(public mixed $allocation = '1000000000') {}
    public function exec(string $statement): int|false
    {
        $this->commands[] = $statement;
        if (!preg_match('/\ASET SESSION information_schema_stats_expiry=([0-9]+)\z/D', $statement, $m)) {
            throw new LogicException('Unexpected write.');
        }
        $this->expiry = (int) $m[1]; return 0;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query === 'SELECT @@SESSION.information_schema_stats_expiry AS expiry') {
            return new AllocationStatement([['expiry' => $this->expiry]]);
        }
        if ($query !== "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_system_menu'"
            || $this->expiry !== 0) { throw new LogicException('Allocation must read current engine statistics.'); }
        if ($this->queryFailure) { throw new RuntimeException('READ_FAILED'); }
        return new AllocationStatement([['AUTO_INCREMENT' => $this->allocation]]);
    }
}

$method = (new ReflectionClass(PeanutAdmin\Modules\Identity\Menu\MenuSchemaMigrationProof::class))->getMethod('assertCustomIdAllocation');
foreach (['1000000000', '1000000001', 1000000000] as $value) {
    $pdo = new AllocationPdo($value); $method->invoke(null, $pdo);
    if ($pdo->expiry !== 86400 || count($pdo->commands) !== 2) { throw new LogicException('Session not restored.'); }
}
foreach ([null, '243', '999999999'] as $value) {
    $pdo = new AllocationPdo($value);
    try { $method->invoke(null, $pdo); throw new LogicException('Invalid allocation accepted.'); }
    catch (RuntimeException $error) {
        if ($error->getMessage() !== 'IDENTITY_MENU_MIGRATION_CUSTOM_ID_ALLOCATION_RANGE') { throw $error; }
    }
    if ($pdo->expiry !== 86400) { throw new LogicException('Refusal changed session.'); }
}
$pdo = new AllocationPdo(); $pdo->queryFailure = true;
try { $method->invoke(null, $pdo); throw new LogicException('Read failure accepted.'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'READ_FAILED') { throw $error; } }
if ($pdo->expiry !== 86400) { throw new LogicException('Failed read changed session.'); }
echo "Menu allocation observation: 3 valid, 3 invalid and failed-read session restoration passed; no database.\n";
