import { createServer } from 'node:http';

// Explicit HTTP fixture only: no global fetch patch and no real tenant data.
export function createSsrUpstream() {
  const requests = [];
  const state = { mode: 'normal' };
  const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://fixture.invalid');
    // Model the installed ThinkPHP Request::host contract at this trusted
    // loopback upstream: forwarded host first, transport Host otherwise.
    const host =
      request.headers['x-forwarded-host'] || request.headers.host || '';
    const tenant = host === 'tenant-a.example.test' ? 'Tenant A' : 'Tenant B';
    requests.push({
      path: `${url.pathname}${url.search}`,
      host: request.headers.host || '',
      forwardedHost: request.headers['x-forwarded-host'] || null,
      forwardedProto: request.headers['x-forwarded-proto'] || null,
      cookie: request.headers.cookie || null,
      authorization: request.headers.authorization || null,
    });
    const send = (status, code, data) => {
      response.writeHead(status, { 'content-type': 'application/json' });
      response.end(JSON.stringify({ code, msg: 'synthetic', data }));
    };
    if (!['tenant-a.example.test', 'tenant-b.example.test'].includes(host)) {
      return send(421, 42100, null);
    }
    if (url.pathname === '/api/index/config') {
      if (state.mode === 'config-failure') return send(503, 50300, null);
      return send(200, 20000, {
        domain: host,
        website: {
          name: tenant,
          web_favicon: '/brand/favicon.svg',
          web_logo: '/brand/logo.svg',
          login_image: '/brand/login-background.svg',
          shop_name: `${tenant} shop`,
          shop_logo: '/brand/logo.svg',
          pc_logo: '/brand/logo.svg',
          pc_title: `${tenant} portal`,
          pc_ico: '/brand/favicon.svg',
          pc_desc: `${tenant} description`,
          pc_keywords: tenant,
          h5_favicon: '/brand/favicon.svg',
          slogan: tenant,
          copyright: tenant,
          official_url: '',
          github_url: '',
        },
        login: { login_way: [1] },
        version: 'synthetic',
      });
    }
    if (url.pathname === '/api/article/detail') {
      if (state.mode === 'missing') return send(404, 40400, null);
      if (state.mode === 'service-failure') return send(503, 50300, null);
      // Different delays make concurrent request identity crossover observable.
      await new Promise((resolve) =>
        setTimeout(resolve, tenant === 'Tenant A' ? 30 : 5)
      );
      return send(200, 20000, {
        id: 1,
        cid: 1,
        cate_name: 'News',
        title: `${tenant} article`,
        image: '/brand/logo.svg',
        desc: `${tenant} summary`,
        abstract: `${tenant} abstract`,
        author: tenant,
        click: 1,
        create_time: '2026-09-22 00:00:00',
        collect: true,
        content: `<p>${tenant} 正文</p><img src=x onerror=alert(1)><script>alert('xss')</script>`,
      });
    }
    return send(404, 40400, null);
  });
  return { server, state, requests };
}
