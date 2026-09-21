<?php

declare(strict_types=1);

namespace app\modules\official\identity;

use app\modules\official\identity\access\infrastructure\ThinkPhpTargetSetConstraintApplier;
use app\modules\official\identity\access\source_read\ThinkPhpReadScopeAuthority;
use app\modules\official\identity\audit\diagnostic\ThinkPhpTenantAuditDiagnosticQuery;
use app\modules\official\identity\audit\diagnostic\ThinkPhpPlatformAuditDiagnosticQuery;
use app\modules\official\identity\identity\query\ThinkPhpPlatformOperatorIdentityQuery;
use app\modules\official\identity\contracts\PlatformAuditDiagnosticQuery;
use app\modules\official\identity\contracts\PlatformOperatorIdentityQuery;
use app\modules\official\identity\contracts\TenantAuditDiagnosticQuery;
use PeanutAdmin\DataPermission\Constraint\TargetSetConstraintApplier;
use PeanutAdmin\DataPermission\Scope\ReadScopeAuthority;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Audit\AuditWriter;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Module\ModuleAvailabilityService;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

/** Boot-required IAM owns the persistent implementation of Core's security boundaries. */
final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.identity';
    }

    public function bindings(): array
    {
        return [
            AuditWriter::class => AuditService::class,
            ModuleAvailability::class => ModuleAvailabilityService::class,
            TargetSetConstraintApplier::class => ThinkPhpTargetSetConstraintApplier::class,
            ReadScopeAuthority::class => ThinkPhpReadScopeAuthority::class,
            TenantAuditDiagnosticQuery::class => ThinkPhpTenantAuditDiagnosticQuery::class,
            PlatformAuditDiagnosticQuery::class => ThinkPhpPlatformAuditDiagnosticQuery::class,
            PlatformOperatorIdentityQuery::class => ThinkPhpPlatformOperatorIdentityQuery::class,
        ];
    }
}
