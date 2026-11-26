import type { MenuRecord } from '@/api/system/menu';

export type RoleMenuPermissionNode = MenuRecord & {
  disabled: boolean;
  sharedCount: number;
  children: RoleMenuPermissionNode[];
};

/**
 * Native tree only projects formally granted permission keys.
 * Directory nodes never grant their descendants and multi-menu shared
 * permission codes are deliberately edited only in the keyed grant list.
 */
export function buildNativeRoleMenuTree(
  menus: readonly MenuRecord[],
  assignable: ReadonlySet<string>,
  readOnly: boolean
): RoleMenuPermissionNode[] {
  const occurrences = new Map<string, number>();
  const visit = (items: readonly MenuRecord[]): void => {
    for (const item of items) {
      if (item.type !== 'M' && item.perms)
        occurrences.set(item.perms, (occurrences.get(item.perms) ?? 0) + 1);
      visit(item.children ?? []);
    }
  };
  visit(menus);
  const copy = (items: readonly MenuRecord[]): RoleMenuPermissionNode[] =>
    items.map((item) => {
      const count = item.perms ? (occurrences.get(item.perms) ?? 0) : 0;
      return {
        ...item,
        disabled: readOnly || item.type === 'M' ||
          !item.perms || !assignable.has(item.perms) || count !== 1,
        sharedCount: count,
        children: copy(item.children ?? []),
      };
    });
  return copy(menus);
}

export function checkedNativeRoleMenuKeys(
  tree: readonly RoleMenuPermissionNode[],
  grantedKeys: ReadonlySet<string>
): string[] {
  const ids: string[] = [];
  const visit = (nodes: readonly RoleMenuPermissionNode[]): void => {
    for (const node of nodes) {
      if (node.type !== 'M' && node.perms && grantedKeys.has(node.perms))
        ids.push(node.menu_key);
      visit(node.children);
    }
  };
  visit(tree);
  return ids;
}

/** No unlisted or shared permissions are ever changed by a tree checkbox. */
export function toggleNativeRoleMenuPermission(
  granted: readonly string[],
  node: RoleMenuPermissionNode,
  checked: boolean
): string[] {
  if (node.disabled || node.type === 'M' || !node.perms || node.sharedCount !== 1)
    return [...granted];
  const keys = new Set(granted);
  if (checked) keys.add(node.perms);
  else keys.delete(node.perms);
  return [...keys];
}
