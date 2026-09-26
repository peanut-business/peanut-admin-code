<?php

declare(strict_types=1);

namespace tests\Unit;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 验证原生清单的选择、归属与来源指纹，不执行构建器的写入入口。 */
final class PublicDevelopmentInventoryPolicyTest extends TestCase
{
    private string $source;

    public static function setUpBeforeClass(): void
    {
        $file = dirname(__DIR__, 3) . '/scripts/build-application-template-inventory';
        $nodes = (new ParserFactory())->createForHostVersion()->parse((string) file_get_contents($file));
        $functions = array_values(array_filter(
            (new NodeFinder())->findInstanceOf($nodes ?? [], Node\Stmt\Function_::class),
            static fn(Node\Stmt\Function_ $node): bool => in_array($node->name->toString(), ['excluded', 'classification', 'transform', 'sourceDigest'], true),
        ));
        self::assertCount(4, $functions);
        // Trusted checked-in function bodies only; no builder entry, package or request code is evaluated.
        $namespace = new Node\Stmt\Namespace_(new Node\Name('tests\\Unit\\InventoryPolicyUnderTest'), $functions);
        eval((new Standard())->prettyPrint([$namespace]));
    }

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = realpath(sys_get_temp_dir());
        self::assertIsString($temporary);
        self::assertStringStartsWith($root . '/.local/tmp/', $temporary);
        $this->source = $temporary . '/public-doc-source-' . bin2hex(random_bytes(8)) . '.md';
        self::assertNotFalse(file_put_contents($this->source, "# Public contract\nRevision one.\n"));
    }

    protected function tearDown(): void
    {
        if (isset($this->source) && is_file($this->source)) {
            unlink($this->source);
        }
    }

    public static function publicDocuments(): array
    {
        return [
            ['docs/development/standard.md'],
            ['docs/README.md'],
            ['docs/development/application-conventions.md'],
            ['docs/development/controller-access-and-namespaces.md'],
            ['docs/development/developer-center-and-documentation.md'],
            ['docs/development/php-thinkphp-guidelines.md'],
            ['docs/architecture/overview.md'],
            ['docs/architecture/default-delivery-and-ownership.md'],
            ['docs/architecture/identity-organization-access.md'],
            ['docs/architecture/tenant-modules-and-data-scopes.md'],
            ['docs/architecture/module-development-delivery.md'],
            ['docs/architecture/service-bindings.md'],
            ['docs/architecture/events-and-tasks.md'],
            ['docs/module-layout.md'],
            ['docs/public/api-and-sdk.md'],
        ];
    }

    #[DataProvider('publicDocuments')]
    public function testPublicContractBytesParticipateInTheSourceBaseline(string $path): void
    {
        self::assertFileExists(dirname(__DIR__, 3) . '/' . $path, 'The selected public source must exist in this candidate.');
        self::assertFalse(InventoryPolicyUnderTest\excluded($path), 'Public development rules must actually ship.');
        self::assertSame('managed', InventoryPolicyUnderTest\classification($path));
        $transform = InventoryPolicyUnderTest\transform($path, 'managed');
        self::assertSame('text', $transform, 'A public contract must not use reconstructed-doc semantics.');
        $before = InventoryPolicyUnderTest\sourceDigest($this->source, $path, $transform);
        self::assertSame(hash_file('sha256', $this->source), $before);
        self::assertNotFalse(file_put_contents($this->source, "# Public contract\nRevision two.\n"));
        $after = InventoryPolicyUnderTest\sourceDigest($this->source, $path, $transform);
        self::assertNotSame($before, $after, 'Changed public contract bytes must invalidate the old baseline.');
    }

    public function testParameterReconstructedReadmeKeepsItsExistingSemantics(): void
    {
        self::assertSame('readme', InventoryPolicyUnderTest\transform('README.md', 'managed'));
        $before = InventoryPolicyUnderTest\sourceDigest($this->source, 'README.md', 'readme');
        self::assertNotFalse(file_put_contents($this->source, "Different maintainer readme.\n"));
        self::assertSame($before, InventoryPolicyUnderTest\sourceDigest($this->source, 'README.md', 'readme'));
    }
}
