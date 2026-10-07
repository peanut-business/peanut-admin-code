import { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';
import { useAppStore, useUserStore } from '@/store';
import { REDIRECT_ROUTE_NAME } from '@/router/constants';
import { permissionEvaluator } from '@/core/runtime';
import { evaluateRequiredPermissions } from '@peanut-admin/vue';

export function hasPermission(
  requiredPermissions: string | string[],
  grantedPermissions?: string[]
): boolean {
  const permissions = grantedPermissions ?? useUserStore().permissions;
  return evaluateRequiredPermissions(
    requiredPermissions,
    permissions,
    permissionEvaluator
  );
}

function hasRouteName(
  routes: RouteRecordRaw[],
  routeName: string,
  tenantModuleKey?: string
): boolean {
  const pending = [...routes];
  while (pending.length) {
    const route = pending.shift();
    if (
      String(route?.name || '') === routeName &&
      (!tenantModuleKey || route?.meta?.tenantModuleKey === tenantModuleKey)
    )
      return true;
    if (route?.children?.length) pending.push(...route.children);
  }
  return false;
}

export default function usePermission() {
  const userStore = useUserStore();
  const appStore = useAppStore();
  return {
    accessRouter(route: RouteLocationNormalized | RouteRecordRaw) {
      if (!route.meta?.requiresAuth) return true;

      if (appStore.menuFromServer) {
        const routeName = String(route.name || '');
        if (
          routeName === REDIRECT_ROUTE_NAME ||
          routeName === 'redirectWrapper'
        ) {
          return true;
        }
        const authorizedName =
          route.meta?.hideInMenu && route.meta?.activeMenu
            ? route.meta.activeMenu
            : routeName;
        return (
          authorizedName !== '' &&
          hasRouteName(
            appStore.appAsyncMenus,
            authorizedName,
            route.meta?.tenantModuleKey
          )
        );
      }

      return (
        !route.meta?.roles ||
        route.meta?.roles?.includes('*') ||
        route.meta?.roles?.includes(userStore.role)
      );
    },
    findFirstPermissionRoute(routers: RouteRecordRaw[], role = 'admin') {
      const pending = [...routers];
      while (pending.length) {
        const route = pending.shift();
        if (route) {
          if (route.children?.length) {
            pending.unshift(...route.children);
          } else {
            const roles = route.meta?.roles;
            const roleAllowed =
              !roles ||
              roles.includes('*') ||
              roles.some((candidate) => candidate === role);
            if ((appStore.menuFromServer || roleAllowed) && route.name) {
              return { name: route.name };
            }
          }
        }
      }
      return null;
    },
    hasPermission,
  };
}
