<?php

declare(strict_types=1);

namespace app\platform\policy;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;

/** Fail-closed guard for reusing native permission_api from one formal menu node. */
final class NativeMenuApiBindingScope
{
    /**
     * Recheck the ENTIRE native tenant menu registration. The UI bindings
     * projection omits instance control pages; hidden/retired author nodes
     * must not conceal another consumer of the same permission.
     * @param list<array<string,mixed>> $registered
     */
    public static function assertWholeSourceExclusive(array $registered, string $permission, string $ownerMenu): void
    {
        $matches = [];
        foreach ($registered as $row) {
            if (!in_array($row['type'] ?? null, ['page', 'action'], true)) {
                continue;
            }
            $actual = $row['instance_permission'] ?? $row['required_permission'] ?? '';
            if ($actual === $permission) {
                $matches[] = $row['key'] ?? null;
            }
        }
        if ($matches !== [$ownerMenu]) {
            throw AdminAccessException::conflict(
                'MENU_API_SHARED_PERMISSION',
                'This permission is also referenced by another native menu or action.',
            );
        }
    }

    /** @param list<array<string,mixed>> $nodes */
    public static function permissionKey(array $nodes, string $menuKey, string $expectedRevision): string
    {
        $matching = array_values(array_filter($nodes,
            static fn(array $node): bool => ($node['menu_key'] ?? null) === $menuKey));
        if ($matching === []) {
            throw AdminAccessException::notFound();
        }
        if (count($matching) !== 1) {
            throw AdminAccessException::conflict('MENU_API_NODE_AMBIGUOUS', 'The formal menu identity is duplicated.');
        }
        $menu = $matching[0];
        if (!in_array($menu['type'] ?? null, ['C', 'A'], true)) {
            throw AdminAccessException::invalid('MENU_API_NODE_INVALID', 'Only a page or button can own API permissions.');
        }
        if (!is_string($menu['revision'] ?? null) ||
            !hash_equals($menu['revision'], $expectedRevision)) {
            throw AdminAccessException::revisionMismatch();
        }
        $permission = $menu['permission_key'] ?? null;
        if (!is_string($permission) || $permission === '' ||
            count(array_filter($nodes, static fn(array $node): bool =>
                in_array($node['type'] ?? null, ['C', 'A'], true) &&
                ($node['permission_key'] ?? null) === $permission)) !== 1) {
            throw AdminAccessException::conflict(
                'MENU_API_SHARED_PERMISSION',
                'The native grant is missing or shared by multiple menu nodes.',
            );
        }
        return $permission;
    }
}
