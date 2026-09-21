<?php
declare(strict_types=1);

namespace app\platform\controller;

use app\platform\validate\PlatformAccessValidate;
use PeanutAdmin\Modules\Identity\Platform\PlatformOperatorStatus;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformAccessAdminService;

final class PlatformAccessController extends BasePlatformController
{
    protected function platformAccess(): PlatformAccessAdminService
    {
        return $this->app->make(PlatformAccessAdminService::class);
    }

    public function createOperator()
    {
        return $this->mutate('createOperator', function (array $params, object $context): array {
            return $this->platformAccess()->createOperator(
                $context->operatorId,
                $context->accountId,
                trim((string)$params['email']),
                trim((string)$params['display_name']),
                isset($params['initial_password']) && (string)$params['initial_password'] !== ''
                    ? (string)$params['initial_password']
                    : null,
                $context->requestId
            );
        });
    }

    public function updateOperator()
    {
        return $this->mutate('updateOperator', function (array $params, object $context): array {
            return $this->platformAccess()->updateOperator(
                $context->operatorId,
                $context->accountId,
                (int)$params['operator_id'],
                (int)$params['expected_revision'],
                trim((string)$params['display_name']),
                trim((string)$params['change_reason']),
                $context->requestId
            );
        });
    }

    public function replaceOperatorRoles()
    {
        return $this->mutate('replaceOperatorRoles', function (array $params, object $context): array {
            return $this->platformAccess()->replaceOperatorRoles(
                $context->operatorId,
                $context->accountId,
                (int)$params['operator_id'],
                array_values(array_map(static fn(mixed $id): int => (int)$id, $params['role_ids'])),
                (int)$params['expected_revision'],
                trim((string)$params['change_reason']),
                $context->requestId
            );
        });
    }

    public function activateOperator()
    {
        return $this->transitionOperator(PlatformOperatorStatus::Active, 'activateOperator');
    }

    public function suspendOperator()
    {
        return $this->transitionOperator(PlatformOperatorStatus::Suspended, 'suspendOperator');
    }

    public function closeOperator()
    {
        return $this->transitionOperator(PlatformOperatorStatus::Closed, 'closeOperator');
    }

    public function createRole()
    {
        return $this->mutate('createRole', function (array $params, object $context): array {
            return $this->platformAccess()->createRole(
                $context->operatorId,
                $context->accountId,
                trim((string)$params['key']),
                trim((string)$params['name']),
                isset($params['description']) && trim((string)$params['description']) !== ''
                    ? trim((string)$params['description'])
                    : null,
                $context->requestId
            );
        });
    }

    public function updateRole()
    {
        return $this->mutate('updateRole', function (array $params, object $context): array {
            return $this->platformAccess()->updateRole(
                $context->operatorId,
                $context->accountId,
                (int)$params['role_id'],
                (int)$params['expected_revision'],
                trim((string)$params['name']),
                isset($params['description']) && trim((string)$params['description']) !== ''
                    ? trim((string)$params['description'])
                    : null,
                trim((string)$params['change_reason']),
                $context->requestId
            );
        });
    }

    public function archiveRole()
    {
        return $this->mutate('archiveRole', function (array $params, object $context): array {
            return $this->platformAccess()->archiveRole(
                $context->operatorId,
                $context->accountId,
                (int)$params['role_id'],
                (int)$params['expected_revision'],
                trim((string)$params['change_reason']),
                $context->requestId
            );
        });
    }

    public function replaceRolePermissions()
    {
        return $this->mutate('replaceRolePermissions', function (array $params, object $context): array {
            return $this->platformAccess()->replaceRolePermissions(
                $context->operatorId,
                $context->accountId,
                (int)$params['role_id'],
                array_values(array_map(static fn(mixed $key): string => trim((string)$key), $params['permission_keys'])),
                (int)$params['expected_revision'],
                trim((string)$params['change_reason']),
                $context->requestId
            );
        });
    }

    private function transitionOperator(PlatformOperatorStatus $status, string $scene)
    {
        return $this->mutate($scene, function (array $params, object $context) use ($status): array {
            return $this->platformAccess()->transitionOperator(
                $context->operatorId,
                $context->accountId,
                (int)$params['operator_id'],
                (int)$params['expected_revision'],
                $status,
                trim((string)$params['change_reason']),
                $context->requestId
            );
        });
    }

    private function mutate(string $scene, callable $operation)
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }

        $params = $this->request->post();
        $this->validate($params, PlatformAccessValidate::class . '.' . $scene);
        return $this->data($operation($params, $this->platformContext->core));
    }
}
