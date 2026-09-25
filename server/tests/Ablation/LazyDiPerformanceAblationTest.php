<?php

declare(strict_types=1);

namespace tests\Ablation;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\adminapi\service\AdminApiAccessRegistry;

/**
 * EXP-02: bounded registry behavior check.
 * It does not measure middleware construction, database access, or performance.
 */
final class LazyDiPerformanceAblationTest
{
    private static function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public static function run(): void
    {
        $registry = new AdminApiAccessRegistry(1, [
            'authenticated' => ['GET adminapi/auth/profile'],
            'public' => ['POST adminapi/auth/login'],
        ]);

        self::expect($registry->version() === 1, 'registry version contract changed');
        self::expect($registry->isAuthenticatedOnly('GET', '/adminapi/auth/profile'), 'authenticated route was not recognized');
        self::expect($registry->isPublic('POST', '/adminapi/auth/login'), 'public route was not recognized');
        self::expect(!$registry->isAuthenticatedOnly('POST', '/adminapi/auth/login'), 'negative control classified the registered public route as authenticated-only');
        self::expect(!$registry->isPublic('GET', '/adminapi/auth/unknown'), 'negative control accepted an unregistered route');

        echo "LAZY-DI-ABLATION-001 passed: registry route behavior and default denial are verified\n";
    }
}

LazyDiPerformanceAblationTest::run();
