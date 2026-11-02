import type { AdminOverride } from '@peanut-admin/vue';

/**
 * Optional application choices. Managed presentation defaults live in core/presentation.
 * Export platformUserOptions or platformPresentation only when this application customizes them.
 */
export const PLATFORM_PRESETS: readonly AdminOverride[] = Object.freeze([]);
export const PLATFORM_OVERRIDES: readonly AdminOverride[] = Object.freeze([]);
