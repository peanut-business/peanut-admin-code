#!/usr/bin/env python3
"""Focused local CLI/Recipe contracts; no services, dependency installs or network."""

import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRATCH = ROOT / '.local' / 'cli-mvp-tests'
SCRATCH.mkdir(parents=True, exist_ok=True)


class PeanutCliTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=SCRATCH)
        self.app = Path(self.temp.name) / 'app'
        (self.app / '.peanut').mkdir(parents=True)
        self.manifest = {
            'schema_version': 2, 'protocol': 'peanut.application-scaffold.v2',
            'application': {'name': 'Example', 'slug': 'example', 'package_identity': 'example/app',
                            'version': '0.1.0', 'edition': 'standalone', 'profile': 'minimal'},
            'template': {'version': '4.0.0-rc.2'}, 'files': [],
        }
        self.save_manifest()
        shutil.copyfile(ROOT / 'release-versions.json', self.app / 'release-versions.json')

    def tearDown(self):
        self.temp.cleanup()

    def save_manifest(self):
        (self.app / '.peanut/application-manifest.json').write_text(json.dumps(self.manifest))

    def cli(self, *arguments, expected=0):
        result = subprocess.run(['php', str(ROOT / 'scripts/peanut'), *arguments, '--path', str(self.app)],
                                text=True, capture_output=True)
        self.assertEqual(result.returncode, expected, result.stderr + result.stdout)
        return json.loads(result.stdout) if expected in (0, 1) else result.stderr

    def workflow(self, name='ci.yml'):
        return self.app / '.github/workflows' / name

    def test_add_and_baseline_identity(self):
        original = (self.app / '.peanut/application-manifest.json').read_bytes()
        result = self.cli('recipe', 'add', 'github-ci')
        self.assertEqual(result['version'], '1.0.0')
        state = json.loads((self.app / '.peanut/recipes/github-ci/manifest.json').read_text())
        self.assertEqual(state['protocol'], 'peanut.recipe-installation.v1')
        for entry in state['manifest']['files']:
            self.assertEqual(entry['owner'], 'recipe:github-ci')
            target = self.app / entry['path']
            baseline = self.app / '.peanut/recipes/github-ci/baseline/files' / entry['path']
            self.assertEqual(target.read_bytes(), baseline.read_bytes())
            self.assertEqual(hashlib.sha256(target.read_bytes()).hexdigest(), entry['sha256'])
            self.assertEqual(target.stat().st_mode & 0o777, entry['mode'])
        self.assertEqual((self.app / '.peanut/application-manifest.json').read_bytes(), original)
        self.assertFalse((self.app / '.peanut/recipes/.github-ci.installing').exists())

    def test_repeat_preserves_customization_and_baseline(self):
        self.cli('recipe', 'add', 'github-ci')
        baseline = self.app / '.peanut/recipes/github-ci/manifest.json'
        before = baseline.read_bytes()
        self.workflow().write_text('downstream customization\n')
        result = self.cli('recipe', 'add', 'github-ci')
        self.assertEqual(self.workflow().read_text(), 'downstream customization\n')
        self.assertEqual(baseline.read_bytes(), before)
        self.assertEqual(result['files'][0]['status'], 'modified')

    def test_later_path_conflict_does_not_install_earlier_file(self):
        self.workflow('release.yml').parent.mkdir(parents=True)
        self.workflow('release.yml').write_text('keep\n')
        self.assertIn('RECIPE_PATH_CONFLICT', self.cli('recipe', 'add', 'github-ci', expected=2))
        self.assertFalse(self.workflow().exists())
        self.assertFalse((self.app / '.peanut/recipes').exists())
        self.assertEqual(self.workflow('release.yml').read_text(), 'keep\n')

    def test_identical_existing_bytes_do_not_grant_ownership(self):
        self.workflow().parent.mkdir(parents=True)
        shutil.copyfile(ROOT / 'recipes/github-ci/1.0.0/files/.github/workflows/ci.yml', self.workflow())
        self.assertIn('RECIPE_PATH_CONFLICT', self.cli('recipe', 'add', 'github-ci', expected=2))

    def test_old_scaffold_owned_missing_path_requires_transition(self):
        self.manifest['files'] = [{'path': '.github/workflows/ci.yml', 'classification': 'managed'}]
        self.save_manifest()
        self.assertIn('RECIPE_OWNERSHIP_CONFLICT', self.cli('recipe', 'add', 'github-ci', expected=2))
        self.assertFalse(self.workflow().exists())

    def test_symlink_target_is_rejected(self):
        outside = Path(self.temp.name) / 'outside'
        outside.mkdir()
        (self.app / '.github').symlink_to(outside, target_is_directory=True)
        self.assertIn('SYMLINK_REJECTED', self.cli('recipe', 'add', 'github-ci', expected=2))
        self.assertEqual(list(outside.iterdir()), [])

    def test_pending_install_is_not_replayed(self):
        (self.app / '.peanut/recipes/.github-ci.installing').mkdir(parents=True)
        self.assertEqual(self.cli('recipe', 'status', 'github-ci')['recipes'][0]['status'], 'recovery_required')
        self.assertIn('RECOVERY_REQUIRED', self.cli('recipe', 'add', 'github-ci', expected=2))
        self.assertFalse(self.workflow().exists())

    def test_baseline_corruption_is_rejected(self):
        self.cli('recipe', 'add', 'github-ci')
        (self.app / '.peanut/recipes/github-ci/baseline/files/.github/workflows/ci.yml').write_text('corrupt')
        self.assertIn('BASELINE_DIGEST_MISMATCH', self.cli('recipe', 'status', expected=2))

    def test_manifest_corruption_is_rejected(self):
        self.cli('recipe', 'add', 'github-ci')
        (self.app / '.peanut/recipes/github-ci/source-manifest.json').write_text('{}')
        self.assertIn('MANIFEST_DIGEST_MISMATCH', self.cli('recipe', 'status', expected=2))

    def test_status_reports_missing_and_mode_changes(self):
        self.cli('recipe', 'add', 'github-ci')
        self.workflow().chmod(0o755)
        self.workflow('release.yml').unlink()
        files = self.cli('status')['recipes'][0]['files']
        self.assertEqual([f['status'] for f in files], ['modified', 'missing'])

    def test_invalid_id_and_unknown_recipe(self):
        self.assertIn('ID_INVALID', self.cli('recipe', 'add', '../escape', expected=2))
        self.assertIn('RECIPE_UNKNOWN', self.cli('recipe', 'add', 'gitlab-ci', expected=2))

    def test_missing_application_fails_closed(self):
        (self.app / '.peanut/application-manifest.json').unlink()
        self.assertIn('APPLICATION_MANIFEST_REQUIRED', self.cli('recipe', 'add', 'github-ci', expected=2))
        self.assertIn('APPLICATION_MANIFEST_REQUIRED', self.cli('status', expected=2))

    def test_doctor_missing_inputs_and_read_only_status(self):
        before = sorted(p.relative_to(self.app).as_posix() for p in self.app.rglob('*'))
        result = self.cli('doctor', expected=1)
        self.assertEqual(result['status'], 'incomplete')
        self.assertFalse(result['checks']['server/composer.lock'])
        self.assertEqual(self.cli('status')['kind'], 'application')
        self.assertEqual(self.cli('recipe', 'list')['available'], {'github-ci': '1.0.0'})
        self.assertEqual(before, sorted(p.relative_to(self.app).as_posix() for p in self.app.rglob('*')))

    def test_corrupt_bundle_is_rejected_before_writes(self):
        tool = Path(self.temp.name) / 'tool'
        shutil.copytree(ROOT / 'scripts/cli', tool / 'scripts/cli')
        shutil.copytree(ROOT / 'scripts/scaffold-runtime', tool / 'scripts/scaffold-runtime')
        shutil.copyfile(ROOT / 'scripts/peanut', tool / 'scripts/peanut')
        shutil.copytree(ROOT / 'recipes', tool / 'recipes')
        (tool / 'recipes/github-ci/1.0.0/files/.github/workflows/release.yml').write_text('corrupt')
        result = subprocess.run(['php', str(tool / 'scripts/peanut'), 'recipe', 'add', 'github-ci', '--path', str(self.app)],
                                text=True, capture_output=True)
        self.assertEqual(result.returncode, 2)
        self.assertIn('SOURCE_DIGEST_MISMATCH', result.stderr)
        self.assertFalse(self.workflow().exists())

    def test_scaffold_decisions_and_legacy_ci_removal_guard(self):
        self.workflow().parent.mkdir(parents=True)
        self.workflow().write_text('existing application CI\n')
        release = {'schema_version': 2, 'protocol': 'peanut.scaffold-release.v2',
                   'release': {'version': '1.0.0', 'source_commit': 'a' * 40, 'source_tree': 'b' * 40,
                               'inventory_sha256': 'c' * 64, 'inventory_template_version': '1.0.0',
                               'tokens': {'product_name': 'product-token', 'slug': 'slug-token', 'package_identity': 'package-token'}},
                   'files': [{'path': '.github/workflows/ci.yml', 'owner': 'host', 'policy': 'managed',
                              'classification': 'managed', 'transform': 'tokens', 'mode': 420,
                              'template_sha256': 'd' * 64}]}
        from_path = Path(self.temp.name) / 'from.json'
        to_path = Path(self.temp.name) / 'to.json'
        from_path.write_text(json.dumps(release))
        release['release']['version'] = '1.0.1'
        release['files'] = []
        to_path.write_text(json.dumps(release))
        code = r'''
foreach (['ScaffoldManifest', 'ScaffoldPathGuard', 'Semver', 'ScaffoldUpgradeRunner'] as $class) {
    require $argv[1] . '/scripts/scaffold-runtime/' . $class . '.php';
}
use app\common\value\scaffold\ScaffoldManifest;
use app\common\infrastructure\scaffold\ScaffoldUpgradeRunner;
$decisions = [ScaffoldManifest::additionDecision(false, false),
    ScaffoldManifest::additionDecision(true, true), ScaffoldManifest::additionDecision(true, false)];
if (array_column($decisions, 'action') !== ['create', 'preserve', 'conflict']) { throw new RuntimeException('new-path decisions changed'); }
$method = new ReflectionMethod(ScaffoldUpgradeRunner::class, 'classify');
$actions = $method->invoke(new ScaffoldUpgradeRunner(), $argv[2],
    ['files' => [['path' => '.github/workflows/ci.yml', 'classification' => 'managed']]],
    ScaffoldManifest::load($argv[3]), ScaffoldManifest::load($argv[4]), [], [], []);
if ($actions[0]['action'] !== 'conflict' || $actions[0]['reason'] !== 'recipe_ownership_transition_required') {
    throw new RuntimeException('legacy CI deletion was allowed');
}
echo "shared decisions and legacy ownership guard passed\n";
'''
        result = subprocess.run(['php', '-r', code, str(ROOT), str(self.app), str(from_path), str(to_path)],
                                text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.workflow().read_text(), 'existing application CI\n')


if __name__ == '__main__':
    unittest.main(verbosity=2)
