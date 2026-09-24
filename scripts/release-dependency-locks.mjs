#!/usr/bin/env node
/** 核对 V3 合同与原生锁；不下载、不发布，也不把字符串核对当作安装成功。 */
import { createHash } from 'node:crypto'
import { readFileSync, realpathSync, lstatSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

export const coreWebPackages = ['client', 'vue', 'ui-vue', 'nuxt', 'uniapp', 'testing'].map(x => `@peanut-admin/${x}`)
const semver = /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value)
const keys = (value, names) => object(value) && JSON.stringify(Object.keys(value)) === JSON.stringify(names)
const fixed = value => typeof value === 'string' && semver.test(value) && !/(?:^|[.-])dev(?:[.+-]|$)/i.test(value)
const fail = message => { throw new Error(`RELEASE_DEPENDENCY_INVALID: ${message}`) }
const https = value => {
  if (typeof value !== 'string' || /[\x00-\x20\x7f]/.test(value)) return false
  try { const u = new URL(value); return u.protocol === 'https:' && u.hostname && u.pathname !== '/' && !u.username && !u.password && !u.search && !u.hash }
  catch { return false }
}
const integrity = value => typeof value === 'string' && value.startsWith('sha512-')
  && Buffer.from(value.slice(7), 'base64').length === 64
  && Buffer.from(value.slice(7), 'base64').toString('base64') === value.slice(7)

export function dependencyMode(versions) {
  const p = versions.core_php, w = versions.core_web
  if (!keys(p, ['package', 'constraint', 'resolved_version', 'source_type', 'source_url', 'source_reference'])
    || p.package !== 'peanut-admin/core' || p.source_type !== 'git' || !https(p.source_url)
    || !/^[0-9a-f]{40}$/.test(p.source_reference ?? '') || typeof p.resolved_version !== 'string') fail('core_php')
  const dev = /^dev-[A-Za-z0-9._-]+$/.test(p.constraint ?? '') && p.constraint === p.resolved_version
  const pinned = fixed(p.constraint) && p.constraint === p.resolved_version.replace(/^v/, '')
  if (!dev && !pinned) fail('core_php version')
  if (!keys(w, ['source_type', 'source_url', 'source_reference', 'packages']) || w.source_type !== 'git'
    || !https(w.source_url) || !/^[0-9a-f]{40}$/.test(w.source_reference ?? '')
    || !keys(w.packages, coreWebPackages)) fail('core_web')
  const modes = new Set()
  for (const [name, i] of Object.entries(w.packages)) {
    if (!object(i) || typeof i.version !== 'string' || !semver.test(i.version)) fail(name)
    if (keys(i, ['version', 'archive', 'sha256'])) {
      if (!/^packages\/core-web\/peanut-admin-[a-z-]+-[0-9A-Za-z.+-]+\.tgz$/.test(i.archive ?? '')
        || !/^[0-9a-f]{64}$/.test(i.sha256 ?? '')) fail(`${name} archive`)
      modes.add('archive')
    } else if (keys(i, ['version', 'resolved', 'integrity'])) {
      if (!fixed(i.version) || !https(i.resolved) || !integrity(i.integrity)) fail(`${name} registry`)
      modes.add('registry')
    } else fail(`${name} shape`)
  }
  if (modes.size !== 1 || (modes.has('registry') && !pinned)) fail('mixed development/registry identities')
  return [...modes][0]
}

/** 从既有锁定 OpenAPI 工具依赖取得 YAML 解析器，不自造 YAML 解析或安装新依赖。 */
export function lockedYamlParser(toolRoot) {
  const require = createRequire(realpathSync(resolve(toolRoot, 'web/node_modules/openapi-typescript/package.json')))
  const redocly = createRequire(require.resolve('@redocly/openapi-core'))
  const yaml = redocly('js-yaml')
  return text => yaml.load(text, { schema: yaml.JSON_SCHEMA })
}

