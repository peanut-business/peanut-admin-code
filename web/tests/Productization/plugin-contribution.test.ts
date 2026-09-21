import type { RouteRecordRaw } from 'vue-router';
import {
  collectPluginContributions,
  routesForTenantModules,
} from '@peanut-admin/vue';
import articleContribution from '../../src/modules/official-article/contribution';

function expect(condition: boolean, message: string): void {
  if (!condition) throw new Error(message);
}

const route: RouteRecordRaw = {
  path: '/app/fixtures/delivery-records',
  name: 'fixture-delivery-record-list',
  redirect: '/',
  meta: {
    requiresAuth: true,
    tenantModuleKey: 'fixture.delivery-record',
    requiredPermissions: 'fixture.delivery-record.read',
  },
};
const contributions = collectPluginContributions({
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
  routesForTenantModules(
    contributions,
    ['fixture.delivery-record'],
    [],
    exact
  ).length === 0,
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
    ['official.article'],
    ['official.article.category.list', 'official.article.list'],
    exact
  ).length === 1,
  'enabled and authorized official Article Module was not visible'
);
expect(
  routesForTenantModules([articleContribution], [], ['*'], exact).length === 0,
  'official Article route bypassed TenantModule enablement'
);
const articleRoot = articleContribution.routes[0];
expect(
  articleRoot.meta?.tenantModuleKey === 'official.article' &&
    articleRoot.children?.every(
      (child) => child.meta?.tenantModuleKey === 'official.article'
    ) === true,
  'official Article deep-link child lost TenantModule metadata'
);
expect(
  routesForTenantModules(
    [articleContribution],
    [],
    ['official.article.category.list', 'official.article.list'],
    exact
  ).flatMap((route) => route.children || []).length === 0,
  'disabled official Article Module exposed a deep-link child route'
);

console.log('PLUGIN-FRONTEND-CONTRIBUTION-001 passed');
