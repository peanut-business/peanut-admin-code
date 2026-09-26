<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\infrastructure\scaffold\ApplicationCreator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/** Exercises the actual inventory validator and renderer, without invoking installation or private operations. */
final class ApplicationDevelopmentEntryTest extends TestCase
{
    private string $root;
    private string $temporary;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $parent = $this->root . '/.local/tmp/application-development-entry-tests';
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('APPLICATION_ENTRY_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($this->root . '/.local/tmp/', (string) realpath($parent));
        $this->temporary = $parent . '/case-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary, 0700));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary . '/*') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) {
                unlink($path);
            }
        }
        rmdir($this->temporary);
    }

    private function inventory(): array
    {
        return json_decode((string) file_get_contents($this->root . '/scaffold/application-template-inventory.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function entry(string $target = 'AGENTS.md'): array
    {
        return [
            'path' => 'server/resources/scaffold-application/' . $target . '.stub',
            'target' => $target,
            'classification' => 'generated-managed',
            'owner' => 'scaffold',
            'transform' => 'docs-page',
            'mode' => 0644,
            'profiles' => ['minimal', 'standard', 'full'],
            'source_sha256' => str_repeat('a', 64),
        ];
    }

    private function validate(array $entries): array
    {
        $inventory = $this->inventory();
        $inventory['files'] = $entries;
        $path = $this->temporary . '/inventory.json';
        file_put_contents($path, json_encode($inventory, JSON_THROW_ON_ERROR));
        $creator = new ApplicationCreator($this->root, $path);
        return (new ReflectionMethod(ApplicationCreator::class, 'loadInventory'))->invoke($creator);
    }

    public function testActualInventorySelectsOnlyThePublicTemplateForTheRootEntry(): void
    {
        $sources = array_column($this->inventory()['files'], null, 'path');
        self::assertArrayNotHasKey('AGENTS.md', $sources, 'The maintainer entry must not compete with the public target.');
        $path = 'server/resources/scaffold-application/AGENTS.md.stub';
        self::assertArrayHasKey($path, $sources);
        self::assertFileExists($this->root . '/' . $path);
        $expected = $this->entry();
        $expected['source_sha256'] = hash_file('sha256', $this->root . '/' . $path);
        self::assertSame($expected, $sources[$path]);
    }

    public function testTheNativeValidatorAcceptsTheDeclaredDevelopmentEntry(): void
    {
        self::assertSame([$this->entry()], $this->validate([$this->entry()])['files']);
    }

    public static function forbiddenTargets(): array
    {
        return [['composer.json'], ['server/config/application.php'], ['scripts/install'], ['agents.md'], ['AGENT.md']];
    }

    #[DataProvider('forbiddenTargets')]
    public function testTheDocumentTemplateExceptionDoesNotAllowRuntimeOrMistypedTargets(string $target): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CREATE_APP_DOCUMENT_TEMPLATE_INVALID');
        $this->validate([$this->entry($target)]);
    }

    public function testTheSharedDevelopmentEntryCannotBeSilentlyMarkedAppOwned(): void
    {
        $entry = $this->entry();
        $entry['classification'] = 'app-owned';
        $this->expectExceptionMessage('CREATE_APP_DOCUMENT_TEMPLATE_INVALID');
        $this->validate([$entry]);
    }

    public function testDuplicateTargetProtectionStillApplies(): void
    {
        $other = $this->entry();
        $other['path'] = 'AGENTS.md';
        $other['transform'] = 'text';
        $this->expectExceptionMessage('CREATE_APP_INVENTORY_DUPLICATE_PATH');
        $this->validate([$this->entry(), $other]);
    }

    public function testEntryUsesPublicLocalReferencesAndRealTemplateBytes(): void
    {
        $relative = 'server/resources/scaffold-application/AGENTS.md.stub';
        $path = $this->root . '/' . $relative;
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);
        foreach (['peanut-admin-project', '/Users/', '.webcodex-managed-worktrees', 'refactor/standard-v1-20260926'] as $privateReference) {
            self::assertStringNotContainsString($privateReference, $content);
        }
        foreach (['docs/development/standard.md', 'docs/README.md', 'tools/quality/README.md'] as $publicPath) {
            self::assertStringContainsString($publicPath, $content);
            self::assertFileExists($this->root . '/' . $publicPath);
        }
        $creator = new ApplicationCreator($this->root, $this->root . '/scaffold/application-template-inventory.json');
        $rendered = (new ReflectionMethod(ApplicationCreator::class, 'transform'))->invoke(
            $creator,
            $content,
            $this->entry(),
            ['APPLICATION_VERSION' => '0.1.0', 'PRODUCT_NAME' => 'Synthetic Console', 'SLUG' => 'synthetic-console', 'PACKAGE_IDENTITY' => 'synthetic/console'],
            $this->temporary,
        );
        self::assertStringContainsString('Synthetic Console', $rendered);
        self::assertStringNotContainsString('{{', $rendered);
        self::assertNotSame((string) file_get_contents($this->root . '/AGENTS.md'), $rendered);
        $digest = new ReflectionMethod(ApplicationCreator::class, 'sourceDigest');
        $copy = $this->temporary . '/entry.stub';
        file_put_contents($copy, $content);
        $first = $digest->invoke(null, $copy, $relative, 'docs-page');
        self::assertSame(hash_file('sha256', $copy), $first);
        file_put_contents($copy, $content . "\nChanged public instruction.\n");
        self::assertNotSame($first, $digest->invoke(null, $copy, $relative, 'docs-page'));
    }
}
