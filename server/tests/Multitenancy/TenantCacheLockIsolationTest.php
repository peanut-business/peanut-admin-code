<?php

declare(strict_types=1);

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

$lockA = TenantNamespace::lockName($tenantA, $lockSeed);
$lockB = TenantNamespace::lockName($tenantB, $lockSeed);
expectTenantCacheLock($lockA !== $lockB, 'same logical lock seed must differ across tenants');
expectTenantCacheLock($lockA === TenantNamespace::lockName($tenantA, $lockSeed), 'lock name must be stable');
expectTenantCacheLock(strlen($lockA) <= 64 && strlen($lockB) <= 64, 'lock names must fit MySQL limits');

foreach ([
    static fn() => TenantScope::fromTrustedContext(0, 'request:req-zero'),
    static fn() => TenantScope::fromTrustedContext(-1, 'request:req-negative'),
    static fn() => TenantScope::fromTrustedContext(1, ''),
    static fn() => TenantScope::fromTrustedContext(1, "request:\nforged"),
    static fn() => TenantNamespace::cacheKey($tenantA, ''),
    static fn() => TenantNamespace::cacheTag($tenantA, "bad\0tag"),
    static fn() => TenantNamespace::lockName($tenantA, str_repeat('x', 513)),
    static fn() => TenantNamespace::cacheKey(['tenant_id' => 101], $logicalKey),
    static fn() => TenantNamespace::lockName(null, $lockSeed),
] as $index => $operation) {
    expectTenantCacheLockRejected($operation, 'invalid or forged scope/input was accepted at case ' . $index);
}

$scopeMethods = array_map(
    static fn(ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass(TenantScope::class))->getMethods(ReflectionMethod::IS_PUBLIC),
);
expectTenantCacheLock(
    !array_intersect(['fromRequest', 'fromPayload', 'fromArray'], $scopeMethods),
    'TenantScope must not accept request or payload data',
);

echo "MT03-CACHE-LOCK-001 passed\n";
