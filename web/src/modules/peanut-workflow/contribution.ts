import type { PluginFrontendContribution } from '@peanut-admin/vue';
import runtimeContribution from './runtime/contribution';

/** Full-mode entry. Runtime products select runtime/contribution.ts at assembly. */
const fullContribution: PluginFrontendContribution = {
  moduleKey: 'peanut.workflow',
  routes: runtimeContribution.routes.map((route) => ({
    ...route,
    children: [
      ...(route.children || []),
      {
        path: 'designer',
        name: 'WorkflowDesigner',
        component: () => import('./designer/WorkflowDesignerPage.vue'),
        meta: {
          requiresAuth: true,
          tenantModuleKey: 'peanut.workflow',
          requiredPermissions: 'peanut.workflow.definition.read',
          locale: '流程设计',
        },
      },
    ],
  })),
};
export default fullContribution;
