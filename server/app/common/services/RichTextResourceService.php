<?php
declare(strict_types=1);

namespace app\common\services;

use app\common\services\HtmlSanitizerService;
use app\modules\official\file\contracts\FileReferences;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** 富文本内资源地址的相对 URI 存储与绝对 URL 读取转换。 */
final readonly class RichTextResourceService
{
    public function __construct(private FileReferences $files) {}

    public function forStorage(
        string $html,
        AuthenticatedMemberContext|TenantContext|TenantSystemContext|null $context = null
    ): string
    {
        return $this->transform(HtmlSanitizerService::sanitize($html), false, $context);
    }

    public function forRead(string $html): string
    {
        return $this->transform(HtmlSanitizerService::sanitize($html), true);
    }

    private function transform(
        string $html,
        bool $forRead,
        AuthenticatedMemberContext|TenantContext|TenantSystemContext|null $context = null
    ): string
    {
        if ($html === '') {
            return '';
        }

        $convert = function (string $url) use ($forRead, $context): string {
            $url = trim($url);
            if ($url === '' || preg_match('/^(?:mailto:|tel:|#)/i', $url)) {
                return $url;
            }
            if ($forRead) {
                return $this->files->getFileUrl($url);
            }
            return $context === null
                ? trim($url)
                : $this->files->setTenantFileUrl($context, $url);
        };

        $html = preg_replace_callback(
            '~(\b(?:src|href|poster)\s*=\s*)(["\'])(.*?)\2~is',
            static fn(array $match): string => $match[1] . $match[2] . $convert($match[3]) . $match[2],
            $html
        ) ?? $html;
        return $html;
    }
}
