<?php

declare(strict_types=1);

use app\common\services\AbstractCrudService;
use app\common\services\BatchProjectionHelper;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Support\FieldDiffCalculator;
use PHPUnit\Framework\TestCase;
use think\db\BaseQuery;
use think\Model;

final class CrudProjectionTest extends TestCase
{
    public function testBatchedLookupsAndConfiguredTransforms(): void
    {
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 's', 1, 2, 3, 'web', new DateTimeImmutable(), 1, 1), 'r');
        $service = new class extends AbstractCrudService {
            protected function query(TenantContext $context): BaseQuery { throw new LogicException(); }
            protected function newModel(TenantContext $context): Model { throw new LogicException(); }
            protected function assertPermission(TenantContext $context, string $action): void {}
            protected function listFields(): array { return []; }
            protected function detailFields(): array { return []; }
            protected function insertFields(): array { return []; }
            protected function updateFields(): array { return []; }
            protected function maskedFields(): array { return ['phone' => 'phone']; }
            protected function richTextFields(): array { return ['body']; }
            public function masked(TenantContext $context, array $row): array { return $this->maskRow($context, $row); }
        };
        self::assertSame('138****5678', $service->masked($context, ['phone' => '13812345678'])['phone']);
        $sanitize = new ReflectionMethod(AbstractCrudService::class, 'sanitizeInput');
        self::assertSame('<p>ok</p>', $sanitize->invoke($service, ['body' => '<p>ok</p><script>bad()</script>'])['body']);
        self::assertSame(['x' => ['before' => 1, 'after' => 2]], (new FieldDiffCalculator())->calculate(['x' => 1], ['x' => 2]));
        $helper = new BatchProjectionHelper();
        $rows = [['owner' => 2, 'image' => 'image'], ['owner' => 2, 'image' => 'image']];
        $rows = $helper->members($context, $rows, ['owner' => 'owner_name'], function ($ctx, $ids) use ($context) {
            self::assertSame($context, $ctx); self::assertSame([2], $ids); return [2 => 'Alice'];
        });
        $rows = $helper->publicFiles($rows, ['image' => 'url'], function ($refs) {
            self::assertSame(['image'], $refs); return ['image' => '/image'];
        });
        self::assertSame('Alice', $rows[1]['owner_name']);
        self::assertSame('/image', $rows[1]['url']);
    }
}
