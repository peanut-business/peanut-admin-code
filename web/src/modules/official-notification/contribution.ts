import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.notification',
  routes: [{
    path: '/notice', name: 'officialNotificationRoot', component: DEFAULT_LAYOUT,
    meta: { requiresAuth: true, tenantModuleKey: 'official.notification' },
    children: [
      { path: 'channel', name: 'NoticeChannel', component: () => import('@/modules/official-notification/views/channel/index.vue'), meta: { locale: 'menu.notice.channel', requiresAuth: true, tenantModuleKey: 'official.notification', requiredPermissions: 'official.notification.channel.detail' } },
      { path: 'template', name: 'NoticeTemplate', component: () => import('@/modules/official-notification/views/template/index.vue'), meta: { locale: 'menu.notice.template', requiresAuth: true, tenantModuleKey: 'official.notification', requiredPermissions: 'official.notification.scene.list' } },
      { path: 'log', name: 'NoticeLog', component: () => import('@/modules/official-notification/views/log/index.vue'), meta: { locale: 'menu.notice.log', requiresAuth: true, tenantModuleKey: 'official.notification', requiredPermissions: 'official.notification.log.list' } },
      { path: 'inbox', name: 'OfficialNotificationInbox', component: () => import('./NotificationInboxPage.vue'), meta: { locale: 'Notification inbox', requiresAuth: true, tenantModuleKey: 'official.notification', requiredPermissions: 'official.notification.inbox.read' } },
    ],
  }],
};
export default contribution;
