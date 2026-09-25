<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Tenancy\TenantCache;
use PeanutAdmin\Kernel\Tenancy\TenantCacheStore;
use PeanutAdmin\Kernel\Tenancy\TenantLockNamespace;
use PeanutAdmin\Kernel\Tenancy\TenantNamespace;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectTenantCacheLock(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectTenantCacheLockRejected(Closure $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException|TypeError) {
        return;
    }
    throw new RuntimeException($message);
}

final class TenantCacheMemoryStore implements TenantCacheStore
{
    /** @var array<string, mixed> */
    private array $values = [];
    private int $calls = 0;

    public function get(string $physicalKey, mixed $default = null): mixed
    {
        $this->calls++;
        return $this->values[$physicalKey] ?? $default;
    }

    public function set(string $physicalKey, mixed $value, int $ttlSeconds = 0): bool
    {
        $this->calls++;
        $this->values[$physicalKey] = $value;
        return true;
    }

    public function delete(string $physicalKey): bool
    {
        $this->calls++;
        unset($this->values[$physicalKey]);
        return true;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}

$tenantA = TenantScope::fromTrustedContext(101, 'request:req-a');
$tenantB = TenantScope::fromTrustedContext(202, 'request:req-b');
$logicalKey = 'shared:record:42';
$logicalTag = 'shared-records';
$lockSeed = 'shared-job-id';

$keyA = TenantNamespace::cacheKey($tenantA, $logicalKey);
$keyB = TenantNamespace::cacheKey($tenantB, $logicalKey);
expectTenantCacheLock($keyA !== $keyB, 'same logical cache key must differ across tenants');
expectTenantCacheLock($keyA === TenantNamespace::cacheKey($tenantA, $logicalKey), 'cache key must be stable');
expectTenantCacheLock(
    TenantNamespace::cacheTag($tenantA, $logicalTag) !== TenantNamespace::cacheTag($tenantB, $logicalTag),
    'same logical cache tag must differ across tenants',
);

$lockA = (new TenantLockNamespace($tenantA))->name($lockSeed);
$lockB = (new TenantLockNamespace($tenantB))->name($lockSeed);
expectTenantCacheLock($lockA !== $lockB, 'same logical lock seed must differ across tenants');
expectTenantCacheLock($lockA === (new TenantLockNamespace($tenantA))->name($lockSeed), 'lock name must be stable');
expectTenantCacheLock(strlen($lockA) <= 64 && strlen($lockB) <= 64, 'lock names must fit MySQL limits');

$store = new TenantCacheMemoryStore();
$cacheA = new TenantCache($tenantA, $store);
$cacheB = new TenantCache($tenantB, $store);
expectTenantCacheLock($cacheA->set($logicalKey, 'tenant-a'), 'tenant A set failed');
expectTenantCacheLock($cacheB->set($logicalKey, 'tenant-b'), 'tenant B set failed');
expectTenantCacheLock($cacheA->get($logicalKey) === 'tenant-a', 'tenant A read crossed namespaces');
expectTenantCacheLock($cacheB->get($logicalKey) === 'tenant-b', 'tenant B read crossed namespaces');
expectTenantCacheLock($cacheA->delete($logicalKey), 'tenant A delete failed');
expectTenantCacheLock($cacheA->get($logicalKey, 'missing') === 'missing', 'tenant A key was not deleted');
expectTenantCacheLock($cacheB->get($logicalKey) === 'tenant-b', 'tenant A cleanup deleted tenant B key');

$callsBeforeRejectedInput = $store->calls();
foreach ([
    static fn() => TenantScope::fromTrustedContext(0, 'request:req-zero'),
    static fn() => TenantScope::fromTrustedContext(-1, 'request:req-negative'),
    static fn() => TenantScope::fromTrustedContext(1, ''),
    static fn() => TenantScope::fromTrustedContext(1, "request:\nforged"),
    static fn() => $cacheA->get(''),
    static fn() => $cacheA->set("bad\0key", 'value'),
    static fn() => $cacheA->delete(str_repeat('x', 513)),
    static fn() => $cacheA->set('negative-ttl', 'value', -1),
    static fn() => new TenantCache(['tenant_id' => 101], $store),
    static fn() => new TenantCache(null, $store),
] as $index => $operation) {
    expectTenantCacheLockRejected($operation, 'invalid or forged scope/input was accepted at case ' . $index);
}
expectTenantCacheLock(
    $store->calls() === $callsBeforeRejectedInput,
    'invalid or forged scope/input reached the cache store',
);

$scopeMethods = array_map(
    static fn(ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(TenantScope::class))->getMethods(ReflectionMethod::IS_PUBLIC),
);
expectTenantCacheLock(
    !array_intersect(['fromRequest', 'fromPayload', 'fromArray'], $scopeMethods),
    'TenantScope must not accept request or payload data',
);
$cacheMethods = array_map(
    static fn(ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(TenantCache::class))->getMethods(ReflectionMethod::IS_PUBLIC),
);
expectTenantCacheLock(
    !array_intersect(['clear', 'flush', 'raw', 'store', 'keys'], $cacheMethods),
    'TenantCache exposed a global or raw store operation',
);

echo "MT03-CACHE-LOCK-001 passed\n";
