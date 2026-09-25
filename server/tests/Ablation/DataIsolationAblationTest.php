<?php

declare(strict_types=1);

namespace tests\Ablation;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * EXP-01: bounded tenant-bound join contract check.
 * This uses in-memory rows only; it is not SQL, ORM, or TenantScope evidence.
 */
final class DataIsolationAblationTest
{
    private static function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public static function run(): void
    {
        $bindings = [
            ['id' => 101, 'tenant_id' => 1, 'provider' => 'wechat'],
            ['id' => 202, 'tenant_id' => 2, 'provider' => 'wechat'],
        ];
        $grants = [
            ['tenant_id' => 1, 'external_binding_id' => 101],
            ['tenant_id' => 2, 'external_binding_id' => 101],
        ];

        $tenantId = 2;
        $targetBindingId = 101;
        $tenantBoundMatches = [];
        $idOnlyMatches = [];
        foreach ($grants as $grant) {
            if ($grant['tenant_id'] !== $tenantId || $grant['external_binding_id'] !== $targetBindingId) {
                continue;
            }
            foreach ($bindings as $binding) {
                if ($binding['id'] !== $grant['external_binding_id']) {
                    continue;
                }
                $idOnlyMatches[] = $binding;
                if ($binding['tenant_id'] === $grant['tenant_id']) {
                    $tenantBoundMatches[] = $binding;
                }
            }
        }

        self::expect($tenantBoundMatches === [], 'tenant-bound join accepted a cross-tenant binding');
        self::expect($idOnlyMatches !== [], 'negative control no longer demonstrates the ID-only match');
        self::expect($idOnlyMatches[0]['tenant_id'] !== $tenantId, 'negative control did not expose the cross-tenant row');
        self::expect(
            $grants[1]['tenant_id'] === $tenantId && $grants[1]['external_binding_id'] === $targetBindingId,
            'cross-tenant grant fixture was not constructed',
        );

        echo "DATA-ISOLATION-ABLATION-001 passed: tenant-bound join rejects cross-tenant row; ID-only control matches it\n";
    }
}

DataIsolationAblationTest::run();
