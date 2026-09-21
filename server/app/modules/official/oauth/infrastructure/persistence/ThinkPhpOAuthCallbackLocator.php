<?php
declare(strict_types=1);

namespace app\modules\official\oauth\infrastructure\persistence;

use app\modules\official\integration\contracts\ExternalTenantBinding;
use app\modules\official\integration\contracts\ExternalTenantResolutionService;
use app\modules\official\integration\contracts\ExternalProvider;
use app\modules\official\oauth\contracts\OAuthCallbackLocator;
use app\modules\official\oauth\model\OAuthAttempt;
use app\modules\official\oauth\model\OAuthCompletionTicket;

final class ThinkPhpOAuthCallbackLocator implements OAuthCallbackLocator
{
    public function locateState(string $provider, string $stateHash): array
    {
        $scene = match ($provider) {
            ExternalProvider::WECHAT_OFFICIAL_OAUTH => 'oa',
            ExternalProvider::WECHAT_OPEN_PLATFORM => 'open_pc',
            default => null,
        };
        if ($scene === null) {
            return [];
        }

        return $this->bindings(
            OAuthAttempt::callbackCandidates()->alias('o')
                ->field($this->bindingFields())
                ->join('external_channel_binding b', 'b.tenant_id = o.tenant_id')
                ->join('tenant t', 't.id = b.tenant_id')
                ->where('b.provider', $provider)
                ->where('o.state_hash', $stateHash)
                ->where('o.scene', $scene)
                ->whereNull('o.used_at')
                ->where('o.expires_at', '>=', time())
                ->limit(2)->select()->toArray()
        );
    }

    public function locateTicket(string $ticketHash): array
    {
        return $this->bindings(
            OAuthCompletionTicket::callbackCandidates()->alias('o')
                ->field($this->bindingFields())
                ->join('external_channel_binding b', 'b.id = o.binding_id AND b.tenant_id = o.tenant_id')
                ->join('tenant t', 't.id = b.tenant_id')
                ->where('o.token_hash', $ticketHash)
                ->whereNull('o.used_at')
                ->where('o.expires_at', '>=', time())
                ->whereIn('b.provider', [
                    ExternalProvider::WECHAT_MINI_PROGRAM,
                    ExternalProvider::WECHAT_OFFICIAL_OAUTH,
                    ExternalProvider::WECHAT_OPEN_PLATFORM,
                ])
                ->limit(2)->select()->toArray()
        );
    }

    /** @param list<array<string, mixed>> $rows @return list<ExternalTenantBinding> */
    private function bindings(array $rows): array
    {
        return array_map(static function (array $row): ExternalTenantBinding {
            $config = json_decode((string)($row['config_json'] ?? ''), true);
            return new ExternalTenantBinding(
                (int)($row['id'] ?? 0),
                (int)($row['tenant_id'] ?? 0),
                (string)($row['provider'] ?? ''),
                (string)($row['callback_key'] ?? ''),
                (string)($row['identity_hash'] ?? ''),
                (string)($row['identity_hint'] ?? ''),
                is_array($config) ? $config : [],
                (int)($row['status'] ?? 0) === 1,
                (string)($row['tenant_status'] ?? '') === 'active',
            );
        }, $rows);
    }

    private function bindingFields(): string
    {
        return 'b.id,b.tenant_id,b.provider,b.callback_key,b.identity_hash,b.identity_hint,'
            . 'b.config_json,b.status,t.status AS tenant_status';
    }
}
