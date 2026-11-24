import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import axios from 'axios';
import * as vue from 'vue';
import { compileScript, parse } from 'vue/compiler-sfc';
import * as peanutClient from '@peanut-admin/client';
import * as peanutVue from '@peanut-admin/vue';
import { transformWithEsbuild } from 'vite';

const path = '../src/components/RoleAuthorizationPreview.vue';
const filename = fileURLToPath(new URL(path, import.meta.url));
const { descriptor, errors } = parse(readFileSync(filename, 'utf8'), {
  filename,
});
assert.deepEqual(errors, []);

const modules = [
  'platform-shell-definitions',
  'tenant-license',
  'permission-bundles',
  'role-authorization-preview',
  'platform',
].map((name) => {
  const directory = name === 'platform-shell-definitions' ? 'core' : 'api';
  const file = fileURLToPath(new URL(`../src/${directory}/${name}.ts`, import.meta.url));
  return [name, readFileSync(file, 'utf8'), file];
});
modules.push(['preview-panel', compileScript(descriptor, { id: 'g18' }).content, filename]);
const transpiled = await Promise.all(
  modules.map(async ([name, source, file]) => {
    const { code } = await transformWithEsbuild(source, file, {
      loader: 'ts',
      format: 'cjs',
      define: {
        'import.meta.env.VITE_API_BASE_URL': 'undefined',
        'import.meta.hot': 'undefined',
      },
    });
    return [name, code, file];
  })
);

const previewPath = '/platformapi/roles/permissions/preview';
const legacyPath = '/platformapi/roles/permissions/replace';
const operatorsPath = '/platformapi/operators';
const createOperatorPath = '/platformapi/operators/create';
const rolesPath = '/platformapi/roles';
const createRolePath = '/platformapi/roles/create';
const moduleTaskPath = '/platformapi/instance-tools/modules/tasks/{task_key}';
const permissionCatalogPath = '/platformapi/permissions';
const permissionBundlesPath = '/platformapi/permission/bundles';
const permissionApisPath = '/platformapi/permission/apis';
const permissionMenusPath = '/platformapi/permission/menus';
const updateOperatorPath = '/platformapi/operators/update';
const replaceOperatorRolesPath = '/platformapi/operators/roles/replace';
const updateRolePath = '/platformapi/roles/update';
const archiveRolePath = '/platformapi/roles/archive';
const operatorTransitions = ['activate', 'suspend', 'close'];
const apiId = 'a'.repeat(64);
const group = {
  key: 'platform.candidate',
  parent_key: null,
  type: 'group',
  api_ids: [],
};
const page = {
  key: 'platform.candidate.page',
  parent_key: group.key,
  type: 'page',
  api_ids: [apiId],
};
const result = {
  operator_id: '1',
  capability_equal: true,
  relation_coverage_complete: false,
  owner_dynamic: true,
  added: [],
  removed: [],
  non_http_permission_keys: ['platform.local.operation'],
  roles: [{
    role_id: '2',
    relation_equal: false,
    owner_dynamic: true,
    added: [],
    removed: [apiId],
  }],
  unresolved: [{ code: 'role_assignment_missing', role_id: '3' }],
};

const plain = (data) => JSON.parse(JSON.stringify(data));
function nativePlatformApis() {
  const source = process.env.PEANUT_PLATFORM_PREVIEW_API_CATALOG ||
    new URL('../../server/generated/api-catalog.json', import.meta.url);
  const { endpoints } = JSON.parse(readFileSync(source, 'utf8'));
  return [
    ['GET', rolesPath],
    ['POST', createRolePath],
    ['GET', moduleTaskPath],
    ['GET', operatorsPath],
    ['POST', createOperatorPath],
    ['GET', permissionCatalogPath],
    ['GET', permissionBundlesPath],
    ['POST', permissionBundlesPath],
    ['PUT', permissionBundlesPath],
    ['GET', permissionApisPath],
    ['GET', permissionMenusPath],
    ['POST', updateOperatorPath],
    ['POST', replaceOperatorRolesPath],
    ...operatorTransitions.map((action) => ['POST', operatorsPath + '/' + action]),
    ['POST', updateRolePath],
    ['POST', archiveRolePath],
  ]
    .map(([method, path]) => {
      const matches = endpoints.filter((row) => row.path === path && row.method === method);
      assert.equal(matches.length, 1, method + ' ' + path);
      const [row] = matches;
      assert.equal(row.application, 'platform');
      assert.equal(row.audience, 'platform');
      assert.equal(row.auth.mode, 'platform_permission');
      assert.equal(row.contract_quality, 'complete');
      return {
        id: row.api_id,
        method,
        path,
        audience: row.audience,
        module_key: row.owner.key,
        access_mode: row.auth.mode,
        status: 'active',
        default_permission_keys: row.default_permission_keys,
        prerequisite_permission_keys: row.prerequisite_permission_keys,
      };
    });
}

