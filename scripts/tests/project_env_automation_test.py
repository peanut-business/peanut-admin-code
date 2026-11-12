"""Portable detection and idempotent local preparation; no dependency installs or DB."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'project-env'


class EnvironmentAutomationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='peanut-env-auto-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'project'
        (self.root / 'tools').mkdir(parents=True)
        self.contract = {'schema_version': 1, 'tools': {
            'php': {'version': '8.3.24', 'extensions': []}, 'node': {'version': '22.23.2'},
            'composer': {'version': '2.10.2'}, 'npm': {'version': '10.9.8'},
            'pnpm': {'version': '9.15.6'}, 'python3': {'version': '3.9.6'}}}
        self.write_contract()
        (self.root / 'web').mkdir()
        (self.root / 'web/package.json').write_text(json.dumps({'engines': {'node': '>=22'}}))
        self.env = {k: v for k, v in os.environ.items() if not k.startswith('PEANUT_TOOL')}
        self.env['PEANUT_TOOL_PYTHON3'] = sys.executable

    def write_contract(self):
        (self.root / 'tools/toolchain.json').write_text(json.dumps(self.contract))

    def call(self, *args, env=None):
        return subprocess.run([sys.executable, str(SCRIPT), '--root', str(self.root), *args],
                              capture_output=True, text=True, env=env or self.env, timeout=35)

    def test_fresh_doctor_without_registration_is_readonly(self):
        result = self.call('--tools', 'python3', 'doctor')
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads(result.stdout)
        self.assertFalse(report['writes'])
        self.assertEqual(report['selection'], 'auto-discovered')
        self.assertEqual(list(report['tools']), ['python3'])
        self.assertFalse((self.root / '.local').exists())

    def test_run_automatically_prepares_once_without_a_host_file(self):
        for _ in range(2):
            result = self.call('--tools', 'python3', 'run', '--', 'python3', '-c', 'print("native-run-ok")')
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout.strip(), 'native-run-ok')
        states = list((self.root / '.local/toolchain/project-env').glob('*/prepared.json'))
        self.assertEqual(len(states), 1)
        self.assertFalse((self.root / '.local/toolchain/host.json').exists())
        before = states[0].stat().st_mtime_ns
        result = self.call('--tools', 'python3', 'ensure')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(states[0].stat().st_mtime_ns, before)

    def test_changed_declaration_invalidates_derived_state_only(self):
        self.assertEqual(self.call('--tools', 'python3', 'ensure').returncode, 0)
        self.contract['tools']['python3']['version'] = '3.10.0'
        self.write_contract()
        result = self.call('--tools', 'python3', 'ensure')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(list((self.root / '.local/toolchain/project-env').glob('*/prepared.json'))), 2)

    def test_missing_selected_tool_does_not_install_or_create_a_host(self):
        env = {**self.env, 'PEANUT_TOOL_PNPM': str(self.root / 'not-installed-pnpm')}
        result = self.call('--tools', 'pnpm', 'ensure', env=env)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('missing tools: pnpm', result.stderr)
        self.assertFalse((self.root / '.local').exists())

    def test_explicit_bad_profile_never_falls_back_to_ambient_tools(self):
        profile = self.root / 'bad-profile.json'
        profile.write_text(json.dumps({'schema_version': 1, 'commands': {'python3': ['/not/a/python']}}))
        result = self.call('--host', str(profile), '--tools', 'python3', 'doctor')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.root / '.local').exists())

    def test_explicit_missing_python_path_cannot_fall_back_to_runner_interpreter(self):
        env = {**self.env, 'PEANUT_TOOL_PYTHON3': str(self.root / 'missing-python')}
        result = self.call('--tools', 'python3', 'doctor', env=env)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('missing tools: python3', result.stderr)
        self.assertFalse((self.root / '.local').exists())

    def test_tool_scope_rejects_unknown_names_and_does_not_require_other_tools(self):
        result = self.call('--tools', 'not-a-tool', 'requirements')
        self.assertNotEqual(result.returncode, 0)
        result = self.call('--tools', 'pnpm', 'requirements')
        self.assertEqual(set(json.loads(result.stdout)), {'node', 'pnpm'})


if __name__ == '__main__':
    unittest.main()
