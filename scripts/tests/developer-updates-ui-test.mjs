import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';

// Real Vue component/compiler test with a mock API boundary, not a logged-in APP acceptance.
const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..'
);
const require = createRequire(path.join(root, 'tools/quality/package.json'));
const { Window } = await import(require.resolve('happy-dom'));
const window = new Window({ url: 'http://localhost/' });
for (const name of [
  'window',
  'document',
  'navigator',
  'HTMLElement',
  'SVGElement',
  'Element',
  'Node',
]) {
  Object.defineProperty(globalThis, name, {
    value: name === 'window' ? window : window[name],
    configurable: true,
  });
}
const Vue = require('vue');
const { mount, flushPromises } = require('@vue/test-utils');
const { parse, compileScript, compileTemplate } = require('vue/compiler-sfc');
const ts = require('typescript');
const filename = path.join(
  root,
  'platform/src/components/DeveloperCenterPage.vue'
);
const source = fs.readFileSync(filename, 'utf8');
const { descriptor, errors } = parse(source, { filename });
assert.deepEqual(errors, []);
const compiled = compileScript(descriptor, { id: 'developer-updates-test' });
const template = compileTemplate({
  source: descriptor.template.content,
  filename,
  id: 'developer-updates-test',
  compilerOptions: { bindingMetadata: compiled.bindings, isTS: true },
});
assert.deepEqual(template.errors, []);
const evaluate = (code, imports) => {
  const result = ts.transpileModule(code, {
    compilerOptions: {
      target: ts.ScriptTarget.ES2022,
      module: ts.ModuleKind.CommonJS,
      esModuleInterop: true,
    },
    reportDiagnostics: true,
  });
  assert.equal(
    result.diagnostics.filter(
      (row) => row.category === ts.DiagnosticCategory.Error
    ).length,
    0
  );
  const module = { exports: {} };
  const load = (name) => {
    if (Object.hasOwn(imports, name)) return imports[name];
    throw new Error('Unexpected component dependency: ' + name);
  };
  vm.runInThisContext(
    '(function(require,module,exports){' + result.outputText + '\n})',
    { filename }
  )(load, module, module.exports);
  return module.exports;
};

const row = {
  key: 'peanut.file',
  source_version: '1.0.0',
  available_version: '1.0.1',
  sha256: 'a'.repeat(64),
  catalog_tag: 'v1.0.0-rc.2',
  status: 'update_available',
  compatibility: 'native_preflight_required',
};
const snapshot = {
  schema_version: 1,
  channel: 'prerelease',
  edition: 'multi-tenant',
  modules: [row],
  changed: false,
  scaffold: {
    current_version: '5.1.10',
    available_version: '5.1.11',
    status: 'update_available',
    upgrade_method: 'native_cli_plan',
  },
};

