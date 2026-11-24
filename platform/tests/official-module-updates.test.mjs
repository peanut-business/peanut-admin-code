import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import axios from 'axios';
import * as peanutClient from '@peanut-admin/client';
import * as peanutVue from '@peanut-admin/vue';
import { transformWithEsbuild } from 'vite';

const updates = '/platformapi/instance-tools/modules/updates';
const refresh = '/platformapi/session/refresh';
const catalog = '/platformapi/developer-center/catalog';
const transportModules = await Promise.all(
  ['tenant-license', 'permission-bundles', 'platform'].map(async (name) => {
    const filename = fileURLToPath(
      new URL(`../src/api/${name}.ts`, import.meta.url)
    );
    const { code } = await transformWithEsbuild(
      readFileSync(filename, 'utf8'),
      filename,
      {
        format: 'cjs',
        define: {
          'import.meta.env.VITE_API_BASE_URL': 'undefined',
          'import.meta.hot': 'undefined',
        },
      }
    );
    return { name, filename, code };
  })
);

// Run the platform transport with its real Axios interceptors and Core
// session code. Only the network adapter and browser storage/lock are fixtures.
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

  const dependencies = {
    axios: {
      ...axios,
      create: (config) => axios.create({ ...config, adapter }),
    },
    '@peanut-admin/client': peanutClient,
    '@peanut-admin/vue': peanutVue,
  };
  for (const { name, filename, code } of transportModules) {
    const module = { exports: {} };
    runInNewContext(
      code,
      {
        module,
        require(dependency) {
          assert.ok(Object.hasOwn(dependencies, dependency), dependency);
          return dependencies[dependency];
        },
      },
      { filename }
    );
    dependencies[`./${name}`] = module.exports;
  }
  return dependencies['./platform'].api;
}

function envelope(config, data, code = 20000, status = 200) {
  return { config, status, headers: {}, data: { code, msg: '', data } };
}

test('only the official catalog extends the platform request timeout', async (t) => {
  const calls = [];
  const api = fixture(t, async (config) => {
    const { method, url, timeout, params } = config;
    calls.push([method, url, timeout, params?.channel]);
    return envelope(config, { catalog: [] });
  });
  for (const channel of ['stable', 'prerelease']) {
    assert.deepEqual(await api.officialModuleUpdates(channel), { catalog: [] });
  }
  await api.developerCatalog();
  assert.deepEqual(calls, [
    ['get', updates, 60_000, 'stable'],
    ['get', updates, 60_000, 'prerelease'],
    ['get', catalog, 15_000, undefined],
  ]);
});

test('catalog timeouts and other network failures have distinct messages', async (t) => {
  const failures = [
    ['ECONNABORTED', '请求超时，请稍后重试'],
    ['ETIMEDOUT', '请求超时，请稍后重试'],
    ['ERR_NETWORK', '网络请求失败，请检查网络连接后重试'],
  ];
  for (const [code, message] of failures) {
    const api = fixture(t, async (config) => {
      throw new axios.AxiosError('transport error', code, config);
    });
    await assert.rejects(api.officialModuleUpdates('stable'), { code, message });
  }
});

test('concurrent catalog reads share session refresh and retain their timeout on retry', async (t) => {
  const attempts = [];
  const refreshCalls = [];
  const api = fixture(t, async (config) => {
    const { url, timeout, params, headers } = config;
    if (url === '/platformapi/session/login') {
      return envelope(config, { access_token: 'initial-token' });
    }
    if (url === refresh) {
      refreshCalls.push(timeout);
      return envelope(config, { access_token: 'refreshed-token' });
    }
    assert.equal(url, updates);
    attempts.push([params.channel, timeout, headers.Authorization]);
    if (headers.Authorization === 'Bearer initial-token') {
      throw new axios.AxiosError(
        'session expired',
        'ERR_BAD_REQUEST',
        config,
        undefined,
        envelope(config, null, 40100, 401)
      );
    }
    return envelope(config, { channel: params.channel });
  });

  await api.login('operator@example.test', 'synthetic');
  const channels = ['stable', 'prerelease'];
  const snapshots = await Promise.all(
    channels.map((channel) => api.officialModuleUpdates(channel))
  );
  assert.deepEqual(snapshots, channels.map((channel) => ({ channel })));
  assert.deepEqual(refreshCalls, [15_000]);
  for (const channel of channels) {
    assert.deepEqual(attempts.filter(([attempt]) => attempt === channel), [
      [channel, 60_000, 'Bearer initial-token'],
      [channel, 60_000, 'Bearer refreshed-token'],
    ]);
  }
});

