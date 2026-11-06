#!/usr/bin/env python3
"""Verify the fixed reference chain preserves the two installed applications' owned files."""
import json
from pathlib import Path
import runpy
import tempfile
import unittest

source = Path(__file__).resolve().parents[1] / 'consumer-module-reference-chain'
driver = runpy.run_path(str(source))


class InstalledApplicationFilesTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name).resolve()
        (self.root / '.peanut').mkdir()
        self.file = self.root / 'customer.php'
        self.file.write_text('fixture')
        self.file.chmod(0o640)
        self.record('customer.php')

    def record(self, path):
        (self.root / '.peanut/application-manifest.json').write_text(json.dumps({
            'files': [{'path': path, 'classification': 'app-owned'}],
        }))

    def proof(self):
        return driver['app_owned_proof'](self.root)

    def test_proof_binds_customer_bytes_mode_and_path(self):
        before = self.proof()
        self.assertEqual(before['file_count'], 1)
        self.assertEqual(before, self.proof())
        self.file.write_text('changed')
        self.assertNotEqual(before['tree_sha256'], self.proof()['tree_sha256'])
        self.file.write_text('fixture')
        self.file.chmod(0o600)
        self.assertNotEqual(before['tree_sha256'], self.proof()['tree_sha256'])
        self.file.rename(self.root / 'renamed.php')
        self.record('renamed.php')
        self.assertNotEqual(before['paths_sha256'], self.proof()['paths_sha256'])

    def test_reject_missing_and_nonlocal_paths(self):
        for path in ('missing.php', '../customer.php', str(self.file)):
            with self.subTest(path=path), self.assertRaises(driver['ChainError']):
                self.record(path)
                self.proof()

    def test_reject_symlink(self):
        (self.root / 'link').symlink_to(self.file)
        self.record('link')
        with self.assertRaisesRegex(driver['ChainError'], 'unavailable'):
            self.proof()

    def test_reject_missing_owned_inventory(self):
        (self.root / '.peanut/application-manifest.json').write_text('{"files":[]}')
        with self.assertRaisesRegex(driver['ChainError'], 'no app-owned'):
            self.proof()


if __name__ == '__main__':
    result = unittest.main(exit=False).result
    if not result.wasSuccessful():
        raise SystemExit(1)
    print(f'CONSUMER-INSTALLED-FILES-001 passed ({result.testsRun} tests)')
