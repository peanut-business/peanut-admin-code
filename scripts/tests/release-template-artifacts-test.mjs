import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { createHash } from 'node:crypto'
import { readTemplateArtifacts, verifyTemplateArtifacts } from '../release-template-artifacts.mjs'
import { lookupRelease, publicationAssets, verifyDraftRelease, verifyPublishedAssets } from '../release-asset-verification.mjs'

const hash = bytes => createHash('sha256').update(bytes).digest('hex')
const json = (file, value) => fs.writeFileSync(file, JSON.stringify(value) + '\n')
const fixture = () => {
  const root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'peanut-release-template-')))
  const input = { root, version: '5.0.2', commit: 'a'.repeat(40), tree: 'b'.repeat(40), inventorySha256: 'c'.repeat(64) }
  for (const edition of ['standalone', 'multi-tenant']) {
    const directory = path.join(root, edition)
    fs.mkdirSync(directory)
    const release = { protocol: 'peanut.scaffold-release.v3', release: { version: input.version,
      source_commit: input.commit, source_tree: input.tree, inventory_sha256: input.inventorySha256, workflow: 'runtime' } }
    const manifest = path.join(directory, 'scaffold-manifest.json')
    json(manifest, release)
    const archiveName = `peanut-scaffold-${edition}.tgz`
    const bytes = Buffer.from('synthetic archive; outer identity checks only: ' + edition)
    fs.writeFileSync(path.join(directory, archiveName), bytes)
    json(path.join(directory, `peanut-scaffold-${edition}.json`), {
      schema_version: 1, protocol: 'peanut.template-package.v1', kind: 'scaffold-template', version: input.version,
      edition, profile: 'full', workflow: 'runtime',
      source: { repository: 'peanut-business/peanut-admin-code', commit: input.commit, tree: input.tree,
        inventory_sha256: input.inventorySha256, edition_profile_sha256: 'd'.repeat(64) },
      archive: { name: archiveName, bytes: bytes.length, sha256: hash(bytes) },
      create_manifest_sha256: 'e'.repeat(64), upgrade_tooling_sha256: 'f'.repeat(64),
      scaffold_manifest_sha256: hash(fs.readFileSync(manifest)),
    })
    const metadata = JSON.parse(fs.readFileSync(path.join(directory, `peanut-scaffold-${edition}.json`)))
    json(path.join(directory, 'scaffold-build-inputs.json'), {
      protocol: 'peanut.scaffold-build-inputs.v1', source: metadata.source, payload_sha256: hash(bytes),
      inputs: { synthetic: true }, environment: { tools: { synthetic: true } },
      artifacts: Object.fromEntries([archiveName, `peanut-scaffold-${edition}.json`, 'scaffold-manifest.json'].map(name => {
        const body = fs.readFileSync(path.join(directory, name))
        return [name, { bytes: body.length, sha256: hash(body) }]
      })),
    })
  }
  return { root, input, close: () => fs.rmSync(root, { recursive: true }) }
}

test('two Edition outer identities reject source, Edition, payload and link drift', () => {
  const f = fixture()
  try {
    const before = readTemplateArtifacts(f.input)
    assert.equal(Object.keys(before.assets).length, 4)
    for (const field of ['commit', 'tree', 'inventorySha256']) {
      assert.throws(() => readTemplateArtifacts({ ...f.input, [field]: '0'.repeat(field === 'inventorySha256' ? 64 : 40) }))
    }
    const meta = path.join(f.root, 'standalone/peanut-scaffold-standalone.json')
    const original = fs.readFileSync(meta)
    for (const patch of [{ edition: 'multi-tenant' }, { protocol: 'peanut.edition-installer.v1' }, { workflow: 'none' }]) {
      json(meta, { ...JSON.parse(original), ...patch })
      assert.throws(() => readTemplateArtifacts(f.input))
    }
    fs.writeFileSync(meta, original)
    const archive = path.join(f.root, 'standalone/peanut-scaffold-standalone.tgz')
    const bytes = fs.readFileSync(archive)
    fs.appendFileSync(archive, 'drift')
    assert.throws(() => readTemplateArtifacts(f.input))
    fs.writeFileSync(archive, bytes)
    const moved = archive + '.original'
    fs.renameSync(archive, moved); fs.symlinkSync(moved, archive)
    assert.throws(() => readTemplateArtifacts(f.input))
  } finally { f.close() }
})

test('native check wiring binds both workflows and rechecks unchanged artifacts', () => {
  const f = fixture()
  const previous = process.env.PATH
  try {
    const bin = path.join(f.root, 'bin'); fs.mkdirSync(bin)
    const log = path.join(f.root, 'calls.jsonl')
    fs.writeFileSync(path.join(bin, 'php'), `#!/usr/bin/env node\nrequire('node:fs').appendFileSync(${JSON.stringify(log)},JSON.stringify(process.argv.slice(2))+'\\n')\n`, { mode: 0o755 })
    process.env.PATH = bin + path.delimiter + previous
    assert.equal(verifyTemplateArtifacts(f.input, f.root).protocol, 'peanut.template-release-inputs.v1')
    const calls = fs.readFileSync(log, 'utf8').trim().split('\n').map(JSON.parse)
    assert.equal(calls.length, 2)
    for (const [index, edition] of ['standalone', 'multi-tenant'].entries()) {
      assert.ok(calls[index].includes('--edition=' + edition))
      assert.ok(calls[index].includes('--source-commit=' + f.input.commit))
      assert.ok(calls[index].includes('--workflow=runtime'))
      assert.equal(calls[index].at(-1), '--check')
    }
  } finally { process.env.PATH = previous; f.close() }
})