const selection = {
  module_key: 'peanut.file',
  version: '1.0.2',
  sha256: 'a'.repeat(64),
  catalog_tag: 'v1.0.2',
  channel: 'stable',
};

test('official preflight explains only known module rejection codes', async (t) => {
  let errorCode;
  const fallback = 'Module runtime request was rejected.';
  const api = fixture(t, async (config) => {
    const response = envelope(config, { error_code: errorCode }, 42200, 422);
    response.data.msg = fallback;
    response.headers['X-Trace-Id'] = 'module-preview-trace';
    throw new axios.AxiosError(
      'rejected',
      'ERR_BAD_REQUEST',
      config,
      undefined,
      response
    );
  });
  for (const [code, message] of [
    [
      'MODULE_RELEASE_WITHDRAWN',
      '所选模块版本已撤回，请刷新官方目录并选择其他版本。',
    ],
    [
      'MODULE_KERNEL_INCOMPATIBLE',
      '所选模块版本与当前应用的 Kernel 版本不兼容。',
    ],
    [
      'MODULE_PACKAGE_DEPENDENCY_MISSING',
      '所选模块版本缺少必需的前置模块，请核对模块依赖。',
    ],
    ['MODULE_FUTURE_REJECTION', fallback],
  ]) {
    errorCode = code;
    await assert.rejects(api.previewOfficialModule(selection, 'install'), {
      message: message + '（追踪 ID：module-preview-trace）',
    });
  }
  errorCode = 'MODULE_RELEASE_WITHDRAWN';
  await assert.rejects(api.developerCatalog(), {
    message: fallback + '（追踪 ID：module-preview-trace）',
  });
});

test('module failures with a 5xx status retain the safe message and trace ID', async (t) => {
  const api = fixture(t, async (config) => {
    const response = envelope(
      config,
      { error_code: 'MODULE_KERNEL_INCOMPATIBLE' },
      50300,
      503
    );
    response.data.msg = 'Private upstream details';
    response.headers['X-Trace-Id'] = 'module-service-trace';
    throw new axios.AxiosError(
      'unavailable',
      'ERR_BAD_RESPONSE',
      config,
      undefined,
      response
    );
  });
  await assert.rejects(api.previewOfficialModule(selection), {
    message: '服务暂时不可用，请稍后重试（追踪 ID：module-service-trace）',
  });
});

test('official install and update preserve dry run, confirmation and task lookup', async (t) => {
  const digest = 'b'.repeat(64);
  const idempotencyKey = 'test-fixed-intent';
  const preview = { dry_run: true, selection_sha256: digest, plan: {} };
  const task = { task_key: 'job_module_update', status: 'queued' };
  const receipt = {
    ...task,
    status: 'succeeded',
    result: { operation: 'upgraded' },
  };
  let url;
  let applied;
  const api = fixture(t, async (config) => {
    assert.equal(config.url, url);
    if (config.method === 'get') return envelope(config, receipt);
    assert.equal(config.method, 'post');
    assert.deepEqual(JSON.parse(config.data), {
      ...selection,
      dry_run: !applied,
      ...(applied ? { confirm_selection_sha256: digest } : {}),
    });
    assert.equal(
      (config.headers || {})['Idempotency-Key'],
      applied ? idempotencyKey : undefined
    );
    return envelope(config, applied ? task : preview);
  });

  for (const operation of ['install', 'update']) {
    url = '/platformapi/instance-tools/modules/official-' + operation;
    applied = false;
    assert.deepEqual(
      await api.previewOfficialModule(selection, operation),
      preview
    );
    applied = true;
    assert.deepEqual(
      await api.applyOfficialModule(
        selection,
        digest,
        idempotencyKey,
        operation
      ),
      task
    );
    url = '/platformapi/instance-tools/modules/tasks/' + task.task_key;
    assert.deepEqual(await api.moduleOperationTask(task.task_key), receipt);
  }
});