/** read 只读取指定源码/锁；Git 候选检查传入 git-show，绝不执行候选中的代码。 */
export function verifyDependencyLocks(versions, read, parseYaml) {
  const mode = dependencyMode(versions)
  const json = path => JSON.parse(String(read(path)))
  const p = versions.core_php, packages = versions.core_web.packages
  const composer = json('server/composer.json'), composerLock = json('server/composer.lock')
  const records = (composerLock.packages ?? []).filter(x => x.name === 'peanut-admin/core')
  const locked = records[0]
  if (records.length !== 1 || composer.require?.['peanut-admin/core'] !== p.constraint
    || locked.version !== p.resolved_version || locked.source?.type !== p.source_type
    || locked.source?.url !== p.source_url || locked.source?.reference !== p.source_reference
    || (locked.dist?.reference && locked.dist.reference !== p.source_reference)) fail('Composer manifest/lock/provenance')
  if (mode === 'registry' && (Object.values(composer.repositories ?? {}).some(x => x?.type === 'path')
    || [...(composerLock.packages ?? []), ...(composerLock['packages-dev'] ?? [])].some(x => x.dist?.type === 'path'))) fail('Composer path dependency')
  const required = { web: ['vue', 'ui-vue'], platform: ['vue', 'ui-vue'], pc: ['client', 'nuxt'], uniapp: ['client', 'uniapp'] }
  const checked = []
  for (const [client, suffixes] of Object.entries(required)) {
    const manifest = json(`${client}/package.json`)
    if (mode === 'registry' && Object.values({ ...manifest.dependencies, ...manifest.devDependencies, ...manifest.optionalDependencies })
      .some(value => typeof value !== 'string' || /^(?:file:|link:|workspace:|\/|\.\.?\/)/.test(value))) fail(`${client} local dependency`)
    for (const suffix of suffixes) if (!manifest.dependencies?.[`@peanut-admin/${suffix}`]) fail(`${client} missing ${suffix}`)
    const selections = Object.entries({ ...manifest.dependencies, ...manifest.devDependencies, ...manifest.optionalDependencies })
      .filter(([name]) => name.startsWith('@peanut-admin/'))
    for (const [name, specifier] of selections) {
      const i = packages[name]
      if (!i || specifier !== (mode === 'registry' ? i.version : `file:../${i.archive}`)) fail(`${client}:${name} declaration`)
    }
    if (mode === 'archive') continue // 开发静态合同继续检查实际 tgz；不冒充原生安装验收。
    if (client === 'web') {
      if (typeof parseYaml !== 'function') fail('locked YAML parser required for native pnpm lock')
      const lock = parseYaml(String(read('web/pnpm-lock.yaml')))
      if (String(lock?.lockfileVersion) !== '9.0' && String(lock?.lockfileVersion) !== '9') fail('pnpm lock version')
      const importer = lock.importers?.['.']
      for (const [name, specifier] of selections) {
        const reference = importer?.dependencies?.[name] ?? importer?.devDependencies?.[name] ?? importer?.optionalDependencies?.[name]
        if (reference?.specifier !== specifier || String(reference?.version).split('(')[0] !== packages[name].version) fail(`pnpm importer:${name}`)
      }
      for (const [key, value] of Object.entries(lock.packages ?? {})) {
        if (!key.startsWith('@peanut-admin/')) continue
        const name = key.slice(0, key.lastIndexOf('@')), i = packages[name]
        if (!i || key !== `${name}@${i.version}` || value.resolution?.integrity !== i.integrity
          || (value.resolution?.tarball && value.resolution.tarball !== i.resolved)) fail(`pnpm resolution:${key}`)
        checked.push(`${client}:${name}`)
      }
      for (const [name] of selections) if (!checked.includes(`${client}:${name}`)) fail(`pnpm package missing:${name}`)
    } else {
      const lock = json(`${client}/package-lock.json`)
      if (![2, 3].includes(lock.lockfileVersion)) fail(`${client} npm lock version`)
      for (const [name, specifier] of selections) {
        const root = lock.packages?.['']
        if ((root?.dependencies?.[name] ?? root?.devDependencies?.[name] ?? root?.optionalDependencies?.[name]) !== specifier
          || !lock.packages?.[`node_modules/${name}`]) fail(`${client} npm importer:${name}`)
      }
      for (const [path, value] of Object.entries(lock.packages ?? {})) {
        const name = path.slice(path.lastIndexOf('node_modules/') + 13)
        if (!name.startsWith('@peanut-admin/')) continue
        const i = packages[name]
        if (!i || value.link || value.version !== i.version || value.resolved !== i.resolved || value.integrity !== i.integrity) fail(`${client} npm resolution:${name}`)
        checked.push(`${client}:${name}`)
      }
    }
  }
  if (mode === 'archive') {
    for (const [name, i] of Object.entries(packages)) {
      if (createHash('sha256').update(read(i.archive)).digest('hex') !== i.sha256) fail(`${name} archive digest`)
    }
  }
  return { mode, checked, proof: mode === 'registry' ? 'native-lock-identity' : 'development-archive-identity', published: false }
}

