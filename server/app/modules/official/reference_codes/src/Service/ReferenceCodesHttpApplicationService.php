<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Service;

use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\EffectiveReferenceCode;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeAdminService;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeException;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Application\ReferenceCodeQuery;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetRegistry;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use think\facade\Db;

final readonly class ReferenceCodesHttpApplicationService
{
    public function __construct(
        private ReferenceCodeSetRegistry $definitions,
        private ReferenceCodeQuery $query,
        private ReferenceCodeAdminService $admin,
        private IdempotentCommandExecutor $idempotency,
        private ModuleRuntimeRepository $modules,
    ) {}

    /** @return array{items:list<array<string,mixed>>} */
    public function sets(TenantContext $context): array
    {
        $enabled = new ReferenceCodeSetRegistry();
        $now = new DateTimeImmutable('now');
        foreach ($this->definitions->moduleKeys() as $moduleKey) {
            if ($this->moduleEnabled($context, $moduleKey, $now)) {
                $enabled->registerModule($moduleKey, array_values(array_filter(
                    $this->definitions->all(),
                    static fn($definition): bool => $definition->moduleKey === $moduleKey,
                )));
            }
        }
        return ['items' => $this->query->sets($enabled)];
    }

    /** @return array<string,mixed> */
    public function list(TenantContext $context, string $moduleKey, string $setKey, array $query): array
    {
        $result = $this->query->list(
            $this->definition($context, $moduleKey, $setKey),
            $context,
            self::instant($query['as_of'] ?? null),
            (string)($query['effective_status'] ?? 'all'),
            filter_var($query['include_retired'] ?? false, FILTER_VALIDATE_BOOL),
            (int)($query['page'] ?? 1),
            (int)($query['page_size'] ?? 50),
        );
        $result['items'] = array_map(static fn(EffectiveReferenceCode $entry): array => $entry->toArray(), $result['items']);
        return $result;
    }

    /** @return array<string,mixed> */
    public function get(TenantContext $context, string $moduleKey, string $setKey, string $code, mixed $asOf): array
    {
        return $this->query->get(
            $this->definition($context, $moduleKey, $setKey),
            $context,
            $code,
            self::instant($asOf),
        )->toArray();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(TenantContext $context, string $moduleKey, string $setKey, array $input, string $key, ?string $ifNoneMatch): array
    {
        return $this->command($context, 'reference-codes.create', $key, [$moduleKey, $setKey, $input, $ifNoneMatch], fn(): array =>
            $this->admin->create(
                $this->definition($context, $moduleKey, $setKey), $context,
                (string)($input['code'] ?? ''), (string)($input['label'] ?? ''),
                is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                (string)($input['status'] ?? ''), (int)($input['sort_order'] ?? 0),
                self::requiredInstant($input['effective_at'] ?? null), self::instant($input['expires_at'] ?? null),
                $ifNoneMatch,
            )->toArray());
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function replace(TenantContext $context, string $moduleKey, string $setKey, string $code, array $input, string $key, ?string $ifMatch): array
    {
        return $this->command($context, 'reference-codes.replace', $key, [$moduleKey, $setKey, $code, $input, $ifMatch], fn(): array =>
            $this->admin->replace(
                $this->definition($context, $moduleKey, $setKey), $context, $code,
                (string)($input['label'] ?? ''), is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                (string)($input['status'] ?? ''), (int)($input['sort_order'] ?? 0),
                self::requiredInstant($input['effective_at'] ?? null), self::instant($input['expires_at'] ?? null),
                $ifMatch,
            )->toArray());
    }

    /** @return array<string,mixed> */
    public function retire(TenantContext $context, string $moduleKey, string $setKey, string $code, string $key, ?string $ifMatch): array
    {
        return $this->command($context, 'reference-codes.retire', $key, [$moduleKey, $setKey, $code, $ifMatch], fn(): array =>
            $this->admin->retire($this->definition($context, $moduleKey, $setKey), $context, $code, $ifMatch)->toArray());
    }

    /** @param array<mixed> $request @param callable():array<string,mixed> $operation @return array<string,mixed> */
    private function command(TenantContext $context, string $operationKey, string $key, array $request, callable $operation): array
    {
        return Db::transaction(function () use ($context, $operationKey, $key, $request, $operation): array {
            $lease = $this->idempotency->begin(IdempotencyCommand::tenant(
                $context, $operationKey, $key,
                hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                new DateTimeImmutable('+24 hours'),
            ));
            if ($lease->isReplayable()) return $lease->responseBody();
            if (!$lease->isExecutionOwner()) {
                throw ReferenceCodeException::inProgress();
            }
            $body = $operation();
            $this->idempotency->complete($lease, new IdempotencyReceipt(200, $body));
            return $body;
        });
    }

    private static function requiredInstant(mixed $value): DateTimeImmutable
    {
        return self::instant($value) ?? throw ReferenceCodeException::invalid('REFERENCE_CODE_REQUEST_INVALID', 'A valid instant is required.');
    }

    private function definition(TenantContext $context, string $moduleKey, string $setKey): ReferenceCodeSetDefinition
    {
        if (!$this->moduleEnabled($context, $moduleKey, new DateTimeImmutable('now'))) {
            throw ReferenceCodeException::setNotFound();
        }
        return $this->definitions->require($moduleKey, $setKey);
    }

    private function moduleEnabled(TenantContext $context, string $moduleKey, DateTimeImmutable $now): bool
    {
        return $this->modules->tenantModule($context->tenantId, $moduleKey)?->isEffective($now) === true;
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw ReferenceCodeException::invalid('REFERENCE_CODE_REQUEST_INVALID', 'The instant is invalid.');
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw ReferenceCodeException::invalid('REFERENCE_CODE_REQUEST_INVALID', 'The instant is invalid.');
        }
    }
}
