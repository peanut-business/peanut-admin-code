#!/usr/bin/env node

import { createHash } from 'node:crypto'
import { spawnSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const scriptDir = dirname(fileURLToPath(import.meta.url))
const rootDir = resolve(scriptDir, '..')
const checkOnly = process.argv.includes('--check')

const expectedCounts = {
  composer: 43,
  web: 849,
  platform: 151,
  pc: 923,
  uniapp: 1007,
  'docs-site': 174,
}

const readJson = (relativePath) => JSON.parse(readFileSync(resolve(rootDir, relativePath), 'utf8'))
const versionContract = readJson('release-versions.json')
const releaseMetadata = readJson('RELEASE_METADATA.json')
const releaseVersion = versionContract.schema_version === 2
  ? versionContract.source_product_version
  : versionContract.product_release
const metadataVersion = releaseMetadata.schema_version === 2
  && releaseMetadata.protocol === 'peanut.release-metadata.v2'
  ? (releaseMetadata.instance_version ?? releaseMetadata.source_product_version)
  : releaseMetadata.version
if (metadataVersion !== releaseVersion
  || (versionContract.schema_version === 2
    && (releaseMetadata.source_product_version !== releaseVersion
      || releaseMetadata.instance_version !== null))
  || releaseMetadata.expected_tag !== `v${releaseVersion}`) {
  throw new Error(`RELEASE_METADATA version contract mismatch: expected ${releaseVersion}`)
}
const releaseTag = releaseMetadata.expected_tag
const releaseDate = releaseMetadata.release_date
const corePhpVersion = versionContract.core_php
const coreWebVersion = versionContract.core_web
const corePcVersion = readJson('pc/package.json').dependencies['@peanut-admin/admin']
const coreUniappVersion = readJson('uniapp/package.json').dependencies['@peanut-admin/admin']

const normalizeLicense = (license, name, version) => {
  if ((name === 'trim' && version === '0.0.1') ||
      (name === 'only' && version === '0.0.2') ||
      (name === 'exif-parser' && version === '0.1.12') ||
      (name === 'dom-walk' && version === '0.1.2') ||
      (name === 'exit' && version === '0.1.2')) {
    return 'MIT'
  }
  if (name === 'qrcode-terminal' && version === '0.12.0') return 'Apache-2.0'
  if (name === 'tslib') return '0BSD'
  if (name.startsWith('@emnapi/') ||
      name.startsWith('@esbuild/') ||
      name === '@napi-rs/wasm-runtime' ||
      name === '@tybys/wasm-util' ||
      name.startsWith('@unrs/resolver-binding-') ||
      name.startsWith('esbuild-') ||
      name === '@napi-rs/lzma-linux-x64-gnu' ||
      name.startsWith('@rollup/rollup-')) {
    return 'MIT'
  }
  if (!license || license === 'Unknown') return 'NOASSERTION'
  if (license === 'Apache 2.0') return 'Apache-2.0'
  if (license === 'BSD') return 'LicenseRef-BSD-ambiguous'
  return license.replace(/^\((.*)\)$/, '$1')
}

const packageId = (ecosystem, name, version, location) => {
  const digest = createHash('sha256')
    .update(`${ecosystem}\0${name}\0${version}\0${location}`)
    .digest('hex')
    .slice(0, 20)
  return `SPDXRef-Package-${ecosystem.replace(/[^A-Za-z0-9.-]/g, '-')}-${digest}`
}

const npmPurl = (name, version) => {
  const encodedName = name.startsWith('@')
    ? `%40${name.slice(1).split('/').map(encodeURIComponent).join('/')}`
    : encodeURIComponent(name)
  return `pkg:npm/${encodedName}@${encodeURIComponent(version)}`
}

const makePackage = ({ ecosystem, name, version, location, license, source, scope }) => ({
  name,
  SPDXID: packageId(ecosystem, name, version, location),
  versionInfo: version,
  downloadLocation: source || 'NOASSERTION',
  filesAnalyzed: false,
  licenseConcluded: 'NOASSERTION',
  licenseDeclared: normalizeLicense(license, name, version),
  copyrightText: 'NOASSERTION',
  primaryPackagePurpose: ecosystem === 'composer' && scope === 'runtime' ? 'LIBRARY' : 'SOURCE',
  packageComment: `Peanut Admin ${ecosystem} locked dependency; release scope: ${scope}.`,
  externalRefs: [{
    referenceCategory: 'PACKAGE-MANAGER',
    referenceType: 'purl',
    referenceLocator: ecosystem === 'composer'
      ? `pkg:composer/${name}@${encodeURIComponent(version)}`
      : npmPurl(name, version),
  }],
  _ecosystem: ecosystem,
  _scope: scope,
})

const composerLock = readJson('server/composer.lock')
const composerPackages = [
  ...composerLock.packages.map((pkg) => ({ pkg, scope: 'runtime' })),
  ...composerLock['packages-dev'].map((pkg) => ({ pkg, scope: 'development' })),
].map(({ pkg, scope }) => makePackage({
  ecosystem: 'composer',
  name: pkg.name,
  version: pkg.version,
  location: `${pkg.name}@${pkg.version}`,
  license: Array.isArray(pkg.license) ? pkg.license.join(' AND ') : pkg.license,
  source: pkg.source?.url || pkg.dist?.url,
  scope,
}))

const npmNameFromPath = (packagePath) => {
  const marker = 'node_modules/'
  const offset = packagePath.lastIndexOf(marker)
  const relative = packagePath.slice(offset + marker.length)
  const parts = relative.split('/')
  return parts[0].startsWith('@') ? `${parts[0]}/${parts[1]}` : parts[0]
}

const readNpmLock = (ecosystem, relativePath) => {
  const lock = readJson(relativePath)
  return Object.entries(lock.packages)
    .filter(([packagePath, pkg]) => packagePath && pkg.version)
    .map(([packagePath, pkg]) => {
      const name = npmNameFromPath(packagePath)
      return makePackage({
        ecosystem,
        name,
        version: pkg.version,
        location: packagePath,
        license: pkg.license,
        source: pkg.resolved,
        scope: pkg.dev ? 'development-or-build' : 'static-build-input',
      })
    })
}

const yamlUnquote = (value) => {
  if (value.startsWith("'") && value.endsWith("'")) {
    return value.slice(1, -1).replace(/''/g, "'")
  }
  return value
}

const readPnpmLicenses = (relativeDir) => {
  const result = spawnSync('pnpm', ['--dir', resolve(rootDir, relativeDir), 'licenses', 'list', '--json'], {
    encoding: 'utf8',
  })
  if (result.status !== 0) {
    throw new Error(`pnpm license inventory failed for ${relativeDir}: ${result.stderr.trim()}`)
  }
  const grouped = JSON.parse(result.stdout)
  const licenses = new Map()
  for (const [license, entries] of Object.entries(grouped)) {
    for (const entry of entries) {
      for (const version of entry.versions) licenses.set(`${entry.name}@${version}`, license)
    }
  }
  return licenses
}

const readPnpmLock = (ecosystem, relativeDir) => {
  const lockText = readFileSync(resolve(rootDir, relativeDir, 'pnpm-lock.yaml'), 'utf8')
  const packageBlock = lockText.split('\npackages:\n', 2)[1]?.split('\nsnapshots:\n', 1)[0]
  if (!packageBlock) throw new Error(`packages block not found in ${relativeDir}/pnpm-lock.yaml`)
  const licenseMap = readPnpmLicenses(relativeDir)
  const specs = packageBlock.split('\n')
    .map((line) => line.match(/^  ([^ ].+):$/)?.[1])
    .filter(Boolean)
    .map(yamlUnquote)
  return specs.map((spec) => {
    const separator = spec.lastIndexOf('@')
    if (separator <= 0) throw new Error(`unsupported pnpm package key: ${spec}`)
    const name = spec.slice(0, separator)
    const version = spec.slice(separator + 1)
    return makePackage({
      ecosystem,
      name,
      version,
      location: spec,
      license: licenseMap.get(`${name}@${version}`),
      source: `https://registry.npmjs.org/${encodeURIComponent(name)}/${encodeURIComponent(version)}`,
      scope: ecosystem === 'docs-site' ? 'documentation-build-input' : 'static-build-input',
    })
  })
}

const allPackages = [
  ...composerPackages,
  ...readPnpmLock('web', 'web'),
  ...readNpmLock('platform', 'platform/package-lock.json'),
  ...readNpmLock('pc', 'pc/package-lock.json'),
  ...readNpmLock('uniapp', 'uniapp/package-lock.json'),
  ...readPnpmLock('docs-site', 'docs-site'),
].sort((a, b) =>
  a._ecosystem.localeCompare(b._ecosystem) ||
  a.name.localeCompare(b.name) ||
  a.versionInfo.localeCompare(b.versionInfo) ||
  a.SPDXID.localeCompare(b.SPDXID))

for (const [ecosystem, expected] of Object.entries(expectedCounts)) {
  const actual = allPackages.filter((pkg) => pkg._ecosystem === ecosystem).length
  if (actual !== expected) throw new Error(`${ecosystem} lock count changed: expected ${expected}, found ${actual}`)
}

const unresolved = allPackages.filter((pkg) => pkg.licenseDeclared === 'NOASSERTION')
if (unresolved.length > 0) {
  throw new Error(`unresolved license metadata: ${unresolved.map((pkg) => `${pkg._ecosystem}:${pkg.name}@${pkg.versionInfo}`).join(', ')}`)
}

const rootPackage = {
  name: 'Peanut Admin',
  SPDXID: 'SPDXRef-Package-Peanut-Admin',
  versionInfo: releaseVersion,
  downloadLocation: 'https://github.com/peanut-business/peanut-admin-code',
  filesAnalyzed: false,
  licenseConcluded: 'Apache-2.0',
  licenseDeclared: 'Apache-2.0',
  copyrightText: 'Copyright 2026 花生科技',
  primaryPackagePurpose: 'APPLICATION',
  externalRefs: [{
    referenceCategory: 'PACKAGE-MANAGER',
    referenceType: 'purl',
    referenceLocator: `pkg:github/peanut-business/peanut-admin-code@${releaseVersion}`,
  }],
}

const sbomPackages = allPackages.map(({ _ecosystem, _scope, ...pkg }) => pkg)
const sbom = {
  spdxVersion: 'SPDX-2.3',
  dataLicense: 'CC0-1.0',
  SPDXID: 'SPDXRef-DOCUMENT',
  name: `Peanut Admin ${releaseVersion} release dependency SBOM`,
  documentNamespace: `https://github.com/peanut-business/peanut-admin-code/releases/tag/${releaseTag}/sbom`,
  creationInfo: {
    created: `${releaseDate}T00:00:00Z`,
    creators: ['Organization: 花生科技', 'Tool: scripts/generate-release-legal.mjs'],
    licenseListVersion: '3.27',
  },
  documentDescribes: [rootPackage.SPDXID],
  hasExtractedLicensingInfos: [{
    licenseId: 'LicenseRef-BSD-ambiguous',
    extractedText: 'The upstream package declares BSD without a more specific SPDX identifier. Consult the package source recorded in this SBOM.',
    name: 'Upstream ambiguous BSD declaration',
  }],
  packages: [rootPackage, ...sbomPackages],
  relationships: allPackages.map((pkg) => ({
    spdxElementId: rootPackage.SPDXID,
    relationshipType: 'DEPENDS_ON',
    relatedSpdxElement: pkg.SPDXID,
    comment: `${pkg._ecosystem}; ${pkg._scope}`,
  })),
}

const licenseCounts = new Map()
for (const pkg of allPackages) {
  const key = `${pkg._ecosystem}\0${pkg.licenseDeclared}`
  licenseCounts.set(key, (licenseCounts.get(key) || 0) + 1)
}

const commonLicenses = new Set([
  'MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC', '0BSD',
])
const notablePackages = [...new Map(
  allPackages
    .filter((pkg) => !commonLicenses.has(pkg.licenseDeclared))
    .map((pkg) => [`${pkg.name}@${pkg.versionInfo}\0${pkg.licenseDeclared}`, pkg]),
).values()]
  .sort((a, b) => a.licenseDeclared.localeCompare(b.licenseDeclared) || a.name.localeCompare(b.name))

const composerRuntime = allPackages.filter((pkg) => pkg._ecosystem === 'composer' && pkg._scope === 'runtime')
const licenseRows = [...licenseCounts.entries()]
  .sort(([a], [b]) => a.localeCompare(b))
  .map(([key, count]) => {
    const [ecosystem, license] = key.split('\0')
    return `| ${ecosystem} | \`${license}\` | ${count} |`
  })
  .join('\n')
const composerRows = composerRuntime
  .map((pkg) => `| \`${pkg.name}\` | \`${pkg.versionInfo}\` | \`${pkg.licenseDeclared}\` | ${pkg.downloadLocation} |`)
  .join('\n')
const notableRows = notablePackages
  .map((pkg) => `| ${pkg._ecosystem} | \`${pkg.name}\` | \`${pkg.versionInfo}\` | \`${pkg.licenseDeclared}\` | ${pkg.downloadLocation} |`)
  .join('\n')

const notices = `# Peanut Admin Third-Party Notices

Generated for Peanut Admin ${releaseVersion} on ${releaseDate}.

Peanut Admin is licensed under Apache-2.0: Copyright 2026 花生科技. Third-party components remain governed by their own licenses.

## Distribution boundary

- The normative GitHub Release distributes this repository's source. It does not attach prebuilt PHP/Nginx images; the fixed core packages are published separately in their public registries.
- Production Compose builds static management, PC and H5 assets and installs the ${composerRuntime.length} Composer production packages listed below. No \`node_modules\` directory is copied into the final images.
- The exhaustive package/version/license/source inventory for the six locked dependency graphs is \`RELEASE_SBOM.spdx.json\` (SPDX 2.3). Build-only entries are retained there so source-release recipients can reproduce the build and its notices.
- Each installed dependency may include additional license or notice files. Those files remain authoritative for that dependency and must not be removed from redistributed dependency archives.

## Material source and framework attributions

| Source | License | Attribution and use in this release |
|---|---|---|
| Arco Design Pro Vue | MIT | The initial management client used Arco Design Pro Vue material; applicable upstream MIT attribution is retained. Source: https://github.com/arco-design/arco-design-pro-vue |
| LikeAdmin 1.9.4 | MIT | Used as the documented behavioral parity reference. This notice does not claim the application is a clean-room implementation. Source: https://github.com/likeadmin-likeshop/likeadmin_php |
| ThinkPHP 8 | Apache-2.0 | Backend framework. Its upstream notice is also retained at \`server/LICENSE.txt\`. Source: https://github.com/top-think/framework |
| \`peanut-admin/core\` | Apache-2.0 | Composer core package locked at ${corePhpVersion}. Source: https://github.com/peanut-business/peanut-admin-core-php |
| \`@peanut-admin/admin\` | Apache-2.0 | npm core package locked at ${coreWebVersion} for Web, ${corePcVersion} for PC and ${coreUniappVersion} for UniApp. Source: https://github.com/peanut-business/peanut-admin-core-web |

## License handling

- MIT, ISC, BSD, 0BSD, MIT-0, Apache-2.0 and Zlib notices are preserved through this file, the SPDX inventory and the upstream package sources recorded there.
- MPL-2.0 entries are build inputs in the current Nuxt lock graph; no standalone package or modified MPL source is shipped as a release attachment. If a future release distributes those files, it must add the MPL source/notice obligations for that artifact.
- CC0, CC-BY, BlueOak-1.0.0 and Python-2.0 entries are identified below and in the SPDX inventory; attribution-bearing data must keep its upstream credit when redistributed.
- Compound expressions retain the upstream choice exactly. \`node-forge@1.4.0\` is recorded as \`BSD-3-Clause OR GPL-2.0\`; this release relies on the permissive BSD-3-Clause option and does not claim a GPL grant for Peanut Admin.
- \`@tybys/wasm-util@0.10.3\` and \`@napi-rs/lzma-linux-x64-gnu@1.5.1\` publish MIT metadata but no separate copyright line or NOTICE in the inspected upstream artifact. Their package/version/source is recorded without inventing an attribution.

## Locked-license summary

| Ecosystem | Declared license | Lock entries |
|---|---|---:|
${licenseRows}

## Composer production packages

These ${composerRuntime.length} packages are installed with \`composer install --no-dev\` in the production PHP image.

| Package | Version | License | Source |
|---|---|---|---|
${composerRows}

## Non-default and compound license entries

The following deduplicated package/version entries are outside the common MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC and 0BSD set. The full inventory, including every common-license entry, is in \`RELEASE_SBOM.spdx.json\`.

| Ecosystem | Package | Version | License | Source |
|---|---|---|---|---|
${notableRows}

## Obtaining license texts

The SPDX identifiers above resolve through https://spdx.org/licenses/. Exact package sources and versions are recorded in \`RELEASE_SBOM.spdx.json\`; Composer-installed packages also retain their upstream license files in \`server/vendor\` after deployment. For a redistributed binary or image, include this file, the SBOM and any additional license files required by the packages actually embedded in that artifact.
`

const outputs = new Map([
  ['RELEASE_SBOM.spdx.json', `${JSON.stringify(sbom, null, 2)}\n`],
  ['THIRD_PARTY_NOTICES.md', notices],
])

let different = false
for (const [relativePath, content] of outputs) {
  const outputPath = resolve(rootDir, relativePath)
  if (checkOnly) {
    let existing = ''
    try {
      existing = readFileSync(outputPath, 'utf8')
    } catch {
      different = true
      console.error(`${relativePath}: missing`)
      continue
    }
    if (existing !== content) {
      different = true
      console.error(`${relativePath}: out of date`)
    }
  } else {
    writeFileSync(outputPath, content)
    console.log(`${relativePath}: generated`)
  }
}

if (different) process.exitCode = 1
