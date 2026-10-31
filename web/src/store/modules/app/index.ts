import { defineStore } from 'pinia';
import { ElNotification, type NotificationHandle } from 'element-plus';
import type { RouteRecordRaw } from 'vue-router';
import { enabledTenantModulesFromRoutes } from '@peanut-admin/vue';
import defaultSettings from '@/config/settings.json';
import { getMenuList } from '@/api/user';
import type { AppSettings, AppState, ServerMenuRecord } from './types';
import mapServerMenu from './server-menu';
import { shellPresentation, shellUserOptions } from '@/peanut.overrides';
import { shellPresets, type ShellPresentation } from '@/layout/shell-presets';

const useAppStore = defineStore('app', {
  state: (): AppState => ({
    ...defaultSettings,
    theme:
      localStorage.getItem('element-theme') ??
      shellPresentation.theme.mode ??
      defaultSettings.theme,
  }),

  getters: {
    shellAppearance(state: AppState): ShellPresentation {
      const preset =
        shellUserOptions.presets.length === 1
          ? shellUserOptions.presets[0]
          : state.shellPreset;
      const selected: ShellPresentation =
        preset && shellUserOptions.presets.includes(preset)
          ? shellPresets[preset]
          : shellPresentation;
      return {
        ...shellPresentation,
        preset: selected.preset,
        layout:
          shellUserOptions.layouts.length === 1
            ? shellUserOptions.layouts[0]
            : state.shellLayout &&
              shellUserOptions.layouts.includes(state.shellLayout)
            ? state.shellLayout
            : shellUserOptions.layouts.length === 0
            ? shellPresentation.layout
            : selected.layout,
        navigationMode:
          shellUserOptions.navigationModes.length === 1
            ? shellUserOptions.navigationModes[0]
            : state.shellNavigationMode &&
              shellUserOptions.navigationModes.includes(
                state.shellNavigationMode
              )
            ? state.shellNavigationMode
            : shellUserOptions.navigationModes.length === 0
            ? shellPresentation.navigationMode
            : selected.navigationMode,
        theme: {
          ...selected.theme,
          ...shellPresentation.theme,
          colors: {
            ...selected.theme.colors,
            ...shellPresentation.theme.colors,
          },
          mode:
            shellUserOptions.modes.length === 1
              ? shellUserOptions.modes[0]
              : shellUserOptions.modes.includes(state.theme as 'light' | 'dark')
              ? (state.theme as 'light' | 'dark')
              : shellPresentation.theme.mode ?? 'light',
          density:
            shellUserOptions.densities.length === 1
              ? shellUserOptions.densities[0]
              : state.shellDensity &&
                shellUserOptions.densities.includes(state.shellDensity)
              ? state.shellDensity
              : shellUserOptions.densities.length === 0
              ? shellPresentation.theme.density ?? 'comfortable'
              : selected.theme.density ?? 'comfortable',
        },
      };
    },
    appCurrentSetting(state: AppState): AppState {
      return { ...state };
    },
    appDevice(state: AppState) {
      return state.device;
    },
    appAsyncMenus(state: AppState): RouteRecordRaw[] {
      return state.serverMenu;
    },
  },

  actions: {
    selectShellPreset(preset: 'admin' | 'workspace') {
      if (!shellUserOptions.presets.includes(preset)) return;
      const selected: ShellPresentation = shellPresets[preset];
      this.updateSettings({
        shellPreset: preset,
        shellLayout: shellUserOptions.layouts.includes(selected.layout)
          ? selected.layout
          : undefined,
        shellNavigationMode: shellUserOptions.navigationModes.includes(
          selected.navigationMode
        )
          ? selected.navigationMode
          : undefined,
        shellDensity: shellUserOptions.densities.includes(
          selected.theme.density ?? 'comfortable'
        )
          ? selected.theme.density ?? 'comfortable'
          : undefined,
        menuCollapse: false,
      });
    },
    // Update presentation settings; runtime menus use setServerMenu/clearServerMenu.
    updateSettings(partial: Partial<AppSettings>) {
      this.$patch(partial);
    },

    // Change theme color
    toggleTheme(dark: boolean) {
      if (!shellUserOptions.modes.includes(dark ? 'dark' : 'light')) return;
      localStorage.setItem('element-theme', dark ? 'dark' : 'light');
      if (dark) {
        this.theme = 'dark';
        document.documentElement.classList.add('dark');
      } else {
        this.theme = 'light';
        document.documentElement.classList.remove('dark');
      }
    },
    toggleDevice(device: string) {
      this.device = device;
    },
    toggleMenu(value: boolean) {
      this.hideMenu = value;
    },
    setServerMenu(menu: ServerMenuRecord[]) {
      this.serverMenu = mapServerMenu(menu);
      this.enabledTenantModules = enabledTenantModulesFromRoutes(
        this.serverMenu
      );
      this.serverMenuLoaded = true;
    },
    async fetchServerMenuConfig() {
      let notifyInstance: NotificationHandle | null = null;
      try {
        notifyInstance = ElNotification.info({
          message: 'loading',
          showClose: true,
        });
        const { data } = await getMenuList();
        this.setServerMenu(data);
        notifyInstance.close();
        notifyInstance = ElNotification.success({
          message: 'success',
          showClose: true,
        });
      } catch (error) {
        this.serverMenu = [];
        this.serverMenuLoaded = true;
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        notifyInstance?.close();
        notifyInstance = ElNotification.error({
          message: 'error',
          showClose: true,
        });
      }
    },
    clearServerMenu() {
      this.serverMenu = [];
      this.enabledTenantModules = [];
      this.serverMenuLoaded = false;
    },
  },
});

export default useAppStore;
