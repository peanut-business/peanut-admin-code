import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.article',
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
        tenantModuleKey: 'peanut.article',
        requiredPermissions: [
          'peanut.article.category.list',
          'peanut.article.list',
        ],
      },
      children: [
        {
          path: 'cate',
          name: 'ArticleCate',
          component: () =>
            import('@/modules/peanut-article/views/cate/index.vue'),
          meta: {
            locale: 'menu.article.cate',
            requiresAuth: true,
            tenantModuleKey: 'peanut.article',
            requiredPermissions: 'peanut.article.category.list',
          },
        },
        {
          path: 'list',
          name: 'ArticleList',
          component: () =>
            import('@/modules/peanut-article/views/list/index.vue'),
          meta: {
            locale: 'menu.article.list',
            requiresAuth: true,
            tenantModuleKey: 'peanut.article',
            requiredPermissions: 'peanut.article.list',
          },
        },
      ],
    },
  ],
};

export default contribution;
