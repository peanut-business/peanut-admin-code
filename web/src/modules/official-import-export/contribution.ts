import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.import-export',
  routes: [
    {
      path: '/system/configuration-transfer',
      name: 'ConfigurationTransfer',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'menu.system.configurationTransfer',
        requiresAuth: true,
        tenantModuleKey: 'official.import-export',
        requiredPermissions: 'official.import-export.configuration.export',
      },
      children: [
        {
          path: '',
          name: 'SystemConfigurationTransfer',
          component: () => import('./views/index.vue'),
          meta: {
            locale: 'menu.system.configurationTransfer',
            requiresAuth: true,
            tenantModuleKey: 'official.import-export',
            requiredPermissions: 'official.import-export.configuration.export',
          },
        },
      ],
    },
    {
      path: '/system/import-export-operations',
      name: 'OfficialImportExportOperationsRoot',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'Import/export operations',
        requiresAuth: true,
        tenantModuleKey: 'official.import-export',
        requiredPermissions: 'official.import-export.operations.read',
      },
      children: [{
        path: '',
        name: 'OfficialImportExportOperations',
        component: () => import('./OperationsPage.vue'),
        meta: {
          locale: 'Import/export operations',
          requiresAuth: true,
          tenantModuleKey: 'official.import-export',
          requiredPermissions: 'official.import-export.operations.read',
        },
      }],
    },
  ],
};
export default contribution;
