import type { PluginFrontendContribution } from '@peanut-admin/vue'
import { DEFAULT_LAYOUT } from '@/router/routes/base'

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.integration',
  routes: [{
    path: '/system',
    name: 'officialIntegrationRoot',
    component: DEFAULT_LAYOUT,
    meta: {
      requiresAuth: true,
      tenantModuleKey: 'official.integration',
      requiredPermissions: 'official.integration.access',
    },
    children: [{
      path: 'integration-security',
      name: 'official.integration.index',
      component: () => import('./RuntimePage.vue'),
      meta: {
        locale: '集成与安全',
        requiresAuth: true,
        tenantModuleKey: 'official.integration',
        requiredPermissions: 'official.integration.access',
      },
    }],
  }],
}

export default contribution
