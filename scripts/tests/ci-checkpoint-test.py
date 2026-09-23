#!/usr/bin/env python3
"""Checkpoint transitions and existing summary rejection, without dependency installs."""
import importlib.machinery
import importlib.util
import json
import os
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
    def test_draft_is_deferred(self):
        self.assertEqual(checkpoint.select('pull_request', {'pull_request': {'draft': True}}), 'deferred')

    def test_ready_and_followup_push_require_checks(self):
        for action in ['ready_for_review', 'opened', 'synchronize', 'reopened']:
            with self.subTest(action=action):
                self.assertEqual(checkpoint.select('pull_request', {'action': action, 'pull_request': {'draft': False}}), 'required')

    def test_return_to_draft_defers(self):
        self.assertEqual(checkpoint.select('pull_request', {'action': 'converted_to_draft', 'pull_request': {'draft': True}}), 'deferred')

    def test_dev_push_cannot_be_skipped(self):
        self.assertEqual(checkpoint.select('push', {}), 'required')

    def test_manual_dispatch_requires_validation(self):
        self.assertEqual(checkpoint.select('workflow_dispatch', {}), 'required')

    def test_missing_ambiguous_or_unknown_context_rejected(self):
        for value in [None, 'false', 0, {}]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                checkpoint.select('pull_request', {'pull_request': {'draft': value}})
        with self.assertRaises(ValueError):
            checkpoint.select('pull_request_target', {})

    def test_real_cli_emits_deferred_without_qualification(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root/'event.json').write_text(json.dumps({'pull_request': {'draft': True}}))
            env = dict(os.environ, GITHUB_EVENT_NAME='pull_request', GITHUB_EVENT_PATH=str(root/'event.json'), GITHUB_STEP_SUMMARY=str(root/'summary.md'))
            result = subprocess.run(['python3', str(ROOT/'scripts/ci-checkpoint'), '--event-name', 'pull_request', '--event-file', str(root/'event.json'), '--github-output', str(root/'output'), '--github-summary', str(root/'summary.md')], env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual((root/'output').read_text(), 'validation=deferred\n')
            self.assertIn('NOT passed', (root/'summary.md').read_text())

    def test_ambient_event_cannot_replace_explicit_inputs(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root/'event.json').write_text(json.dumps({'pull_request': {'draft': False}}))
            env = dict(os.environ, GITHUB_EVENT_NAME='pull_request', GITHUB_EVENT_PATH=str(root/'event.json'))
            result = subprocess.run(['python3', str(ROOT/'scripts/ci-checkpoint')], env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 2)
            self.assertNotIn('validation=', result.stdout)
        source = (ROOT/'scripts/ci-checkpoint').read_text()
        self.assertNotIn('os.environ', source)

    def test_existing_summary_rejects_non_success_required_jobs(self):
        env = dict(os.environ)
        env.pop('GITHUB_STEP_SUMMARY', None)
        for result in ['failure', 'cancelled', 'skipped']:
            with self.subTest(result=result):
                run = subprocess.run(['bash', str(ROOT/'scripts/ci-summary'), '--candidate='+'a'*40, '--check=php:'+result+':required'], env=env, capture_output=True, text=True)
                self.assertEqual(run.returncode, 1, run.stdout+run.stderr)

    def test_existing_summary_accepts_success_and_explicitly_inapplicable(self):
        env = dict(os.environ)
        env.pop('GITHUB_STEP_SUMMARY', None)
        run = subprocess.run(['bash', str(ROOT/'scripts/ci-summary'), '--candidate='+'a'*40, '--check=php:success:required', '--check=pc:skipped:false'], env=env, capture_output=True, text=True)
        self.assertEqual(run.returncode, 0, run.stdout+run.stderr)
        self.assertIn('not applicable by classifier', run.stdout)

    def test_workflow_keeps_required_summary_and_read_only_token(self):
        workflow = (ROOT/'.github/workflows/ci.yml').read_text()
        self.assertIn('ready_for_review, converted_to_draft', workflow)
        self.assertIn("if: always() && needs.changes.outputs.validation != 'deferred'", workflow)
        self.assertEqual(workflow.count("if: needs.changes.outputs.validation == 'required' &&"), 8)
        self.assertIn('contents: read', workflow)
        self.assertNotIn('continue-on-error:', workflow)
        self.assertNotIn('paths-ignore:', workflow)
        self.assertNotIn('working-directory: platform\n        run: npm run type:check', workflow)

    def test_source_workflow_has_no_duplicate_broad_gate(self):
        workflow = (ROOT/'.github/workflows/core-source-development.yml').read_text()
        self.assertIn('ready_for_review, converted_to_draft', workflow)
        self.assertEqual(workflow.count("if: github.event_name != 'pull_request' || github.event.pull_request.draft == false"), 2)
        self.assertNotIn('  backend-broad:', workflow)
        self.assertIn('Run broad server gates', (ROOT/'.github/workflows/ci.yml').read_text())
        self.assertIn('freeze-core-release-test.py', workflow)
        self.assertIn('local-core-environment-test.py', workflow)

    def test_global_cache_uses_only_workflow_available_context(self):
        workflow = (ROOT/'.github/workflows/ci.yml').read_text()
        global_env = workflow.split('\nenv:\n', 1)[1].split('\nconcurrency:', 1)[0]
        self.assertNotIn('runner.', global_env)
        self.assertIn('COMPOSER_CACHE_DIR: ${{ github.workspace }}/../.peanut-composer-cache', global_env)
        self.assertEqual(workflow.count('path: ${{ env.COMPOSER_CACHE_DIR }}/files'), 2)

    def test_inventory_fails_before_dependency_installation(self):
        workflow = (ROOT/'.github/workflows/ci.yml').read_text()
        self.assertLess(workflow.index('Verify source inventory before dependency installation'), workflow.index("Install the tool's locked dependencies"))


if __name__ == '__main__':
    unittest.main()
