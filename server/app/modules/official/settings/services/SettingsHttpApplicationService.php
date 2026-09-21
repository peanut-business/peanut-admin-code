<?php
declare(strict_types=1);

namespace app\modules\official\settings\services;

use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\modules\official\settings\Application\EffectiveSetting;
use app\modules\official\settings\Application\SettingAdminService;
use app\modules\official\settings\Application\SettingException;
use app\modules\official\settings\Application\SettingResolver;
use app\modules\official\settings\Definition\SettingDefinition;
use app\modules\official\settings\Definition\SettingDefinitionRegistry;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use think\facade\Db;

final readonly class SettingsHttpApplicationService
{
    public function __construct(
        private SettingDefinitionRegistry $definitions,
        private SettingAdminService $admin,
        private SettingResolver $resolver,
        private IdempotentCommandExecutor $idempotency,
        private ModuleRuntimeRepository $modules,
    ) {}

    /** @return array{items:list<array<string,mixed>>} */
    public function list(TenantContext $context): array
    {
        $items = [];
        $asOf = self::now();
        foreach ($this->definitions->all() as $definition) {
            if ($definition->allows('tenant') && $this->moduleEnabled($context, $definition->moduleKey, $asOf)) {
                $items[] = $this->record($definition, $this->resolver->resolveTenant($definition, $context->tenantId, $asOf));
            }
        }
        return ['items' => $items];
    }

    /** @return array<string,mixed> */
    public function replace(
        TenantContext $context,
        string $moduleKey,
        string $settingKey,
        mixed $value,
        ?string $ifMatch,
        ?string $ifNoneMatch,
        string $idempotencyKey,
    ): array {
        $definition = $this->tenantDefinition($context, $moduleKey, $settingKey);
        return $this->command($context, 'settings.replace', $idempotencyKey, [
            'module_key' => $moduleKey,
            'setting_key' => $settingKey,
            'value' => $value,
            'if_match' => $ifMatch,
            'if_none_match' => $ifNoneMatch,
        ], function () use ($context, $definition, $value, $ifMatch, $ifNoneMatch): array {
            $now = self::now();
            return $this->record($definition, $this->admin->replaceTenant(
                $definition,
                $context->tenantId,
                $context->memberId,
                $value,
                $now,
                null,
                $ifMatch,
                $ifNoneMatch,
                $now,
            ));
        });
    }

    /** @return array<string,mixed> */
    public function unset(
        TenantContext $context,
        string $moduleKey,
        string $settingKey,
        ?string $ifMatch,
        string $idempotencyKey,
    ): array {
        $definition = $this->tenantDefinition($context, $moduleKey, $settingKey);
        return $this->command($context, 'settings.unset', $idempotencyKey, [
            'module_key' => $moduleKey,
            'setting_key' => $settingKey,
            'if_match' => $ifMatch,
        ], function () use ($context, $definition, $ifMatch): array {
            $now = self::now();
            return $this->record($definition, $this->admin->unsetTenant(
                $definition,
                $context->tenantId,
                $context->memberId,
                $now,
                $ifMatch,
                $now,
            ));
        });
    }

    private function tenantDefinition(TenantContext $context, string $moduleKey, string $settingKey): SettingDefinition
    {
        $definition = $this->definitions->require($moduleKey, $settingKey);
        if (!$definition->allows('tenant') || !$this->moduleEnabled($context, $definition->moduleKey, self::now())) {
            throw SettingException::notFound();
        }
        return $definition;
    }

    private function moduleEnabled(TenantContext $context, string $moduleKey, DateTimeImmutable $now): bool
    {
        return $this->modules->tenantModule($context->tenantId, $moduleKey)?->isEffective($now) === true;
    }

    /** @param array<string,mixed> $request @param callable():array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function command(TenantContext $context, string $operationKey, string $key, array $request, callable $operation): array
    {
        return Db::transaction(function () use ($context, $operationKey, $key, $request, $operation): array {
            $lease = $this->idempotency->begin(IdempotencyCommand::tenant(
                $context,
                $operationKey,
                $key,
                hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                new DateTimeImmutable('+24 hours'),
            ));
            if ($lease->isReplayable()) {
                return $lease->responseBody();
            }
            if (!$lease->isExecutionOwner()) {
                throw new SettingException('SETTING_REQUEST_IN_PROGRESS', 409, 'The setting request is still processing.');
            }
            $body = $operation();
            $this->idempotency->complete($lease, new IdempotencyReceipt(200, $body));
            return $body;
        });
    }

    /** @return array<string,mixed> */
    private function record(SettingDefinition $definition, EffectiveSetting $setting): array
    {
        $record = [
            'module_key' => $definition->moduleKey,
            'setting_key' => $definition->key,
            'name' => $definition->name,
            'description' => $definition->description,
            'schema' => $definition->schema,
            'required' => $definition->required,
            'secret' => $definition->secret,
            'configured' => $setting->configured,
            'source_scope' => $setting->source,
            'effective_at' => $setting->effectiveAt,
            'expires_at' => $setting->expiresAt,
            'revision' => (string)$setting->revision,
            'etag' => $setting->etag,
        ];
        if (!$definition->secret) {
            $record['value'] = $setting->value;
        }
        return $record;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
