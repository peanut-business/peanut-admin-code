<?php
declare(strict_types=1);

namespace app\modules\official\integration\controller;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\execution\CurrentExecutionContext;
use app\modules\official\integration\application\SessionDevice;
use app\modules\official\integration\application\SessionSecurityService;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use think\App;
use think\response\Json;

final class SessionSecurityController extends IntegrationAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        AdminAuthorizationQuery $authorization,
        private readonly SessionSecurityService $sessions,
    ) {
        parent::__construct($app, $executionContext, $authorization);
    }

    public function index(): Json
    {
        try {
            $items = $this->sessions->list($this->operation('official.integration.session.read', 'session-read'));
            return $this->response(['items' => array_map($this->session(...), $items)]);
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function revoke(string $sessionKey): Json
    {
        try {
            $this->body([]);
            return $this->response($this->session($this->sessions->revoke(
                $this->operation('official.integration.session.revoke', 'session-revoke'),
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
