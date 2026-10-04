import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.rich-text',
  routes: [
    {
      path: '/rich-text',
      name: 'richText',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'menu.richText',
        requiresAuth: true,
        icon: 'icon-edit',
        order: 6,
        tenantModuleKey: 'official.rich-text',
        requiredPermissions: 'official.rich-text.document.list',
      },
      children: [
        {
          path: 'documents',
          name: 'RichTextDocuments',
          component: () => import('./views/index.vue'),
          meta: {
            locale: 'menu.richText.documents',
            requiresAuth: true,
            tenantModuleKey: 'official.rich-text',
            requiredPermissions: 'official.rich-text.document.list',
          },
        },
      ],
    },
  ],
};

export default contribution;
