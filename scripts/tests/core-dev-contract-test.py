#!/usr/bin/env python3
"""Regression of source-selection commands; no DB/browser/release qualification."""
from pathlib import Path
import json
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
PACKAGES = ('client', 'vue', 'ui-vue', 'nuxt', 'uniapp', 'testing')

class CoreDevelopmentContract(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='peanut-core-dev-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        self.code = self.root / 'code'
        self.php = self.root / 'php'
        self.web = self.root / 'core-web'
        (self.code / 'scripts').mkdir(parents=True)
        (self.code / 'server').mkdir(parents=True, exist_ok=True)
        (self.code / 'server/composer.json').write_text('{"require":{"peanut-admin/core":"5.1.0"}}')
        self.php.mkdir()
        (self.php / 'composer.json').write_text('{"name":"peanut-admin/core"}')
        self.web.mkdir()
        (self.web / 'package.json').write_text('{"name":"@peanut-admin/web-workspace"}')
        (self.web / 'pnpm-workspace.yaml').write_text('packages:\n  - packages/*\n')
        for name in PACKAGES:
            pkg = self.web / 'packages' / name
            (pkg / 'dist').mkdir(parents=True)
            (pkg / 'package.json').write_text(json.dumps({
                'name': '@peanut-admin/' + name, 'version': '9.7.0-dev.42',
                'exports': {'.': {'import': './dist/index.js', 'types': './dist/index.d.ts'}}
            }))
            (pkg / 'dist/index.js').write_text('export const source = true;\n')
            (pkg / 'dist/index.d.ts').write_text('export declare const source: true;\n')
        self.modules = self.code / 'web/node_modules/@peanut-admin'
        self.modules.mkdir(parents=True)
        for name in ('client', 'vue', 'ui-vue'):
            (self.modules / name).symlink_to(self.web / 'packages' / name, target_is_directory=True)
        for script in ('core-dev', 'local-core-web', 'local-core-composer'):
            shutil.copy2(ROOT / 'scripts' / script, self.code / 'scripts' / script)

    def run_web(self, action='link-status'):
        return subprocess.run(['bash', str(self.code / 'scripts/local-core-web'), action,
                               '--core-dir', str(self.web), '--client', 'web'],
                              text=True, capture_output=True, timeout=30)

    def test_source_status_needs_no_old_archive_or_release_version(self):
        result = self.run_web()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('mode=local-source', result.stdout)
        self.assertFalse((self.code / 'release-versions.json').exists())

    def test_wrong_package_identity_is_rejected(self):
        manifest = self.web / 'packages/vue/package.json'
        data = json.loads(manifest.read_text()); data['name'] = '@foreign/vue'
        manifest.write_text(json.dumps(data))
        self.assertNotEqual(self.run_web().returncode, 0)

    def test_copied_package_does_not_count_as_source_link(self):
        (self.modules / 'vue').unlink()
        shutil.copytree(self.web / 'packages/vue', self.modules / 'vue')
        result = self.run_web()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('expected local source', result.stderr)

    def test_missing_compilation_is_not_reported_ready(self):
        (self.web / 'packages/vue/dist/index.js').unlink()
        result = self.run_web()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('needs a local Core build', result.stderr)

    def test_candidate_mode_still_rejects_source_selection(self):
        self.assertNotEqual(self.run_web('status').returncode, 0)

    def test_registry_candidate_status_validates_v3_release_versions_and_installed_version(self):
        packages = {}
        importer_lines = ['importers:', '  .:', '    dependencies:']
        pkg_lines = ['packages:']
        for name in PACKAGES:
            integrity = 'sha512-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=='
            packages[f'@peanut-admin/{name}'] = {
                'version': '5.1.0',
                'resolved': f'https://registry.npmjs.org/@peanut-admin/{name}/-/{name}-5.1.0.tgz',
                'integrity': integrity,
            }
            if name in ('client', 'vue', 'ui-vue'):
                importer_lines.extend([f"      '@peanut-admin/{name}':", '        specifier: 5.1.0', '        version: 5.1.0'])
                pkg_lines.extend([f"  '@peanut-admin/{name}@5.1.0':", f'    resolution: {{integrity: {integrity}}}'])
        (self.code / 'release-versions.json').write_text(json.dumps({'core_web': {'packages': packages}}))
        (self.code / 'web/package.json').write_text(json.dumps({
            'dependencies': {f'@peanut-admin/{name}': '5.1.0' for name in ('client', 'vue', 'ui-vue')}
        }))
        (self.code / 'web/pnpm-lock.yaml').write_text('\n'.join(importer_lines + pkg_lines) + '\n')
        for name in ('client', 'vue', 'ui-vue'):
            (self.modules / name).unlink()
            installed_dir = self.code / 'web/node_modules/.pnpm' / f'@peanut-admin+{name}@5.1.0/node_modules/@peanut-admin/{name}'
            (installed_dir / 'dist').mkdir(parents=True)
            (installed_dir / 'package.json').write_text(json.dumps({
                'name': f'@peanut-admin/{name}', 'version': '5.1.0',
                'exports': {'.': {'import': './dist/index.js', 'types': './dist/index.d.ts'}}
            }))
            (installed_dir / 'dist/index.js').write_text('export const ok = true;\n')
            (installed_dir / 'dist/index.d.ts').write_text('export declare const ok: true;\n')
            (self.modules / name).symlink_to(installed_dir, target_is_directory=True)
        ok = subprocess.run(['bash', str(self.code / 'scripts/local-core-web'), 'status', '--client', 'web'],
                            text=True, capture_output=True, timeout=30)
        self.assertEqual(ok.returncode, 0, ok.stderr)
        self.assertIn('mode=candidate', ok.stdout)
        stale = self.modules / 'vue/package.json'
        stale_data = json.loads(stale.read_text()); stale_data['version'] = '5.0.5'
        stale.write_text(json.dumps(stale_data))
        bad = subprocess.run(['bash', str(self.code / 'scripts/local-core-web'), 'status', '--client', 'web'],
                             text=True, capture_output=True, timeout=30)
        self.assertNotEqual(bad.returncode, 0)

    def test_install_default_calls_both_local_sources(self):
        record = self.root / 'calls'
        for name in ('local-core-composer', 'local-core-web'):
            target = self.code / 'scripts' / name
            target.write_text('#!/bin/sh\nprintf "%s\\n" "$0 $*" >> "' + str(record) + '"\n')
            target.chmod(0o755)
        env = self.root / 'backend.env'; env.write_text('APP_ENV=development\n'); env.chmod(0o600)
        result = subprocess.run(['bash', str(self.code / 'scripts/core-dev'), 'install',
                                 '--php-core-dir', str(self.php), '--web-core-dir', str(self.web),
                                 '--backend-env', str(env)], text=True, capture_output=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        calls = record.read_text().splitlines()
        self.assertEqual(len(calls), 2)
        self.assertIn('local-core-composer install --core-dir ' + str(self.php), calls[0])
        self.assertIn('local-core-web link --core-dir ' + str(self.web), calls[1])
        self.assertNotIn('local-core-web install', '\n'.join(calls))

    def test_missing_source_path_has_no_fallback(self):
        result = subprocess.run(['bash', str(self.code / 'scripts/core-dev'), 'install'],
                                text=True, capture_output=True, timeout=30)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('both absolute Core checkout paths', result.stderr)

if __name__ == '__main__':
    unittest.main(verbosity=2)
