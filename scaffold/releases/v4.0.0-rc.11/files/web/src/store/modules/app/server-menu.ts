import type { RouteRecordRaw } from 'vue-router';
import appClientMenus from '@/router/app-menus';
import type { ServerMenuRecord } from './types';

function normalizePath(path: string): string {
  const normalized = `/${path}`.replace(/\/{2,}/g, '/');
  return normalized.length > 1 ? normalized.replace(/\/$/, '') : normalized;
}

function resolvePath(parentPath: string, path: string): string {
  if (path.startsWith('/')) return normalizePath(path);
  return normalizePath(`${parentPath}/${path}`);
}

const staticRoutesByPath = new Map<string, RouteRecordRaw>();

function indexStaticRoutes(routes: RouteRecordRaw[], parentPath = ''): void {
  routes.forEach((route) => {
    const fullPath = resolvePath(parentPath, route.path);
    staticRoutesByPath.set(fullPath, route);
    if (route.children?.length) {
      indexStaticRoutes(route.children, fullPath);
    }
  });
}

indexStaticRoutes(appClientMenus as RouteRecordRaw[]);

/**
 * 服务端菜单只作为授权与可见性来源；组件始终来自已注册的静态路由。
 * 无静态路由映射的服务端节点会被忽略，避免运行时加载任意 component 字符串。
 */
export default function mapServerMenu(
  menus: ServerMenuRecord[]
): RouteRecordRaw[] {
  return menus
    .map((menu): RouteRecordRaw | null => {
      const staticRoute = staticRoutesByPath.get(
        normalizePath(menu.paths || '')
      );
      if (!staticRoute) return null;

      const children = mapServerMenu(menu.children || []);
      if (staticRoute.children?.length && children.length === 0) return null;

      return {
        ...staticRoute,
        path: menu.module_key
          ? normalizePath(menu.paths || staticRoute.path)
          : staticRoute.path,
        meta: {
          ...staticRoute.meta,
          icon: menu.icon || staticRoute.meta?.icon,
          tenantModuleKey:
            menu.module_key && menu.module_key !== 'core'
              ? menu.module_key
              : staticRoute.meta?.tenantModuleKey,
          requiredPermissions:
            menu.required_permission || staticRoute.meta?.requiredPermissions,
          title: menu.name || staticRoute.meta?.title,
          hideInMenu:
            Number(menu.is_show ?? 1) === 0 ||
            (!menu.module_key && staticRoute.meta?.hideInMenu === true),
        },
        children,
      } as RouteRecordRaw;
    })
    .filter((route): route is RouteRecordRaw => route !== null);
}
