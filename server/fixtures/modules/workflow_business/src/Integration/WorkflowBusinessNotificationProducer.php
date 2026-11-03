<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowBusinessAccess;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Notification\Contract\NotificationProducer;
use PeanutAdmin\Modules\Notification\Contract\NotificationPublicationIdentity;
use PeanutAdmin\Modules\Workflow\Application\WorkflowQueries;

/** Fixed source-code producer: only committed approvals of this scene, only frozen initiator recipients. */
final readonly class WorkflowBusinessNotificationProducer implements NotificationProducer
{
    public function __construct(private string $scene, private WorkflowBusinessAccess $access, private WorkflowQueries $workflow) { Package::resource($scene); }
    public function key(): string { return Package::resource($this->scene) . '.notification'; }
    public function resourceKey(): string { return Package::resource($this->scene); }
    public function operation(): string { return 'read'; }
    public function taskType(): string { return $this->key() . '.dispatch'; }
    public function handlerKey(): string { return $this->key(); }
    public function authorize(TenantContext $context, NotificationPublicationIdentity $identity): AuthorizedOperationContext
    {
        $read = $this->authorizeRead($context, $identity);
        $fact = $this->workflow->notificationFact($read, $this->key(), $identity->eventKey, $identity->businessKey);
        $this->access->authorize($read, $this->resourceKey(), $fact['operation'], [Package::MODULE_KEY . '.' . $fact['operation'], ...$fact['permission_keys']], $identity->businessKey);
        return $read;
    }
    public function authorizeRead(TenantContext $context, NotificationPublicationIdentity $identity): AuthorizedOperationContext
    {
        if ($identity->producerKey !== $this->key()) { throw \PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException::denied(); }
        // This basis carries an already validated session; the owner immediately rechecks real RBAC and SQL object scope.
        $basis = AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($context, $this->resourceKey(), 'read', [], hash('sha256', $context->requestId . ':' . $context->authorizationRevision)));
        $read = $this->access->authorize($basis, $this->resourceKey(), 'read', [Package::MODULE_KEY . '.read'], $identity->businessKey);
        $fact = $this->workflow->notificationFact($read, $this->key(), $identity->eventKey, $identity->businessKey);
        if ($fact['subject_type'] !== $this->resourceKey() || !in_array($fact['operation'], ['approve', 'reject'], true)) { throw \PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException::denied(); }
        return $read;
    }
    public function publication(AuthorizedOperationContext $context, NotificationPublicationIdentity $identity): array
    {
        $this->authorize($context->tenantContext, $identity);
        $fact = $this->workflow->notificationFact($context, $this->key(), $identity->eventKey, $identity->businessKey);
        return ['template' => ['template_key' => 'workflow.' . $this->scene . '.decision', 'name' => 'Workflow decision', 'revision' => 1,
            'subject_template' => '{{title}}：流程意见', 'body_template' => '{{title}} 的流程意见为 {{decision}}。请在获准业务页面查看。',
            'channels' => ['inbox'], 'variables' => ['title', 'decision']], 'recipients' => $fact['recipients'], 'file_keys' => $fact['file_keys']];
    }
}
