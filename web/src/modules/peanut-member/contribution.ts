import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.member',
  routes: [
    {
      path: '/member',
      name: 'officialMemberRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.member' },
      children: [
        {
          path: 'list',
          name: 'MemberList',
          component: () =>
            import('@/modules/peanut-member/views/list/index.vue'),
          meta: {
            locale: 'menu.member.list',
            requiresAuth: true,
            tenantModuleKey: 'peanut.member',
            requiredPermissions: 'peanut.member.list',
          },
        },
        {
          path: 'tag',
          name: 'MemberTag',
          component: () =>
            import('@/modules/peanut-member/views/tag/index.vue'),
          meta: {
            locale: 'menu.member.tag',
            requiresAuth: true,
            tenantModuleKey: 'peanut.member',
            requiredPermissions: 'peanut.member.tag.list',
          },
        },
      ],
    },
    {
      path: '/finance',
      name: 'officialMemberFinanceRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.member' },
      children: [
        {
          path: 'account-log',
          name: 'FinanceAccountLog',
          component: () =>
            import('@/modules/peanut-member/views/account-log/index.vue'),
          meta: {
            locale: 'menu.finance.accountLog',
            requiresAuth: true,
            tenantModuleKey: 'peanut.member',
            requiredPermissions: 'peanut.member.account-log.list',
          },
        },
      ],
    },
  ],
};
export default contribution;
