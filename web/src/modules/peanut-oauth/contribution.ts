import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.oauth',
  routes: [
    {
      path: '/app-setting',
      name: 'officialOAuthRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.oauth' },
      children: [
        {
          path: 'channel',
          name: 'AppSettingChannel',
          component: () =>
            import('@/modules/peanut-oauth/views/channel/index.vue'),
          meta: {
            locale: 'menu.appSetting.channel',
            requiresAuth: true,
            tenantModuleKey: 'peanut.oauth',
            requiredPermissions: 'peanut.oauth.web-page.config',
          },
        },
      ],
    },
  ],
};
export default contribution;