const reply = (config, data, code = 20000, status = 200, msg = '') => ({
  config,
  status,
  headers: {},
  data: { code, msg, data },
});

function fixture(t, adapter) {
  const storage = new Map();
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key) => storage.get(key) ?? null,
      setItem: (key, value) => storage.set(key, value),
      removeItem: (key) => storage.delete(key),
    },
  });
  Object.defineProperty(navigator, 'locks', {
    configurable: true,
    value: { request: (_name, operation) => operation() },
  });
  t.after(() => {
    delete globalThis.localStorage;
    delete navigator.locks;
  });

  let start;
  const dependencies = {
    axios: { ...axios, create: (config) => axios.create({ ...config, adapter }) },
    vue: { ...vue, onMounted: (callback) => { start = callback; } },
    '@peanut-admin/client': peanutClient,
    '@peanut-admin/vue': peanutVue,
  };
  for (const [name, code, filename] of transpiled) {
    const module = { exports: {} };
    runInNewContext(code, {
      module,
      Error,
      require(dependency) {
        if (dependency === '../api/platform') return dependencies['./platform'];
        if (dependency === '../core/platform-shell-definitions')
          return dependencies['./platform-shell-definitions'];
        if (dependency === '../api/role-authorization-preview')
          return dependencies['./role-authorization-preview'];
        assert.ok(Object.hasOwn(dependencies, dependency), dependency);
        return dependencies[dependency];
      },
    }, { filename });
    dependencies['./' + name] = module.exports;
  }

  let allowed = true;
  const platform = dependencies['./platform'];
  const contract = dependencies['./role-authorization-preview'];
  const canPreview = () => allowed;
  return {
    api: platform.api,
    session: platform.getPlatformSessionSnapshot,
    onSessionChange: platform.onPlatformSessionChange,
    contract,
    revoke: () => { allowed = false; },
    async mount(useOfficialDirectory = false, mode = 'manual') {
      const scope = vue.effectScope();
      const state = scope.run(() =>
        dependencies['./preview-panel'].default.setup({
          canPreview,
          loadDirectory: useOfficialDirectory
            ? (signal) => contract.readRoleAuthorizationPreviewDirectory(platform.api, canPreview, signal)
            : async () => ({
            operators: [
              { id: '1', role_keys: ['editor', 'reviewer'] },
              { id: '4', role_keys: ['reviewer'] },
            ],
            roles: [
              { id: '2', key: 'editor', name: 'Editor' },
              { id: '3', key: 'reviewer', name: 'Reviewer' },
            ],
            apis: [],
          }),
        }, { emit: (event) => { if (event === 'close') scope.stop(); }, expose: () => {} })
      );
      t.after(() => scope.stop());
      await start();
      if (!useOfficialDirectory) {
        state.operatorId.value = '1';
        state.roleId.value = '2';
      }
      if (mode === 'manual') {
        state.candidateMode.value = mode;
        state.candidateText.value = JSON.stringify([group, page]);
      }
      return state;
    },
  };
}

