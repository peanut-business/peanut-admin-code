import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const require = createRequire(new URL('../../package.json', import.meta.url));
const ts = require('typescript');
const compiler = require('vue/compiler-sfc');
const file = fileURLToPath(new URL('../../src/views/system/dict/components/DataDrawer.vue', import.meta.url));
const { descriptor, errors } = compiler.parse(readFileSync(file, 'utf8'), { filename: file });
assert.equal(errors.length, 0);
const script = descriptor.scriptSetup?.content ?? '';
const start = script.indexOf('  const fetchData = async (');
const end = script.indexOf('  // ---- 弹窗 & 表单 ----', start);
assert.ok(start > 0 && end > start);
const actual = script.slice(start, end);
const template = descriptor.template?.content ?? '';

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
function fixture() {
  const requests = [];
  const props = { visible: true, typeId: 11, typeName: '旧字典' };
  const keyword = { value: 'one' };
  const renderData = { value: [] };
  const listError = { value: false };
  const failedQuery = { value: null };
  const pagination = { current: 1, pageSize: 10, total: 0 };
  let loading = false;
  let onWatch;
  let unmount;
  const output = ts.transpileModule(
    'let requestSequence=0;\n' + actual + '\nexports.controls={fetchData,retryList,onPageChange};',
    { fileName: file + '.ts', compilerOptions: {
      target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS,
    } }
  );
  assert.equal(output.diagnostics?.length ?? 0, 0);
  const exports = {};
  runInNewContext(output.outputText, {
    exports, props, keyword, renderData, listError, failedQuery, pagination,
    setLoading: (value) => { loading = value; },
    getDictDataList: (params) => {
      const call = deferred();requests.push({ params, ...call });return call.promise;
    },
    watch: (_selector, callback) => { onWatch = callback; },
    onBeforeUnmount: (callback) => { unmount = callback; },
  }, { filename: file });
  return {
    ...exports.controls, props, keyword, renderData, listError, failedQuery,
    pagination, requests,
    watch: (next, prev) => onWatch(next, prev),
    unmount: () => unmount(),
    get loading() { return loading; },
  };
}
const page = (id, count = 1) =>
  ({ data: { lists: [{ id }], pageNo: 2, count } });
const tick = () => new Promise((resolve) => setImmediate(resolve));

test('data drawer retry reproduces the exact failed type, filter and page and preserves old results', async () => {
  const f = fixture();
  const first = f.fetchData(2);
  f.requests[0].resolve(page(100, 21));
  await first;
  f.keyword.value = 'second';
  const failure = f.fetchData(3);
  f.requests[1].reject(Error('read failed'));
  await failure;
  assert.equal(f.listError.value, true);
  assert.equal(f.renderData.value[0].id, 100);
  assert.equal(f.pagination.total, 21);
  f.keyword.value = 'edited after failed query';
  f.retryList();
  assert.equal(f.requests[2].params.type_id, 11);
  assert.equal(f.requests[2].params.name, 'second');
  assert.equal(f.requests[2].params.page_no, 3);
  f.requests[2].resolve({ data: { lists: [], pageNo: 3, count: 0 } });
  await tick();
  assert.equal(f.listError.value, false);
  assert.equal(f.failedQuery.value, null);
  assert.equal(f.pagination.current, 3);
  assert.equal(f.renderData.value.length, 0);
});

test('closing a drawer invalidates its late responses and never shows another type data', async () => {
  const f = fixture();
  const old = f.fetchData(2);
  f.props.visible = false;
  f.watch([false, 11], [true, 11]);
  f.requests[0].resolve(page(999));
  await old;
  assert.equal(f.renderData.value.length, 0);
  assert.equal(f.loading, false);
  assert.equal(f.failedQuery.value, null);

  f.props.visible = true;
  f.props.typeId = 44;
  f.watch([true, 44], [false, 11]);
  assert.equal(f.requests[1].params.type_id, 44);
  f.requests[1].resolve(page(44));
  await tick();
  assert.equal(f.renderData.value[0].id, 44);
});

test('switching dictionary type invalidates old failed-query retry and in-flight responses', async () => {
  const f = fixture();
  const pending = f.fetchData(2);
  f.props.typeId = 99;
  f.watch([true, 99], [true, 11]);
  f.requests[0].reject(new Error('late old type'));
  await pending;
  assert.equal(f.listError.value, false);
  assert.equal(f.failedQuery.value, null);
  f.requests[1].resolve(page(99));
  await tick();
  assert.equal(f.renderData.value[0].id, 99);
  f.unmount();
});

test('rendered drawer visibly distinguishes retained-read-error from successful empty data and uses scoped retry', () => {
  const compiled = compiler.compileTemplate({ source: template, filename: file, id: 'dict-data-drawer' });
  assert.equal(compiled.errors.length, 0);
  assert.match(template, /listError && !loading/);
  assert.match(template, /@click="retryList"/);
  assert.match(template, /systemDict\.data\.loadFailedRetained/);
  assert.match(template, /systemDict\.data\.loadFailed/);
  assert.match(script, /hasPermission\('peanut\.reference-codes\.manage', route\.path\)/);
});
