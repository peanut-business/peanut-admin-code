import type {
  Router,
  LocationQueryRaw,
  RouteLocationNormalized,
} from 'vue-router';
import NProgress from 'nprogress'; // progress bar

import { useUserStore } from '@/store';
import { isLogin } from '@/utils/auth';
import { WHITE_LIST } from '../constants';

function loginQuery(to: RouteLocationNormalized): LocationQueryRaw {
  const query: LocationQueryRaw = { ...to.query };
  if (to.name && to.name !== 'login') {
    query.redirect = String(to.name);
  } else {
    delete query.redirect;
  }
  return query;
}

export default function setupUserLoginInfoGuard(router: Router) {
  router.beforeEach(async (to, from, next) => {
    NProgress.start();
    const userStore = useUserStore();
    if (WHITE_LIST.some((route) => route.name === to.name)) {
      next();
      return;
    }

    if (isLogin()) {
      if (userStore.role) {
        next();
      } else {
        try {
          await userStore.info();
          next();
        } catch (error) {
          await userStore.logout();
          next({
            name: 'login',
            query: loginQuery(to),
          });
        }
      }
    } else {
      next({
        name: 'login',
        query: loginQuery(to),
      });
    }
  });
}
