import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { createHash } from 'node:crypto'
import { readTemplateArtifacts, verifyTemplateArtifacts } from '../release-template-artifacts.mjs'
import { lookupRelease, publicationAssets, verifyPublishedAssets } from '../release-asset-verification.mjs'

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

test('published attachments require exact channel/set and actual downloaded bytes', () => {
  const root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'peanut-release-assets-')))
  const previous = process.env.PATH
  try {
    const publication = path.join(root, 'publication'); fs.mkdirSync(publication)
    const remote = path.join(root, 'remote'); fs.mkdirSync(remote)
    const assets = publicationAssets('5.0.2').map((name, index) => {
      const bytes = Buffer.from(name)
      fs.writeFileSync(path.join(publication, name), bytes); fs.writeFileSync(path.join(remote, String(index + 1)), bytes)
      return { id: index + 1, name, size: bytes.length, state: 'uploaded', digest: 'sha256:' + hash(bytes) }
    })
    const release = { id: 27, tag_name: 'v5.0.2', prerelease: false, draft: false, assets }
    const bin = path.join(root, 'bin'); fs.mkdirSync(bin)
    fs.writeFileSync(path.join(bin, 'gh'), `#!/usr/bin/env node\nconst fs=require('node:fs');const args=process.argv.slice(2);if(args[0]!=='api'||!args[1].includes('/releases/assets/'))process.exit(91);process.stdout.write(fs.readFileSync(${JSON.stringify(remote)}+'/'+args[1].split('/').at(-1)))\n`, { mode: 0o755 })
    process.env.PATH = bin + path.delimiter + previous
    assert.equal(Object.keys(verifyPublishedAssets(publication, '5.0.2', false, release).assets).length, 11)
    assert.throws(() => verifyPublishedAssets(publication, '5.0.2', true, release), /channel/)
    assert.throws(() => verifyPublishedAssets(publication, '5.0.2', false, { ...release, assets: assets.slice(1) }), /set differs/)
    assert.throws(() => verifyPublishedAssets(publication, '5.0.2', false, { ...release, assets: [...assets.slice(1), assets[1]] }), /set differs/)
    fs.writeFileSync(path.join(remote, '1'), 'different downloaded body')
    assert.throws(() => verifyPublishedAssets(publication, '5.0.2', false, release), /downloaded asset bytes differ/)
  } finally { process.env.PATH = previous; fs.rmSync(root, { recursive: true }) }
})

test('only explicit Release 404 after repository visibility establishes absence', () => {
  const root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'peanut-release-lookup-')))
  const previous = process.env.PATH
  try {
    const bin = path.join(root, 'bin'); fs.mkdirSync(bin)
    const state = path.join(root, 'state.json')
    fs.writeFileSync(path.join(bin, 'gh'), `#!/usr/bin/env node\nconst fs=require('node:fs');const s=JSON.parse(fs.readFileSync(${JSON.stringify(state)}));const args=process.argv.slice(2);if(args[0]!=='api')process.exit(91);if(!args[1].includes('/releases/')){if(s.hidden){process.stderr.write('gh: Not Found (HTTP 404)');process.exit(1)}console.log(JSON.stringify({full_name:'peanut-business/peanut-admin-code'}));process.exit(0)}if(s.rc){process.stderr.write(s.stderr);process.stdout.write(s.stdout||'');process.exit(s.rc)}console.log(JSON.stringify({id:27,tag_name:'v5.0.2'}))\n`, { mode: 0o755 })
    process.env.PATH = bin + path.delimiter + previous
    json(state, { rc: 1, stderr: 'gh: Not Found (HTTP 404)' })
    assert.equal(lookupRelease('5.0.2'), null)
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
