import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { parse, compileTemplate } from 'vue/compiler-sfc';

const root = fileURLToPath(new URL('../../', import.meta.url));
const filename = root + 'src/views/system/role/index.vue';
const source = readFileSync(filename, 'utf8');
const { descriptor, errors } = parse(source, { filename });
assert.deepEqual(errors, []);
const template = compileTemplate({ source: descriptor.template.content, filename, id: 'tenant-role-tree' });

test('tenant role management is the registered /app/roles route; Instance menu page stays standalone-only', () => {
  assert.deepEqual(template.errors, []);
  const route = readFileSync(root + 'src/router/routes/modules/system.ts', 'utf8');
  assert.match(route, /path: '\/app\/roles'/);
  assert.match(route, /name: 'tenant\.roles\.list'/);
  assert.match(route, /name: 'SystemMenu'[\s\S]*?instanceTool: true/);
});

test('tenant role dialog reads licensed official menu tree only with native core.menu.read', () => {
  assert.match(source, /getMenuAll\(\)/);
  assert.match(source, /hasPermission\('core\.menu\.read', undefined, userStore\.permissions\)/);
  assert.match(source, /node-key="menu_key"/);
  assert.match(source, /:data="tenantMenuTree"/);
  assert.match(source, /:props="\{ label: 'name', children: 'children' \}"/);
  assert.match(source, /菜单角色权限只读树/);
  assert.doesNotMatch(source, /<el-tree[\s\S]*?show-checkbox[\s\S]*?<\/el-tree>/);
});

test('read-only tree does not alter current role permissions CAS or bypass owner/entitlement checks', () => {
  assert.match(source, /getRolePermissions\(record\.id\)/);
  assert.match(source, /getRolePermissionCatalog/);
  assert.match(source, /generation === authGeneration && authVisible\.value/);
  assert.match(source, /permission_keys: \[\.\.\.new Set\(authForm\.permission_keys\)\]/);
  assert.match(source, /authRoleKey\.value === 'core\.tenant-owner'/);
  const api = readFileSync(root + 'src/api/system/role.ts', 'utf8');
  assert.match(api, /replaceRolePermissions\(data: RolePermissionWrite\)/);
  assert.match(api, /axios\.put<RolePermissionSnapshot>\('\/adminapi\/role\/permissions', data\)/);
});
