export const platformTenancyNavigation = {
  id: 'tenant-operations',
  key: 'platform.tenancy',
  parentKey: null,
  type: 'group',
  label: '租户运营',
  endpoints: [],
} as const;

export const platformTenantsPage = {
  key: 'platform.tenant.list',
  parentKey: platformTenancyNavigation.key,
  type: 'page',
  view: 'tenants',
  label: '租户与生命周期',
  endpoints: [['GET', '/platformapi/tenants']],
} as const;

export const platformTenantCreate = {
  key: 'platform.tenant.create',
  parentKey: platformTenantsPage.key,
  type: 'action',
  label: '新建租户',
  endpoints: [['POST', '/platformapi/tenants/provision']],
} as const;

export const platformTenantDetail = {
  key: 'platform.tenant.detail',
  parentKey: platformTenantsPage.key,
  type: 'action',
  label: '详情',
  endpoints: [['GET', '/platformapi/tenants/detail']],
} as const;

export const platformTenantTransitions = {
  activate: {
    key: 'platform.tenant.activate',
    parentKey: platformTenantsPage.key,
    type: 'action',
    label: '启用',
    endpoints: [['POST', '/platformapi/tenants/activate']],
  },
  suspend: {
    key: 'platform.tenant.suspend',
    parentKey: platformTenantsPage.key,
    type: 'action',
    label: '暂停',
    endpoints: [['POST', '/platformapi/tenants/suspend']],
  },
  close: {
    key: 'platform.tenant.close',
    parentKey: platformTenantsPage.key,
    type: 'action',
    label: '关闭',
    endpoints: [['POST', '/platformapi/tenants/close']],
  },
} as const;

export const organizationAccessNavigation = {
  id: 'organization-access',
  key: 'platform.governance',
  parentKey: null,
  type: 'group',
  label: '组织与权限',
  endpoints: [],
} as const;

export const platformOperatorsPage = {
  key: 'platform.operator.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'operators',
  label: '实例平台操作员',
  endpoints: [['GET', '/platformapi/operators']],
} as const;

export const platformOperatorCreate = {
  key: 'platform.operator.create',
  parentKey: platformOperatorsPage.key,
  type: 'action',
  label: '创建操作员',
  endpoints: [['POST', '/platformapi/operators/create']],
} as const;

export const platformRolesPage = {
  key: 'platform.role.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'roles',
  label: '平台角色',
  endpoints: [['GET', '/platformapi/roles']],
} as const;

export const platformRoleCreate = {
  key: 'platform.role.create',
  parentKey: platformRolesPage.key,
  type: 'action',
  label: '创建角色',
  endpoints: [['POST', '/platformapi/roles/create']],
} as const;

export const platformRoleUpdate = {
  key: 'platform.role.update',
  parentKey: platformRolesPage.key,
  type: 'action',
  label: '编辑',
  endpoints: [['POST', '/platformapi/roles/update']],
} as const;

export const platformRoleArchive = {
  key: 'platform.role.archive',
  parentKey: platformRolesPage.key,
  type: 'action',
  label: '归档',
  endpoints: [['POST', '/platformapi/roles/archive']],
} as const;

export const platformOperatorUpdate = {
  key: 'platform.operator.update',
  parentKey: platformOperatorsPage.key,
  type: 'action',
  label: '编辑',
  endpoints: [['POST', '/platformapi/operators/update']],
} as const;

export const platformOperatorRolesReplace = [
  'POST',
  '/platformapi/operators/roles/replace',
] as const;

export const platformOperatorRolesAssign = {
  key: 'platform.operator.role.assign',
  parentKey: platformOperatorsPage.key,
  type: 'action',
  label: '分配角色',
  endpoints: [platformRolesPage.endpoints[0], platformOperatorRolesReplace],
} as const;

export const platformOperatorTransitions = {
  activate: {
    key: 'platform.operator.activate',
    parentKey: platformOperatorsPage.key,
    type: 'action',
    label: '启用',
    endpoints: [['POST', '/platformapi/operators/activate']],
  },
  suspend: {
    key: 'platform.operator.suspend',
    parentKey: platformOperatorsPage.key,
    type: 'action',
    label: '暂停',
    endpoints: [['POST', '/platformapi/operators/suspend']],
  },
  close: {
    key: 'platform.operator.close',
    parentKey: platformOperatorsPage.key,
    type: 'action',
    label: '关闭',
    endpoints: [['POST', '/platformapi/operators/close']],
  },
} as const;

export const platformPermissionCatalogRead = [
  'GET',
  '/platformapi/permissions',
] as const;

export const platformRoleAuthorizationPage = {
  key: 'platform.governance.workbench',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'role-permissions',
  label: '角色授权',
  endpoints: [platformRolesPage.endpoints[0], platformPermissionCatalogRead],
} as const;

export const platformPermissionBundlesPage = {
  key: 'platform.permission.bundle.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'permission-bundles',
  label: '权限包管理',
  endpoints: [['GET', '/platformapi/permission/bundles']],
} as const;

export const platformPermissionBundleCreate = {
  key: 'platform.permission.bundle.create',
  parentKey: platformPermissionBundlesPage.key,
  type: 'action',
  label: '创建权限包',
  endpoints: [['POST', '/platformapi/permission/bundles']],
} as const;

