import {
  createAdminOverrideRegistry,
  type AdminOverride,
} from '@peanut-admin/vue';
import {
  ADMIN_SHELL_OVERRIDE_SLOTS,
  resolveWorkspaceShell,
  type ShellThemeInput,
  type ShellLayout,
  type ShellNavigationMode,
} from '@peanut-admin/ui-vue';

/** Trusted application source; this does not select permissions or API providers. */
export const platformPresentation: {
  brand: string;
  theme: ShellThemeInput;
  layout: ShellLayout;
  navigationMode: ShellNavigationMode;
} = {
  brand: 'PEANUT 平台',
  theme: {},
  layout: 'admin',
  navigationMode: 'tree',
};

const presets: readonly AdminOverride[] = [];
const overrides: readonly AdminOverride[] = [];
const registry = createAdminOverrideRegistry({
  slots: ADMIN_SHELL_OVERRIDE_SLOTS,
  presets,
  overrides,
});
export const platformShell = resolveWorkspaceShell(registry, 'platform');
