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
  platformModuleTaskRead,
] as const;
