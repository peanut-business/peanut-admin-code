import { execFileSync, spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { lstatSync, readFileSync, readdirSync, realpathSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { isDeepStrictEqual } from 'node:util'
import { templateAssetNames } from './release-template-artifacts.mjs'

const repository = 'peanut-business/peanut-admin-code'
const digest = bytes => createHash('sha256').update(bytes).digest('hex')
const fail = message => { throw new Error(`release-asset-verification: ${message}`) }

export function lookupRelease(version) {
  if (!/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(version)) fail('invalid version')
  const visible = JSON.parse(execFileSync('gh', ['api', `repos/${repository}`], { encoding: 'utf8', timeout: 60000 }))
  if (visible.full_name !== repository) fail('repository visibility identity differs')
  const result = spawnSync('gh', ['api', `repos/${repository}/releases/tags/v${version}`],
    { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024, timeout: 60000 })
  if (result.error || result.signal) fail('Release lookup has unknown transport result')
  if (result.status === 1 && result.stderr.includes('(HTTP 404)')) return null
  if (result.status !== 0) fail(`Release lookup failed with exit ${result.status}; absence not established`)
  const release = JSON.parse(result.stdout)
  if (release.tag_name !== `v${version}` || !Number.isSafeInteger(release.id) || release.id <= 0) fail('Release lookup identity differs')
  return release
}

export function publicationAssets(version) {
  return [`peanut-admin-${version}.tar.gz`, `peanut-admin-${version}-LICENSE.txt`,
    `peanut-admin-${version}-NOTICE.txt`, `peanut-admin-${version}-THIRD-PARTY-NOTICES.md`,
    `peanut-admin-${version}-SBOM.spdx.json`, 'RELEASE_CANDIDATE_LOCK.json',
    ...templateAssetNames, 'RELEASE_MANIFEST.json'].sort()
}

/** Bind current local bytes to the sealed manifest before any external write. */
export function verifyLocalAssets(publication, version) {
  if (!/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(version)) fail('invalid version')
  if (realpathSync(publication) !== resolve(publication) || !lstatSync(publication).isDirectory()) fail('unsafe publication directory')
  const expected = publicationAssets(version)
  const allowed = new Set([...expected, 'release-notes.md', 'MAIN_INTEGRATION_PROOF.json'])
  const names = readdirSync(publication)
  if (names.some(name => !allowed.has(name)) || expected.some(name => !names.includes(name))) fail('local attachment set differs')
  const files = {}
  for (const name of expected) {
    const path = join(publication, name)
    const stat = lstatSync(path)
    if (stat.isSymbolicLink() || !stat.isFile() || stat.nlink !== 1 || stat.size > 128 * 1024 * 1024) fail(`unsafe local attachment: ${name}`)
    const bytes = readFileSync(path)
    files[name] = { size: bytes.length, sha256: digest(bytes) }
  }
  const manifest = JSON.parse(readFileSync(join(publication, 'RELEASE_MANIFEST.json')))
  const lock = JSON.parse(readFileSync(join(publication, 'RELEASE_CANDIDATE_LOCK.json')))
  if (manifest.schema_version !== 1 || manifest.version !== version || manifest.tag !== `v${version}`
    || manifest.repository !== `https://github.com/${repository}` || manifest.commit !== lock.candidate?.commit
    || manifest.candidate_lock?.path !== 'RELEASE_CANDIDATE_LOCK.json'
    || manifest.candidate_lock.sha256 !== files['RELEASE_CANDIDATE_LOCK.json'].sha256
    || !isDeepStrictEqual(manifest.template_artifacts, lock.template_artifacts)
    || lock.inputs?.template_artifacts_sha256 !== digest(JSON.stringify(lock.template_artifacts))) fail('sealed local release identity differs')
  const manifestNames = expected.filter(name => name !== 'RELEASE_MANIFEST.json').sort()
  if (!isDeepStrictEqual(Object.keys(manifest.artifacts ?? {}).sort(), manifestNames)) fail('sealed local manifest attachment set differs')
  for (const name of manifestNames) {
    const row = manifest.artifacts[name], file = files[name]
    if (row?.size_bytes !== file.size || row.sha256 !== file.sha256) fail(`sealed local attachment bytes differ: ${name}`)
    if (templateAssetNames.includes(name) && !isDeepStrictEqual(row, lock.template_artifacts?.assets?.[name])) fail(`qualified local template differs: ${name}`)
  }
  return files
}

/** Read-only reconciliation. Never uploads, deletes or overwrites a remote asset. */
export function verifyPublishedAssets(publication, version, prerelease, suppliedRelease = null) {
  const files = verifyLocalAssets(publication, version)
  const expected = publicationAssets(version)
  const release = suppliedRelease ?? JSON.parse(execFileSync('gh', ['api', `repos/${repository}/releases/tags/v${version}`], { encoding: 'utf8', timeout: 60000 }))
  if (release.tag_name !== `v${version}` || release.draft !== false || release.prerelease !== prerelease
    || !Number.isSafeInteger(release.id) || release.id <= 0) fail('remote Release identity/channel differs')
  if (!Array.isArray(release.assets) || release.assets.length !== expected.length
    || new Set(release.assets.map(asset => asset.name)).size !== expected.length
    || release.assets.some(asset => !expected.includes(asset.name))) fail('remote attachment set differs')
  for (const asset of release.assets) {
    const local = files[asset.name]
    if (!Number.isSafeInteger(asset.id) || asset.id <= 0 || asset.size !== local.size
      || asset.state !== 'uploaded' || asset.digest !== `sha256:${local.sha256}`) fail(`remote asset metadata differs: ${asset.name}`)
    const bytes = execFileSync('gh', ['api', `repos/${repository}/releases/assets/${asset.id}`,
      '-H', 'Accept: application/octet-stream'], { maxBuffer: 128 * 1024 * 1024, timeout: 60000 })
    if (bytes.length !== local.size || digest(bytes) !== local.sha256) fail(`downloaded asset bytes differ: ${asset.name}`)
  }
  return { release_id: release.id, assets: Object.fromEntries(expected.map(name => [name, files[name].sha256])) }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  if (process.argv[2] === 'lookup') {
    if (process.argv.length !== 4) fail('expected lookup version')
    const release = lookupRelease(process.argv[3])
    console.log(JSON.stringify({ exists: release !== null, release }))
  } else if (process.argv[2] === 'local') {
    if (process.argv.length !== 5) fail('expected local publication/version')
    console.log(JSON.stringify(verifyLocalAssets(process.argv[3], process.argv[4])))
  } else {
  const [publication, version, channel, supplied] = process.argv.slice(2)
  if (!publication || !version || !['stable', 'prerelease'].includes(channel)
    || (supplied !== undefined && supplied !== '--release-json-stdin')) fail('expected publication/version/channel and optional --release-json-stdin')
  const release = supplied ? JSON.parse(readFileSync(0, 'utf8')) : null
  console.log(JSON.stringify(verifyPublishedAssets(publication, version, channel === 'prerelease', release)))
  }
}
