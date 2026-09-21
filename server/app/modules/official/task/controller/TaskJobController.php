<?php
declare(strict_types=1);

namespace app\modules\official\task\controller;

use app\adminapi\controller\BaseAdminController;
use app\common\dto\authorization\AdminPrincipal;
use app\common\execution\CurrentExecutionContext;
use app\common\http\ApiProblem;
use app\common\services\authorization\AdminAuthorizationService;
use app\modules\official\task\contracts\JobRecord;
use app\modules\official\task\contracts\TaskJobRuntime;
use app\modules\official\task\contracts\TaskJobService;
use app\modules\official\task\job\Application\TaskJobException;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\App;
use think\response\Json;

final class TaskJobController extends BaseAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        private readonly TaskJobRuntime $tasks,
        private readonly AdminAuthorizationService $authorization,
    ) {
        parent::__construct($app, $executionContext);
    }

    public function index(): Json
    {
        try {
            $status = trim((string)$this->request->get('status', 'queued'));
            $page = $this->positiveInteger($this->request->get('page', 1));
            $pageSize = $this->positiveInteger($this->request->get('page_size', 20));
            $result = $this->tasks->jobs()->list(
                $this->context('official.task.jobs.read', 'read'),
                $status,
                $page,
                $pageSize,
            );
            return json([
                'data' => ['items' => array_map(
                    static fn(JobRecord $job): array => $job->toPublicArray(),
                    $result['items'],
                )],
                'meta' => [
                    'request_id' => $this->executionContext()->requestId(),
                    'page' => $result['page'],
                    'page_size' => $result['page_size'],
                    'total' => $result['total'],
                ],
            ]);
        } catch (TaskJobException $exception) {
            throw $this->problem($exception);
        }
    }

    public function cancel(string $jobKey): Json
    {
        return $this->mutate($jobKey, 'cancel');
    }

    public function retry(string $jobKey): Json
    {
        return $this->mutate($jobKey, 'retry');
    }

    private function mutate(string $jobKey, string $action): Json
    {
        try {
            $revision = $this->positiveInteger($this->request->post('revision'));
            $service = $this->tasks->jobs();
            $context = $this->context('official.task.jobs.manage', 'manage');
            $job = $action === 'cancel'
                ? $service->cancel($context, $jobKey, $revision)
                : $service->retry($context, $jobKey, $revision);
            return json([
                'data' => $job->toPublicArray(),
                'meta' => ['request_id' => $this->executionContext()->requestId()],
            ]);
        } catch (TaskJobException $exception) {
            throw $this->problem($exception);
        }
    }

    private function context(string $permission, string $operation): AuthorizedOperationContext
    {
        $tenant = $this->tenantAdminContext();
        $principal = AdminPrincipal::fromArray($this->executionContext()->tenantAdminPrincipal());
        if (!$this->authorization->decide($tenant, $principal, $permission)->allowed) {
            throw new ApiProblem('TASK_PERMISSION_DENIED', 403, 'Task job access was denied.');
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            TaskJobService::RESOURCE_KEY,
            $operation,
            [],
            hash('sha256', $tenant->requestId . '|' . $permission . '|' . $operation),
        ));
    }

    private function positiveInteger(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            || (int)$value < 1
        ) {
            throw TaskJobException::invalid();
        }
        return (int)$value;
    }

    private function problem(TaskJobException $exception): ApiProblem
    {
        return new ApiProblem($exception->problemCode, $exception->status, 'Task job request was rejected.');
    }
}
