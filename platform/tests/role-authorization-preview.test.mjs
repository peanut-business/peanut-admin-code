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
  'tenant-license',
  'permission-bundles',
  'role-authorization-preview',
  'platform',
].map((name) => {
  const file = fileURLToPath(new URL('../src/api/' + name + '.ts', import.meta.url));
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
    async mount(useOfficialDirectory = false) {
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
      state.candidateText.value = JSON.stringify([group, page]);
      return state;
    },
  };
}

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

test('operator, candidate, role selection, and closing cancel or ignore stale responses', async (t) => {
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
    () => { panel.setRoleSelection([]); },
    () => { panel.close(); },
  ];
  for (const change of changes) {
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
