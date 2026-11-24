import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import {
  copyFileSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import test from 'node:test';

const generator = new URL('../sync-brand-assets.mjs', import.meta.url);
const webPackage = JSON.parse(
  readFileSync(new URL('../../web/package.json', import.meta.url), 'utf8')
);
const clients = {
  web: ['web/src/generated/brand.json', 'web/public/brand'],
  pc: ['pc/generated/brand.json', 'pc/public/brand'],
  uniapp: ['uniapp/src/generated/brand.json', 'uniapp/src/static/brand'],
};
const names = [
  'favicon.svg',
  'logo.svg',
  'login-background.svg',
  'avatar-admin.svg',
  'avatar-member.svg',
  'menu.svg',
  'docs.svg',
  'support.svg',
];

test('Admin brand commands scope generation to Web', () => {
  for (const script of ['build', 'type:check']) {
    assert.equal(
      webPackage.scripts[script].split(' && ')[0],
      'node ../scripts/sync-brand-assets.mjs --client web'
    );
  }
});

test('brand generation and checking honor the selected client', (t) => {
  const root = mkdtempSync(join(tmpdir(), 'brand-scope-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  const write = (path, value) => {
    const file = join(root, path);
    mkdirSync(dirname(file), { recursive: true });
    writeFileSync(file, value);
  };
  const read = (path) => readFileSync(join(root, path), 'utf8');
  const source = { schema_version: 1, website: { name: 'Synthetic brand' } };
  write('server/config/brand.json', JSON.stringify(source));
  for (const name of names)
    write('server/public/brand/' + name, '<svg id="' + name + '"/>');
  const allPaths = Object.values(clients).flatMap(([manifest, assets]) => [
    manifest,
    ...names.map((name) => assets + '/' + name),
  ]);
  for (const path of allPaths) write(path, 'sentinel:' + path);
  mkdirSync(join(root, 'scripts'));
  copyFileSync(generator, join(root, 'scripts/sync-brand-assets.mjs'));
  const run = (...options) => {
    const result = spawnSync(
      process.execPath,
      ['../scripts/sync-brand-assets.mjs', ...options],
      { cwd: join(root, 'web'), encoding: 'utf8' }
    );
    assert.ifError(result.error);
    return result;
  };
  const assertGenerated = ([manifest, assets]) => {
    const generated = JSON.parse(read(manifest));
    assert.deepEqual(generated.website, source.website);
    assert.equal(generated.sidebar_footer.sidebar_footer_settings, true);
    for (const name of names)
      assert.equal(
        read(assets + '/' + name),
        read('server/public/brand/' + name)
      );
  };

  for (const args of [
    ['--client', 'invalid'],
    ['--client'],
    ['--client='],
    ['--client', '--check'],
    ['--client=web', '--client=pc'],
  ]) {
    const result = run(...args);
    assert.equal(result.status, 1);
    assert.match(result.stderr, /--client/);
    for (const path of allPaths) assert.equal(read(path), 'sentinel:' + path);
  }

  const web = run('--client', 'web');
  assert.equal(web.status, 0, web.stderr);
  assertGenerated(clients.web);
  for (const path of allPaths.filter((path) => !path.startsWith('web/'))) {
    assert.equal(read(path), 'sentinel:' + path);
  }
  const webCheck = run('--check', '--client=web');
  assert.equal(webCheck.status, 0, webCheck.stderr);
  const allCheck = run('--check');
  assert.equal(allCheck.status, 1);
  assert.match(allCheck.stderr, /stale: pc\/generated\/brand\.json/);
  assert.equal(read(clients.pc[0]), 'sentinel:' + clients.pc[0]);

  const all = run();
  assert.equal(all.status, 0, all.stderr);
  for (const target of Object.values(clients)) assertGenerated(target);
  const freshCheck = run('--check');
  assert.equal(freshCheck.status, 0, freshCheck.stderr);

  write(clients.web[0], 'stale-web');
  const pc = run('--client', 'pc');
  assert.equal(pc.status, 0, pc.stderr);
  assert.equal(read(clients.web[0]), 'stale-web');
  const uniapp = run('--check', '--client', 'uniapp');
  assert.equal(uniapp.status, 0, uniapp.stderr);
  const staleWeb = run('--client=web', '--check');
  assert.equal(staleWeb.status, 1);
  assert.match(staleWeb.stderr, /stale: web\/src\/generated\/brand\.json/);
  assert.equal(read(clients.web[0]), 'stale-web');
});
