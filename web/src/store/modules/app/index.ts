import { defineStore } from 'pinia';
import { ElNotification, type NotificationHandle } from 'element-plus';
import type { RouteRecordRaw } from 'vue-router';
import { enabledTenantModulesFromRoutes } from '@peanut-admin/vue';
import defaultSettings from '@/config/settings.json';
import { getMenuList } from '@/api/user';
import { shellPresentation, shellUserOptions } from '@/core/runtime';
import { shellPresets, type ShellPresentation } from '@/layout/shell-presets';
import type { AppSettings, AppState, ServerMenuRecord } from './types';
import mapServerMenu from './server-menu';

const presentationStorageKeys = {
  preset: 'peanut-shell-preset',
  layout: 'peanut-shell-layout',
  navigationMode: 'peanut-shell-navigation-mode',
  density: 'peanut-shell-density',
  tabBar: 'peanut-tab-bar',
  themeColor: 'peanut-theme-color',
} as const;

function readAllowedPreference<Value extends string>(
  key: string,
  choices: readonly Value[]
): Value | undefined {
  const value = localStorage.getItem(key);
  return choices.find((choice) => choice === value);
}

function writeAllowedPreference<Value extends string>(
  key: string,
  choices: readonly Value[],
  value: Value | undefined
) {
  if (value === undefined) {
    localStorage.removeItem(key);
  } else if (choices.includes(value)) {
    localStorage.setItem(key, value);
  }
}

function readStoredBoolean(key: string, fallback: boolean): boolean {
  const value = localStorage.getItem(key);
  if (value === 'true') return true;
  if (value === 'false') return false;
  return fallback;
}

/** Filters user preferences and preset defaults through the application's choices. */
function allowedAppearanceValue<Value extends string>(
  choices: readonly Value[],
  preferred: string | undefined,
  fallback: Value
): Value {
  if (choices.length === 0) return fallback;
  return (
    choices.find((value) => value === preferred) ??
    choices.find((value) => value === fallback) ??
    choices[0]
  );
}

const useAppStore = defineStore('app', {
  state: (): AppState => ({
    ...defaultSettings,
    shellPreset: readAllowedPreference(
      presentationStorageKeys.preset,
      shellUserOptions.presets
    ),
    shellLayout: readAllowedPreference(
      presentationStorageKeys.layout,
      shellUserOptions.layouts
    ),
    shellNavigationMode: readAllowedPreference(
      presentationStorageKeys.navigationMode,
      shellUserOptions.navigationModes
    ),
    shellDensity: readAllowedPreference(
      presentationStorageKeys.density,
      shellUserOptions.densities
    ),
    tabBar: readStoredBoolean(presentationStorageKeys.tabBar, defaultSettings.tabBar),
    themeColorOverride:
      localStorage.getItem(presentationStorageKeys.themeColor) ?? undefined,
    theme:
      localStorage.getItem('element-theme') ??
      shellPresentation.theme.mode ??
      defaultSettings.theme,
  }),

  getters: {
    shellAppearance(state: AppState): ShellPresentation {
      const preset = allowedAppearanceValue(
        shellUserOptions.presets,
        state.shellPreset,
        shellPresentation.preset
      );
      const selected: ShellPresentation =
        preset === shellPresentation.preset
          ? shellPresentation
          : shellPresets[preset];
      return {
        ...shellPresentation,
        preset: selected.preset,
        layout: allowedAppearanceValue(
          shellUserOptions.layouts,
          state.shellLayout,
          shellUserOptions.layouts.length === 0
            ? shellPresentation.layout
            : selected.layout
        ),
        navigationMode: allowedAppearanceValue(
          shellUserOptions.navigationModes,
          state.shellNavigationMode,
          shellUserOptions.navigationModes.length === 0
            ? shellPresentation.navigationMode
            : selected.navigationMode
        ),
        theme: {
          ...selected.theme,
          ...shellPresentation.theme,
          colors: {
            ...selected.theme.colors,
            primary:
              state.themeColorOverride ??
              selected.theme.colors?.primary ??
              state.themeColor,
            ...shellPresentation.theme.colors,
          },
          mode: allowedAppearanceValue(
            shellUserOptions.modes,
            state.theme,
            shellPresentation.theme.mode ?? 'light'
          ),
          density: allowedAppearanceValue(
            shellUserOptions.densities,
            state.shellDensity,
            (shellUserOptions.densities.length === 0
              ? shellPresentation.theme.density
              : selected.theme.density) ?? 'comfortable'
          ),
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
      if ('shellPreset' in partial) {
        writeAllowedPreference(
          presentationStorageKeys.preset,
          shellUserOptions.presets,
          partial.shellPreset
        );
      }
      if ('shellLayout' in partial) {
        writeAllowedPreference(
          presentationStorageKeys.layout,
          shellUserOptions.layouts,
          partial.shellLayout
        );
      }
      if ('shellNavigationMode' in partial) {
        writeAllowedPreference(
          presentationStorageKeys.navigationMode,
          shellUserOptions.navigationModes,
          partial.shellNavigationMode
        );
      }
      if ('shellDensity' in partial) {
        writeAllowedPreference(
          presentationStorageKeys.density,
          shellUserOptions.densities,
          partial.shellDensity
        );
      }
      if ('tabBar' in partial) {
        if (partial.tabBar === undefined) {
          localStorage.removeItem(presentationStorageKeys.tabBar);
        } else {
          localStorage.setItem(
            presentationStorageKeys.tabBar,
            String(partial.tabBar)
          );
        }
      }
    },

    setThemeColor(color: string) {
      localStorage.setItem(presentationStorageKeys.themeColor, color);
      this.themeColorOverride = color;
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
