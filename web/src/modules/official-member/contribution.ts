import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.member',
  routes: [
    { path: '/member', name: 'officialMemberRoot', component: DEFAULT_LAYOUT, meta: { requiresAuth: true, tenantModuleKey: 'official.member' }, children: [
      { path: 'list', name: 'MemberList', component: () => import('@/modules/official-member/views/list/index.vue'), meta: { locale: 'menu.member.list', requiresAuth: true, tenantModuleKey: 'official.member', requiredPermissions: 'official.member.list' } },
      { path: 'tag', name: 'MemberTag', component: () => import('@/modules/official-member/views/tag/index.vue'), meta: { locale: 'menu.member.tag', requiresAuth: true, tenantModuleKey: 'official.member', requiredPermissions: 'official.member.tag.list' } },
    ] },
    { path: '/finance', name: 'officialMemberFinanceRoot', component: DEFAULT_LAYOUT, meta: { requiresAuth: true, tenantModuleKey: 'official.member' }, children: [{ path: 'account-log', name: 'FinanceAccountLog', component: () => import('@/modules/official-member/views/account-log/index.vue'), meta: { locale: 'menu.finance.accountLog', requiresAuth: true, tenantModuleKey: 'official.member', requiredPermissions: 'official.member.account-log.list' } }] },
  ],
};
export default contribution;
