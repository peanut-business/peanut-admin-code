<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Member;

use PeanutAdmin\Modules\Member\Service\MemberAdministrationService;
use PeanutAdmin\Modules\Member\Service\MemberBalanceContractService;
use PeanutAdmin\Modules\Member\Service\MemberIdentityContractService;
use PeanutAdmin\Modules\Member\Service\MemberQueryService;
use PeanutAdmin\Modules\Member\Service\MemberProfileContractService;
use PeanutAdmin\Modules\Member\Service\MemberSessionService;
use PeanutAdmin\Modules\Member\Service\MemberTagContractService;
use PeanutAdmin\Modules\Member\Contract\MemberBalanceCommands;
use PeanutAdmin\Modules\Member\Contract\MemberAdministration;
use PeanutAdmin\Modules\Member\Contract\MemberIdentityCommands;
use PeanutAdmin\Modules\Member\Contract\MemberProfileCommands;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use PeanutAdmin\Modules\Member\Contract\MemberTagCommands;
use PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup;
use PeanutAdmin\Modules\Member\Contract\MemberSessionStore;
use PeanutAdmin\Modules\Member\Contract\MemberSessions;
use PeanutAdmin\Modules\Member\Infrastructure\Persistence\ThinkPhpMemberSessionStore;
use PeanutAdmin\Modules\Member\Infrastructure\Persistence\ThinkPhpMemberSubjectLookup;
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