test('the default preview projects the real Platform Shell branches and a no-menu operation', async (t) => {
  const apis = nativePlatformApis();
  const node = (key, parent_key, type, endpoints = []) => ({
    key,
    parent_key,
    type,
    api_ids: endpoints.map(([method, path]) => {
      const api = apis.find((row) => row.method === method && row.path === path);
      assert.ok(api, method + ' ' + path);
      return api.id;
    }).sort(),
  });
  const previewCalls = [];
  const taskKey = 'synthetic/key?x=1';
  const taskUrl = moduleTaskPath.replace('{task_key}', encodeURIComponent(taskKey));
  const roleInput = { key: 'platform.synthetic', name: 'Synthetic' };
  const operatorInput = { email: 'operator@example.test', display_name: 'Synthetic' };
  const bundleInput = { key: 'platform.synthetic', name: 'Synthetic', audience: 'platform' };
  const bundleUpdate = {
    permission_key: bundleInput.key,
    name: bundleInput.name,
    status: 'active',
    expected_revision: 'r1',
  };
  const bundle = {
    ...bundleInput,
    module_key: 'platform',
    status: 'active',
    revision: 'r1',
    api_ids: [],
    configured: false,
    source: 'custom',
  };
  const { api, mount } = fixture(t, async (config) => {
    if (config.method === 'get') {
      if (config.url === operatorsPath)
        return reply(config, {
          count: 1,
          lists: [{ id: '1', display_name: 'Operator', role_keys: ['editor', 'reviewer'] }],
        });
      if (config.url === rolesPath)
        return reply(config, {
          count: 2,
          lists: [
            { id: '2', key: 'editor', name: 'Editor' },
            { id: '3', key: 'reviewer', name: 'Reviewer' },
          ],
        });
      if (config.url === taskUrl) return reply(config, { task_key: taskKey });
      if (config.url === permissionCatalogPath) {
        assert.deepEqual(plain(config.params), { page: 2, page_size: 20 });
        return reply(config, { lists: [], count: 0 });
      }
      if (config.url === permissionBundlesPath) {
        assert.deepEqual(plain(config.params), { audience: 'platform', page_no: 2, page_size: 100 });
        return reply(config, { items: [bundle], total: 1 });
      }
      assert.equal(config.url, permissionApisPath);
      assert.equal(config.params.audience, 'platform');
      return reply(config, { items: apis, total: apis.length });
    }
    if (config.url === permissionBundlesPath) {
      assert.ok(config.method === 'post' || config.method === 'put');
      assert.deepEqual(JSON.parse(config.data), config.method === 'post' ? bundleInput : bundleUpdate);
      return reply(config, bundle);
    }
    assert.equal(config.method, 'post');
    if (config.url === createRolePath) {
      assert.deepEqual(JSON.parse(config.data), roleInput);
      return reply(config, null);
    }
    if (config.url === createOperatorPath) {
      assert.deepEqual(JSON.parse(config.data), operatorInput);
      return reply(config, null);
    }
    assert.equal(config.url, previewPath);
    previewCalls.push(JSON.parse(config.data));
    return reply(config, result);
  });
  const panel = await mount(true, 'shell');
  assert.equal(panel.candidateMode.value, 'shell');
  assert.equal(panel.candidateText.value, '');
  const [groupNode, operation] = panel.candidate.value.data.tree;
  const [operatorsNode, pageNode, workbench, bundlesPage, apisPage, menusPage] =
    groupNode.children;
  const [operatorAction] = operatorsNode.children;
  const [action] = pageNode.children;
  assert.equal(groupNode.label, '组织与权限');
  assert.match(groupNode.source, /侧栏分组/);
  assert.equal(groupNode.disabled, true);
  assert.equal(operatorsNode.label, '实例平台操作员');
  assert.match(operatorsNode.source, /#\/operators$/);
  assert.equal(operatorAction.label, '创建操作员');
  assert.match(operatorAction.source, /实例平台操作员$/);
  assert.equal(pageNode.label, '平台角色');
  assert.match(pageNode.source, /#\/roles$/);
  assert.equal(action.label, '创建角色');
  assert.match(action.source, /平台角色$/);
  assert.match(workbench.source, /#\/role-permissions$/);
  assert.equal(workbench.api_ids.length, 2);
  assert.deepEqual(plain(bundlesPage.children.map((child) => child.label)), ['创建权限包', '编辑']);
  assert.match(apisPage.source, /#\/permission-apis$/);
  assert.match(menusPage.source, /#\/permission-menus$/);
  assert.equal(menusPage.children.length, 0);
  assert.equal(operation.label, '读取模块操作任务');
  assert.match(operation.source, /开发者中心.*模块任务回执/);
  assert.equal(panel.describeApis(operation.api_ids), 'GET ' + moduleTaskPath);
  assert.equal(previewCalls.length, 0);

  panel.operatorId.value = '1';
  panel.roleId.value = '2';
  panel.setRoleSelection([]);
  await panel.runPreview();
  assert.deepEqual(previewCalls, [{
    operator_id: '1',
    nodes: [
      node('platform.governance', null, 'group'),
      node('platform.operator.list', 'platform.governance', 'page', [['GET', operatorsPath]]),
      node('platform.operator.create', 'platform.operator.list', 'action', [['POST', createOperatorPath]]),
      node('platform.operator.update', 'platform.operator.list', 'action', [['POST', updateOperatorPath]]),
      node('platform.operator.role.assign', 'platform.operator.list', 'action', [['GET', rolesPath], ['POST', replaceOperatorRolesPath]]),
      ...operatorTransitions.map((action) => node(
        'platform.operator.' + action,
        'platform.operator.list',
        'action',
        [['POST', operatorsPath + '/' + action]]
      )),
      node('platform.role.list', 'platform.governance', 'page', [['GET', rolesPath]]),
      node('platform.role.create', 'platform.role.list', 'action', [['POST', createRolePath]]),
      node('platform.role.update', 'platform.role.list', 'action', [['POST', updateRolePath]]),
      node('platform.role.archive', 'platform.role.list', 'action', [['POST', archiveRolePath]]),
      node('platform.governance.workbench', 'platform.governance', 'page', [['GET', rolesPath], ['GET', permissionCatalogPath]]),
      node('platform.permission.bundle.list', 'platform.governance', 'page', [['GET', permissionBundlesPath]]),
      node('platform.permission.bundle.create', 'platform.permission.bundle.list', 'action', [['POST', permissionBundlesPath]]),
      node('platform.permission.bundle.update', 'platform.permission.bundle.list', 'action', [['PUT', permissionBundlesPath]]),
      node('platform.permission.api.list', 'platform.governance', 'page', [['GET', permissionApisPath]]),
      node('platform.permission.menu.list', 'platform.governance', 'page', [['GET', permissionMenusPath]]),
      node('platform.module.operation-task.read', null, 'operation', [['GET', moduleTaskPath]]),
    ],
    role_assignments: [{ role_id: '2', node_keys: [] }],
  }]);
  assert.equal(panel.result.value.capability_equal, true);
  assert.equal(panel.result.value.relation_coverage_complete, false);
  await api.createRole(roleInput);
  await api.createOperator(operatorInput);
  assert.deepEqual(await api.permissions(2, 20), { lists: [], count: 0 });
  assert.deepEqual(plain(await api.permissionBundles('platform', 2)), { items: [bundle], total: 1 });
  assert.deepEqual(plain(await api.createPermissionBundle(bundleInput)), bundle);
  assert.deepEqual(plain(await api.updatePermissionBundle(bundleUpdate)), bundle);
  assert.deepEqual(await api.moduleOperationTask(taskKey), { task_key: taskKey });
});

test('existing Platform management actions retain methods, data, and revisions', async (t) => {
  const calls = [];
  const { api } = fixture(t, async (config) => {
    calls.push([config.method, config.url, config.data ? JSON.parse(config.data) : null]);
    return reply(config, config.url === permissionMenusPath ? { items: [], total: 0 } : null);
  });
  const role = { id: 41, revision: 7 };
  const operator = { id: 43, security_revision: 8 };
  const roleChange = { role_id: 41, expected_revision: 7, change_reason: 'synthetic' };
  const operatorChange = { operator_id: 43, expected_revision: 8, change_reason: 'synthetic' };
  await api.updateRole(role, 'Updated role', 'Description', 'synthetic');
  await api.archiveRole(role, 'synthetic');
  await api.updateOperator(operator, 'Display name', 'synthetic');
  await api.replaceOperatorRoles(operator, [41], 'synthetic');
  for (const action of operatorTransitions)
    await api.transitionOperator(action, operator, 'synthetic');
  await api.permissionMenus();
  assert.deepEqual(calls, [
    ['post', updateRolePath, { ...roleChange, name: 'Updated role', description: 'Description' }],
    ['post', archiveRolePath, roleChange],
    ['post', updateOperatorPath, { ...operatorChange, display_name: 'Display name' }],
    ['post', replaceOperatorRolesPath, { ...operatorChange, role_ids: [41] }],
    ...operatorTransitions.map((action) => ['post', operatorsPath + '/' + action, operatorChange]),
    ['get', permissionMenusPath, null],
  ]);
});

test('formal candidates stop on missing, inactive, incompatible or ambiguous API rows', async (t) => {
  const apis = nativePlatformApis();
  const [roles, ...otherApis] = apis;
  const { contract, mount } = fixture(t, () => assert.fail('unexpected request'));
  const diagnostics = [
    [otherApis, /GET \/platformapi\/roles：API 未登记/],
    [[{ ...roles, status: 'retired' }, ...otherApis], /目录状态为 retired/],
    [[{ ...roles, audience: 'tenant' }, ...otherApis], /受众不是 Platform/],
    [[{ ...roles, access_mode: 'platform_bearer' }, ...otherApis], /授权模式为 platform_bearer/],
    [[...apis, { ...roles, id: apiId }], /存在多个目录条目/],
  ];
  for (const [rows, error] of diagnostics)
    assert.throws(() => contract.buildPlatformShellCandidate(rows), error);

  const panel = await mount(false, 'shell');
  panel.operatorId.value = '1';
  assert.match(panel.candidate.value.error, /API 未登记/);
  assert.equal(panel.candidate.value.data, null);
  await panel.runPreview();
});

test('preview access requires the exact POST and all three read endpoints', (t) => {
  const { contract } = fixture(t, () => assert.fail('unexpected request'));
  const grants = new Set([
    'POST ' + previewPath,
    'GET /platformapi/operators',
    'GET /platformapi/roles',
    'GET /platformapi/permission/apis',
  ]);
  const canApi = (method, path) => grants.has(method + ' ' + path);
  assert.equal(contract.allowsRoleAuthorizationPreview(canApi), true);
  for (const grant of grants) {
    const allowed = (method, path) =>
      canApi(method, path) && method + ' ' + path !== grant;
    assert.equal(contract.allowsRoleAuthorizationPreview(allowed), false, grant);
  }
});

test('preview sends explicit empty but omits untouched roles and preserves both outcomes', async (t) => {
  const calls = [];
  const { mount, contract } = fixture(t, async (config) => {
    calls.push([config.method, config.url, JSON.parse(config.data)]);
    return reply(config, result);
  });
  const panel = await mount();
  assert.equal(calls.length, 0);
  assert.equal(panel.candidate.value.data.tree[0].disabled, true);

  panel.setRoleSelection([]);
  assert.equal(panel.selectionLabel('2'), '明确空选择');
  assert.equal(panel.selectionLabel('3'), '未提供选择');
  await panel.runPreview();
  assert.deepEqual(calls, [['post', previewPath, {
    operator_id: '1',
    nodes: [group, page],
    role_assignments: [{ role_id: '2', node_keys: [] }],
  }]]);
  assert.deepEqual(plain(panel.result.value), result);

  panel.roleId.value = '3';
  assert.equal(panel.result.value, undefined);
  panel.changeCheckedNodes(page, { checkedKeys: [page.key] });
  await panel.runPreview();
  assert.deepEqual(calls[1][2].role_assignments, [
    { role_id: '2', node_keys: [] },
    { role_id: '3', node_keys: [page.key] },
  ]);

  panel.candidateText.value = JSON.stringify([{
    key: page.key, parent_key: null, type: 'page',
  }]);
  assert.match(panel.candidate.value.error, /缺少 api_ids/);
  await panel.runPreview();
  assert.equal(calls.length, 2);
  assert.equal(panel.result.value, undefined);
  assert.equal(panel.selectionLabel('2'), '未提供选择');
  assert.throws(
    () => contract.readCandidateNodes(JSON.stringify([group, page, { ...page, api_ids: [] }])),
    /定义冲突/
  );
});

test('reopening reloads all paginated directories and preserves large textual IDs and diagnostics', async (t) => {
  const operatorId = '9007199254740993';
  const editorId = '9007199254740995';
  const reviewerId = '9007199254740997';
  const diagnosticId = 'legacy/api-id';
  let roleKey = 'editor';
  const directoryReads = [];
  const previews = [];
  const { mount } = fixture(t, async (config) => {
    const { url, params } = config;
    if (config.method === 'get') directoryReads.push([url, params]);
    if (url === '/platformapi/operators') {
      return reply(config, {
        count: 1,
        lists: [{ id: operatorId, display_name: 'Operator', role_keys: [roleKey] }],
      });
    }
    if (url === '/platformapi/roles') {
      const filler = Array.from({ length: 99 }, (_, index) => ({
        id: index + 10, key: 'other-' + index, name: 'Other ' + index,
      }));
      return reply(config, {
        count: 101,
        lists: params.page === 1
          ? [{ id: editorId, key: 'editor', name: 'Editor' }, ...filler]
          : [{ id: reviewerId, key: 'reviewer', name: 'Reviewer' }],
      });
    }
    if (url === '/platformapi/permission/apis')
      return reply(config, { items: [], total: 0 });
    assert.equal(url, previewPath);
    const candidate = JSON.parse(config.data);
    previews.push(candidate);
    return reply(config, {
      ...result,
      operator_id: operatorId,
      unresolved: [{ code: 'legacy_api_not_active_platform', api_id: diagnosticId }],
    });
  });

  const first = await mount(true);
  assert.equal(first.directoryReady.value, true);
  first.operatorId.value = operatorId;
  assert.deepEqual(plain(first.targetRoles.value.map((role) => role.id)), [editorId]);
  first.close();

  roleKey = 'reviewer';
  const second = await mount(true);
  assert.equal(second.directoryReady.value, true);
  second.operatorId.value = operatorId;
  assert.deepEqual(plain(second.targetRoles.value.map((role) => role.id)), [reviewerId]);
  second.roleId.value = reviewerId;
  second.setRoleSelection([]);
  await second.runPreview();
  assert.deepEqual(previews, [{
    operator_id: operatorId,
    nodes: [group, page],
    role_assignments: [{ role_id: reviewerId, node_keys: [] }],
  }]);
  assert.equal(second.previewError.value, null);
  assert.equal(second.result.value.unresolved[0].api_id, diagnosticId);
  assert.equal(second.result.value.capability_equal, true);

  const reads = [
    ['/platformapi/operators', { page: 1, page_size: 100 }],
    ['/platformapi/roles', { page: 1, page_size: 100 }],
    ['/platformapi/permission/apis', { audience: 'platform', page_no: 1, page_size: 100 }],
    ['/platformapi/roles', { page: 2, page_size: 100 }],
  ];
  assert.deepEqual(plain(directoryReads), [...reads, ...reads]);
});

test('unsafe numeric directory IDs fail before a preview can be sent', async (t) => {
  const { mount } = fixture(t, async (config) => {
    assert.equal(config.method, 'get');
    if (config.url === '/platformapi/operators')
      return reply(config, {
        count: 1,
        lists: [{ id: Number.MAX_SAFE_INTEGER + 1, display_name: 'Operator', role_keys: [] }],
      });
    if (config.url === '/platformapi/roles')
      return reply(config, { count: 0, lists: [] });
    assert.equal(config.url, '/platformapi/permission/apis');
    return reply(config, { total: 0, items: [] });
  });
  const panel = await mount(true);
  assert.equal(panel.directoryReady.value, false);
  assert.match(panel.directoryError.value, /无效的操作员或角色标识/);
  await panel.runPreview();
});

test('operator, candidate, source, role selection, and closing cancel or ignore stale responses', async (t) => {
  let pending;
  const { mount } = fixture(t, (config) => new Promise((resolve) => {
    pending = {
      signal: config.signal,
      finish: () => resolve(reply(config, result)),
    };
  }));
  const panel = await mount();
  const changes = [
    () => { panel.operatorId.value = '4'; },
    () => { panel.candidateText.value = '[]'; },
    () => { panel.candidateMode.value = 'shell'; },
    () => { panel.setRoleSelection([]); },
    () => { panel.close(); },
  ];
  for (const change of changes) {
    panel.candidateMode.value = 'manual';
    panel.operatorId.value = '1';
    panel.roleId.value = '2';
    panel.candidateText.value = JSON.stringify([group, page]);
    pending = undefined;
    const action = panel.runPreview();
    await new Promise((resolve) => setImmediate(resolve));
    assert.ok(pending);
    change();
    assert.equal(pending.signal.aborted, true);
    pending.finish();
    await action;
    assert.equal(panel.result.value, undefined);
    assert.equal(panel.previewError.value, null);
  }
});

test('malformed responses and refusal do not show success or retry', async (t) => {
  const calls = [];
  let errorCode;
  const { api, mount, revoke } = fixture(t, async (config) => {
    calls.push(config.url);
    if (config.url === '/platformapi/session/login')
      return reply(config, { access_token: 'synthetic' });
    if (!errorCode)
      return reply(config, { ...result, relation_coverage_complete: 'false' });
    const status = errorCode === 40300 ? 403 : 401;
    throw new axios.AxiosError('denied', 'ERR_BAD_REQUEST', config, undefined,
      reply(config, null, errorCode, status, '权限不足'));
  });
  const panel = await mount();
  await api.login('operator@example.test', 'synthetic');
  await panel.runPreview();
  assert.match(panel.previewError.value, /响应格式异常/);
  assert.equal(panel.result.value, undefined);
  for (const code of [40300, 40100]) {
    errorCode = code;
    await panel.runPreview();
    assert.match(panel.previewError.value, /权限不足/);
    assert.equal(panel.result.value, undefined);
  }
  revoke();
  await panel.runPreview();
  assert.deepEqual(calls, ['/platformapi/session/login', previewPath, previewPath, previewPath]);
});

for (const status of [200, 401]) {
  test('initial preview 40100 via HTTP ' + status + ' preserves session and draft', async (t) => {
    const calls = [];
    const { api, mount, onSessionChange, session } = fixture(t, async (config) => {
      if (config.url === '/platformapi/session/login')
        return reply(config, { access_token: 'synthetic' });
      calls.push([config.method, config.url, config.headers.Authorization]);
      assert.equal(config.url, previewPath);
      const response = reply(config, null, 40100, status, '本次预览的访问凭据已过期');
      if (status === 401)
        throw new axios.AxiosError('unauthorized', 'ERR_BAD_REQUEST', config, undefined, response);
      return response;
    });
    await api.login('operator@example.test', 'synthetic');
    const authenticated = plain(session());
    assert.equal(authenticated.token, 'synthetic');
    let sessionChanges = 0;
    t.after(onSessionChange(() => { sessionChanges += 1; }));

    const panel = await mount();
    panel.setRoleSelection([]);
    const candidate = panel.candidateText.value;
    await panel.runPreview();

    assert.deepEqual(calls, [['post', previewPath, 'Bearer synthetic']]);
    assert.deepEqual(plain(session()), authenticated);
    assert.equal(sessionChanges, 0);
    assert.equal(panel.operatorId.value, '1');
    assert.equal(panel.candidateText.value, candidate);
    assert.equal(panel.selectionLabel('2'), '明确空选择');
    assert.equal(panel.result.value, undefined);
    assert.match(panel.previewError.value, /本次预览的访问凭据已过期/);
  });
}

test('ordinary Platform requests still refresh once and clear a rejected replay', async (t) => {
  const attempts = [];
  const catalog = '/platformapi/developer-center/catalog';
  const refresh = '/platformapi/session/refresh';
  const { api, onSessionChange, session } = fixture(t, async (config) => {
    if (config.url === '/platformapi/session/login')
      return reply(config, { access_token: 'expired' });
    attempts.push([config.url, config.headers.Authorization]);
    if (config.url === refresh)
      return reply(config, { access_token: 'replacement' });
    assert.equal(config.url, catalog);
    return reply(config, null, 40100, 200, '会话已过期');
  });
  await api.login('operator@example.test', 'synthetic');
  const changes = [];
  t.after(onSessionChange((present) => changes.push(present)));
  await assert.rejects(api.developerCatalog(), /会话已过期/);
  assert.deepEqual(attempts, [
    [catalog, 'Bearer expired'],
    [refresh, 'Bearer expired'],
    [catalog, 'Bearer replacement'],
  ]);
  assert.equal(session().token, null);
  assert.deepEqual(changes, [false]);
});

test('existing role save uses its original independent contract', async (t) => {
  let request;
  const { api } = fixture(t, async (config) => {
    request = [config.method, config.url, JSON.parse(config.data)];
    return reply(config, null);
  });
  await api.replaceRolePermissions({ id: 2, revision: 7 }, ['platform.role.read'], 'synthetic');
  assert.deepEqual(request, ['post', legacyPath, {
    role_id: 2,
    permission_keys: ['platform.role.read'],
    expected_revision: 7,
    change_reason: 'synthetic',
  }]);
});
