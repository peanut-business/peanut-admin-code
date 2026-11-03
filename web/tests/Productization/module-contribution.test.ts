import type { ModuleFrontendRoute, ModuleFrontendContribution } from '@peanut-admin/vue';
import {
  collectModuleContributions,
  routesForTenantModules,
} from '@peanut-admin/vue';
const articleContribution: ModuleFrontendContribution = {
  moduleKey: 'fixture.article',
  routes: [{
    path: '/fixture/article',
    name: 'fixture-article',
    redirect: '/fixture/article/list',
    meta: { tenantModuleKey: 'fixture.article' },
    children: ['category', 'list'].map((kind) => ({
      path: kind,
      name: `fixture-article-${kind}`,
      redirect: '/',
      meta: {
        tenantModuleKey: 'fixture.article',
        requiredPermissions: kind === 'category' ? 'fixture.article.category.list' : 'fixture.article.list',
      },
    })),
  }],
};

function expect(condition: boolean, message: string): void {
  if (!condition) throw new Error(message);
}

const route: ModuleFrontendRoute = {
  path: '/app/fixtures/delivery-records',
  name: 'fixture-delivery-record-list',
  redirect: '/',
  meta: {
    requiresAuth: true,
    tenantModuleKey: 'fixture.delivery-record',
    requiredPermissions: 'fixture.delivery-record.read',
  },
};
const contributions = collectModuleContributions({
  fixture: {
    default: { moduleKey: 'fixture.delivery-record', routes: [route] },
  },
});
const exact = (permissions: ReadonlySet<string>, permission: string) =>
  permissions.has(permission);

expect(contributions.length === 1, 'fixture contribution was not collected');
expect(
  routesForTenantModules(contributions, [], ['*'], exact).length === 0,
  'a deployed Module became visible before TenantModule enablement'
);
expect(
  routesForTenantModules(contributions, ['fixture.delivery-record'], [], exact)
    .length === 0,
  'an enabled TenantModule became visible without member permission'
);
expect(
  routesForTenantModules(
    contributions,
    ['fixture.delivery-record'],
    ['fixture.delivery-record.read'],
    exact
  ).length === 1,
  'enabled and authorized fixture Module was not visible'
);
expect(
  routesForTenantModules(
    [articleContribution],
    ['fixture.article'],
    ['fixture.article.category.list', 'fixture.article.list'],
    exact
  ).length === 1,
  'enabled and authorized official Article Module was not visible'
);
expect(
  routesForTenantModules(
    [articleContribution],
    ['fixture.article'],
    ['fixture.article.category.list'],
    exact
  ).length === 1,
  'category-only permission could not enter the official Article Module'
);
expect(
  routesForTenantModules([articleContribution], [], ['*'], exact).length === 0,
  'official Article route bypassed TenantModule enablement'
);
const articleRoot = articleContribution.routes[0];
expect(
  articleRoot.meta?.tenantModuleKey === 'fixture.article' &&
    articleRoot.children?.every(
      (child) => child.meta?.tenantModuleKey === 'fixture.article'
    ) === true,
  'official Article deep-link child lost TenantModule metadata'
);
expect(
  routesForTenantModules(
    [articleContribution],
    [],
    ['fixture.article.category.list', 'fixture.article.list'],
    exact
  ).flatMap((route) => route.children || []).length === 0,
  'disabled official Article Module exposed a deep-link child route'
);

console.log('MODULE-FRONTEND-CONTRIBUTION-001 passed');
