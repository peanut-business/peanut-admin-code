import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const require = createRequire(new URL('../../package.json', import.meta.url));
const ts = require('typescript');
const compiler = require('vue/compiler-sfc');
const file = fileURLToPath(new URL('../../src/views/dev-tools/code/index.vue', import.meta.url));
const { descriptor, errors } = compiler.parse(readFileSync(file, 'utf8'), { filename: file });
assert.equal(errors.length, 0);
const script = descriptor.scriptSetup?.content ?? '';
const start = script.indexOf('  const previewVisible = ref(false);');
const end = script.indexOf('  const copyPreview = async ', start);
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
  let onDestroy;
  const scriptToExecute = ts.transpileModule(
    actual + '\nexports.controls = { previewVisible, previewFiles, previewActiveKey, previewLoading, previewError, previewRecord, closePreview, openPreview, retryPreview };',
    { fileName: file + '.ts', compilerOptions: {
      target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS,
    } }
  );
  assert.equal(scriptToExecute.diagnostics?.length ?? 0, 0);
  const exports = {};
  runInNewContext(scriptToExecute.outputText, {
    exports,
    ref: (value) => ({ value }),
    onBeforeUnmount: (callback) => { onDestroy = callback; },
    previewGenerator: (id) => {
      const request = deferred();
      requests.push({ id, ...request });
      return request.promise;
    },
  }, { filename: file });
  return { ui: exports.controls, requests, destroy: () => onDestroy() };
}
const row = (id) => ({ id });
const output = (path) => ({ path, content: path, language: 'ts', operation: 'create' });

test('real Standalone generator preview source ignores late result of previously selected table', async () => {
  const f = fixture();
  const old = f.ui.openPreview(row(1));
  const latest = f.ui.openPreview(row(2));
  assert.deepEqual(f.requests.map((r) => r.id), [1, 2]);
  assert.equal(f.ui.previewLoading.value, true);
  f.requests[1].resolve({ data: [output('new-table.ts')] });
  await latest;
  f.requests[0].resolve({ data: [output('older-table.ts')] });
  await old;
  assert.equal(f.ui.previewFiles.value[0].path, 'new-table.ts');
  assert.equal(f.ui.previewActiveKey.value, 'new-table.ts');
  assert.equal(f.ui.previewLoading.value, false);
});

test('closing the dialog cancels ownership of in-flight successful and failed responses', async () => {
  const f = fixture();
  const pending = f.ui.openPreview(row(3));
  f.ui.previewVisible.value = false;
  f.ui.closePreview();
  f.requests[0].resolve({ data: [output('late.ts')] });
  await pending;
  assert.equal(f.ui.previewFiles.value.length, 0);
  assert.equal(f.ui.previewError.value, '');
  assert.equal(f.ui.previewRecord.value, null);
  assert.equal(f.ui.previewLoading.value, false);
});

test('a failed preview is not an empty successful generation, and user retry loads the same input', async () => {
  const f = fixture();
  const pending = f.ui.openPreview(row(4));
  f.requests[0].reject(new Error('synthetic endpoint rejected'));
  await pending;
  assert.match(f.ui.previewError.value, /预览读取失败/);
  assert.equal(f.ui.previewFiles.value.length, 0);
  assert.equal(f.ui.previewLoading.value, false);
  f.ui.retryPreview();
  assert.equal(f.requests.length, 2);
  assert.equal(f.requests[1].id, 4);
  f.requests[1].resolve({ data: [output('retry-success.ts')] });
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(f.ui.previewError.value, '');
  assert.equal(f.ui.previewLoading.value, false);
  assert.equal(f.ui.previewFiles.value[0].path, 'retry-success.ts');
});

test('unmount invalidates in-flight request without altering private preview state afterwards', async () => {
  const f = fixture();
  const pending = f.ui.openPreview(row(5));
  f.destroy();
  f.requests[0].reject(new Error('late request failed'));
  await pending;
  assert.equal(f.ui.previewRecord.value, null);
  assert.equal(f.ui.previewError.value, '');
  assert.equal(f.ui.previewLoading.value, false);
});

test('native ElementPlus preview dialog displays pending, error/retry and files as exclusive states', () => {
  const compiled = compiler.compileTemplate({ source: template, filename: file, id: 'generator-preview' });
  assert.equal(compiled.errors.length, 0);
  assert.match(template, /@close="closePreview"/);
  assert.match(template, /<el-skeleton v-if="previewLoading"/);
  assert.match(template, /v-else-if="previewError"/);
  assert.match(template, /@click="retryPreview"/);
  assert.match(template, /v-else-if="previewFiles.length"/);
  assert.match(template, /file.operation === 'conflict'/);
});