export const platformPermissionBundleUpdate = {
  key: 'platform.permission.bundle.update',
  parentKey: platformPermissionBundlesPage.key,
  type: 'action',
  label: '编辑',
  endpoints: [['PUT', '/platformapi/permission/bundles']],
} as const;

export const platformPermissionApisPage = {
  key: 'platform.permission.api.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'permission-apis',
  label: 'API 目录与关联',
  endpoints: [['GET', '/platformapi/permission/apis']],
} as const;

export const platformPermissionMenusPage = {
  key: 'platform.permission.menu.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'permission-menus',
  label: '菜单与按钮权限',
  endpoints: [['GET', '/platformapi/permission/menus']],
} as const;

export const platformOperationsNavigation = {
  id: 'operations-audit',
  key: 'platform.operations',
  parentKey: null,
  type: 'group',
  label: '运维与审计',
  endpoints: [],
} as const;

export const platformOpsOverviewPage = {
  key: 'platform.ops.console',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'ops',
  label: '运维概览',
  endpoints: [['GET', '/platformapi/v1/ops/status']],
} as const;

export const platformOpsMaintenancePage = {
  key: 'platform.ops.maintenance',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'maintenance',
  label: '维护窗口',
  endpoints: [['GET', '/platformapi/v1/ops/maintenance']],
} as const;

export const platformOpsBackupsPage = {
  key: 'platform.ops.backups',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'backups',
  label: '备份与恢复',
  endpoints: [['GET', '/platformapi/v1/ops/backups']],
} as const;

const opsUpgradesPageKey = 'platform.upgrade.status';

export const platformOpsUpgradeReadiness = {
  key: 'platform.upgrade.readiness.read',
  parentKey: opsUpgradesPageKey,
  type: 'operation',
  label: '读取升级就绪状态',
  endpoints: [['GET', '/platformapi/v1/ops/upgrade-readiness']],
} as const;

export const platformOpsUpgradeHistory = {
  key: 'platform.upgrade.history.read',
  parentKey: opsUpgradesPageKey,
  type: 'operation',
  label: '读取升级任务',
  endpoints: [['GET', '/platformapi/v1/ops/upgrades']],
} as const;

export const platformOpsUpgradesPage = {
  key: opsUpgradesPageKey,
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'upgrades',
  label: '应用升级',
  endpoints: [],
  readAlternatives: [platformOpsUpgradeReadiness, platformOpsUpgradeHistory],
} as const;

const opsDiagnosticsPageKey = 'platform.ops.diagnostics';

export const platformOpsLogs = {
  key: 'platform.ops.logs.read',
  parentKey: opsDiagnosticsPageKey,
  type: 'operation',
  label: '读取运行日志',
  endpoints: [['GET', '/platformapi/v1/ops/logs']],
} as const;

export const platformOpsDiagnosticsDownload = {
  key: 'platform.ops.diagnostics.download',
  parentKey: opsDiagnosticsPageKey,
  type: 'operation',
  label: '下载脱敏诊断包',
  endpoints: [['GET', '/platformapi/v1/ops/diagnostics']],
} as const;

export const platformOpsDiagnosticsPage = {
  key: opsDiagnosticsPageKey,
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'diagnostics',
  label: '运行诊断',
  endpoints: [],
  readAlternatives: [platformOpsLogs, platformOpsDiagnosticsDownload],
} as const;

export const platformOpsProvidersPage = {
  key: 'platform.ops.providers',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'providers',
  label: '服务提供者',
  endpoints: [['GET', '/platformapi/v1/ops/providers']],
} as const;

export const platformAuditPage = {
  key: 'platform.audit.list',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'audit',
  label: '平台审计',
  endpoints: [['GET', '/platformapi/audit']],
} as const;

export const platformModuleTaskRead = {
  key: 'platform.module.operation-task.read',
  parentKey: null,
  type: 'operation',
  label: '读取模块操作任务',
  source: '开发者中心 · 模块任务回执',
  endpoints: [['GET', '/platformapi/instance-tools/modules/tasks/{task_key}']],
} as const;

export function platformViewHash(view: string): string {
  return '#/' + view;
}

export const platformShellPreviewDefinitions = [
  platformTenancyNavigation,
  platformTenantsPage,
  platformTenantCreate,
  platformTenantDetail,
  ...Object.values(platformTenantTransitions),
  organizationAccessNavigation,
  platformOperatorsPage,
  platformOperatorCreate,
  platformOperatorUpdate,
  platformOperatorRolesAssign,
  ...Object.values(platformOperatorTransitions),
  platformRolesPage,
  platformRoleCreate,
  platformRoleUpdate,
  platformRoleArchive,
  platformRoleAuthorizationPage,
  platformPermissionBundlesPage,
  platformPermissionBundleCreate,
  platformPermissionBundleUpdate,
  platformPermissionApisPage,
  platformPermissionMenusPage,
  platformOperationsNavigation,
  platformOpsOverviewPage,
  platformOpsMaintenancePage,
  platformOpsBackupsPage,
  platformOpsUpgradesPage,
  ...platformOpsUpgradesPage.readAlternatives,
  platformOpsDiagnosticsPage,
  ...platformOpsDiagnosticsPage.readAlternatives,
  platformOpsProvidersPage,
  platformAuditPage,
  platformModuleTaskRead,
] as const;
