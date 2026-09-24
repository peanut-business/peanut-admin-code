#!/usr/bin/env node
/** 合成原生锁+既有 YAML 解析器；只验证身份/拒绝路径，不访问合成 registry URL。 */
import assert from 'node:assert/strict'
import test from 'node:test'
import { createHash } from 'node:crypto'
import { readFileSync, mkdirSync, mkdtempSync, writeFileSync, copyFileSync, symlinkSync, rmSync } from 'node:fs'
import { resolve, dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
import { coreWebPackages, dependencyMode, verifyDependencyLocks, lockedYamlParser } from '../release-dependency-locks.mjs'

const root = fileURLToPath(new URL('../..', import.meta.url))
const parseYaml = lockedYamlParser(process.argv[2] || root)
function fixture() {
  const versions = JSON.parse(readFileSync(resolve(root, 'release-versions.json')))
  Object.assign(versions.core_php, { constraint: '9.2.1', resolved_version: 'v9.2.1' })
  for (const name of coreWebPackages) versions.core_web.packages[name] = {
    version: '8.1.0-rc.2', resolved: `https://registry.example.invalid/${name}/-/fixture-8.1.0-rc.2.tgz`,
    integrity: 'sha512-' + createHash('sha512').update('synthetic:' + name).digest('base64'),
  }
  const files = new Map()
  files.set('server/composer.json', { require: { 'peanut-admin/core': '9.2.1' }, repositories: [] })
  files.set('server/composer.lock', { packages: [{ name: 'peanut-admin/core', version: 'v9.2.1',
    source: { type: 'git', url: versions.core_php.source_url, reference: versions.core_php.source_reference } }] })
  for (const [client, suffixes] of Object.entries({ web: ['vue', 'ui-vue'], platform: ['vue', 'ui-vue'], pc: ['client', 'nuxt'], uniapp: ['client', 'uniapp'] })) {
    const dependencies = Object.fromEntries(suffixes.map(s => [`@peanut-admin/${s}`, '8.1.0-rc.2']))
    files.set(`${client}/package.json`, { version: '9.8.7', dependencies })
    if (client !== 'web') files.set(`${client}/package-lock.json`, {
      lockfileVersion: 3, packages: { '': { dependencies },
        ...Object.fromEntries(Object.keys(dependencies).map(name => [`node_modules/${name}`, { ...versions.core_web.packages[name] }])) },
    })
  }
  const names = ['@peanut-admin/vue', '@peanut-admin/ui-vue']
  const yaml = "lockfileVersion: '9.0'\nimporters:\n  .:\n    dependencies:\n"
    + names.map(n => `      '${n}':\n        specifier: 8.1.0-rc.2\n        version: 8.1.0-rc.2(vue@3.5.0)\n`).join('')
    + 'packages:\n' + names.map(n => `  '${n}@8.1.0-rc.2':\n    resolution: {integrity: '${versions.core_web.packages[n].integrity}'}\n`).join('')
  files.set('web/pnpm-lock.yaml', yaml)
  const read = path => {
    assert.ok(files.has(path), 'unexpected source read: ' + path)
    const data = files.get(path)
    return Buffer.from(typeof data === 'string' ? data : JSON.stringify(data))
  }
  return { versions, files, run: () => verifyDependencyLocks(versions, read, parseYaml) }
}

test('fixed v3 native locks work without a local Core tgz', () => {
  const f = fixture(), result = f.run()
  assert.equal(result.mode, 'registry'); assert.equal(result.checked.length, 8)
  assert.equal(result.published, false)
})
test('Composer lock source identity cannot drift', () => {
  const f = fixture(); f.files.get('server/composer.lock').packages[0].source.reference = '0'.repeat(40)
  assert.throws(f.run, /Composer/)
})
test('npm exact version, source and integrity must all agree', () => {
  for (const key of ['version', 'resolved', 'integrity']) {
    const f = fixture(); f.files.get('pc/package-lock.json').packages['node_modules/@peanut-admin/client'][key] = 'different'
    assert.throws(f.run, /npm resolution/)
  }
})
test('npm root manifest and lock importer cannot disagree', () => {
  const f = fixture(); f.files.get('pc/package-lock.json').packages[''].dependencies = {}
  assert.throws(f.run, /npm importer/)
})
test('pnpm YAML importer identity is checked with the locked parser', () => {
  const f = fixture(); f.files.set('web/pnpm-lock.yaml', f.files.get('web/pnpm-lock.yaml').replace('specifier: 8.1.0-rc.2', 'specifier: ^8.1.0'))
  assert.throws(f.run, /pnpm importer/)
})
test('pnpm package integrity cannot disagree or disappear', () => {
  const f = fixture(); f.files.set('web/pnpm-lock.yaml', f.files.get('web/pnpm-lock.yaml').replace(/integrity: '[^']+'/, "integrity: 'sha512-AAAA'"))
  assert.throws(f.run, /pnpm resolution/)
})
test('duplicate YAML keys are not quietly overwritten', () => {
  const f = fixture(); f.files.set('web/pnpm-lock.yaml', f.files.get('web/pnpm-lock.yaml') + 'packages: {}\n')
  assert.throws(f.run, /duplicated mapping key/)
})
test('missing required client dependency is not accepted as an empty package', () => {
  const f = fixture(); delete f.files.get('web/package.json').dependencies['@peanut-admin/vue']
  assert.throws(f.run, /missing vue/)
})
test('mixed development and registry identities remain rejected', () => {
  const f = fixture(); f.versions.core_php.constraint = f.versions.core_php.resolved_version = 'dev-dev'
  assert.throws(f.run, /mixed/)
})
test('credential-bearing sources, ranges and malformed SRI are rejected', () => {
  for (const changed of [
    { resolved: 'https://user:secret@example.invalid/package.tgz' },
    { version: '^8.1.0' }, { integrity: 'sha512-AAAA' }, { version: '8.1.0-dev.1' },
    { archive: 'packages/core-web/extra.tgz' },
  ]) {
    const f = fixture(); Object.assign(f.versions.core_web.packages['@peanut-admin/client'], changed)
    assert.throws(() => dependencyMode(f.versions), /RELEASE_DEPENDENCY_INVALID/)
  }
})
test('existing static checker reads native V3 metadata and preserves not-ready status', () => {
  assert.ok(process.env.TMPDIR?.includes('/.local/tmp/'), 'test requires checkout TMPDIR')
  const temporary = mkdtempSync(join(process.env.TMPDIR, 'native-checker-'))
  const app = join(temporary, 'candidate'), tools = join(temporary, 'tools')
  try {
    const f = fixture()
    Object.assign(f.versions, { source_product_version: '9.8.7', scaffold_template: '9.8.7' })
    f.files.set('release-versions.json', f.versions)
    f.files.set('RELEASE_METADATA.json', { schema_version: 3, protocol: 'peanut.release-metadata.v3',
      source_product_version: '9.8.7', instance_version: null, expected_tag: 'v9.8.7' })
    f.files.set('scaffold/application-template-inventory.json', { template_version: '9.8.7', application: { version: '0.1.0' } })
    f.files.set('server/tests/fixtures/p0e-runtime-qualification/matrix.json', { target_release: { version: '9.8.7' } })
    f.files.set('CHANGELOG.md', '## [9.8.7]\nSynthetic checker input, not a release.\n')
    for (const [relative, data] of f.files) {
      const path = join(app, relative); mkdirSync(dirname(path), { recursive: true })
      writeFileSync(path, typeof data === 'string' ? data : JSON.stringify(data))
    }
    mkdirSync(join(tools, 'scripts'), { recursive: true })
    for (const name of ['check-release-consistency', 'release-dependency-locks.mjs']) copyFileSync(join(root, 'scripts', name), join(tools, 'scripts', name))
    // 测试工具独立于候选，仅读既有锁定解析器；候选自身不带 node_modules。
    mkdirSync(join(tools, 'web/node_modules'), { recursive: true })
    symlinkSync(resolve(process.argv[2] || root, 'web/node_modules/openapi-typescript'), join(tools, 'web/node_modules/openapi-typescript'))
    const command = [join(tools, 'scripts/check-release-consistency'), '--static-only']
    let r = spawnSync(process.execPath, command, { cwd: app, encoding: 'utf8', timeout: 10000 })
    assert.equal(r.status, 0, r.stderr)
    const result = JSON.parse(r.stdout)
    assert.equal(result.dependency_identity.mode, 'registry')
    assert.equal(result.product_release_ready, false)
    const lock = f.files.get('pc/package-lock.json')
    lock.packages['node_modules/@peanut-admin/client'].integrity = 'different'
    writeFileSync(join(app, 'pc/package-lock.json'), JSON.stringify(lock))
    r = spawnSync(process.execPath, command, { cwd: app, encoding: 'utf8', timeout: 10000 })
    assert.equal(r.status, 1); assert.match(r.stderr, /npm resolution/)
  } finally { rmSync(temporary, { recursive: true, force: true }) }
})
test('candidate gate rejects absent qualification before reading candidate contents', () => {
  const r = spawnSync(process.execPath, [resolve(root, 'scripts/check-release-consistency'), '--candidate', 'a'.repeat(40)], { encoding: 'utf8' })
  assert.equal(r.status, 64); assert.match(r.stderr, /requires --qualification/)
})
