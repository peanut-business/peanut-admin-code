<?php
declare(strict_types=1);

namespace app\api\services;

use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use app\common\services\RichTextResourceService;
use PeanutAdmin\Modules\Settings\Service\TenantApplicationSettingService;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use app\common\enum\decoration\DecorationEnum;
use app\common\services\decoration\DecorationReadService;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use app\common\services\tenant\TenantIdentityQuery;

class IndexApplicationService
{
    public function __construct(
        private readonly TenantIdentityQuery $tenantIdentities,
        private readonly TenantApplicationSettingService $applicationSettings,
        private readonly PublicArticleQueries $articles,
        private readonly RichTextResourceService $richText,
        private readonly DecorationReadService $decoration,
        private readonly WebsiteConfigService $website,
        private readonly string $projectVersion,
        private readonly array $demoLoginConfig,
    ) {
    }

    /** 全局配置（uniapp / H5 用） */
    public function getConfigData(
        TenantContext|TenantSystemContext $context,
        string $domain,
        string $host,
        ?int $entryTenantId,
    ): array
    {
        $website = $this->website->get($context);
        $login = $this->applicationSettings->login($context);
        $statistics = $this->applicationSettings->statistics($context);
        $webPageSetting = $this->applicationSettings->webPage($context);
        $webPage   = [
            'status'      => (int)$webPageSetting['status'],
            'page_status' => (int)$webPageSetting['page_status'],
            'page_url'    => (string)$webPageSetting['page_url'],
            'url'         => rtrim($domain, '/') . '/mobile',
        ];

        return [
            'domain'   => $domain,
            'website'  => $website,
            'tenantName' => $this->entryTenantName($entryTenantId),
            'demo'     => $this->demoLogin($host),
            'login'    => [
                'login_way' => $login['login_way'],
                'coerce_mobile' => (int)$login['coerce_mobile'],
                'login_agreement' => (int)$login['login_agreement'],
                'third_auth' => (int)$login['third_auth'],
                'wechat_auth' => (int)$login['wechat_auth'],
            ],
            'copyright' => $this->copyright($context),
            'site_statistics' => [
                'clarity_code' => (string)$statistics['clarity_code'],
            ],
            'web_page' => $webPage,
            'tabbar'   => $this->decoration->tabbar(
                $context,
                true,
                'decoration.config'
            ),
            'theme'    => $this->decoration->pageByType(
                $context,
                DecorationEnum::SYSTEM_THEME,
                'decoration.config'
            ),
            'version'  => $this->projectVersion,
        ];
    }

    /** @return array{enabled:bool,email:string,password:string} */
    private function demoLogin(string $host): array
    {
        if (!($this->demoLoginConfig['enabled'] ?? false)) {
            return ['enabled' => false, 'email' => '', 'password' => ''];
        }
        try {
            $host = TenantEntryBindingResolver::normalizeHost($host);
            $tenantAHost = TenantEntryBindingResolver::normalizeHost(
                (string)($this->demoLoginConfig['tenant_a_host'] ?? '')
            );
            $tenantBHost = TenantEntryBindingResolver::normalizeHost(
                (string)($this->demoLoginConfig['tenant_b_host'] ?? '')
            );
            $sharedHosts = array_filter(array_map(
                static fn(string $value): string => TenantEntryBindingResolver::normalizeHost($value),
                (array)($this->demoLoginConfig['shared_hosts'] ?? [])
            ));
        } catch (\Throwable) {
            return ['enabled' => false, 'email' => '', 'password' => ''];
        }
        if (hash_equals($tenantBHost, $host)) {
            $email = (string)($this->demoLoginConfig['tenant_b_email'] ?? '');
        } elseif (hash_equals($tenantAHost, $host) || in_array($host, $sharedHosts, true)) {
            $email = (string)($this->demoLoginConfig['tenant_a_email'] ?? '');
        } else {
            return ['enabled' => false, 'email' => '', 'password' => ''];
        }
        return [
            'enabled' => true,
            'email' => trim($email),
            'password' => (string)($this->demoLoginConfig['password'] ?? ''),
        ];
    }

    private function entryTenantName(?int $tenantId): string
    {
        try {
            if ($tenantId === null) {
                return '';
            }
            return $this->tenantIdentities->activeName($tenantId);
        } catch (\Throwable) {
            return '';
        }
    }

    private function copyright(TenantContext|TenantSystemContext $context): array
    {
        $document = $this->applicationSettings->copyright($context);
        return is_array($document['config'] ?? null) ? $document['config'] : [];
    }

    /** 政策协议（type: privacy | service） */
    public function getPolicyByType(
        TenantContext|TenantSystemContext $context,
        string $type,
    ): array
    {
        $setting = $this->applicationSettings->agreement($context);
        $prefix = $type === 'privacy' ? 'privacy' : 'service';
        return [
            'title'   => (string)$setting[$prefix . '_title'],
            'content' => $this->richText->forRead(
                (string)$setting[$prefix . '_content']
            ),
        ];
    }

    /** 首页数据 */
    public function getIndexData(TenantContext|TenantSystemContext $context): array
    {
        return [
            'article' => $this->articles->homeArticles(20),
            'decorate' => $this->decoration->pageByType(
                $context,
                DecorationEnum::MOBILE_HOME,
                'article.index'
            ),
        ];
    }
}
