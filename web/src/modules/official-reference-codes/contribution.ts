import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.reference-codes',
  routes: [{
    path: '/system',
    name: 'officialReferenceCodesRoot',
    component: DEFAULT_LAYOUT,
    meta: {
      requiresAuth: true,
      tenantModuleKey: 'official.reference-codes',
      requiredPermissions: 'official.reference-codes.read',
    },
    children: [{
      path: 'reference-codes',
      name: 'OfficialReferenceCodes',
      component: () => import('./RuntimePage.vue'),
      meta: {
        locale: 'Reference codes',
        requiresAuth: true,
        tenantModuleKey: 'official.reference-codes',
        requiredPermissions: 'official.reference-codes.read',
      },
    }],
  }],
};

export default contribution;
