<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity;

use PeanutAdmin\Modules\Identity\access\Infrastructure\ThinkPhpTargetSetConstraintApplier;
use PeanutAdmin\Modules\Identity\access\SourceRead\ThinkPhpReadScopeAuthority;
use PeanutAdmin\Modules\Identity\Audit\Diagnostic\ThinkPhpTenantAuditDiagnosticQuery;
use PeanutAdmin\Modules\Identity\Audit\Diagnostic\ThinkPhpPlatformAuditDiagnosticQuery;
use PeanutAdmin\Modules\Identity\Identity\Query\ThinkPhpPlatformOperatorIdentityQuery;
use PeanutAdmin\Modules\Identity\Contract\PlatformAuditDiagnosticQuery;
use PeanutAdmin\Modules\Identity\Contract\PlatformOperatorIdentityQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuditDiagnosticQuery;
use PeanutAdmin\DataPermission\Constraint\TargetSetConstraintApplier;
use PeanutAdmin\DataPermission\Scope\ReadScopeAuthority;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Audit\AuditWriter;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Modules\Identity\Module\ModuleAvailabilityService;
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
