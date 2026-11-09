#!/usr/bin/env python3
"""Check explicit validation dispatch and strict summaries without dependency installs."""
import importlib.machinery
import importlib.util
import json
import os
import re
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('checkpoint', str(ROOT / 'scripts/ci-checkpoint'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checkpoint = importlib.util.module_from_spec(spec)
loader.exec_module(checkpoint)


class CheckpointTest(unittest.TestCase):
    def test_explicit_dispatch_requires_checks(self):
        self.assertEqual(checkpoint.select('workflow_dispatch', {}), 'required')

    def test_dev_push_is_not_a_request_to_validate(self):
        with self.assertRaises(ValueError):
            checkpoint.select('push', {})

    def test_pr_lifecycle_never_starts_validation(self):
        for draft in (True, False):
            for action in ('opened', 'synchronize', 'ready_for_review', 'reopened', 'converted_to_draft'):
                with self.subTest(draft=draft, action=action), self.assertRaises(ValueError):
                    checkpoint.select('pull_request', {'action': action, 'pull_request': {'draft': draft}})

    def test_unknown_or_scheduled_event_is_rejected(self):
        for event in ('', 'schedule', 'pull_request_target', 'merge_group'):
            with self.subTest(event=event), self.assertRaises(ValueError):
                checkpoint.select(event, {})

    def test_invalid_payload_is_rejected(self):
        for value in (None, [], 'false', 0):
            with self.subTest(value=value), self.assertRaises(ValueError):
                checkpoint.select('workflow_dispatch', value)

    def test_cli_writes_required_with_no_success_claim(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root/'event.json').write_text('{}')
            result = subprocess.run(['python3', str(ROOT/'scripts/ci-checkpoint'), '--event-name', 'workflow_dispatch', '--event-file', str(root/'event.json'), '--github-output', str(root/'out'), '--github-summary', str(root/'summary')], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual((root/'out').read_text(), 'validation=required\n')
            self.assertIn('not tested or release-qualified', (root/'summary').read_text())

    def test_rejected_cli_does_not_write_validation_output(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root/'event.json').write_text('{}')
            result = subprocess.run(['python3', str(ROOT/'scripts/ci-checkpoint'), '--event-name', 'push', '--event-file', str(root/'event.json'), '--github-output', str(root/'out')], capture_output=True, text=True)
            self.assertEqual(result.returncode, 2)
            self.assertFalse((root/'out').exists())

    def test_ambient_metadata_cannot_replace_explicit_inputs(self):
        env = dict(os.environ, GITHUB_EVENT_NAME='workflow_dispatch', GITHUB_EVENT_PATH='/not-used')
        result = subprocess.run(['python3', str(ROOT/'scripts/ci-checkpoint')], env=env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 2)
        self.assertNotIn('validation=', result.stdout)
        self.assertNotIn('os.environ', (ROOT/'scripts/ci-checkpoint').read_text())

    def test_summary_rejects_missing_cancelled_failed_required_jobs(self):
        env = dict(os.environ)
        env.pop('GITHUB_STEP_SUMMARY', None)
        for status in ('failure', 'cancelled', 'skipped'):
            with self.subTest(status=status):
                result = subprocess.run(['bash', str(ROOT/'scripts/ci-summary'), '--candidate='+'a'*40, '--check=php:'+status+':required'], env=env, capture_output=True, text=True)
                self.assertEqual(result.returncode, 1, result.stdout+result.stderr)

    def test_summary_accepts_success_and_explicitly_inapplicable(self):
        env = dict(os.environ)
        env.pop('GITHUB_STEP_SUMMARY', None)
        result = subprocess.run(['bash', str(ROOT/'scripts/ci-summary'), '--candidate='+'a'*40, '--check=php:success:required', '--check=pc:skipped:false'], env=env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout+result.stderr)

    def test_validation_is_manual_and_release_tags_are_read_only_by_default(self):
        paths = tuple((ROOT/'.github/workflows').glob('*.yml'))
        self.assertEqual({path.name for path in paths}, {
            'ci.yml', 'core-source-development.yml', 'repository-policy.yml',
            'resource-registry.yml', 'scaffold-release.yml',
        })
        for path in paths:
            with self.subTest(workflow=path.name):
                text = path.read_text()
                triggers = text.split('\non:\n', 1)[1].split('\npermissions:', 1)[0]
                permissions = text.split('\npermissions:\n', 1)[1].split('\n\n', 1)[0]
                self.assertEqual(permissions, '  contents: read')
                if path.name == 'scaffold-release.yml':
                    self.assertEqual(re.findall(r'^  (\S+):', triggers, re.MULTILINE), ['push', 'workflow_dispatch'])
                    self.assertEqual(triggers.split('  workflow_dispatch:', 1)[0].strip(), "push:\n    tags:\n      - 'v[0-9]*.[0-9]*.[0-9]*'")
                    dispatch, templates = text.split('\n  dispatch-tag:\n', 1)[1].split('\n  templates:\n', 1)
                    self.assertIn("    if: ${{ github.event_name == 'push' && !github.event.deleted && github.repository == 'peanut-business/peanut-admin-code' }}\n", dispatch)
                    self.assertIn('      actions: write\n', dispatch)
                    self.assertIn(
                        "    if: ${{ !cancelled() && github.event_name == 'workflow_dispatch' && !inputs.publish && (needs.full-tests.result == 'success' || (!inputs.run_tests && needs.full-tests.result == 'skipped')) }}\n",
                        templates,
                    )
                    # Tags may dispatch main; only the main publisher writes release content.
                    before_publish, publish = text.split('\n  publish:\n', 1)
                    self.assertNotIn('contents: write', before_publish)
                    self.assertIn("    if: ${{ github.event_name == 'workflow_dispatch' && !inputs.pack_only && inputs.publish && github.ref == 'refs/heads/main' && github.repository == 'peanut-business/peanut-admin-code' }}\n", publish)
                    self.assertEqual(publish.count('contents: write'), 1)
                elif path.name == 'ci.yml':
                    self.assertEqual(
                        re.findall(r'^  (\S+):', triggers, re.MULTILINE),
                        ['workflow_dispatch', 'workflow_call'],
                    )
                    self.assertIn("  workflow_dispatch:\n    inputs:\n      expected_source_commit:\n", triggers)
                    self.assertIn(
                        "  workflow_call:\n    inputs:\n      expected_source_commit:\n"
                        "        description: Exact source selected by the scaffold production caller\n"
                        "        required: true\n        type: string\n",
                        triggers,
                    )
                    self.assertNotIn('  push:', triggers)
                    self.assertNotIn('contents: write', text)
                else:
                    self.assertEqual(triggers.strip(), 'workflow_dispatch:')
                    self.assertNotIn('contents: write', text)
                self.assertNotIn('continue-on-error:', text)

    def test_php_ci_uses_the_exact_registered_toolchain_version(self):
        toolchain = json.loads((ROOT/'tools/toolchain.json').read_text())
        expected = toolchain['tools']['php']['version']
        self.assertRegex(expected, r'^8\.3\.\d+$')
        for workflow in ('ci.yml', 'core-source-development.yml'):
            with self.subTest(workflow=workflow):
                contents = (ROOT/'.github/workflows'/workflow).read_text()
                actual = re.findall(r"(?m)^\s+php-version:\s*['\"]?(8\.3(?:\.\d+)?)", contents)
                self.assertGreater(len(actual), 0)
                self.assertEqual(actual, [expected]*len(actual))

    def test_main_workflow_preserves_required_results_and_cache_alignment(self):
        text = (ROOT/'.github/workflows/ci.yml').read_text()
        self.assertIn('if: always()', text)
        self.assertNotIn("validation != 'deferred'", text)
        self.assertEqual(text.count("if: needs.changes.outputs.validation == 'required' &&"), 8)
        self.assertEqual(text.count('path: ${{ env.COMPOSER_CACHE_DIR }}/files'), 2)
        self.assertNotIn('working-directory: platform\n        run: npm run type:check', text)

    def test_core_workflow_preserves_source_and_frozen_consumer_jobs(self):
        text = (ROOT/'.github/workflows/core-source-development.yml').read_text()
        self.assertIn('  source-link:', text)
        self.assertIn('  frozen-core-inputs:', text)
        self.assertIn('local-core-environment-test.py', text)
        self.assertIn('freeze-core-release-test.py', text)
        self.assertNotIn('  backend-broad:', text)
        self.assertNotIn('github.event.pull_request.draft', text)

    def test_inventory_waits_for_its_real_composer_prerequisite(self):
        text = (ROOT/'.github/workflows/ci.yml').read_text()
        self.assertLess(text.index("Install the tool's locked dependencies"), text.index('Verify source inventory after locked dependencies'))
        self.assertLess(text.index('Verify source inventory after locked dependencies'), text.index('Verify create-app contract'))


if __name__ == '__main__':
    unittest.main(verbosity=2)
