import type {
  AdminModuleShellSlotContribution,
  ModuleFrontendContribution,
} from '@peanut-admin/vue';

export interface ModuleFrontendContribution extends ModuleFrontendContribution {
  shellSlots?: readonly AdminModuleShellSlotContribution[];
  locales?: Partial<Record<'en-US' | 'zh-CN', Record<string, string>>>;
}
