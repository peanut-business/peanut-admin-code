import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.payment',
  routes: [
    {
      path: '/app-setting',
      name: 'officialPaymentSettingsRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'official.payment' },
      children: [
        {
          path: 'pay',
          name: 'AppSettingPay',
          component: () =>
            import('@/modules/official-payment/views/settings/index.vue'),
          meta: {
            locale: 'menu.appSetting.pay',
            requiresAuth: true,
            tenantModuleKey: 'official.payment',
            requiredPermissions: 'official.payment.settings.detail',
          },
        },
      ],
    },
    {
      path: '/finance',
      name: 'officialPaymentFinanceRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'official.payment' },
      children: [
        {
          path: 'recharge',
          name: 'FinanceRecharge',
          component: () =>
            import('@/modules/official-payment/views/recharge/index.vue'),
          meta: {
            locale: 'menu.finance.recharge',
            requiresAuth: true,
            tenantModuleKey: 'official.payment',
            requiredPermissions: 'official.payment.recharge.list',
          },
        },
        {
          path: 'refund',
          name: 'FinanceRefund',
          component: () =>
            import('@/modules/official-payment/views/refund/index.vue'),
          meta: {
            locale: 'menu.finance.refund',
            requiresAuth: true,
            tenantModuleKey: 'official.payment',
            requiredPermissions: 'official.payment.refund.list',
          },
        },
      ],
    },
  ],
};
export default contribution;
