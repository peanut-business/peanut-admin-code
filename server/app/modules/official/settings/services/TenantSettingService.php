<?php
declare(strict_types=1);

namespace app\modules\official\settings\services;

use app\modules\official\settings\value\TenantSettingsNamespace;
use app\modules\official\settings\contracts\TenantSettingSnapshot;
use app\modules\official\settings\contracts\TenantSettingsCommands;
use app\modules\official\settings\contracts\TenantSettingsProvider;
use app\modules\official\settings\contracts\TenantSettingsQuery;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final readonly class TenantSettingService implements TenantSettingsQuery, TenantSettingsCommands
{
    public function __construct(private TenantSettingsProvider $provider)
    {
    }

    public function get(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $namespace,
        array $default = []
    ): TenantSettingSnapshot {
        $tenantId = $context->tenantId;
        TenantSettingsNamespace::assertValid($namespace);
        $snapshot = $this->provider->find($tenantId, $namespace);
        if ($snapshot === null) {
            return new TenantSettingSnapshot($tenantId, $namespace, $default, 0, 0, 0);
        }
        return new TenantSettingSnapshot($snapshot->tenantId, $snapshot->namespace, array_replace_recursive($default, $snapshot->document), $snapshot->revision, $snapshot->createTime, $snapshot->updateTime);
    }

    public function replace(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $namespace,
        array $document
    ): TenantSettingSnapshot {
        $tenantId = $context->tenantId;
        TenantSettingsNamespace::assertValid($namespace);
        return $this->provider->replace($tenantId, $namespace, $document);
    }
}