const publicationFixture = root => {
  const publication = path.join(root, 'publication'); fs.mkdirSync(publication)
  const remote = path.join(root, 'remote'); fs.mkdirSync(remote)
  const version = '5.0.2', commit = 'a'.repeat(40)
  const names = publicationAssets(version)
  for (const name of names) fs.writeFileSync(path.join(publication, name), name)
  const metadata = name => {
    const bytes = fs.readFileSync(path.join(publication, name))
    return { size_bytes: bytes.length, sha256: hash(bytes) }
  }
  const templates = { assets: Object.fromEntries(names.filter(name => name.startsWith('peanut-scaffold-')).map(name => [name, metadata(name)])) }
  const lock = { candidate: { commit }, template_artifacts: templates, inputs: { template_artifacts_sha256: hash(JSON.stringify(templates)) } }
  json(path.join(publication, 'RELEASE_CANDIDATE_LOCK.json'), lock)
  json(path.join(publication, 'RELEASE_MANIFEST.json'), { schema_version: 1, version, tag: 'v' + version,
    repository: 'https://github.com/peanut-business/peanut-admin-code', commit, template_artifacts: templates,
    candidate_lock: { path: 'RELEASE_CANDIDATE_LOCK.json', sha256: metadata('RELEASE_CANDIDATE_LOCK.json').sha256 },
    artifacts: Object.fromEntries(names.filter(name => name !== 'RELEASE_MANIFEST.json').map(name => [name, metadata(name)])) })
  const assets = names.map((name, index) => {
    const bytes = fs.readFileSync(path.join(publication, name))
    fs.writeFileSync(path.join(remote, String(index + 1)), bytes)
    return { id: index + 1, name, size: bytes.length, state: 'uploaded', digest: 'sha256:' + hash(bytes) }
  })
  const bin = path.join(root, 'bin'); fs.mkdirSync(bin)
  fs.writeFileSync(path.join(bin, 'gh'), `#!/usr/bin/env node\nconst fs=require('node:fs');const args=process.argv.slice(2);if(args[0]!=='api'||!args[1].includes('/releases/assets/'))process.exit(91);process.stdout.write(fs.readFileSync(${JSON.stringify(remote)}+'/'+args[1].split('/').at(-1)))\n`, { mode: 0o755 })
  return { publication, remote, assets, bin, version, commit }
}

test('published attachments require exact channel/set and actual downloaded bytes', () => {
  const root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'peanut-release-assets-')))
  const previous = process.env.PATH
  try {
    const { publication, remote, assets, bin } = publicationFixture(root)
    const release = { id: 27, tag_name: 'v5.0.2', prerelease: false, draft: false, assets }
    process.env.PATH = bin + path.delimiter + previous
    assert.equal(Object.keys(verifyPublishedAssets(publication, '5.0.2', false, release).assets).length, 11)
    assert.throws(() => verifyPublishedAssets(publication, '5.0.2', true, release), /channel/)
    assert.throws(() => verifyPublishedAssets(publication, '5.0.2', false, { ...release, assets: assets.slice(1) }), /set differs/)
    assert.throws(() => verifyPublishedAssets(publication, '5.0.2', false, { ...release, assets: [...assets.slice(1), assets[1]] }), /set differs/)
    fs.writeFileSync(path.join(remote, '1'), 'different downloaded body')
    assert.throws(() => verifyPublishedAssets(publication, '5.0.2', false, release), /downloaded asset bytes differ/)
  } finally { process.env.PATH = previous; fs.rmSync(root, { recursive: true }) }
})