function fixture(t, props = {}) {
  const calls = [],
    controls = { confirm: true, failApply: false };
  const api = {
    developerCatalog: async () => null,
    runtimeModules: async () => ({ lists: [], count: 0 }),
    officialModuleUpdates: async (channel) => {
      calls.push(['discover', channel]);
      return snapshot;
    },
    previewOfficialModule: async (selection) => {
      calls.push(['preview', structuredClone(selection)]);
      return {
        dry_run: true,
        plan: { source: { version: '1.0.0' }, target: { version: '1.0.1' } },
        selection_sha256: 'b'.repeat(64),
      };
    },
    applyOfficialModule: async (...args) => {
      calls.push(['apply', ...structuredClone(args)]);
      if (controls.failApply) throw new Error('response unavailable');
      return { task_key: 'job_' + 'a'.repeat(32), status: 'queued' };
    },
  };
  const component = evaluate(compiled.content, {
    'vue': Vue,
    '../api/platform': { api },
    'element-plus': {
      ElMessageBox: {
        confirm: async () => {
          calls.push(['confirm']);
          if (!controls.confirm) throw new Error('cancelled');
        },
      },
    },
    './StructuredDetails.vue': { default: { template: '<div />' } },
  }).default;
  component.render = evaluate(template.code, { vue: Vue }).render;
  const tags = [
    'ElAlert',
    'ElSelect',
    'ElOption',
    'ElButton',
    'ElCard',
    'ElForm',
    'ElFormItem',
    'ElInput',
    'ElTable',
    'ElTableColumn',
    'ElPagination',
    'ElTag',
    'ElCollapse',
    'ElCollapseItem',
    'ElDescriptions',
    'ElDescriptionsItem',
  ];
  const stubs = Object.fromEntries(
    tags.map((name) => [
      name,
      { template: '<div><slot /><slot name="header" /></div>' },
    ])
  );
  stubs.ElTableColumn = true;
  const wrapper = mount(component, {
    props: {
      canReadUpdates: true,
      canUpdateOfficial: true,
      canReadRuntime: false,
      canInstall: false,
      canUpdate: false,
      canReadTasks: false,
      ...props,
    },
    global: { stubs, config: { warnHandler: () => {} } },
  });
  t.after(() => wrapper.unmount());
  return { wrapper, state: wrapper.vm.$.setupState, calls, controls };
}

test('developer updates are explicit and permission-gated, not polled for tenants', async (t) => {
  const f = fixture(t, { canReadUpdates: false, canUpdateOfficial: false });
  await flushPromises();
  await f.state.checkOfficialUpdates();
  await f.state.previewOfficial(row);
  await f.state.applyOfficial();
  assert.deepEqual(f.calls, []);
  assert.equal(f.wrapper.text().includes('官方发行更新（开发者）'), false);
});

test('real component discovers source versions and previews exact target without execution', async (t) => {
  const f = fixture(t);
  await flushPromises();
  assert.deepEqual(f.calls, []);
  f.state.releaseChannel = 'prerelease';
  await f.state.checkOfficialUpdates();
  await f.state.previewOfficial(row);
  await flushPromises();
  assert.deepEqual(
    f.calls.map((call) => call[0]),
    ['discover', 'preview']
  );
  assert.deepEqual(f.calls[1][1], {
    module_key: row.key,
    version: row.available_version,
    sha256: row.sha256,
    catalog_tag: row.catalog_tag,
    channel: 'prerelease',
  });
  assert.ok(f.wrapper.text().includes('5.1.11'));
  assert.ok(f.wrapper.text().includes('发现新版不代表已兼容或已安装'));
});

test('confirmation cancellation submits nothing; lost-response retry retains same idempotency key and pinned selection', async (t) => {
  const f = fixture(t);
  await f.state.previewOfficial(row);
  f.controls.confirm = false;
  await f.state.applyOfficial();
  assert.equal(f.calls.filter((call) => call[0] === 'apply').length, 0);
  f.controls.confirm = true;
  f.controls.failApply = true;
  await f.state.applyOfficial();
  assert.equal(f.state.updatesError, 'response unavailable');
  f.controls.failApply = false;
  await f.state.applyOfficial();
  const applied = f.calls.filter((call) => call[0] === 'apply');
  assert.equal(applied.length, 2);
  assert.deepEqual(applied[0], applied[1]);
  assert.equal(applied[0][2], 'b'.repeat(64));
  assert.match(applied[0][3], /^official-module-[a-f0-9]{32}$/);
  assert.equal(f.state.packageTask.status, 'queued');
  assert.equal(f.state.officialPreview, null);
});

test('changing channel clears the previous upgrade selection and confirmation', async (t) => {
  const f = fixture(t);
  await f.state.previewOfficial(row);
  f.state.resetOfficialSelection();
  await f.state.applyOfficial();
  assert.equal(f.state.officialPreview, null);
  assert.equal(f.state.officialSelection, null);
  assert.equal(f.calls.filter((call) => call[0] === 'apply').length, 0);
});
