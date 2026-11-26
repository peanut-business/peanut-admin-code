import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import { transformWithEsbuild } from 'vite';

const filename = fileURLToPath(new URL('../../src/views/system/role/native-role-menu-tree.ts', import.meta.url));
const { code } = await transformWithEsbuild(readFileSync(filename, 'utf8'), filename, {
  loader: 'ts', format: 'cjs',
});
const module = { exports: {} };
runInNewContext(code, { module, exports: module.exports });
const { buildNativeRoleMenuTree, checkedNativeRoleMenuKeys, toggleNativeRoleMenuPermission } = module.exports;
const record = (key, perms, type = 'C', children = []) =>
  ({ menu_key: key, type, name: key, perms, children });
const plain = (value) => JSON.parse(JSON.stringify(value));

test('tree shows M/C/A but never grants group, unauthorised or shared code implicitly', () => {
  const menu = [record('group', '', 'M', [
    record('page', 'core.role.read', 'C', [record('button', 'core.role.update', 'A')]),
    record('settings', 'peanut.settings.read', 'C'),
    record('settings-other', 'peanut.settings.read', 'C'),
    record('missing-license', 'peanut.not-licensed.read', 'C'),
  ])];
  const result = buildNativeRoleMenuTree(menu, new Set(['core.role.read', 'core.role.update', 'peanut.settings.read']), false);
  assert.equal(result[0].disabled, true);
  assert.equal(result[0].children[0].disabled, false);
  assert.equal(result[0].children[0].children[0].disabled, false);
  assert.equal(result[0].children[1].disabled, true);
  assert.equal(result[0].children[1].sharedCount, 2);
  assert.equal(result[0].children[2].disabled, true);
  assert.equal(result[0].children[3].disabled, true);
  assert.ok(!('disabled' in menu[0]), 'native menu records must remain immutable');
});

test('role tree grant/revoke preserves unrelated and no-menu rights', () => {
  const node = buildNativeRoleMenuTree([record('page', 'core.role.read')], new Set(['core.role.read']), false)[0];
  const previous = ['platform.isolation.forbidden', 'core.background.operation'];
  assert.deepEqual(plain(toggleNativeRoleMenuPermission(previous, node, true)),
    [...previous, 'core.role.read']);
  assert.deepEqual(plain(toggleNativeRoleMenuPermission([...previous, 'core.role.read'], node, false)), previous);
  assert.deepEqual(previous, ['platform.isolation.forbidden', 'core.background.operation']);
});

test('shared keys and folders cannot be changed through a single tree checkbox', () => {
  const nodes = buildNativeRoleMenuTree([
    record('group', '', 'M'), record('a', 'core.same'), record('b', 'core.same'),
  ], new Set(['core.same']), false);
  for (const node of nodes)
    assert.deepEqual(plain(toggleNativeRoleMenuPermission(['core.same', 'core.other'], node, false)),
      ['core.same', 'core.other']);
  assert.deepEqual(plain(checkedNativeRoleMenuKeys(nodes, new Set(['core.same']))), ['a', 'b']);
});

test('owner/fenced viewer mode and license ceiling make all permission nodes non-writable', () => {
  const node = buildNativeRoleMenuTree([record('page', 'core.role.read')],
    new Set(['core.role.read']), true)[0];
  assert.equal(node.disabled, true);
  assert.deepEqual(plain(toggleNativeRoleMenuPermission([], node, true)), []);
  const limited = buildNativeRoleMenuTree([record('page', 'core.role.read')], new Set(), false)[0];
  assert.equal(limited.disabled, true);
});

test('checked state derives exclusively from saved native role permission keys', () => {
  const tree = buildNativeRoleMenuTree([record('group', '', 'M', [
    record('page', 'core.role.read'), record('button', 'core.role.write', 'A'),
  ])], new Set(['core.role.read', 'core.role.write']), false);
  assert.deepEqual(plain(checkedNativeRoleMenuKeys(tree, new Set(['core.role.write']))), ['button']);
  assert.deepEqual(plain(checkedNativeRoleMenuKeys(tree, new Set())), []);
});
