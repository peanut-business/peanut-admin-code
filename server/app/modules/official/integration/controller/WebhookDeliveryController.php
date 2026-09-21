<?php
declare(strict_types=1);

namespace app\modules\official\integration\controller;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\execution\CurrentExecutionContext;
use app\modules\official\integration\application\IntegrationSecurityPage;
use app\modules\official\integration\application\WebhookDeliveryLogService;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use think\App;
use think\response\Json;

final class WebhookDeliveryController extends IntegrationAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        AdminAuthorizationQuery $authorization,
        private readonly WebhookDeliveryLogService $deliveries,
    ) {
        parent::__construct($app, $executionContext, $authorization);
    }

    public function index(): Json
    {
        try {
            return $this->response($this->deliveries->deliveries(
                $this->operation('official.integration.delivery.read', 'delivery-read'),
                $this->positiveInteger($this->request->get('page', 1)),
                $this->positiveInteger($this->request->get('page_size', 20)),
            ));
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    public function attempts(string $deliveryKey): Json
    {
        try {
            return $this->response($this->deliveries->attempts(
                $this->operation('official.integration.delivery.read', 'delivery-read'),
                $deliveryKey,
                $this->positiveInteger($this->request->get('page', 1)),
                $this->positiveInteger($this->request->get('page_size', 20)),
            ));
        } catch (IntegrationSecurityException $exception) {
            throw $this->problem($exception);
        }
    }

    private function response(IntegrationSecurityPage $page): Json
    {
        return json([
            'data' => ['items' => $page->items],
            'meta' => [
                'request_id' => $this->executionContext()->requestId(),
                'page' => $page->page, 'page_size' => $page->pageSize, 'total' => $page->total,
            ],
        ]);
    }
}
