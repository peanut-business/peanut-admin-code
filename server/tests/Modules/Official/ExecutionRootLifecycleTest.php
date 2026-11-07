<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use app\common\execution\ExecutionContextStore;
use app\common\execution\InstanceExecutionContext;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

$checks = 0;
$expect = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$store = new ExecutionContextStore();
$system = fn(int $tenant) => new SystemExecutionContext(new TenantSystemContext($tenant, 'task-worker', 'root.test', 'root-test'));
$expect($store->rootIdentity() === null && $store->isEmpty(), 'empty stack has no root');
$previous = null;
foreach ([101, 101, 202] as $tenant) {
    $store->run($system($tenant), function () use ($store, $system, $tenant, $expect, &$previous): void {
        $root = $store->rootIdentity();
        $expect($root !== null && $root !== $previous, 'each outer run has a fresh opaque object');
        $previous = $root;
        $store->run($system($tenant), fn() => $expect($store->rootIdentity() === $root, 'nested run retains root'));
        foreach ([[$system($tenant + 1), 'EXECUTION_TENANT_CONTEXT_MISMATCH'],
            [new InstanceExecutionContext('root.instance', 'root-instance'), 'EXECUTION_AUDIENCE_CONTEXT_MISMATCH']] as [$context, $code]) {
            try {
                $store->run($context, fn() => throw new RuntimeException('invalid context ran'));
                throw new RuntimeException('cross-boundary run accepted');
            } catch (DomainException $error) {
                $expect($error->getMessage() === $code && $store->rootIdentity() === $root, 'rejected nested run preserves root and ordinary guard');
            }
        }
    });
    $expect($store->rootIdentity() === null && $store->isEmpty(), 'outer finally releases identity');
}
try {
    $store->run($system(101), fn() => throw new RuntimeException('expected failure'));
} catch (RuntimeException $error) {
    $expect($error->getMessage() === 'expected failure' && $store->rootIdentity() === null && $store->isEmpty(), 'exception finally releases root');
}
echo 'EXECUTION_ROOT_LIFECYCLE_OK assertions=' . $checks . ' no_db=1' . PHP_EOL;
