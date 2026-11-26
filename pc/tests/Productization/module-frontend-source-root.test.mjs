import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, realpathSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { moduleFrontendEntries } from '../../../scripts/module-frontend-entries.mjs';
import { prepareModulePages } from '../../../uniapp/scripts/module-pages.mjs';

const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const fixture = (callback) => {
  const created = mkdtempSync(join(tmpdir(), 'peanut-frontend-source-'));
  const root = realpathSync(created);
  try { return callback(root); }
  finally { rmSync(root, { recursive: true, force: true }); }
};
const write = (root, relative, data) => {
  const target = resolve(root, relative);
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, data);
};
const moduleKey = 'peanut.sample';
const pcEntry = 'pc/modules/peanut-sample/contribution.ts';
const h5Entry = 'uniapp/src/modules/peanut-sample/contribution.ts';
const lock = (frontend = []) =>
  JSON.stringify({ schema_version: 1, modules: [{
    key: moduleKey, version: '1.0.1', frontend,
  }] });
const clientEntry = (client_key, entry) => ({ client_key, entry });

test('industrial Scaffold source starts PC and H5 with absent optional source and zero locked Modules', () => {
  assert.deepEqual(moduleFrontendEntries(sourceRoot, 'pc-web', true), []);
  assert.deepEqual(moduleFrontendEntries(sourceRoot, 'uniapp', true), []);
});

test('a checkout missing both source root and formal modules.lock cannot silently start', () => fixture((root) => {
  assert.throws(() => moduleFrontendEntries(root, 'pc-web', true),
    /modules.lock is required/);
  assert.throws(() => moduleFrontendEntries(root, 'uniapp', true),
    /modules.lock is required/);
}));

test('missing source root is not silently ignored when a locked frontend Module is declared', () => fixture((root) => {
  write(root, 'modules.lock', lock([clientEntry('pc-web', pcEntry)]));
  assert.throws(() => moduleFrontendEntries(root, 'pc-web', true), /Module source root is unavailable/);
  // The other client has no declared modules and may still start.
  assert.deepEqual(moduleFrontendEntries(root, 'uniapp', true), []);
}));

test('missing Module declaration file or frontend contribution must still fail closed', () => fixture((root) => {
  write(root, 'modules.lock', lock([clientEntry('pc-web', pcEntry)]));
  mkdirSync(resolve(root, 'server/app/modules'), { recursive: true });
  assert.deepEqual(moduleFrontendEntries(root, 'pc-web', true), []);
  assert.throws(() => moduleFrontendEntries(root, 'pc-web', false),
    /Module frontend contribution is unavailable/);
}));

test('declared development source is found for both PC and H5 from one author manifest', () => fixture((root) => {
  write(root, 'modules.lock', lock([clientEntry('pc-web', pcEntry), clientEntry('uniapp', h5Entry)]));
  write(root, 'server/app/modules/peanut/sample/module.json', JSON.stringify({
    key: moduleKey, frontend: { clients: {
      'pc-web': { entry: pcEntry },
      uniapp: { entry: h5Entry },
    } },
  }));
  write(root, pcEntry, 'export default {}\n');
  write(root, h5Entry, 'export default {}\n');
  assert.deepEqual(moduleFrontendEntries(root, 'pc-web', true), [resolve(root, pcEntry)]);
  assert.deepEqual(moduleFrontendEntries(root, 'uniapp', true), [resolve(root, h5Entry)]);
  assert.deepEqual(moduleFrontendEntries(root, 'pc-web', false), [resolve(root, pcEntry)]);
}));

test('a linked fake source directory cannot bypass real Module ownership', () => fixture((root) => {
  const actual = resolve(root, 'owner');
  mkdirSync(actual);
  mkdirSync(resolve(root, 'server/app'), { recursive: true });
  symlinkSync(actual, resolve(root, 'server/app/modules'), 'dir');
  write(root, 'modules.lock', lock());
  assert.throws(() => moduleFrontendEntries(root, 'pc-web', true),
    /must be a real directory/);
}));

test('native UniApp page preparation works with absent source and empty installed lock', async () => {
  const created = mkdtempSync(join(tmpdir(), 'peanut-uniapp-thin-'));
  const root = realpathSync(created);
  try {
    write(root, 'modules.lock', JSON.stringify({ schema_version: 1, modules: [] }));
    const uniapp = resolve(root, 'uniapp');
    // Native UniApp templates own an existing components directory.
    mkdirSync(resolve(uniapp, 'src/components'), { recursive: true });
    write(root, 'uniapp/src/pages.base.json', JSON.stringify({ pages: [{ path: 'pages/index/index' }] }));
    const plugin = await prepareModulePages({ mode: 'development', command: 'serve' }, uniapp);
    assert.equal(plugin.name, 'peanut-module-pages');
    const pages = JSON.parse(readFileSync(resolve(uniapp, 'src/pages.json'), 'utf8'));
    assert.deepEqual(pages.pages, [{ path: 'pages/index/index' }]);
    const slot = readFileSync(resolve(uniapp, 'src/components/ModuleSlot.vue'), 'utf8');
    assert.match(slot, /<view \/>/);
  } finally { rmSync(root, { recursive: true, force: true }); }
});
