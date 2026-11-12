#!/usr/bin/env python3
"""Check the actual PHP Core command's cross-platform file guard, without installing dependencies."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

class LocalCoreEnvironmentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='peanut-core-environment-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.code = (self.root/'code').resolve()
        (self.code/'server').mkdir(parents=True)
        self.env = self.code/'server/.env.test';self.env.write_text('APP_ENV=development\n');self.env.chmod(0o600)
        # Execute the exact native file guard, not Composer/service boot. Tool selection
        # and Core preparation are separate tested contracts; fake success there would
        # make every invalid-file case pass for the wrong prerequisite failure.
        source = (ROOT/'scripts/local-core-composer').read_text()
        start = source.index('if [[ "$ACTION" != manifest ]]; then')
        end = source.index('\nif [[ "$ACTION" != services', start)
        self.guard = source[start:end]

    def run_guard(self):
        environment = dict(os.environ, ACTION='autoload', ROOT_DIR=str(self.code), BACKEND_ENV=str(self.env))
        script = 'set -Eeuo pipefail\ndie() { echo "$*" >&2; exit 1; }\ncore_php() { php "$@"; }\n'
        return subprocess.run(['bash','-c',script + self.guard + '\nprintf "FILE_GUARD_PASSED\\n"'],
                              env=environment,capture_output=True,text=True,timeout=30)

    def test_regular_single_link_0600_is_accepted(self):
        result=self.run_guard();self.assertEqual(result.returncode,0,result.stderr)
        self.assertIn('FILE_GUARD_PASSED',result.stdout)

    def test_publicly_readable_file_is_rejected(self):
        self.env.chmod(0o644);result=self.run_guard();self.assertNotEqual(result.returncode,0)
        self.assertIn('mode 0600 and one hard link',result.stderr)

    def test_hard_link_is_rejected(self):
        os.link(self.env,self.root/'second-link');result=self.run_guard();self.assertNotEqual(result.returncode,0)
        self.assertIn('mode 0600 and one hard link',result.stderr)

    def test_symbolic_link_is_rejected(self):
        target=self.root/'actual.env';self.env.rename(target);self.env.symlink_to(target)
        self.assertNotEqual(self.run_guard().returncode,0)

    def test_missing_file_is_rejected(self):
        self.env.unlink();self.assertNotEqual(self.run_guard().returncode,0)

    def test_outside_server_and_example_file_are_rejected(self):
        outside=self.root/'outside.env';self.env.rename(outside);self.env=outside
        result=self.run_guard();self.assertNotEqual(result.returncode,0)
        self.assertIn('inside server/',result.stderr)
        example=self.code/'server/.env.example';outside.rename(example);self.env=example
        result=self.run_guard();self.assertNotEqual(result.returncode,0)
        self.assertIn('must not be the example',result.stderr)

if __name__=='__main__':unittest.main(verbosity=2)
