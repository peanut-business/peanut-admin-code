import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'fixture.workflow-business',
  routes: [
    {
      path: '/app',
      name: 'fixture-workflow-business',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'Workflow business samples',
        requiresAuth: true,
        tenantModuleKey: 'fixture.workflow-business',
        requiredPermissions: 'fixture.workflow-business.read',
      },
      children: [
        {
          path: 'fixtures/workflow-business',
          name: 'fixture-workflow-business-list',
          component: () => import('./WorkflowBusinessPage.vue'),
          meta: {
            locale: '开发用业务样例',
            requiresAuth: true,
            tenantModuleKey: 'fixture.workflow-business',
            requiredPermissions: 'fixture.workflow-business.read',
          },
        },
        {
          path: 'fixtures/workflow-business-runtime',
          name: 'fixture-workflow-business-runtime',
          component: () => import('./WorkflowBusinessRuntime.vue'),
          meta: {
            locale: '样例流程办理',
            requiresAuth: true,
            tenantModuleKey: 'fixture.workflow-business',
            requiredPermissions: 'fixture.workflow-business.read',
          },
        },
        {
          path: 'fixtures/workflow-business/:scene?/:objectKey?',
          name: 'fixture-workflow-business-form',
          component: () => import('./WorkflowBusinessPage.vue'),
          meta: {
            locale: '开发用业务样例',
            hideInMenu: true,
            activeMenu: 'fixture-workflow-business-list',
            requiresAuth: true,
            tenantModuleKey: 'fixture.workflow-business',
            requiredPermissions: 'fixture.workflow-business.read',
          },
        },
      ],
    },
  ],
};
export default contribution;
