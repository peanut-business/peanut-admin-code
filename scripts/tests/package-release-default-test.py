#!/usr/bin/env python3
"""Execute the real shell option/output boundary; stop before a product snapshot/build.

Only interpreter/build commands are explicit fixture sentinels. No dependencies,
network, database, application generation or product build are performed.
"""
from pathlib import Path
import json
import os
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class DefaultReleaseTest(unittest.TestCase):
    def setUp(self):
        owned = ROOT / '.local/tmp'
        owned.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(prefix='release-default-', dir=owned)
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve()
        self.app = self.root / 'my-app'
        (self.app / '.peanut').mkdir(parents=True)
        self.manifest = self.app / '.peanut/application-manifest.json'
        self.manifest.write_text(json.dumps({'application': {
            'slug': 'my-app', 'version': '1.2.3', 'edition': 'standalone'
        }}))
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.observed = self.root / 'snapshot-argv.json'
        self.write_program('python3', f'''#!{sys.executable}
import json, os, pathlib, sys
if len(sys.argv) > 2 and sys.argv[1].endswith('/scripts/package-release-files.py'):
    if sys.argv[2] != 'snapshot':
        raise SystemExit('unexpected assembly in boundary test')
    pathlib.Path(os.environ['FIXTURE_OBSERVED']).write_text(json.dumps(sys.argv[2:]))
    raise SystemExit(72)
os.execv({sys.executable!r}, [{sys.executable!r}] + sys.argv[1:])
''')
        self.write_program('php', '#!/bin/sh\nprintf 8.3\n')
        self.write_program('node', '#!/bin/sh\nexit 0\n')
        for name in ('npm', 'pnpm'):
            self.write_program(name, '#!/bin/sh\necho unexpected-build >&2\nexit 99\n')
        self.env = dict(os.environ, PATH=str(self.bin) + os.pathsep + os.environ['PATH'],
                        FIXTURE_OBSERVED=str(self.observed))

    def write_program(self, name, body):
        path = self.bin / name
        path.write_text(body)
        path.chmod(0o755)

    def invoke(self, *args):
        return subprocess.run(['bash', str(ROOT / 'scripts/package-release.sh'), *map(str, args),
                               '--application-root=' + str(self.app)], env=self.env,
                              capture_output=True, text=True, timeout=15)

    def options(self):
        return json.loads(self.observed.read_text())

    def assert_snapshot_boundary(self, result):
        self.assertEqual(72, result.returncode, result.stderr)
        self.assertNotIn('unexpected-build', result.stderr)
        self.assertTrue(self.observed.exists())

    def test_default_is_server_only_and_uses_workspace_release(self):
        result = self.invoke()
        self.assert_snapshot_boundary(result)
        self.assertIn('--server-only', self.options())
        self.assertTrue((self.root / 'release/apps/my-app/1.2.3').is_dir())
        self.assertFalse((self.app / 'release').exists())
        self.assertFalse(list(self.root.rglob('*.packaging-lock')))
        self.assertFalse(list(self.root.rglob('.peanut-package.*')))

    def test_explicit_server_only_is_equivalent(self):
        self.assert_snapshot_boundary(self.invoke(self.root / 'explicit', '--server-only'))
        self.assertIn('--server-only', self.options())
        self.assertFalse((self.root / 'release').exists())

    def test_directory_only_is_still_server_only(self):
        self.assert_snapshot_boundary(self.invoke(self.root / 'directory', '--directory-only'))
        self.assertIn('--server-only', self.options())

    def test_upstream_template_keeps_explicit_source_and_server_projection(self):
        self.assert_snapshot_boundary(self.invoke(self.root / 'template', '--generated-template',
            '--directory-only', '--server-output=' + str(self.root / 'template-server')))
        self.assertIn('--generated-template', self.options())
        self.assertNotIn('--server-only', self.options())

    def test_internal_recovery_producer_keeps_its_explicit_old_contract(self):
        self.assert_snapshot_boundary(self.invoke(self.root / 'internal', '--internal-candidate', '--directory-only'))
        self.assertIn('--internal-candidate', self.options())
        self.assertNotIn('--server-only', self.options())

    def test_internal_cannot_be_called_a_server_release(self):
        result = self.invoke(self.root / 'bad', '--internal-candidate', '--server-only')
        self.assertEqual(1, result.returncode)
        self.assertIn('internal candidate requires', result.stderr)
        self.assertFalse(self.observed.exists())

    def test_ordinary_app_cannot_request_dual_output(self):
        result = self.invoke(self.root / 'bad', '--directory-only', '--server-output=' + str(self.root / 'other'))
        self.assertEqual(1, result.returncode)
        self.assertIn('requires the upstream generated-template', result.stderr)
        self.assertFalse(self.observed.exists())

    def test_special_producers_require_explicit_output(self):
        for mode in ('--generated-template', '--internal-candidate'):
            with self.subTest(mode=mode):
                result = self.invoke(mode)
                self.assertEqual(1, result.returncode)
                self.assertIn('select an explicit output', result.stderr)
                self.assertFalse(self.observed.exists())

    def test_existing_default_archive_is_not_overwritten(self):
        archive = self.root / 'release/apps/my-app/1.2.3/my-app-1.2.3-standalone-server.tar.gz'
        archive.parent.mkdir(parents=True)
        archive.write_bytes(b'original fixed release')
        result = self.invoke()
        self.assertEqual(1, result.returncode)
        self.assertIn('output already exists', result.stderr)
        self.assertEqual(b'original fixed release', archive.read_bytes())
        self.assertFalse(self.observed.exists())

    def test_invalid_manifest_cannot_escape_output_root(self):
        for slug in ('../escape', 'bad/name', '..', 'Name with spaces'):
            with self.subTest(slug=slug):
                self.manifest.write_text(json.dumps({'application': {
                    'slug': slug, 'version': '1.2.3', 'edition': 'standalone'}}))
                result = self.invoke()
                self.assertNotEqual(0, result.returncode)
                self.assertIn('invalid application output identity', result.stderr)
                self.assertFalse(self.observed.exists())

    def test_release_ancestor_symlink_is_rejected(self):
        destination = self.root / 'unrelated'
        destination.mkdir()
        (self.root / 'release').symlink_to(destination, target_is_directory=True)
        result = self.invoke()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('output ancestors must not be links', result.stderr)
        self.assertEqual([], list(destination.iterdir()))

    def test_source_manifest_is_unchanged(self):
        before = self.manifest.read_bytes()
        self.assert_snapshot_boundary(self.invoke(self.root / 'output'))
        self.assertEqual(before, self.manifest.read_bytes())


if __name__ == '__main__':
    unittest.main(verbosity=2)