test('only the exact target draft original transport can precede the sealed eleven attachments', () => {
  const root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'peanut-release-draft-')))
  const previous = process.env.PATH
  try {
    const { publication, remote, assets, bin, version, commit } = publicationFixture(root)
    process.env.PATH = bin + path.delimiter + previous
    const bytes = Buffer.from('original independently admitted transport')
    fs.writeFileSync(path.join(remote, '90'), bytes)
    const transport = { id: 90, name: 'scaffold-full-qualification.tgz', state: 'uploaded', size: bytes.length,
      digest: 'sha256:' + hash(bytes), url: 'https://api.github.com/repos/peanut-business/peanut-admin-code/releases/assets/90' }
    const release = { id: 27, tag_name: 'v' + version, draft: true, prerelease: false, target_commitish: commit,
      url: 'https://api.github.com/repos/peanut-business/peanut-admin-code/releases/27', assets: [transport] }
    const check = (value, sha = hash(bytes), releaseId = null, transportId = null) =>
      verifyDraftRelease(publication, version, false, commit, sha, value, releaseId, transportId)
    assert.deepEqual(check(null), { release_id: 0, transport_id: 0 })
    assert.deepEqual(check(release), { release_id: 27, transport_id: 90 })
    for (const patch of [{ draft: false }, { prerelease: true }, { tag_name: 'v5.0.1' }, { target_commitish: 'main' },
      { target_commitish: 'b'.repeat(40) }, { url: 'https://api.github.com/repos/foreign/repo/releases/27' },
      { assets: [] }, { assets: [transport, assets[0]] }, { assets: [transport, transport] },
      { assets: [{ ...transport, digest: 'sha256:' + '0'.repeat(64) }] },
      { assets: [{ ...transport, url: 'https://api.github.com/repos/foreign/repo/releases/assets/90' }] },
      { assets: [{ ...transport, state: 'new' }] }]) assert.throws(() => check({ ...release, ...patch }))
    assert.throws(() => check(release, ''), /identity/)
    fs.writeFileSync(path.join(remote, '90'), 'wrong downloaded transport')
    assert.throws(() => check(release), /downloaded transport/)
    fs.writeFileSync(path.join(remote, '90'), bytes)
    const uploaded = { ...release, assets: [transport, ...assets] }
    assert.deepEqual(check(uploaded, hash(bytes), 27, 90), { release_id: 27, transport_id: 90 })
    assert.throws(() => check(uploaded, hash(bytes), 28, 90), /identity/)
    assert.throws(() => check(uploaded, hash(bytes), 27, 91), /transport/)
    assert.throws(() => check({ ...uploaded, assets: uploaded.assets.slice(1) }, hash(bytes), 27, 90), /transport/)
    assert.throws(() => check({ ...uploaded, assets: [transport, ...assets.slice(1)] }, hash(bytes), 27, 90), /transport/)
    assert.throws(() => check({ ...uploaded, assets: [transport, transport, ...assets] }, hash(bytes), 27, 90), /transport/)
    fs.writeFileSync(path.join(remote, '1'), 'changed final bytes')
    assert.throws(() => check(uploaded, hash(bytes), 27, 90), /downloaded asset bytes/)
  } finally { process.env.PATH = previous; fs.rmSync(root, { recursive: true }) }
})

test('only explicit Release 404 after repository visibility establishes absence', () => {
  const root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'peanut-release-lookup-')))
  const previous = process.env.PATH
  try {
    const bin = path.join(root, 'bin'); fs.mkdirSync(bin)
    const state = path.join(root, 'state.json')
    fs.writeFileSync(path.join(bin, 'gh'), `#!/usr/bin/env node
const fs=require('node:fs');const s=JSON.parse(fs.readFileSync(${JSON.stringify(state)}));const args=process.argv.slice(2);
if(args[0]!=='api')process.exit(91);
if(args[1]==='graphql'){if(s.graphqlError){console.log(JSON.stringify({errors:[{message:'denied'}]}));process.exit(0)}console.log(JSON.stringify({data:{repository:{release:s.draft?{databaseId:27,isDraft:true}:null}}}));process.exit(0)}
if(args[1].endsWith('/releases/27')){console.log(JSON.stringify({id:s.wrongId?28:27,tag_name:'v5.0.2',draft:true}));process.exit(0)}
if(!args[1].includes('/releases/')){if(s.hidden){process.stderr.write('gh: Not Found (HTTP 404)');process.exit(1)}console.log(JSON.stringify({full_name:'peanut-business/peanut-admin-code'}));process.exit(0)}
if(s.rc){process.stderr.write(s.stderr);process.stdout.write(s.stdout||'');process.exit(s.rc)}console.log(JSON.stringify({id:27,tag_name:'v5.0.2'}))
`, { mode: 0o755 })
    process.env.PATH = bin + path.delimiter + previous
    json(state, { rc: 1, stderr: 'gh: Not Found (HTTP 404)' })
    assert.equal(lookupRelease('5.0.2'), null)
    assert.equal(lookupRelease('5.0.2', true), null)
    json(state, { rc: 1, stderr: 'gh: Not Found (HTTP 404)', draft: true })
    assert.equal(lookupRelease('5.0.2', true).id, 27)
    for (const patch of [{ graphqlError: true }, { draft: true, wrongId: true }]) {
      json(state, { rc: 1, stderr: 'gh: Not Found (HTTP 404)', ...patch })
      assert.throws(() => lookupRelease('5.0.2', true))
    }
    json(state, {})
    assert.equal(lookupRelease('5.0.2').id, 27)
    for (const scenario of [
      { hidden: true }, { rc: 1, stderr: 'gh: Forbidden (HTTP 403)' },
      { rc: 1, stderr: 'connection reset' }, { rc: 1, stderr: '', stdout: '{"message":"Not Found"}' },
    ]) {
      json(state, scenario)
      assert.throws(() => lookupRelease('5.0.2'))
    }
  } finally { process.env.PATH = previous; fs.rmSync(root, { recursive: true }) }
})
