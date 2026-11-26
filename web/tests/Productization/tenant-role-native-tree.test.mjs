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

test('tenant role tree authorizes exclusive native keys and reads only with tenant core.menu.read', () => {
  assert.match(source, /getMenuAll\(\)/);
  assert.match(source, /hasPermission\('core\.menu\.read', undefined, userStore\.permissions\)/);
  assert.match(source, /node-key="menu_key"/);
  assert.match(source, /:data="tenantAssignableMenuTree"/);
  assert.match(source, /:props="\{ label: 'name', children: 'children', disabled: 'disabled' \}"/);
  assert.match(source, /菜单角色权限只读树/);
  assert.match(source, /show-checkbox[\s\S]*?check-strictly/);
  assert.match(source, /@check="checkNativeMenuPermission"/);
  assert.match(source, /toggleNativeRoleMenuPermission\(/);
});

test('native editable tree retains role CAS and entitlement checks from original Identity service', () => {
  assert.match(source, /getRolePermissions\(record\.id\)/);
  assert.match(source, /getRolePermissionCatalog/);
  assert.match(source, /generation === authGeneration && authVisible\.value/);
  assert.match(source, /permission_keys: \[\.\.\.new Set\(authForm\.permission_keys\)\]/);
  assert.match(source, /authRoleKey\.value === 'core\.tenant-owner'/);
  const api = readFileSync(root + 'src/api/system/role.ts', 'utf8');
  assert.match(api, /replaceRolePermissions\(data: RolePermissionWrite\)/);
  assert.match(api, /axios\.put<RolePermissionSnapshot>\('\/adminapi\/role\/permissions', data\)/);
});
