<?php
declare(strict_types=1);

namespace app\modules\official\member;

use app\modules\official\member\services\MemberAdministrationService;
use app\modules\official\member\services\MemberBalanceContractService;
use app\modules\official\member\services\MemberIdentityContractService;
use app\modules\official\member\services\MemberQueryService;
use app\modules\official\member\services\MemberProfileContractService;
use app\modules\official\member\services\MemberSessionService;
use app\modules\official\member\services\MemberTagContractService;
use app\modules\official\member\contracts\MemberBalanceCommands;
use app\modules\official\member\contracts\MemberAdministration;
use app\modules\official\member\contracts\MemberIdentityCommands;
use app\modules\official\member\contracts\MemberProfileCommands;
use app\modules\official\member\contracts\MemberQueries;
use app\modules\official\member\contracts\MemberTagCommands;
use app\modules\official\member\contracts\MemberSubjectLookup;
use app\modules\official\member\contracts\MemberSessionStore;
use app\modules\official\member\contracts\MemberSessions;
use app\modules\official\member\infrastructure\persistence\ThinkPhpMemberSessionStore;
use app\modules\official\member\infrastructure\persistence\ThinkPhpMemberSubjectLookup;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.member';
    }

    public function bindings(): array
    {
        return [
            MemberQueries::class => MemberQueryService::class,
            MemberSubjectLookup::class => ThinkPhpMemberSubjectLookup::class,
            MemberSessionStore::class => ThinkPhpMemberSessionStore::class,
            MemberSessions::class => MemberSessionService::class,
            MemberIdentityCommands::class => MemberIdentityContractService::class,
            MemberProfileCommands::class => MemberProfileContractService::class,
            MemberTagCommands::class => MemberTagContractService::class,
            MemberBalanceCommands::class => MemberBalanceContractService::class,
            MemberAdministration::class => MemberAdministrationService::class,
        ];
    }
}
