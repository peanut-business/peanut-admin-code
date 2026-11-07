#!/usr/bin/env python3
"""Exercise the guard used by the native client build and its public outputs."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
GUARD = r'''
require $argv[1] . '/server/vendor/autoload.php';
$dependencies = new app\common\module\Dependencies($argv[1] . '/server');
try {
    (new ReflectionMethod($dependencies, 'verifyPublicAssets'))->invoke($dependencies, $argv[2], $argv[3] === 'built');
} catch (app\platform\exception\module\ModulePackageException $error) {
    fwrite(STDERR, $error->errorCode);
    exit(1);
}
'''


class PublicAssetsTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name).resolve()
        self.assets = self.root / 'public'
        self.assets.mkdir()
        (self.assets / 'index.html').write_text('<title>Fixture</title>')

    def check(self, ok, reason='', *, built=False, directory=None):
        result = subprocess.run(
            ['php', '-r', GUARD, str(ROOT), str(directory or self.assets), 'built' if built else 'input'],
            cwd=ROOT, capture_output=True, text=True, timeout=10,
        )
        self.assertEqual(0 if ok else 1, result.returncode, result.stderr)
        if reason:
            self.assertEqual(reason, result.stderr)

    def test_normal_assets_and_generated_vite_manifest(self):
        self.check(True)
        vite = self.assets / '.vite'
        vite.write_text('not a manifest directory')
        self.check(False, 'MODULE_FRONTEND_PUBLIC_ASSET_INVALID', built=True)
        vite.unlink()
        vite.mkdir()
        (vite / 'manifest.json').write_text('{}')
        (vite / 'manifest.json.gz').write_bytes(b'fixture')
        self.check(True, built=True)
        self.check(False, 'MODULE_FRONTEND_PUBLIC_ASSET_INVALID')
        (vite / 'private.map').write_text('fixture')
        self.check(False, 'MODULE_FRONTEND_PUBLIC_ASSET_INVALID', built=True)

    def test_hidden_files_and_dependencies_do_not_enter_public_release(self):
        for name in ['.env', '.env.local', '.npmrc', '.git', 'node_modules']:
            with self.subTest(name=name):
                file = self.assets / name
                file.write_text('synthetic fixture only')
                for built in [False, True]:
                    self.check(False, 'MODULE_FRONTEND_PUBLIC_ASSET_INVALID', built=built)
                file.unlink()

    def test_links_fifo_and_hardlinks_are_rejected_without_modifying_the_source(self):
        external = self.root / 'private'
        external.write_text('preserve this fixture')
        alias = self.assets / 'logo.svg'
        for create in [lambda: os.link(external, alias), lambda: alias.symlink_to(external), lambda: os.mkfifo(alias)]:
            create()
            self.check(False, 'MODULE_FRONTEND_PUBLIC_ASSET_INVALID')
            self.assertEqual('preserve this fixture', external.read_text())
            alias.unlink()

    def test_linked_root_ancestor_and_missing_root_are_rejected(self):
        alias = self.root / 'linked'
        alias.symlink_to(self.root, target_is_directory=True)
        for directory in [alias, alias / 'public', self.root / 'absent', self.assets / 'index.html']:
            self.check(False, 'MODULE_FRONTEND_PUBLIC_PATH_INVALID', directory=directory)

    def test_dependency_names_and_conflicting_paths_are_case_insensitive(self):
        deps = self.assets / 'Node_Modules'
        deps.mkdir()
        self.check(False, 'MODULE_FRONTEND_PUBLIC_ASSET_INVALID', built=True)
        deps.rmdir()
        upper = self.assets / 'INDEX.HTML'
        if upper.exists():
            print('Distinct filename collision not exercised on this case-insensitive filesystem')
            return
        for lower, upper in [('index.html', 'INDEX.HTML'), ('ä.js', 'Ä.js')]:
            with self.subTest(lower=lower, upper=upper):
                (self.assets / lower).write_text('first entry')
                (self.assets / upper).write_text('second entry')
                self.check(False, 'MODULE_FRONTEND_PUBLIC_ASSET_INVALID', built=True)
                (self.assets / upper).unlink()


if __name__ == '__main__':
    unittest.main(verbosity=2)
