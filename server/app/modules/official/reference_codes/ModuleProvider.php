<?php
declare(strict_types=1);

namespace app\modules\official\reference_codes;

use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\modules\official\reference_codes\Application\DictionaryService;
use app\modules\official\reference_codes\Contract\SystemDictionaryProvider;
use app\modules\official\reference_codes\Contract\TenantDictionaryCommandProvider;
use app\modules\official\reference_codes\Contract\TenantDictionaryQueryProvider;
use app\modules\official\reference_codes\contracts\DictionaryQuery;
use app\modules\official\reference_codes\contracts\SystemReferenceCodeQuery;
use app\modules\official\reference_codes\contracts\TenantDictionaryCommands;
use app\modules\official\reference_codes\infrastructure\ThinkPhpSystemDictionaryProvider;
use app\modules\official\reference_codes\infrastructure\ThinkPhpTenantDictionaryProvider;
use app\modules\official\reference_codes\services\DictionaryRuntime;
use app\modules\official\reference_codes\services\ReferenceCodesHttpApplicationService;
use app\modules\official\reference_codes\Versioned\Application\ReferenceCodeAdminService;
use app\modules\official\reference_codes\Versioned\Application\ReferenceCodeQuery;
use app\modules\official\reference_codes\Versioned\Definition\DeployedReferenceCodeSetRegistry;
use app\modules\official\reference_codes\Versioned\Definition\ReferenceCodeSetRegistry;
use app\modules\official\reference_codes\Versioned\Persistence\ReferenceCodeStore;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use think\App;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.reference-codes';
    }

    public function bindings(): array
    {
        return [
            TenantDictionaryQueryProvider::class => ThinkPhpTenantDictionaryProvider::class,
            TenantDictionaryCommandProvider::class => ThinkPhpTenantDictionaryProvider::class,
            SystemDictionaryProvider::class => ThinkPhpSystemDictionaryProvider::class,
            DictionaryService::class => fn(App $app): DictionaryService => new DictionaryService(
                $app->make(TenantDictionaryQueryProvider::class),
                $app->make(TenantDictionaryCommandProvider::class),
                $app->make(SystemDictionaryProvider::class),
            ),
            DictionaryRuntime::class => fn(App $app): DictionaryRuntime => new DictionaryRuntime(
                $app->make(DictionaryService::class),
                $app->make(SystemDictionaryProvider::class),
            ),
            DictionaryQuery::class => DictionaryRuntime::class,
            TenantDictionaryCommands::class => DictionaryRuntime::class,
            SystemReferenceCodeQuery::class => DictionaryRuntime::class,
            ReferenceCodeSetRegistry::class => fn(App $app): ReferenceCodeSetRegistry =>
                $app->make(DeployedReferenceCodeSetRegistry::class)->build(),
            ReferenceCodeQuery::class => fn(App $app): ReferenceCodeQuery => new ReferenceCodeQuery($app->make(ReferenceCodeStore::class)),
            ReferenceCodeAdminService::class => fn(App $app): ReferenceCodeAdminService => new ReferenceCodeAdminService($app->make(ReferenceCodeStore::class)),
            ReferenceCodesHttpApplicationService::class => fn(App $app): ReferenceCodesHttpApplicationService => new ReferenceCodesHttpApplicationService(
                $app->make(ReferenceCodeSetRegistry::class),
                $app->make(ReferenceCodeQuery::class),
                $app->make(ReferenceCodeAdminService::class),
                $app->make(IdempotentCommandExecutor::class),
                $app->make(ModuleRuntimeRepository::class),
            ),
        ];
    }
}
