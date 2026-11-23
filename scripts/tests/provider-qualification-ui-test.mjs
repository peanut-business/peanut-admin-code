import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(
  new URL('../../tools/quality/package.json', import.meta.url)
);
const { Window } = await import(require.resolve('happy-dom'));
const window = new Window({ url: 'http://localhost/' });
for (const name of [
  'window',
  'document',
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
const { mount } = require('@vue/test-utils');
const { parse, compileScript } = require('vue/compiler-sfc');
const ts = require('typescript');
const filename = new URL(
  '../../platform/src/components/ProviderQualificationCard.vue',
  import.meta.url
);
const { descriptor, errors } = parse(fs.readFileSync(filename, 'utf8'));
assert.deepEqual(errors, []);
const script = compileScript(descriptor, {
  id: 'provider-qualification-test',
  inlineTemplate: true,
});
const compiled = ts.transpileModule(script.content, {
  compilerOptions: {
    target: ts.ScriptTarget.ES2022,
    module: ts.ModuleKind.CommonJS,
  },
  reportDiagnostics: true,
});
assert.deepEqual(compiled.diagnostics, []);
const component = { exports: {} };
new Function('require', 'exports', compiled.outputText)(
  require,
  component.exports
);

const ElTable = {
  props: ['data'],
  setup(props, { slots }) {
    Vue.provide('provider-rows', props.data);
    return () => Vue.h('div', slots.default());
  },
};
const ElTableColumn = {
  props: ['label', 'prop'],
  setup(props, { slots }) {
    const rows = Vue.inject('provider-rows');
    return () =>
      Vue.h(
        'div',
        { 'data-label': props.label || props.prop },
        rows.map((row) => Vue.h('span', slots.default?.({ row })))
      );
  },
};
const ElCard = {
  setup(_, { slots }) {
    return () => Vue.h('section', [slots.header?.(), slots.default?.()]);
  },
};
const ElAlert = {
  props: ['title'],
  setup: (props) => () => Vue.h('p', props.title),
};

const baseRow = {
  provider_key: 'storage.local',
  category: 'storage',
  scope: { type: 'instance', key: 'scope_' + 'f'.repeat(64) },
  configured: true,
  connected: false,
  callback_verified: false,
  qualified: false,
  status_code: 'CONNECTIVITY_EVIDENCE_REQUIRED',
  credential_rotated_at: null,
  recent_failure: null,
  expires_at: null,
};

function render(t, row) {
  const wrapper = mount(component.exports.default, {
    props: {
      loading: false,
      snapshot: { page: 1, page_size: 20, total: 1, providers: [row] },
    },
    global: {
      components: { ElCard, ElAlert, ElTable, ElTableColumn },
      directives: { loading: () => {} },
      stubs: {
        ElTag: { template: '<span><slot /></span>' },
        ElPagination: true,
        ElEmpty: true,
      },
    },
  });
  t.after(() => wrapper.unmount());
  return wrapper;
}

test('configured providers without evidence are displayed as unverified', (t) => {
  const wrapper = render(t, baseRow);
  assert.equal(wrapper.get('[data-label="配置"]').text(), '是');
  for (const column of ['连通', '回调', '生产资格']) {
    assert.equal(wrapper.get('[data-label="' + column + '"]').text(), '未验证');
  }
  assert.match(wrapper.text(), /缺少连通验证证据/);
  assert.match(wrapper.text(), /刷新只读取记录/);
});

test('unsupported qualification and future positive evidence stay distinct', (t) => {
  const unsupported = render(t, {
    ...baseRow,
    configured: false,
    status_code: 'NOT_IMPLEMENTED',
  });
  assert.equal(unsupported.get('[data-label="配置"]').text(), '—');
  assert.equal(unsupported.get('[data-label="生产资格"]').text(), '未接入核验');
  const proved = render(t, {
    ...baseRow,
    connected: true,
    callback_verified: true,
    qualified: true,
    status_code: 'PROVIDER_PRODUCTION_QUALIFIED',
  });
  assert.equal(proved.get('[data-label="连通"]').text(), '已验证');
  assert.equal(proved.get('[data-label="回调"]').text(), '已验证');
  assert.equal(proved.get('[data-label="生产资格"]').text(), '有效');
});
