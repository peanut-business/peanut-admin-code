import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'fixture.delivery-record',
  routes: [
    {
      path: '/app',
      name: 'fixture-delivery-record',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'Delivery records (fixture)',
        requiresAuth: true,
        tenantModuleKey: 'fixture.delivery-record',
        requiredPermissions: 'fixture.delivery-record.read',
      },
      children: [
        {
          path: 'fixtures/delivery-records',
          name: 'fixture-delivery-record-list',
          component: () => import('./views/index.vue'),
          meta: {
            locale: 'Delivery record list',
            requiresAuth: true,
            tenantModuleKey: 'fixture.delivery-record',
            requiredPermissions: 'fixture.delivery-record.read',
          },
        },
      ],
    },
  ],
};

export default contribution;
