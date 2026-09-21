<?php
declare(strict_types=1);

namespace app\modules\official\integration\controller;

use app\modules\official\integration\application\IntegrationAdminApplicationService;
use app\modules\official\integration\application\SessionDevice;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use think\response\Json;

final class SessionSecurityController extends IntegrationAdminController
{
    protected function sessions(): IntegrationAdminApplicationService
    {
        return $this->app->make(IntegrationAdminApplicationService::class);
    }

    public function index(): Json
    {
        try {
            $items = $this->sessions()->sessions($this->tenantAdminContext(), $this->tenantAdminActor());
            return $this->response(['items' => array_map($this->session(...), $items)]);
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function revoke(string $sessionKey): Json
    {
        try {
            $this->body([]);
            return $this->response($this->session($this->sessions()->revokeSession(
                $this->tenantAdminContext(),
                $this->tenantAdminActor(),
                $sessionKey,
            )));
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    /** @return array<string,mixed> */
    private function session(SessionDevice $session): array
    {
        return [
            'session_key' => $session->sessionKey, 'client_key' => $session->clientKey,
            'status' => $session->status, 'current' => $session->current,
            'masked_ip' => $session->maskedIp, 'user_agent_fingerprint' => $session->userAgentFingerprint,
            'issued_at' => $session->issuedAt, 'last_seen_at' => $session->lastSeenAt,
            'absolute_expires_at' => $session->absoluteExpiresAt, 'revoked_at' => $session->revokedAt,
        ];
    }

    private function response(mixed $data): Json
    {
        return json(['data' => $data, 'meta' => ['request_id' => $this->executionContext()->requestId()]]);
    }
}
