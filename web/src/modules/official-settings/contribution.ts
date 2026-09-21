import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.settings',
  routes: [{
    path: '/system',
    name: 'officialSettingsRoot',
    component: DEFAULT_LAYOUT,
    meta: {
      requiresAuth: true,
      tenantModuleKey: 'official.settings',
      requiredPermissions: 'official.settings.read',
    },
    children: [{
      path: 'settings',
      name: 'OfficialSettings',
      component: () => import('./RuntimePage.vue'),
      meta: {
        locale: 'Settings',
        requiresAuth: true,
        tenantModuleKey: 'official.settings',
        requiredPermissions: 'official.settings.read',
      },
    }],
  }],
};

export default contribution;
