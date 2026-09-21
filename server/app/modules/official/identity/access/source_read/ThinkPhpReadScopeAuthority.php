<?php

declare(strict_types=1);

namespace app\modules\official\identity\access\source_read;

use DateTimeImmutable;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Scope\AuthorizedReadScope;
use PeanutAdmin\DataPermission\Scope\ReadScopeAuthority;
use PeanutAdmin\DataPermission\Scope\SourceReadScope;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use app\modules\official\identity\access\source_read\Model\SourceReadGrantRecord;
use think\db\Raw;

/** Authoritative IAM implementation of Core's server-issued read scope contract. */
final readonly class ThinkPhpReadScopeAuthority implements ReadScopeAuthority
{
    public function __construct(
        private SourceReadCapabilityRegistry $capabilities,
        private TenantAuthorizationEvaluator $permissions,
        private TenantAuthorizationRepository $authorization,
        private ModuleAvailability $modules,
    ) {}

    public function authorize(
        TenantContext $actor,
        string $capability,
        string $action,
        array $requestedSourceTenantIds = [],
    ): AuthorizedReadScope {
        $definition = $this->capabilities->require($capability, $action);
        $this->permissions->assertAllowed($actor, $definition->readPermissionKey);
        $requested = $this->requestedSources($actor, $requestedSourceTenantIds);
        $this->assertTenantAndModule($actor->tenantId, $definition->moduleKey);
        $authorizationRevision = $this->authorization->revision($actor->tenantId, $actor->memberId);
        $sources = [];
        $revisionParts = [
            $definition->key, $definition->action, $definition->moduleKey,
            $definition->readPermissionKey, $authorizationRevision, (string)$actor->authorizationRevision,
        ];

        foreach ($requested as $sourceTenantId) {
            $this->assertTenantAndModule($sourceTenantId, $definition->moduleKey);
            [$source, $grantRevisionParts] = $this->authorizedSource($actor, $definition, $sourceTenantId);
            $sources[] = $source;
            array_push($revisionParts, ...$grantRevisionParts);
        }

        if ($sources === []) {
            throw new DataAuthorizationException('AUTHZ_READ_SOURCE_DENIED', 'No authorized read source remains.');
        }

        return new AuthorizedReadScope(
            $actor,
            $definition->key,
            $definition->action,
            $sources,
            hash('sha256', json_encode($revisionParts, JSON_THROW_ON_ERROR)),
        );
    }

    public function assertCurrent(AuthorizedReadScope $scope): void
    {
        $current = $this->authorize(
            $scope->actor,
            $scope->capability,
            $scope->action,
            $scope->sourceTenantIds(),
        );
        if (!hash_equals($scope->revision, $current->revision)) {
            throw new DataAuthorizationException(
                'AUTHZ_READ_SCOPE_STALE',
                'The authorized read scope has been revised or revoked.',
            );
        }
    }

    /** @param list<int> $requested @return non-empty-list<int> */
    private function requestedSources(TenantContext $actor, array $requested): array
    {
        if (!array_is_list($requested)) {
            throw new DataAuthorizationException('AUTHZ_READ_SOURCE_DENIED', 'The source selection is invalid.');
        }
        if ($requested === []) {
            throw new DataAuthorizationException(
                'AUTHZ_READ_SOURCE_REQUIRED',
                'Cross-Tenant source reads require an explicit source selection.',
            );
        }
        $normalized = [];
        foreach ($requested as $tenantId) {
            if (!is_int($tenantId) || $tenantId < 1 || $tenantId === $actor->tenantId
                || isset($normalized[$tenantId])) {
                throw new DataAuthorizationException('AUTHZ_READ_SOURCE_DENIED', 'The source selection is invalid.');
            }
            $normalized[$tenantId] = true;
        }
        $tenantIds = array_keys($normalized);
        sort($tenantIds, SORT_NUMERIC);

        return $tenantIds;
    }

    private function assertTenantAndModule(int $tenantId, string $moduleKey): void
    {
        if (Tenant::where('id', $tenantId)->where('status', 'active')->value('id') === null) {
            throw new DataAuthorizationException('AUTHZ_READ_TENANT_UNAVAILABLE', 'A source-read tenant is unavailable.');
        }
        $this->modules->assertAvailable(
            TenantScope::fromTrustedContext($tenantId, 'authorized-read-scope'),
            $moduleKey,
            new DateTimeImmutable('now'),
        );
    }

    /** @return array{SourceReadScope,list<string>} */
    private function authorizedSource(
        TenantContext $actor,
        SourceReadCapability $definition,
        int $sourceTenantId,
    ): array {
        $rows = SourceReadGrantRecord::where('source_tenant_id', $sourceTenantId)
            ->where('recipient_tenant_id', $actor->tenantId)
            ->where('capability', $definition->key)
            ->where('action', $definition->action)
            ->where('status', 'active')
            ->where('valid_from', '<=', new Raw('UTC_TIMESTAMP(3)'))
            ->where(function ($query): void {
                $query->whereNull('valid_until')->whereOr('valid_until', '>', new Raw('UTC_TIMESTAMP(3)'));
            })
            ->order('effect')->order('object_key')->order('id')->select()->toArray();
        $allows = [];
        $denied = [];
        $globalDeny = false;
        $revisionParts = [];
        foreach ($rows as $row) {
            $objectId = $row['object_id'] === null ? null : (int)$row['object_id'];
            $fields = is_array($row['fields_json']) ? array_values(array_map('strval', $row['fields_json'])) : [];
            $revisionParts[] = implode(':', [
                (string)$row['id'], (string)$row['effect'], (string)($objectId ?? 0),
                (string)$row['revision'], hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
                (string)($row['valid_until'] ?? ''),
            ]);
            if ($row['effect'] === 'deny') {
                if ($objectId === null) {
                    $globalDeny = true;
                } else {
                    $denied[$objectId] = true;
                }
                continue;
            }
            $allows[] = ['object_id' => $objectId, 'fields' => $fields];
        }
        if ($globalDeny || $allows === []) {
            throw new DataAuthorizationException('AUTHZ_READ_SOURCE_DENIED', 'The requested read source is explicitly denied.');
        }

        $allObjects = false;
        $allowedObjects = [];
        // The scope exposes one projection for the whole source. Intersecting all
        // applicable allows prevents a field granted for one object leaking from another.
        $fields = null;
        foreach ($allows as $allow) {
            if ($allow['object_id'] === null) {
                $allObjects = true;
            } else {
                $allowedObjects[$allow['object_id']] = true;
            }
            $allowFields = array_values(array_intersect($definition->fields, $allow['fields']));
            $fields = $fields === null ? $allowFields : array_values(array_intersect($fields, $allowFields));
        }
        $fields ??= [];
        sort($fields, SORT_STRING);
        if ($fields === []) {
            throw new DataAuthorizationException('AUTHZ_READ_FIELDS_DENIED', 'No common granted output field remains.');
        }
        foreach (array_keys($denied) as $deniedId) {
            unset($allowedObjects[$deniedId]);
        }
        $objectIds = $allObjects ? null : array_map('intval', array_keys($allowedObjects));
        if ($objectIds !== null) {
            sort($objectIds, SORT_NUMERIC);
        }
        $deniedIds = array_map('intval', array_keys($denied));
        sort($deniedIds, SORT_NUMERIC);
        if ($objectIds === []) {
            throw new DataAuthorizationException('AUTHZ_READ_SOURCE_DENIED', 'No authorized object remains in the source.');
        }
        $sourceRevision = hash('sha256', json_encode($revisionParts, JSON_THROW_ON_ERROR));

        return [
            new SourceReadScope($sourceTenantId, $objectIds, $deniedIds, $fields, $sourceRevision),
            [$sourceRevision],
        ];
    }
}
