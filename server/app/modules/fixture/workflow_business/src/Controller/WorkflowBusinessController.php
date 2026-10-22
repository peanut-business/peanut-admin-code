<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowBusinessService;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\ArtifactRevision\Application\ArtifactRevisionException;
use think\response\Json;

/**
 * @property-read WorkflowBusinessService $business
 * @property-read \PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowCollaborationService $collaboration
 */
final class WorkflowBusinessController extends BaseAdminController
{
    protected string $businessClass = WorkflowBusinessService::class;
    protected string $collaborationClass = \PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowCollaborationService::class;

    public function index(string $scene): Json
    {
        return $this->respond(function () use ($scene): array {
            $page = $this->positive($this->request->get('page', '1'));
            $size = $this->positive($this->request->get('page_size', '20'));
            if ($size > 100) {
                throw AdminAccessException::invalid('PAGE_SIZE_INVALID', 'Page size must not exceed 100.');
            }
            $result = $this->business->lists($this->basis($scene, 'read'), $scene, new PageRequest($page, $size));
            return ['data' => ['items' => $result['items']], 'meta' => ['page' => $page, 'page_size' => $size, 'total' => $result['total']]];
        });
    }

    public function detail(string $scene, string $objectKey): Json
    {
        return $this->respond(fn(): array => ['data' => $this->business->detail($this->basis($scene, 'read'), $scene, $objectKey), 'meta' => []]);
    }

    public function create(string $scene): Json
    {
        return $this->respond(fn(): array => ['data' => $this->business->create(
            $this->basis($scene, 'create'),
            $scene,
            $this->body(['title', 'amount_minor', 'sku', 'quantity', 'business_department_key']),
        ), 'meta' => []], 201);
    }

    public function update(string $scene, string $objectKey): Json
    {
        return $this->respond(function () use ($scene, $objectKey): array {
            $body = $this->body(['revision', 'title', 'amount_minor', 'sku', 'quantity']);
            $revision = $this->positive($body['revision'] ?? null);
            unset($body['revision']);
            return ['data' => $this->business->save($this->basis($scene, 'update'), $scene, $objectKey, $revision, $body), 'meta' => []];
        });
    }

    public function confirm(string $scene, string $objectKey): Json
    {
        return $this->respond(function () use ($scene, $objectKey): array {
            $body = $this->body(['revision']);
            return ['data' => $this->business->confirm(
                $this->basis($scene, 'confirm'),
                $scene,
                $objectKey,
                $this->positive($body['revision'] ?? null),
                (string) $this->request->header('Idempotency-Key', ''),
            ), 'meta' => []];
        });
    }

    public function saveForm(string $scene, string $objectKey): Json
    {
        return $this->respond(function () use ($scene, $objectKey): array {
            $body = $this->body(['revision', 'note', 'reference', 'result']);
            $revision = $this->positive($body['revision'] ?? null);
            unset($body['revision']);
            return ['data' => $this->business->saveCompletionForm($this->basis($scene, 'complete'), $scene, $objectKey, $revision, $body), 'meta' => []];
        });
    }

    public function collaboration(string $scene, string $objectKey): Json
    {
        return $this->respond(fn(): array => ['data' => $this->collaboration->collaboration($this->basis($scene, 'read'), $scene, $objectKey,
            (string) $this->request->get('instance_key', ''), (string) $this->request->get('work_item_key', '')), 'meta' => []]);
    }

    public function appendCollaboration(string $scene, string $objectKey): Json
    {
        return $this->respond(fn(): array => ['data' => $this->collaboration->appendCollaboration($this->basis($scene, 'collaborate'), $scene, $objectKey,
            $this->body(['instance_key', 'work_item_key', 'instance_revision', 'node_revision', 'task_revision', 'kind', 'note', 'reference']),
            (string) $this->request->header('Idempotency-Key', '')), 'meta' => []], 201);
    }

    private function basis(string $scene, string $operation): AuthorizedOperationContext
    {
        $tenant = $this->tenantAdminContext();
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            Package::resource($scene),
            $operation,
            [],
            hash('sha256', $tenant->requestId . ':' . $tenant->authorizationRevision . ':' . $operation),
        ));
    }

    private function body(array $allowed): array
    {
        $body = $this->request->post();
        if (!is_array($body) || array_is_list($body) || array_diff(array_keys($body), $allowed) !== []) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_INPUT_INVALID', 'Unknown business fields are rejected.');
        }
        return $body;
    }

    private function positive(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && strlen($value) <= 18)) || (int) $value < 1) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_REVISION_INVALID', 'A positive bounded revision is required.');
        }
        return (int) $value;
    }

    private function respond(callable $operation, int $status = 200): Json
    {
        try {
            $result = $operation();
            if (($result['meta'] ?? null) === []) {
                $result['meta'] = (object) [];
            }
            return json($result, $status);
        } catch (AdminAccessException|ArtifactRevisionException|\PeanutAdmin\Modules\Workflow\Application\WorkflowException $exception) {
            throw new \app\common\http\ApiProblem($exception->errorCode, $exception->httpStatus, $exception->getMessage());
        } catch (DataAuthorizationException $exception) {
            throw new \app\common\http\ApiProblem($exception->errorCode, 403, 'Current business data scope is required.');
        } catch (\InvalidArgumentException) {
            throw new \app\common\http\ApiProblem('WORKFLOW_BUSINESS_INPUT_INVALID', 422, 'The business scene or input is invalid.');
        }
    }
}
