<?php

declare(strict_types=1);

namespace tests\Unit\InputValidatorPolicy;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\validate\InputValidator;
use PHPUnit\Framework\TestCase;
use think\App;
use think\exception\ValidateException;
use think\Validate;

final class PolicyFixtureValidate extends Validate
{
    protected $rule = [
        'first' => 'require',
        'second' => 'require',
        'name' => 'string',
        'nullable' => 'max:10',
        'items' => 'array',
        'items.*.safe' => 'string',
    ];

    protected $scene = [
        'first' => ['first'],
        'second' => ['second'],
        'payload' => ['name', 'nullable', 'items', 'items.*.safe'],
    ];
}

final class InputValidatorPolicyTest extends TestCase
{
    public function testValidatorsAreFreshPerSceneAndResolvedFromTheCurrentApp(): void
    {
        [$appA, $validatorA] = $this->validator('A', $createdA);
        [$appB, $validatorB] = $this->validator('B', $createdB);

        $validatorA->validate(['first' => 'yes'], PolicyFixtureValidate::class . '.first');
        $validatorA->validate(['second' => 'yes'], PolicyFixtureValidate::class . '.second');
        $validatorB->validate(['first' => 'yes'], PolicyFixtureValidate::class . '.first');

        self::assertSame(2, $createdA);
        self::assertSame(1, $createdB);
        self::assertNotSame($appA, $appB);
    }

    public function testExplicitPolicySeparatesRawValidatedAndWritableShapes(): void
    {
        [, $validator] = $this->validator('policy', $created);
        $result = $validator->validateInput(
            [
                'name' => '',
                'nullable' => null,
                'items' => [
                    ['safe' => 'one'],
                    ['safe' => ''],
                ],
            ],
            PolicyFixtureValidate::class . '.payload',
            ['name', 'nullable', 'items' => ['*' => ['safe']]],
            ['name', 'items' => ['*' => ['safe']]],
        );

        self::assertSame('', $result->raw()['name']);
        self::assertArrayHasKey('nullable', $result->validated());
        self::assertNull($result->validated()['nullable']);
        self::assertSame([['safe' => 'one'], ['safe' => '']], $result->writable()['items']);
        self::assertArrayNotHasKey('nullable', $result->writable());
        self::assertSame(1, $created);
    }

    public function testUnknownTopLevelAndNestedFieldsAreRejectedWithoutChangingLegacyInput(): void
    {
        [, $validator] = $this->validator('unknown', $created);
        $policy = ['name', 'nullable', 'items' => ['*' => ['safe']]];

        try {
            $validator->validateInput(
                ['name' => '', 'nullable' => null, 'items' => [], 'tenant_id' => 999],
                PolicyFixtureValidate::class . '.payload',
                $policy,
            );
            self::fail('unknown top-level field was accepted');
        } catch (ValidateException $exception) {
            self::assertStringContainsString('tenant_id', $exception->getMessage());
        }

        try {
            $validator->validateInput(
                ['name' => '', 'nullable' => null, 'items' => [['safe' => 'x', 'balance' => 100]]],
                PolicyFixtureValidate::class . '.payload',
                $policy,
            );
            self::fail('unknown nested field was accepted');
        } catch (ValidateException $exception) {
            self::assertStringContainsString('items.0.balance', $exception->getMessage());
        }

        $legacy = $validator->validate(
            ['first' => 'yes', 'legacy_extra' => null],
            PolicyFixtureValidate::class . '.first',
        );
        self::assertSame(['first' => 'yes', 'legacy_extra' => null], $legacy->all());
        self::assertSame([], $legacy->writable());
    }

    public function testWritablePolicyCannotBroadenAnAcceptedNestedShape(): void
    {
        [, $validator] = $this->validator('writable-subset', $created);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('broader than accepted input: items');
        $validator->validateInput(
            ['items' => [['safe' => 'x']]],
            ['items' => 'array'],
            ['items' => ['*' => ['safe']]],
            ['items'],
        );
    }

    /** @return array{App,InputValidator} */
    private function validator(string $marker, ?int &$created): array
    {
        $created = 0;
        $app = new App(sys_get_temp_dir() . '/peanut-input-policy-' . $marker . '-' . bin2hex(random_bytes(4)));
        $current = new CurrentExecutionContext(new ExecutionContextStore());
        $app->bind(PolicyFixtureValidate::class, static function () use (&$created): PolicyFixtureValidate {
            $created++;
            return new PolicyFixtureValidate();
        });
        return [$app, new InputValidator($app, $current)];
    }
}
