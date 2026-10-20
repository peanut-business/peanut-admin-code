import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { lstatSync, readFileSync, realpathSync } from 'node:fs'
import { isAbsolute, join, resolve } from 'node:path'
import { isDeepStrictEqual } from 'node:util'
import { pathToFileURL } from 'node:url'

export const templateEditions = ['standalone', 'multi-tenant']
export const templateAssetNames = templateEditions.flatMap(edition => [
  `peanut-scaffold-${edition}.tgz`, `peanut-scaffold-${edition}.json`,
])
const sha256 = bytes => createHash('sha256').update(bytes).digest('hex')
const fail = message => { throw new Error(`release-template-artifacts: ${message}`) }
const sha = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value)

function canonical(path, directory = false) {
  if (!isAbsolute(path) || realpathSync(path) !== resolve(path)) fail(`linked or noncanonical input: ${path}`)
  const stat = lstatSync(path)
  if (stat.isSymbolicLink() || (directory ? !stat.isDirectory() : !stat.isFile())) fail(`invalid input: ${path}`)
  if (!directory && (stat.nlink !== 1 || stat.size > 128 * 1024 * 1024)) fail(`unsafe file: ${path}`)
  return stat
}

function read(path) {
  canonical(path)
  return readFileSync(path)
}

/** Outer identities only. Archive members are checked by the native producer below. */
export function readTemplateArtifacts({ root, version, commit, tree, inventorySha256 }) {
  canonical(root, true)
  if (!/^[a-f0-9]{40}$/.test(commit) || !/^[a-f0-9]{40}$/.test(tree) || !sha(inventorySha256)) fail('invalid fixed source')
  const editions = {}
  const assets = {}
  for (const edition of templateEditions) {
    const directory = join(root, edition)
    canonical(directory, true)
    const name = `peanut-scaffold-${edition}`
    const metadataBytes = read(join(directory, name + '.json'))
    const metadata = JSON.parse(metadataBytes)
    const releaseBytes = read(join(directory, 'scaffold-manifest.json'))
    const release = JSON.parse(releaseBytes)
    const archiveBytes = read(join(directory, name + '.tgz'))
    if (metadata.schema_version !== 1 || metadata.protocol !== 'peanut.template-package.v1'
      || metadata.kind !== 'scaffold-template' || metadata.version !== version || metadata.edition !== edition
      || metadata.profile !== 'full' || !['none', 'runtime', 'full'].includes(metadata.workflow)
      || metadata.source?.repository !== 'peanut-business/peanut-admin-code'
      || metadata.source?.commit !== commit || metadata.source?.tree !== tree
      || metadata.source?.inventory_sha256 !== inventorySha256 || !sha(metadata.source?.edition_profile_sha256)
      || metadata.archive?.name !== name + '.tgz' || metadata.archive?.bytes !== archiveBytes.length
      || metadata.archive?.sha256 !== sha256(archiveBytes)
      || !sha(metadata.create_manifest_sha256) || !sha(metadata.upgrade_tooling_sha256)
      || metadata.scaffold_manifest_sha256 !== sha256(releaseBytes)
      || release.protocol !== 'peanut.scaffold-release.v3' || 'internal_core_projection' in release
      || release.release?.version !== version || release.release?.source_commit !== commit
      || release.release?.source_tree !== tree || release.release?.inventory_sha256 !== inventorySha256
      || release.release?.workflow !== metadata.workflow) fail(`template identity mismatch: ${edition}`)
    assets[name + '.tgz'] = { size_bytes: archiveBytes.length, sha256: sha256(archiveBytes) }
    assets[name + '.json'] = { size_bytes: metadataBytes.length, sha256: sha256(metadataBytes) }
    editions[edition] = {
      archive: name + '.tgz', manifest: name + '.json', workflow: metadata.workflow, profile: metadata.profile,
      scaffold_manifest_sha256: metadata.scaffold_manifest_sha256,
      create_manifest_sha256: metadata.create_manifest_sha256,
      upgrade_tooling_sha256: metadata.upgrade_tooling_sha256,
    }
  }
  if (editions.standalone.workflow !== editions['multi-tenant'].workflow) fail('Edition workflow mismatch')
  return { protocol: 'peanut.template-release-inputs.v1', version,
    source: { commit, tree, inventory_sha256: inventorySha256 }, editions, assets }
}

/** Reuses exact-source projection/member/mode/tooling checks; no second tar validator. */
export function verifyTemplateArtifacts(input, sourceRoot) {
  canonical(sourceRoot, true)
  const before = readTemplateArtifacts(input)
  for (const edition of templateEditions) {
    execFileSync('php', [join(sourceRoot, 'scripts/build-scaffold-release'),
      `--version=${input.version}`, `--source-commit=${input.commit}`, `--edition=${edition}`,
      `--workflow=${before.editions[edition].workflow}`, `--output=${join(input.root, edition)}`, '--check'],
    { cwd: sourceRoot, encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 })
  }
  if (!isDeepStrictEqual(before, readTemplateArtifacts(input))) fail('input changed during native check')
  return before
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [operation, root, version, commit, tree, inventorySha256, sourceRoot] = process.argv.slice(2)
  if (!['read', 'check'].includes(operation) || !inventorySha256
    || (operation === 'check' ? !sourceRoot : sourceRoot !== undefined)) fail('expected read/check root/version/commit/tree/inventory-sha256 [producer-root]')
  const input = { root, version, commit, tree, inventorySha256 }
  console.log(JSON.stringify(operation === 'check' ? verifyTemplateArtifacts(input, sourceRoot) : readTemplateArtifacts(input)))
}
