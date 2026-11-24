import {
  platformOperatorCreate,
  platformOperatorRolesAssign,
  platformOperatorRolesReplace,
  platformOperatorsPage,
  platformOperatorUpdate,
  platformOpsLogs,
  platformOpsOverviewPage,
  platformPermissionBundleCreate,
  platformPermissionCatalogRead,
  platformRoleArchive,
  platformRoleCreate,
  platformRolesPage,
  platformRoleUpdate,
  platformTenantCreate,
  platformTenantsPage,
} from './platform-shell-definitions';

export interface PlatformApiAccess {
  method: string;
  path: string;
}

export function allowsPlatformApi(
  access: readonly PlatformApiAccess[],
  method: string,
  path: string
): boolean {
  return access.some(
    (item) => item.method === method.toUpperCase() && item.path === path
  );
}

// Existing action names remain presentation labels; only the server's actual API
// grant projection decides access, including instance-defined permission bundles.
const actionEndpoints: Record<string, readonly [string, string]> = {
  'platform.tenant.read': platformTenantsPage.endpoints[0],
  [platformTenantCreate.key]: platformTenantCreate.endpoints[0],
  'platform.tenant.update': [
    'POST',
    '/platformapi/tenant-entry-bindings/enable',
  ],
  'platform.tenant.provision-owner': [
    'POST',
    '/platformapi/tenants/invitations',
  ],
  'platform.tenant.license.manage': ['PUT', '/platformapi/tenants/license'],
  'platform.operator.read': platformOperatorsPage.endpoints[0],
  [platformOperatorCreate.key]: platformOperatorCreate.endpoints[0],
  [platformOperatorUpdate.key]: platformOperatorUpdate.endpoints[0],
  [platformOperatorRolesAssign.key]: platformOperatorRolesReplace,
  'platform.role.read': platformRolesPage.endpoints[0],
  [platformRoleCreate.key]: platformRoleCreate.endpoints[0],
  [platformRoleUpdate.key]: platformRoleUpdate.endpoints[0],
  [platformRoleArchive.key]: platformRoleArchive.endpoints[0],
  'platform.role.permission.assign': [
    'POST',
    '/platformapi/roles/permissions/replace',
  ],
  'platform.permission.read': platformPermissionCatalogRead,
  'platform.permission.bundle.manage': platformPermissionBundleCreate.endpoints[0],
  'platform.permission.api.manage': [
    'PUT',
    '/platformapi/permission/apis/bindings',
  ],
  'platform.plan.read': ['GET', '/platformapi/plans'],
  'platform.plan.manage': ['PUT', '/platformapi/plans'],
  'platform.module.read': ['GET', '/platformapi/developer-center/catalog'],
  'platform.ops.read': platformOpsOverviewPage.endpoints[0],
  'platform.ops.backup.manage': ['POST', '/platformapi/v1/ops/tasks/backup'],
  'platform.ops.restore.manage': ['POST', '/platformapi/v1/ops/tasks/restore'],
  'platform.ops.maintenance.manage': ['PUT', '/platformapi/v1/ops/maintenance'],
  [platformOpsLogs.key]: platformOpsLogs.endpoints[0],
  'platform.ops.upgrade.manage': ['POST', '/platformapi/v1/ops/tasks/upgrade'],
  'platform.ops.module.manage': ['POST', '/platformapi/v1/ops/tasks/module'],
};

export function allowsPlatformAction(
  access: readonly PlatformApiAccess[],
  action: string
): boolean {
  const endpoint = actionEndpoints[action];
  return !!endpoint && allowsPlatformApi(access, endpoint[0], endpoint[1]);
}
