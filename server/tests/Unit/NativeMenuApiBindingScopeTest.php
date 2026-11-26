<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\policy\NativeMenuApiBindingScope;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PHPUnit\Framework\TestCase;

final class NativeMenuApiBindingScopeTest extends TestCase
{
    private const REV = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private static function page(string $key, string $permission, string $type = 'C'): array
    {
        return [
            'menu_key' => $key, 'type' => $type,
            'permission_key' => $permission, 'revision' => self::REV,
        ];
    }

    private function rejects(string $code, array $nodes, string $key = 'tenant.catalog.page', string $revision = self::REV): void
    {
        try {
            NativeMenuApiBindingScope::permissionKey($nodes, $key, $revision);
            self::fail('An unsafe native menu/API source must not become editable.');
        } catch (AdminAccessException $error) {
            self::assertSame($code, $error->errorCode);
        }
    }

    public function testExistingModulePermissionWithOneMenuCanBindMultipleOfficialApisWithoutNewBundle(): void
    {
        $items = [
            self::page('tenant.catalog.page', 'core.role.read'),
            self::page('tenant.catalog.other', 'core.role.update'),
        ];
        self::assertSame('core.role.read',
            NativeMenuApiBindingScope::permissionKey($items, 'tenant.catalog.page', self::REV));
    }

    public function testDistinctButtonsCanUseDistinctNativePermissionKeys(): void
    {
        $items = [
            self::page('tenant.catalog.button.edit', 'core.role.update', 'A'),
            self::page('tenant.catalog.button.read', 'core.role.read', 'A'),
        ];
        self::assertSame('core.role.update',
            NativeMenuApiBindingScope::permissionKey($items, 'tenant.catalog.button.edit', self::REV));
    }

    public function testSharedNativePermissionAcrossMenusIsNotIndependentlyEditable(): void
    {
        $this->rejects('MENU_API_SHARED_PERMISSION', [
            self::page('tenant.catalog.page', 'peanut.settings.read'),
            self::page('tenant.settings.page', 'peanut.settings.read'),
        ]);
    }

    public function testGroupCannotOwnDirectApiWriteAndDoesNotBlockOtherNode(): void
    {
        $group = self::page('tenant.catalog.group', '', 'M');
        self::assertSame('core.role.read',
            NativeMenuApiBindingScope::permissionKey([
                $group, self::page('tenant.catalog.page', 'core.role.read'),
            ], 'tenant.catalog.page', self::REV));
        $this->rejects('MENU_API_NODE_INVALID', [$group], 'tenant.catalog.group');
    }

    public function testEmptyPermissionCannotPretendToGrantAnApi(): void
    {
        $this->rejects('MENU_API_SHARED_PERMISSION', [self::page('tenant.catalog.page', '')]);
    }

    public function testDuplicateMenuIdentityFailsClosed(): void
    {
        $this->rejects('MENU_API_NODE_AMBIGUOUS', [
            self::page('tenant.catalog.page', 'core.role.read'),
            self::page('tenant.catalog.page', 'core.role.update'),
        ]);
    }

    public function testHiddenSourceNodesCannotShareAGrantEvenWhenExcludedFromVisibleBindings(): void
    {
        $record = static fn(string $key, string $permission): array => [
            'key' => $key, 'type' => 'page',
            'instance_permission' => null, 'required_permission' => $permission,
        ];
        NativeMenuApiBindingScope::assertWholeSourceExclusive([
            $record('tenant.catalog.page', 'core.role.read'),
        ], 'core.role.read', 'tenant.catalog.page');
        try {
            NativeMenuApiBindingScope::assertWholeSourceExclusive([
                $record('tenant.catalog.page', 'core.role.read'),
                $record('hidden.system.page', 'core.role.read'),
            ], 'core.role.read', 'tenant.catalog.page');
            self::fail('Hidden source menu must not become an accidental extra grantee.');
        } catch (AdminAccessException $error) {
            self::assertSame('MENU_API_SHARED_PERMISSION', $error->errorCode);
        }
    }

    public function testUnknownNodeIsRejected(): void
    {
        $this->rejects('RESOURCE_NOT_FOUND', []);
    }

    public function testStaleRevisionCannotUseCurrentApiBindings(): void
    {
        $this->rejects('REVISION_MISMATCH',
            [self::page('tenant.catalog.page', 'core.role.read')], revision: str_repeat('b', 64));
    }
}
