import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.oauth',
  routes: [
    {
      path: '/app-setting',
      name: 'officialOAuthRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'official.oauth' },
      children: [
        {
          path: 'channel',
          name: 'AppSettingChannel',
          component: () =>
            import('@/modules/official-oauth/views/channel/index.vue'),
          meta: {
            locale: 'menu.appSetting.channel',
            requiresAuth: true,
            tenantModuleKey: 'official.oauth',
            requiredPermissions: 'official.oauth.web-page.config',
          },
        },
      ],
    },
  ],
};
export default contribution;