/** 只解析真实安装位置；pnpm目录内链接合法，发布候选不得借用应用外源码。 */
function installedPath(root, path) {
  const actual = realpathSync(path)
  if (!actual.startsWith(root + sep)) fail('installed dependency outside application')
  return actual
}

/** 读取Composer原生安装记录，不启动框架、不生成services.php、不访问数据库。
 * 调用者仍须先以verifyDependencyLocks核对原生锁；本函数不证明业务运行或制品未被本地改写。
 */
export function verifyInstalledPhp(applicationRoot, expected) {
  const root = realpathSync(applicationRoot)
  const autoload = installedPath(root, resolve(root, 'server/vendor/autoload.php'))
  if (!lstatSync(autoload).isFile()) fail('PHP autoload unavailable')
  const metadataPath = installedPath(root, resolve(root, 'server/vendor/composer/installed.json'))
  const document = JSON.parse(readFileSync(metadataPath, 'utf8'))
  const records = Array.isArray(document) ? document : document?.packages
  if (!Array.isArray(records)) fail('PHP installed metadata')
  const matches = records.filter(item => item?.name === 'peanut-admin/core')
  const installed = matches[0]
  if (matches.length !== 1 || installed.version !== expected.resolved_version
    || installed.source?.type !== expected.source_type || installed.source?.url !== expected.source_url
    || installed.source?.reference !== expected.source_reference || installed.dist?.type === 'path'
    || (installed.dist?.reference && installed.dist.reference !== expected.source_reference)
    || typeof installed['install-path'] !== 'string' || installed['install-path'] === '') fail('PHP installed identity differs from native lock')
  const location = installedPath(root, resolve(dirname(metadataPath), installed['install-path']))
  const conventional = resolve(root, 'server/vendor/peanut-admin/core')
  if (lstatSync(conventional).isSymbolicLink() || location !== installedPath(root, conventional)) fail('PHP installed location')
  const manifest = JSON.parse(readFileSync(installedPath(root, resolve(location, 'composer.json')), 'utf8'))
  if (manifest.name !== expected.package) fail('PHP installed package name')
  return { package: installed.name, version: installed.version, source_reference: installed.source.reference,
    path: relative(root, location), proof: 'native-installed-metadata-and-contained-location' }
}

/** 开发archive模式保持本地Core连接；registry候选严格核实际PHP/Web安装位置。 */
export function verifyInstalledDependencies(applicationRoot, document) {
  const root = realpathSync(applicationRoot), independent = dependencyMode(document) === 'registry'
  const php = independent ? verifyInstalledPhp(root, document.core_php) : null
  const web = []
  for (const client of ['web', 'platform', 'pc', 'uniapp']) {
    const manifest = JSON.parse(readFileSync(resolve(root, client, 'package.json'), 'utf8'))
    for (const [name] of Object.entries({ ...manifest.dependencies, ...manifest.devDependencies, ...manifest.optionalDependencies })) {
      if (!name.startsWith('@peanut-admin/')) continue
      const file = resolve(root, client, 'node_modules', name, 'package.json')
      const actual = independent ? installedPath(root, file) : realpathSync(file)
      const installed = JSON.parse(readFileSync(actual, 'utf8'))
      if (installed.name !== name || installed.version !== document.core_web.packages[name]?.version) fail(`${client} installed:${name}`)
      web.push({ client, package: name, version: installed.version, contained: actual.startsWith(root + sep) })
    }
  }
  return { php, web, independent_locations_checked: independent,
    proof: independent ? 'native-installed-identities-and-locations' : 'development-web-manifests-only', runtime_qualified: false }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const args = process.argv.slice(2)
    if (!args[0] || args.length > 2 || (args[1] && args[1] !== '--installed')) throw new Error('Usage: release-dependency-locks.mjs <application-root> [--installed]')
    const root = realpathSync(args[0])
    const read = relative => {
      const path = resolve(root, relative)
      if (!path.startsWith(root + '/') || lstatSync(path).isSymbolicLink()) fail('source path')
      return readFileSync(path)
    }
    const document = JSON.parse(read('release-versions.json'))
    const result = verifyDependencyLocks(document, read, dependencyMode(document) === 'registry' ? lockedYamlParser(root) : undefined)
    const installed = args[1] === '--installed' ? verifyInstalledDependencies(root, document) : null
    console.log(JSON.stringify({ ...result, installed_manifests_checked: args[1] === '--installed', installed }))
  } catch (error) { console.error(error.message); process.exitCode = 1 }
}
