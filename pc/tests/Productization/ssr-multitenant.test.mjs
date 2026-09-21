import assert from 'node:assert/strict'
import { cpSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'
import { test } from 'node:test'

test('Nuxt SSR keeps alternating Tenant HTML and request context isolated', async (context) => {
  const nitroSourceDirectory = new URL('../../.output/server/', import.meta.url)
  const testNitroDirectory = new URL(`../../.output/ssr-test-${process.pid}/`, import.meta.url)
  const testNitroPath = new URL('chunks/nitro/nitro.mjs', testNitroDirectory)
  const recordPath = new URL(`../../.output/ssr-upstream-${process.pid}.jsonl`, import.meta.url)
  context.after(() => {
    rmSync(testNitroDirectory, { force: true, recursive: true })
    rmSync(recordPath, { force: true })
  })

  process.env.NUXT_UPSTREAM_ORIGIN = 'http://upstream.invalid'
  process.env.NUXT_FORWARDED_PROTO = 'https'
  process.env.NUXT_TRUSTED_HOSTS = '*.example.test'
  process.env.PEANUT_SSR_TEST_RECORD = recordPath.pathname

  const upstream = await import('./fixtures/ssr-upstream.mjs')
  context.after(() => upstream.restoreSsrTestFetch())

  cpSync(nitroSourceDirectory, testNitroDirectory, { recursive: true })
  const source = readFileSync(testNitroPath, 'utf8')
  const start = source.indexOf('const cert = process.env.NITRO_SSL_CERT;')
  const endMarker = 'const nodeServer = {};'
  const end = source.indexOf(endMarker, start)
  assert.notEqual(start, -1, 'Nitro node listener start marker changed')
  assert.notEqual(end, -1, 'Nitro node listener end marker changed')
  const localSource = `${source.slice(0, start)}const nodeServer = {};\n${source.slice(end + endMarker.length)}`
  writeFileSync(testNitroPath, localSource)

  const nitroModule = await import(`${pathToFileURL(testNitroPath.pathname).href}?test=${Date.now()}`)
  const nitroApp = nitroModule.i()

  const render = async (tenant) => {
    const response = await nitroApp.localFetch('/pc/information/detail/1', {
      headers: {
        host: `${tenant}.example.test`,
        cookie: `tenant_session=${tenant}; token=${tenant}-token`,
      },
    })
    assert.equal(response.status, 200)
    return response.text()
  }

  const firstA = await render('tenant-a')
  const tenantB = await render('tenant-b')
  const secondA = await render('tenant-a')

  for (const html of [firstA, secondA]) {
    assert.match(html, /Tenant A article/)
    assert.match(html, /Tenant A 正文/)
    assert.doesNotMatch(html, /Tenant B article/)
    assert.doesNotMatch(html, /<script>alert\('xss'\)<\/script>/)
    assert.doesNotMatch(html, /<img src=x onerror=alert\(1\)>/)
  }
  assert.match(tenantB, /Tenant B article/)
  assert.match(tenantB, /Tenant B 正文/)
  assert.doesNotMatch(tenantB, /Tenant A article/)

  const upstreamRequests = readFileSync(recordPath, 'utf8')
    .trim()
    .split('\n')
    .filter(Boolean)
    .map(line => JSON.parse(line))
  assert.equal(upstreamRequests.length, 6)
  for (const request of upstreamRequests) {
    const tenant = request.host.startsWith('tenant-a.') ? 'tenant-a' : 'tenant-b'
    assert.equal(request.host, `${tenant}.example.test`)
    assert.equal(request.forwardedHost, `${tenant}.example.test`)
    assert.equal(request.forwardedProto, 'https')
    assert.equal(request.cookie, `tenant_session=${tenant}; token=${tenant}-token`)
    if (request.path.startsWith('/api/article/detail')) {
      assert.equal(request.authorization, `Bearer ${tenant}-token`)
    }
  }
})
