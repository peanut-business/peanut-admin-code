import type { RouteLocationNormalized } from 'vue-router';
import { defineStore } from 'pinia';
import {
  tabFromRoute,
  type ShellTab as TagProps,
  type ShellTabState as TabBarState,
} from '@peanut-admin/ui-vue';
import {
  DEFAULT_ROUTE,
  REDIRECT_ROUTE_NAME,
} from '@/router/constants';
import { isString } from '@/utils/is';

const BAN_LIST = [REDIRECT_ROUTE_NAME];

const useAppStore = defineStore('tabBar', {
  state: (): TabBarState => ({
    cacheTabList: new Set([DEFAULT_ROUTE.fullPath]),
    tagList: [DEFAULT_ROUTE],
  }),

  getters: {
    getTabList(): TagProps[] {
      return this.tagList;
    },
    getCacheList(): string[] {
      return Array.from(this.cacheTabList);
    },
  },

  actions: {
    updateTabList(route: RouteLocationNormalized) {
      if (BAN_LIST.includes(route.name as string)) return;
      if (this.tagList.some((tag) => tag.fullPath === route.fullPath)) return;
      this.tagList.push(tabFromRoute(route));
      if (!route.meta.ignoreCache) {
        this.cacheTabList.add(route.fullPath);
      }
    },
    deleteTag(idx: number, tag: TagProps) {
      this.tagList.splice(idx, 1);
      if (!tag.ignoreCache) this.cacheTabList.delete(tag.fullPath);
    },
    addCache(fullPath: string) {
      if (isString(fullPath) && fullPath !== '') this.cacheTabList.add(fullPath);
    },
    deleteCache(tag: TagProps) {
      this.cacheTabList.delete(tag.fullPath);
    },
    freshTabList(tags: TagProps[]) {
      this.tagList = tags;
      this.cacheTabList.clear();
      // 要先判断ignoreCache
      this.tagList
        .filter((el) => !el.ignoreCache)
        .map((el) => el.fullPath)
        .forEach((x) => this.cacheTabList.add(x));
    },
    resetTabList() {
      this.tagList = [DEFAULT_ROUTE];
      this.cacheTabList.clear();
      this.cacheTabList.add(DEFAULT_ROUTE.fullPath);
    },
  },
});

export default useAppStore;
