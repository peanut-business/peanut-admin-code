<?php
declare(strict_types=1);

namespace app\modules\official\integration\controller;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\execution\CurrentExecutionContext;
use app\modules\official\integration\application\MachineIdentity;
use app\modules\official\integration\application\MachineIdentityService;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use think\App;
use think\response\Json;

final class MachineIdentityController extends IntegrationAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        AdminAuthorizationQuery $authorization,
        private readonly MachineIdentityService $machines,
    ) {
        parent::__construct($app, $executionContext, $authorization);
    }

    public function index(): Json
    {
        try {
            $items = $this->machines->list($this->operation('official.integration.machine.read', 'machine-read'));
            return $this->response(['items' => array_map($this->identity(...), $items)]);
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function create(): Json
    {
        try {
            $body = $this->body(['name', 'scopes', 'expires_at']);
            if (!is_string($body['name'] ?? null) || !is_array($body['scopes'] ?? null) || !array_is_list($body['scopes'])) {
                throw IntegrationSecurityException::invalid();
            }
            $created = $this->machines->create(
                $this->operation('official.integration.machine.manage', 'machine-manage'),
                $body['name'],
                $body['scopes'],
                $this->expiry($body['expires_at'] ?? null),
            );
            return $this->response(['identity' => $this->identity($created->identity), 'token' => $created->token], 201);
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function rotate(string $identityKey): Json
    {
        try {
            $body = $this->body(['revision']);
            $rotated = $this->machines->rotate(
                $this->operation('official.integration.machine.manage', 'machine-manage'),
                $identityKey,
                $this->positiveInteger($body['revision'] ?? null),
            );
            return $this->response(['identity' => $this->identity($rotated->identity), 'token' => $rotated->token]);
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function revoke(string $identityKey): Json
    {
        try {
            $body = $this->body(['revision']);
            return $this->response($this->identity($this->machines->revoke(
                $this->operation('official.integration.machine.manage', 'machine-manage'),
                $identityKey,
                $this->positiveInteger($body['revision'] ?? null),
            )));
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    /** @return array<string,mixed> */
    private function identity(MachineIdentity $identity): array
    {
        return [
            'identity_key' => $identity->identityKey, 'name' => $identity->name,
            'scopes' => $identity->scopes, 'status' => $identity->status,
            'token_prefix' => $identity->tokenPrefix, 'token_last_four' => $identity->tokenLastFour,
            'expires_at' => $identity->expiresAt, 'last_used_at' => $identity->lastUsedAt,
            'revision' => $identity->revision, 'created_at' => $identity->createdAt,
        ];
    }

    private function response(mixed $data, int $status = 200): Json
    {
        return json(['data' => $data, 'meta' => ['request_id' => $this->executionContext()->requestId()]], $status);
    }
}
