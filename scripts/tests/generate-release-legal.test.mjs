import assert from 'node:assert/strict';
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const codeRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const generator = resolve(codeRoot, 'scripts/generate-release-legal.mjs');
const temporaryRoot = resolve(codeRoot, '.local/tmp');

const writeJson = (root, relative, value) => {
  const path = join(root, relative);
  mkdirSync(resolve(path, '..'), { recursive: true });
  writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`);
};

const createFixture = (identity, product = identity.split('/').at(-1)) => {
  mkdirSync(temporaryRoot, { recursive: true });
  const root = mkdtempSync(join(temporaryRoot, 'release-legal-'));
  const bin = join(root, 'bin');
  mkdirSync(bin);
  writeFileSync(join(bin, 'pnpm'), '#!/bin/sh\nif [ "$1" = "--version" ]; then echo 10.15.0; else echo \'{"MIT":[{"name":"demo","versions":["1.0.0"]}]}\'; fi\n');
  writeFileSync(join(bin, 'corepack'), '#!/bin/sh\n[ "$1" = "pnpm" ] && shift\nexec pnpm "$@"\n');
  chmodSync(join(bin, 'pnpm'), 0o755);
  chmodSync(join(bin, 'corepack'), 0o755);

  const packageManager = 'pnpm@10.15.0';
  for (const dir of ['web', 'platform', 'pc', 'uniapp', 'tools/quality']) {
    writeJson(root, `${dir}/package.json`, { name: dir.replaceAll('/', '-'), packageManager });
    const lock = join(root, dir, 'pnpm-lock.yaml');
    mkdirSync(resolve(lock, '..'), { recursive: true });
    writeFileSync(lock, 'lockfileVersion: "9.0"\npackages: {}\n');
  }
  writeJson(root, 'tools/toolchain.json', { tools: { pnpm: { package_manager_file: 'web/package.json' } } });
  writeJson(root, 'server/composer.lock', { packages: [], 'packages-dev': [] });
  writeJson(root, 'tools/quality/composer.lock', { packages: [], 'packages-dev': [] });
  writeJson(root, 'release-versions.json', {
    schema_version: 3,
    protocol: 'peanut.release-versions.v3',
    source_product_version: '5.1.0',
    instance_version: '0.1.0',
    core_php: { resolved_version: '5.1.0', source_reference: 'fixture-ref' },
    core_web: { packages: { '@peanut-admin/vue': { version: '5.1.0' } } },
  });
  writeJson(root, 'RELEASE_METADATA.json', {
    schema_version: 3,
    protocol: 'peanut.release-metadata.v3',
    product,
    application_identity: identity,
    source_product_version: '5.1.0',
    instance_version: '0.1.0',
    source_repository: null,
    release_date: null,
  });
  return { root, bin };
};

const runGenerator = (root, bin, ...args) => spawnSync(
  process.execPath,
  [generator, `--application-root=${root}`, ...args],
  { encoding: 'utf8', env: { ...process.env, PATH: `${bin}:${process.env.PATH || ''}` } },
);

test('native local applications generate truthful legal files and stable --check output', (t) => {
  const first = createFixture('peanut-business/local-one');
  const renamed = createFixture('peanut-business/local-one', 'renamed-product');
  const second = createFixture('peanut-business/local-two');
  const published = createFixture('peanut-business/published-app');
  const publishedMetadata = JSON.parse(readFileSync(join(published.root, 'RELEASE_METADATA.json')));
  publishedMetadata.source_repository = 'peanut-business/published-app';
  publishedMetadata.release_date = '2026-11-23';
  writeJson(published.root, 'RELEASE_METADATA.json', publishedMetadata);
  const secondMetadata = JSON.parse(readFileSync(join(second.root, 'RELEASE_METADATA.json')));
  delete secondMetadata.source_repository;
  delete secondMetadata.release_date;
  writeJson(second.root, 'RELEASE_METADATA.json', secondMetadata);
  t.after(() => {
    rmSync(first.root, { recursive: true, force: true });
    rmSync(renamed.root, { recursive: true, force: true });
    rmSync(second.root, { recursive: true, force: true });
    rmSync(published.root, { recursive: true, force: true });
  });

  const generated = runGenerator(first.root, first.bin);
  assert.equal(generated.status, 0, generated.stderr);
  const sbom = JSON.parse(readFileSync(join(first.root, 'RELEASE_SBOM.spdx.json'), 'utf8'));
  const rootPackage = sbom.packages.find((item) => item.SPDXID === sbom.documentDescribes[0]);
  assert.equal(rootPackage.downloadLocation, 'NOASSERTION');
  assert.equal(rootPackage.externalRefs[0].referenceLocator, 'pkg:generic/peanut-business/local-one@0.1.0');
  assert.match(sbom.creationInfo.created, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  assert.match(sbom.documentNamespace, /^https:\/\/spdx\.org\/spdxdocs\/[a-f0-9]{64}$/);
  const notices = readFileSync(join(first.root, 'THIRD_PARTY_NOTICES.md'), 'utf8');
  assert.match(notices, /release date not declared/);
  assert.match(notices, /source repository: not declared/);
  assert.doesNotMatch(notices, /on null|github\.com\/null/);

  const checked = runGenerator(first.root, first.bin, '--check');
  assert.equal(checked.status, 0, checked.stderr);
  sbom.creationInfo.created = '2026-02-30T12:00:00Z';
  writeJson(first.root, 'RELEASE_SBOM.spdx.json', sbom);
  const invalidCreated = runGenerator(first.root, first.bin, '--check');
  assert.notEqual(invalidCreated.status, 0);
  assert.equal(runGenerator(second.root, second.bin).status, 0);
  const secondSbom = JSON.parse(readFileSync(join(second.root, 'RELEASE_SBOM.spdx.json'), 'utf8'));
  assert.notEqual(sbom.documentNamespace, secondSbom.documentNamespace);
  assert.equal(runGenerator(renamed.root, renamed.bin).status, 0);
  const renamedSbom = JSON.parse(readFileSync(join(renamed.root, 'RELEASE_SBOM.spdx.json'), 'utf8'));
  assert.notEqual(sbom.documentNamespace, renamedSbom.documentNamespace);

  assert.equal(runGenerator(published.root, published.bin).status, 0);
  const publishedSbom = JSON.parse(readFileSync(join(published.root, 'RELEASE_SBOM.spdx.json'), 'utf8'));
  const publishedRoot = publishedSbom.packages.find((item) => item.SPDXID === publishedSbom.documentDescribes[0]);
  assert.equal(publishedRoot.downloadLocation, 'https://github.com/peanut-business/published-app');
  assert.equal(publishedRoot.externalRefs[0].referenceLocator, 'pkg:github/peanut-business/published-app@0.1.0');
  assert.equal(publishedSbom.documentNamespace, 'https://github.com/peanut-business/published-app/sbom/0.1.0');
  assert.equal(publishedSbom.creationInfo.created, '2026-11-23T00:00:00Z');

  writeFileSync(join(first.root, 'web/pnpm-lock.yaml'), 'lockfileVersion: "9.0"\npackages:\n  demo@1.0.0:\n    resolution:\n      tarball: https://registry.npmjs.org/demo/-/demo-1.0.0.tgz\n');
  const stale = runGenerator(first.root, first.bin, '--check');
  assert.notEqual(stale.status, 0);
  assert.match(stale.stderr, /lock count changed|out of date/);
});

test('explicitly invalid repository and release date evidence is rejected', (t) => {
  const invalidRepository = createFixture('peanut-business/invalid-repo');
  const nonStringRepository = createFixture('peanut-business/non-string-repo');
  const invalidDate = createFixture('peanut-business/invalid-date');
  const missingIdentity = createFixture('peanut-business/missing-identity');
  t.after(() => {
    rmSync(invalidRepository.root, { recursive: true, force: true });
    rmSync(nonStringRepository.root, { recursive: true, force: true });
    rmSync(invalidDate.root, { recursive: true, force: true });
    rmSync(missingIdentity.root, { recursive: true, force: true });
  });
  const repositoryMetadata = JSON.parse(readFileSync(join(invalidRepository.root, 'RELEASE_METADATA.json')));
  repositoryMetadata.source_repository = 'not a repository url';
  writeJson(invalidRepository.root, 'RELEASE_METADATA.json', repositoryMetadata);
  const repositoryResult = runGenerator(invalidRepository.root, invalidRepository.bin);
  assert.notEqual(repositoryResult.status, 0);
  assert.match(repositoryResult.stderr, /provided source_repository\/release_date must be valid/);

  const nonStringMetadata = JSON.parse(readFileSync(join(nonStringRepository.root, 'RELEASE_METADATA.json')));
  nonStringMetadata.source_repository = ['peanut-business/array-repo'];
  writeJson(nonStringRepository.root, 'RELEASE_METADATA.json', nonStringMetadata);
  const nonStringResult = runGenerator(nonStringRepository.root, nonStringRepository.bin);
  assert.notEqual(nonStringResult.status, 0);
  assert.match(nonStringResult.stderr, /provided source_repository\/release_date must be valid/);

  const dateMetadata = JSON.parse(readFileSync(join(invalidDate.root, 'RELEASE_METADATA.json')));
  dateMetadata.release_date = '2026-02-30';
  writeJson(invalidDate.root, 'RELEASE_METADATA.json', dateMetadata);
  const dateResult = runGenerator(invalidDate.root, invalidDate.bin);
  assert.notEqual(dateResult.status, 0);
  assert.match(dateResult.stderr, /provided source_repository\/release_date must be valid/);

  const identityMetadata = JSON.parse(readFileSync(join(missingIdentity.root, 'RELEASE_METADATA.json')));
  delete identityMetadata.application_identity;
  writeJson(missingIdentity.root, 'RELEASE_METADATA.json', identityMetadata);
  const identityResult = runGenerator(missingIdentity.root, missingIdentity.bin);
  assert.notEqual(identityResult.status, 0);
  assert.match(identityResult.stderr, /RELEASE_METADATA version contract mismatch/);
});
