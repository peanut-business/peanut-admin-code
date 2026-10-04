import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'official.article',
  routes: [
    {
      path: '/article',
      name: 'article',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'menu.article',
        requiresAuth: true,
        icon: 'icon-file',
        order: 5,
        tenantModuleKey: 'official.article',
        requiredPermissions: [
          'official.article.category.list',
          'official.article.list',
        ],
      },
      children: [
        {
          path: 'cate',
          name: 'ArticleCate',
          component: () =>
            import('@/modules/official-article/views/cate/index.vue'),
          meta: {
            locale: 'menu.article.cate',
            requiresAuth: true,
            tenantModuleKey: 'official.article',
            requiredPermissions: 'official.article.category.list',
          },
        },
        {
          path: 'list',
          name: 'ArticleList',
          component: () =>
            import('@/modules/official-article/views/list/index.vue'),
          meta: {
            locale: 'menu.article.list',
            requiresAuth: true,
            tenantModuleKey: 'official.article',
            requiredPermissions: 'official.article.list',
          },
        },
      ],
    },
  ],
};

export default contribution;
