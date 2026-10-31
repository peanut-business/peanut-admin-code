import type {
  ShellLayout,
  ShellNavigationMode,
  ShellThemeInput,
} from '@peanut-admin/ui-vue';

/** Presentation only; menus, action bindings and identity remain host-owned. */
export interface ShellPresentation {
  layout: ShellLayout;
  navigationMode: ShellNavigationMode;
  theme: ShellThemeInput;
  brand?: { name?: string; logo?: string };
  breadcrumb: boolean;
}

export const shellPresets = {
  admin: {
    layout: 'admin',
    navigationMode: 'tree',
    theme: {},
    breadcrumb: true,
  },
  workspace: {
    layout: 'workspace',
    navigationMode: 'grouped',
    theme: { density: 'compact', colors: { primary: '#5367d8' } },
    breadcrumb: true,
  },
} satisfies Record<string, ShellPresentation>;
