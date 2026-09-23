#!/usr/bin/env python3
"""Exercise release transformations with real temporary Git/ZIP/TAR objects."""
import importlib.machinery
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('freeze_core_release', str(ROOT / 'scripts/freeze-core-release'))
spec = importlib.util.spec_from_loader(loader.name, loader)
release = importlib.util.module_from_spec(spec)
loader.exec_module(release)

class FreezeCoreReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='peanut-release-transform-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def repo(self):
        root = self.root / 'repo'; root.mkdir()
        subprocess.run(['git', 'init', '-q', '-b', 'dev', str(root)], check=True)
        subprocess.run(['git', '-C', str(root), 'remote', 'add', 'origin',
                        'https://github.com/peanut-business/peanut-admin-core-php.git'], check=True)
        (root / 'composer.json').write_text('{"name":"peanut-admin/core","autoload":{"psr-4":{"Probe\\\\":"src/"}}}')
        (root / 'src').mkdir(); (root / 'src/Probe.php').write_text('<?php namespace Probe; class Probe {}\n')
        (root / 'LICENSE').write_text('Fixture license\n')
        subprocess.run(['git', '-C', str(root), 'add', '.'], check=True)
        subprocess.run(['git', '-C', str(root), '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                        'commit', '-qm', 'fixture'], check=True)
        return root

    def test_exact_versions_and_prereleases_are_accepted(self):
        for value in ('4.0.0', '4.0.0-rc.1', '4.0.0-dev.2', '4.0.0+build.12'):
            self.assertEqual(release.version(value), value)

    def test_branch_range_alias_and_malformed_versions_are_rejected(self):
        for value in ('dev-dev', '^4.0.0', 'v4.0.0', '4.0.0 as 3.0.0', '04.0.0', '4.0.0-01', '4.0.0\n'):
            with self.assertRaises(ValueError, msg=value): release.version(value)

    def test_source_identity_records_real_git_commit_and_tree(self):
        root = self.repo()
        result = release.identity(root, 'peanut-business/peanut-admin-core-php')
        self.assertEqual(result['commit'], release.git(root, 'rev-parse', 'HEAD'))
        self.assertEqual(result['tree'], release.git(root, 'rev-parse', 'HEAD^{tree}'))

    def test_dirty_release_input_is_rejected(self):
        root = self.repo(); (root / 'src/Probe.php').write_text('changed')
        with self.assertRaisesRegex(ValueError, 'dirty'):
            release.identity(root, 'peanut-business/peanut-admin-core-php')

    def test_wrong_repository_is_rejected(self):
        root = self.repo()
        with self.assertRaisesRegex(ValueError, 'origin'):
            release.identity(root, 'peanut-business/peanut-admin-core-web')

    def test_php_freeze_is_reproducible_and_does_not_mutate_source(self):
        root = self.repo(); original = (root / 'composer.json').read_bytes()
        commit = release.git(root, 'rev-parse', 'HEAD')
        for suffix in ('a', 'b'):
            projection = self.root / suffix
            release.snapshot(root, commit, projection)
            release.build_php_archive(projection, self.root / (suffix + '.zip'), '4.0.0-rc.1')
        self.assertEqual((self.root / 'a.zip').read_bytes(), (self.root / 'b.zip').read_bytes())
        with zipfile.ZipFile(self.root / 'a.zip') as archive:
            self.assertEqual(json.loads(archive.read('core/composer.json'))['version'], '4.0.0-rc.1')
            self.assertIn('core/src/Probe.php', archive.namelist())
        self.assertEqual((root / 'composer.json').read_bytes(), original)
        self.assertEqual(release.git(root, 'status', '--porcelain'), '')

    def test_php_projection_does_not_package_maintainer_or_environment_files(self):
        root = self.repo(); projection = self.root / 'projection'
        release.snapshot(root, release.git(root, 'rev-parse', 'HEAD'), projection)
        (projection / 'AGENTS.md').write_text('maintainer-only fixture')
        (projection / '.env').write_text('FIXTURE_ONLY=true')
        output = self.root / 'core.zip'; release.build_php_archive(projection, output, '4.0.0')
        with zipfile.ZipFile(output) as archive:
            self.assertNotIn('core/AGENTS.md', archive.namelist())
            self.assertNotIn('core/.env', archive.namelist())

    def test_web_stamping_preserves_external_versions_and_source_code(self):
        root = self.root / 'web'; root.mkdir()
        release.write_json(root / 'package.json', {'name': 'workspace', 'version': '1.0.0'})
        for name in release.PACKAGES:
            package = root / 'packages' / name; package.mkdir(parents=True)
            release.write_json(package / 'package.json', {'name': '@peanut-admin/' + name,
                'version': '1.0.0', 'peerDependencies': {'@peanut-admin/client': '1.0.0', 'vue': '^3.4.21'},
                'devDependencies': {'@peanut-admin/client': 'workspace:*'}})
            (package / 'source.ts').write_text('export const value = "1.0.0";')
        release.stamp_web(root, '4.0.0-rc.1')
        data = release.read_json(root / 'packages/vue/package.json')
        self.assertEqual(data['version'], '4.0.0-rc.1')
        self.assertEqual(data['peerDependencies']['@peanut-admin/client'], '4.0.0-rc.1')
        self.assertEqual(data['peerDependencies']['vue'], '^3.4.21')
        self.assertEqual(data['devDependencies']['@peanut-admin/client'], 'workspace:*')
        self.assertIn('1.0.0', (root / 'packages/vue/source.ts').read_text())

    def test_existing_output_is_never_replaced(self):
        output = self.root / 'output'; output.mkdir(); (output / 'keep').write_text('keep')
        with self.assertRaisesRegex(ValueError, 'new absolute'):
            release.freeze(self.root, self.root, output, '4.0.0', '4.0.0')
        self.assertEqual((output / 'keep').read_text(), 'keep')

    def test_incomplete_web_archive_is_rejected(self):
        path = self.root / 'bad.tgz'
        with tarfile.open(path, 'w:gz') as archive:
            raw = b'{}'; info = tarfile.TarInfo('package/package.json'); info.size = len(raw)
            archive.addfile(info, io.BytesIO(raw))
        with self.assertRaisesRegex(ValueError, 'incomplete'):
            release.inspect_web_archive(path, 'vue', '4.0.0')

if __name__ == '__main__': unittest.main(verbosity=2)
