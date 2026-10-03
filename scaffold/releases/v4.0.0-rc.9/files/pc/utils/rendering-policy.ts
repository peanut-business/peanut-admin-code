/** PC 的渲染方式与私有页面规则；不保存任何请求、用户或租户状态。 */
export const pcPrivateRouteRoots = Object.freeze([
  '/user',
  '/account',
  '/login',
  '/oauth',
  '/recharge',
] as const);

/** 接受 Vue Router 的规范化 path，不将 /username 等相似名称视为用户中心。 */
export function isPcPrivateRoute(path: string): boolean {
  return pcPrivateRouteRoots.some(
    (root) => path === root || path.startsWith(`${root}/`)
  );
}

const publicDisplayRoutes = [
  '/',
  '/information',
  '/information/**',
  '/about',
] as const;

/**
 * hybrid：仅登记的展示页 SSR，其余页面 CSR；spa：全部客户端渲染。
 * 这是构建配置，不是运行时随意关闭 Node 的开关。静态部署仍需独立生成
 * 并验证页面回退和 API 代理；禁索引与禁缓存也不代替后端鉴权。
 */
export function createPcRenderingOptions(value: string | undefined) {
  const mode = value === undefined ? 'hybrid' : value;
  if (mode !== 'hybrid' && mode !== 'spa') {
    throw new Error('PC_RENDER_MODE_INVALID: expected hybrid or spa');
  }
  const routeRules: Record<string, Record<string, unknown>> = {
    '/**': { ssr: false },
  };
  if (mode === 'hybrid') {
    for (const path of publicDisplayRoutes) {
      routeRules[path] = { ssr: true, prerender: false };
    }
  } else {
    routeRules['/'] = { ssr: false, prerender: true };
  }
  for (const root of pcPrivateRouteRoots) {
    for (const path of [root, `${root}/**`]) {
      // 每个规则使用独立对象，避免后续配置合并修改其他规则。
      routeRules[path] = {
        ssr: false,
        prerender: false,
        cache: false,
        headers: {
          'cache-control': 'private, no-store',
          'x-robots-tag': 'noindex, nofollow',
        },
      };
    }
  }
  return { ssr: mode === 'hybrid', routeRules };
}
