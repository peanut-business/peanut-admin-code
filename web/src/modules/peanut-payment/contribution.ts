import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.payment',
  routes: [
    {
      path: '/app-setting',
      name: 'officialPaymentSettingsRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.payment' },
      children: [
        {
          path: 'pay',
          name: 'AppSettingPay',
          component: () =>
            import('@/modules/peanut-payment/views/settings/index.vue'),
          meta: {
            locale: 'menu.appSetting.pay',
            requiresAuth: true,
            tenantModuleKey: 'peanut.payment',
            requiredPermissions: 'peanut.payment.settings.detail',
          },
        },
      ],
    },
    {
      path: '/finance',
      name: 'officialPaymentFinanceRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.payment' },
      children: [
        {
          path: 'recharge',
          name: 'FinanceRecharge',
          component: () =>
            import('@/modules/peanut-payment/views/recharge/index.vue'),
          meta: {
            locale: 'menu.finance.recharge',
            requiresAuth: true,
            tenantModuleKey: 'peanut.payment',
            requiredPermissions: 'peanut.payment.recharge.list',
          },
        },
        {
          path: 'refund',
          name: 'FinanceRefund',
          component: () =>
            import('@/modules/peanut-payment/views/refund/index.vue'),
          meta: {
            locale: 'menu.finance.refund',
            requiresAuth: true,
            tenantModuleKey: 'peanut.payment',
            requiredPermissions: 'peanut.payment.refund.list',
          },
        },
      ],
    },
  ],
};
export default contribution;
