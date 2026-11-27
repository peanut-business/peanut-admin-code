import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const require = createRequire(new URL('../../package.json', import.meta.url));
const ts = require('typescript');
const compiler = require('vue/compiler-sfc');
const filename = fileURLToPath(new URL('../../src/views/dev-tools/code/index.vue', import.meta.url));
const { descriptor, errors } = compiler.parse(readFileSync(filename, 'utf8'), { filename });
assert.deepEqual(errors, []);
const script = descriptor.scriptSetup?.content ?? '';
const begin = script.indexOf('  const { loading, setLoading } = useLoading(true);');
const end = script.indexOf('  const handleImport = async', begin);
assert.ok(begin !== -1 && end > begin, 'Use current Generator UI source rather than a duplicate implementation');
const actual = script.slice(begin, end);

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((ok, fail) => { resolve = ok; reject = fail; });
  return { promise, resolve, reject };
}

function fixture() {
  const lists = [];
  const sources = [];
  let destroyed = () => {};
  const code = ts.transpileModule(
    actual + '\nexports.controls = { loading, renderData, keyword, pagination, selectedKeys, fetchData, sourceVisible, sourceLoading, sourceKeyword, sourceTables, sourceSelectedKeys, sourcePagination, fetchSourceTables, openSourceTables, closeSourceTables };',
    { fileName: filename + '.ts', compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }, reportDiagnostics: true },
  );
  assert.equal(code.diagnostics?.length ?? 0, 0);
  const exports = {};
  runInNewContext(code.outputText, {
    exports,
    ref: (value) => ({ value }),
    reactive: (value) => value,
    useLoading: (initial) => {
      const loading = { value: initial };
      return { loading, setLoading: (value) => { loading.value = value; } };
    },
    getGeneratorList: (args) => {
      const result = deferred();
      lists.push({ args, ...result });
      return result.promise;
    },
    getGeneratorSourceTables: (args) => {
      const result = deferred();
      sources.push({ args, ...result });
      return result.promise;
    },
    onBeforeUnmount: (callback) => { destroyed = callback; },
  }, { filename });
  return { ui: exports.controls, lists, sources, destroy: () => destroyed() };
}

function list(pageNo, id) {
  return { data: { lists: [{ id, table_name: 'test_' + id }], count: 1, pageNo } };
}

function source(pageNo, name) {
  return { data: { lists: [{ table_name: name }], count: 1, pageNo } };
}

test('main generator list keeps newest search/page even if old request resolves last', async () => {
  const f = fixture();
  const initial = f.lists[0];
  const old = f.ui.fetchData(2);
  f.ui.keyword.value = 'new';
  const latest = f.ui.fetchData(1);
  assert.equal(f.lists.length, 3);
  assert.equal(f.lists[2].args.keyword, 'new');
  f.lists[2].resolve(list(1, 202)); await latest;
  f.lists[1].resolve(list(2, 101)); await old;
  initial.resolve(list(1, 10)); await Promise.resolve();
  assert.equal(f.ui.renderData.value[0].id, 202);
  assert.equal(f.ui.pagination.current, 1);
  assert.equal(f.ui.loading.value, false);
});

test('source-table picker keeps last filter and discards late result after dialog closes', async () => {
  const f = fixture();
  f.lists[0].resolve(list(1, 10)); await Promise.resolve();
  const first = f.ui.openSourceTables();
  f.ui.sourceKeyword.value = 'orders';
  const latest = f.ui.fetchSourceTables(1);
  f.sources[1].resolve(source(1, 'orders')); await latest;
  f.sources[0].resolve(source(1, 'users')); await first;
  assert.equal(f.ui.sourceTables.value[0].table_name, 'orders');
  const closing = f.ui.fetchSourceTables(3);
  f.ui.closeSourceTables();
  f.sources[2].resolve(source(3, 'late')); await closing;
  assert.equal(f.ui.sourceVisible.value, false);
  assert.equal(f.ui.sourceTables.value.length, 0);
  assert.equal(f.ui.sourceLoading.value, false);
});

test('a failed latest list request keeps previously loaded authorized rows, not a late old page', async () => {
  const f = fixture();
  f.lists[0].resolve(list(1, 10));
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(f.ui.renderData.value[0].id, 10);
  const older = f.ui.fetchData(2);
  const latest = f.ui.fetchData(3);
  f.lists[2].reject(new Error('latest request failed'));
  await latest;
  f.lists[1].resolve(list(2, 50));
  await older;
  assert.equal(f.ui.renderData.value[0].id, 10);
  assert.equal(f.ui.pagination.current, 1);
  assert.equal(f.ui.loading.value, false);
});

test('unmount invalidates outstanding list and source-table responses', async () => {
  const f = fixture();
  const sourceRequest = f.ui.openSourceTables();
  f.destroy();
  f.lists[0].resolve(list(1, 77));
  f.sources[0].resolve(source(1, 'late_table'));
  await sourceRequest;
  await f.lists[0].promise;
  await Promise.resolve();
  assert.equal(f.ui.renderData.value.length, 0);
  assert.equal(f.ui.sourceTables.value.length, 0);
});

test('real Element Plus import source dialog exposes cancellation of in-flight requests', () => {
  const { template } = descriptor;
  assert.match(template?.content ?? '', /@close="closeSourceTables"/);
  assert.match(template?.content ?? '', /@click="closeSourceTables"/);
});
