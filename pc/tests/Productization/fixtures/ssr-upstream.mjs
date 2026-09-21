import { appendFileSync } from 'node:fs'

const nativeFetch = globalThis.fetch
const recordPath = process.env.PEANUT_SSR_TEST_RECORD
if (!recordPath) throw new Error('PEANUT_SSR_TEST_RECORD_REQUIRED')

const envelope = data => JSON.stringify({ code: 20000, msg: 'success', data })

globalThis.fetch = async (input, init) => {
  const request = input instanceof Request ? input : null
  const url = new URL(request?.url || String(input))
  if (url.hostname !== 'upstream.invalid') return nativeFetch(input, init)

  const headers = new Headers(request?.headers)
  new Headers(init?.headers).forEach((value, key) => headers.set(key, value))
  const host = headers.get('host') || ''
  const tenant = host.startsWith('tenant-a.') ? 'Tenant A' : 'Tenant B'
  appendFileSync(recordPath, `${JSON.stringify({
    path: `${url.pathname}${url.search}`,
    host,
    forwardedHost: headers.get('x-forwarded-host'),
    forwardedProto: headers.get('x-forwarded-proto'),
    cookie: headers.get('cookie'),
    authorization: headers.get('authorization'),
  })}\n`)

  if (url.pathname === '/api/index/config') {
    return new Response(envelope({
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
      version: 'test',
    }), { headers: { 'content-type': 'application/json' } })
  }

  if (url.pathname === '/api/article/detail') {
    return new Response(envelope({
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
      collect: false,
      content: `<p>${tenant} 正文</p><img src=x onerror=alert(1)><script>alert('xss')</script>`,
    }), { headers: { 'content-type': 'application/json' } })
  }

  return new Response(envelope(null), {
    status: 404,
    headers: { 'content-type': 'application/json' },
  })
}

export const restoreSsrTestFetch = () => {
  globalThis.fetch = nativeFetch
}
