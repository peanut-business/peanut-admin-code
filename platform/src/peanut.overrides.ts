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
import { reactive } from 'vue';

export const platformUserOptions = {
  presets: ['admin', 'workspace'] as readonly ('admin' | 'workspace')[],
  layouts: ['admin', 'workspace'] as readonly ShellLayout[],
  navigationModes: ['tree', 'grouped', 'top'] as readonly ShellNavigationMode[],
  densities: ['comfortable', 'compact'] as readonly (
    | 'comfortable'
    | 'compact'
  )[],
  modes: ['light', 'dark'] as readonly ('light' | 'dark')[],
};

export const platformPresets = {
  admin: { layout: 'admin', navigationMode: 'tree', density: 'comfortable' },
  workspace: {
    layout: 'workspace',
    navigationMode: 'grouped',
    density: 'compact',
  },
} as const;

/** Trusted application source; this does not select permissions or API providers. */
export const platformPresentation = reactive<{
  preset: 'admin' | 'workspace';
  brand: string;
  theme: ShellThemeInput;
  layout: ShellLayout;
  navigationMode: ShellNavigationMode;
}>({
  preset: platformUserOptions.presets[0] ?? 'admin',
  brand: 'PEANUT 平台',
  theme: {
    mode: platformUserOptions.modes[0] ?? 'light',
    density: platformUserOptions.densities[0] ?? 'comfortable',
  },
  layout: platformUserOptions.layouts[0] ?? 'admin',
  navigationMode: platformUserOptions.navigationModes[0] ?? 'tree',
});

export function selectPlatformPreset(preset: 'admin' | 'workspace') {
  if (!platformUserOptions.presets.includes(preset)) return;
  const selected = platformPresets[preset];
  platformPresentation.preset = preset;
  if (platformUserOptions.layouts.includes(selected.layout))
    platformPresentation.layout = selected.layout;
  if (platformUserOptions.navigationModes.includes(selected.navigationMode))
    platformPresentation.navigationMode = selected.navigationMode;
  if (platformUserOptions.densities.includes(selected.density))
    platformPresentation.theme.density = selected.density;
}

selectPlatformPreset(platformPresentation.preset);

const presets: readonly AdminOverride[] = [];
const overrides: readonly AdminOverride[] = [];
const registry = createAdminOverrideRegistry({
  slots: ADMIN_SHELL_OVERRIDE_SLOTS,
  presets,
  overrides,
});
export const platformShell = resolveWorkspaceShell(registry, 'platform');
