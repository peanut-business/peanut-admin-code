<?php

declare(strict_types=1);

namespace tests\Unit;

use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class TaskJobJsonHydrationTest extends TestCase
{
    private TaskJobStore $store;
    private ReflectionMethod $payload;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(TaskJobStore::class);
        $this->store = $reflection->newInstanceWithoutConstructor();
        $this->payload = $reflection->getMethod('payload');
    }

    public function testRawJsonAndOrmDecodedPayloadKeepTheSameShape(): void
    {
        $expected = [
            'message' => 'hello',
            'options' => [
                'attempts' => 2,
                'enabled' => true,
                'nullable' => null,
                'items' => [1, 'two', false],
                'nested' => ['name' => 'value'],
            ],
        ];
        $json = json_encode(
            $expected,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        self::assertSame($expected, $this->hydrate($json));
        self::assertSame($expected, $this->hydrate($expected));
    }

    public function testInvalidJsonShapesAndValuesKeepTheInternalErrorContract(): void
    {
        foreach ([
            '{',
            'null',
            '["list"]',
            null,
            ['list'],
            ['nested' => new \stdClass()],
        ] as $stored) {
            $this->assertInternalError($stored);
        }
    }

    public function testDecodedPayloadStillEnforcesDepthAndSizeLimits(): void
    {
        $atDepthLimit = ['leaf' => true];
        for ($depth = 0; $depth < 30; $depth++) {
            $atDepthLimit = ['nested' => $atDepthLimit];
        }
        $atDepthLimitJson = json_encode($atDepthLimit, JSON_THROW_ON_ERROR, 512);
        $tooDeep = ['nested' => $atDepthLimit];
        $tooLarge = ['message' => str_repeat('x', 65_536)];

        self::assertSame($atDepthLimit, $this->hydrate($atDepthLimit));
        self::assertSame($atDepthLimit, $this->hydrate($atDepthLimitJson));
        $this->assertInternalError($tooDeep);
        $this->assertInternalError(json_encode($tooDeep, JSON_THROW_ON_ERROR, 512));
        $this->assertInternalError($tooLarge);
        $this->assertInternalError(json_encode($tooLarge, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function hydrate(mixed $stored): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->payload->invoke($this->store, $stored);

        return $payload;
    }

    private function assertInternalError(mixed $stored): void
    {
        try {
            $this->hydrate($stored);
            self::fail('Invalid stored payload was accepted.');
        } catch (TaskJobException $exception) {
            self::assertSame('TASK_INTERNAL_ERROR', $exception->problemCode);
            self::assertSame(500, $exception->status);
        }
    }
}
