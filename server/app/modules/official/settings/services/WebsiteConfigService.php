<?php
declare(strict_types=1);

namespace app\modules\official\settings\services;

use app\modules\official\settings\infrastructure\BrandDefaults;
use app\modules\official\settings\infrastructure\TenantSettingWebsiteStore;
use app\modules\official\file\contracts\FileReferences;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use app\modules\official\settings\services\TenantSettingService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use app\modules\official\settings\Application\WebsiteConfigService as CoreWebsiteConfigService;

/** Tenant-aware application bridge to the framework-neutral core service. */
final readonly class WebsiteConfigService
{
    public function __construct(
        private TenantSettingService $settings,
        private FileReferences $files,
    ) {}

    /** @return array<string, string> */
    public function get(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context): array
    {
        return $this->delegate($context)->get();
    }

    /** @param array<string, mixed> $params */
    public function save(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        array $params,
    ): void {
        $this->delegate($context)->save($params);
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return CoreWebsiteConfigService::fields();
    }

    private function delegate(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
    ): CoreWebsiteConfigService {
        return new CoreWebsiteConfigService(
            new TenantSettingWebsiteStore($context, $this->settings),
            fn(string $value): string => $this->files->getFileUrl($value),
            fn(string $value): string => $this->files->setTenantFileUrl($context, $value),
            BrandDefaults::website(),
        );
    }
}
