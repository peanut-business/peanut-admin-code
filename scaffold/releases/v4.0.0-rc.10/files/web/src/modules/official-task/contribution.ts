import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.task',
  routes: [
    {
      path: '/system',
      name: 'officialTaskRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'official.task' },
      children: [
        {
          path: 'crontab',
          name: 'SystemCrontab',
          component: () => import('@/modules/official-task/views/index.vue'),
          meta: {
            locale: 'menu.system.crontab',
            requiresAuth: true,
            tenantModuleKey: 'official.task',
            requiredPermissions: 'official.task.list',
          },
        },
        {
          path: 'task-jobs',
          name: 'OfficialTaskJobs',
          component: () => import('./TaskJobsPage.vue'),
          meta: {
            locale: 'Task jobs',
            requiresAuth: true,
            tenantModuleKey: 'official.task',
            requiredPermissions: 'official.task.jobs.read',
          },
        },
      ],
    },
  ],
};
export default contribution;
