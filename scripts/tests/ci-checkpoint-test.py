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
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[2]
loader = importlib.machinery.SourceFileLoader('checkpoint', str(ROOT / 'scripts/ci-checkpoint'))
spec = importlib.util.spec_from_loader(loader.name, loader)
checkpoint = importlib.util.module_from_spec(spec)
loader.exec_module(checkpoint)


class CheckpointTest(unittest.TestCase):
    def release_script(self, name, fixture):
        workflow = (ROOT/'.github/workflows/scaffold-release.yml').read_text()
        step = workflow.split('      - name: ' + name + '\n', 1)[1].split('\n      - ', 1)[0]
        script = textwrap.dedent(step.split('          script: |\n', 1)[1])
        harness = r'''
const fs = require('node:fs');
const {script, fixture: f} = JSON.parse(fs.readFileSync(0, 'utf8'));
Object.assign(process.env, f.env);
const outputs = {};
const github = {rest: {git: {
  getRef: async ({ref}) => ({data: ref === 'heads/main' ? f.main : f.tagRef}),
  getTag: async () => ({data: f.annotated}),
}, repos: {getContent: async ({path, ref}) => {
  if (ref !== f.context.sha) throw new Error('Metadata source changed');
  return {data: {type: 'file', encoding: 'base64', content: Buffer.from(JSON.stringify(f.files[path])).toString('base64')}};
}}, actions: {
  getWorkflowRun: async ({run_id}) => ({data: f.runs[run_id]}),
  listJobsForWorkflowRunAttempt: 'jobs', listWorkflowRunArtifacts: 'artifacts',
}}, paginate: async (method, args) => {
  if (args.run_id !== Number(f.env.BUILD_RUN)) throw new Error('Wrong producing run');
  if (method === 'jobs' && args.attempt_number !== f.runs[args.run_id].run_attempt) throw new Error('Wrong producing attempt');
  return f[method];
}};
const AsyncFunction = Object.getPrototypeOf(async function() {}).constructor;
(async () => {
  try {
    await new AsyncFunction('github', 'context', 'core', script)(github, f.context, {setOutput: (key, value) => {outputs[key] = value;}});
    process.stdout.write(JSON.stringify(outputs));
  } catch (error) {process.stderr.write(error.message); process.exitCode = 1;}
})();
'''
        return subprocess.run(['node', '-e', harness], input=json.dumps({'script': script, 'fixture': fixture}),
                              text=True, capture_output=True, timeout=10)

    def release_fixture(self, manual=False):
        source, repository = 'a'*40, 'peanut-business/peanut-admin-code'
        event = 'workflow_dispatch' if manual else 'push'
        return {
            'context': {'eventName': event, 'sha': source, 'runId': 12 if manual else 11,
                        'ref': 'refs/heads/main' if manual else 'refs/tags/v1.2.3',
                        'repo': {'owner': 'peanut-business', 'repo': 'peanut-admin-code'},
                        'payload': {'repository': {'full_name': repository}}},
            'env': {'SOURCE': source, 'EXPECTED_SOURCE': source, 'BUILD_RUN': '11', 'CI_RUN': '',
                    'RUN_TESTS': 'false', 'TRANSPORT_SHA256': 'b'*64, 'PRODUCER_RESULT': 'success',
                    'PRODUCER_ATTEMPT': '2', 'PRODUCER_ARTIFACT_ID': '21', 'GITHUB_RUN_ATTEMPT': '2'},
            'runs': {'11': {'id': 11, 'head_sha': source, 'head_repository': {'full_name': repository},
                            'status': 'completed' if manual else 'in_progress',
                            'conclusion': 'success' if manual else None, 'event': event,
                            'path': '.github/workflows/scaffold-release.yml', 'run_attempt': 2}},
            # Jobs deliberately have no run_attempt field: the native endpoint binds it.
            'jobs': [{'name': 'templates', 'id': 31, 'run_id': 11, 'head_sha': source,
                      'status': 'completed', 'conclusion': 'success'}],
            'artifacts': [{'id': 21, 'name': f'scaffold-template-qualification-{source}-11-2', 'expired': False}],
            'tagRef': {'object': {'type': 'tag', 'sha': 'c'*40}},
            'annotated': {'tag': 'v1.2.3', 'object': {'type': 'commit', 'sha': source}},
            'main': {'object': {'type': 'commit', 'sha': source}},
            'files': {'RELEASE_METADATA.json': {'expected_tag': 'v1.2.3', 'source_product_version': '1.2.3'},
                      'release-versions.json': {'scaffold_template': '1.2.3', 'source_product_version': '1.2.3'}},
        }

    def test_tag_source_requires_annotated_stable_or_rc_at_current_main(self):
        step = 'Require the exact production source'
        for tag in ('v1.2.3', 'v1.2.3-rc.1'):
            f = self.release_fixture()
            f['context']['ref'] = 'refs/tags/' + tag
            f['annotated']['tag'] = tag
            f['files']['RELEASE_METADATA.json'] = {'expected_tag': tag, 'source_product_version': tag[1:]}
            f['files']['release-versions.json'] = {'scaffold_template': tag[1:], 'source_product_version': tag[1:]}
            result = self.release_script(step, f)
            self.assertEqual(result.returncode, 0, result.stderr)
        negatives = [
            ('lightweight', lambda f: f['tagRef']['object'].update(type='commit')),
            ('nested-tag', lambda f: f['annotated']['object'].update(type='tag')),
            ('other-source', lambda f: f['annotated']['object'].update(sha='d'*40)),
            ('not-main', lambda f: f['main']['object'].update(sha='d'*40)),
            ('tag-name', lambda f: f['annotated'].update(tag='v1.2.4')),
            ('metadata-version', lambda f: f['files']['RELEASE_METADATA.json'].update(source_product_version='1.2.4')),
            ('metadata-tag', lambda f: f['files']['RELEASE_METADATA.json'].update(expected_tag='v1.2.4')),
            ('scaffold-version', lambda f: f['files']['release-versions.json'].update(scaffold_template='1.2.4')),
            ('source-version', lambda f: f['files']['release-versions.json'].update(source_product_version='1.2.4')),
        ]
        for tag in ('v01.2.3', 'v1.2.3-rc.01', 'v1.2.3-beta.1', 'v1.2.3+build'):
            negatives.append((tag, lambda f, t=tag: f['context'].update(ref='refs/tags/' + t)))
        for name, change in negatives:
            with self.subTest(rejected=name):
                f = self.release_fixture()
                change(f)
                self.assertNotEqual(self.release_script(step, f).returncode, 0)
        f = self.release_fixture(manual=True)
        self.assertEqual(self.release_script(step, f).returncode, 0)
        f['env']['EXPECTED_SOURCE'] = 'd'*40
        self.assertNotEqual(self.release_script(step, f).returncode, 0)

    def test_same_run_authenticates_completed_producing_job_and_original_artifact(self):
        step = 'Authenticate the original CI build and enforce the selected test requirement'
        f = self.release_fixture()
        result = self.release_script(step, f)
        self.assertEqual(result.returncode, 0, result.stderr)
        output = json.loads(result.stdout)
        self.assertEqual(output['ci_run'], '')
        self.assertEqual(output['run_tests'], 'false')
        self.assertEqual(output['transport_sha256'], f['env']['TRANSPORT_SHA256'])
        self.assertEqual(output['build_run'], '11')
        negatives = [
            ('other-run', lambda f: f['context'].update(runId=12)),
            ('other-source', lambda f: f['runs']['11'].update(head_sha='d'*40)),
            ('other-repository', lambda f: f['runs']['11']['head_repository'].update(full_name='foreign/repo')),
            ('other-event', lambda f: f['runs']['11'].update(event='workflow_dispatch')),
            ('other-workflow', lambda f: f['runs']['11'].update(path='.github/workflows/ci.yml')),
            ('old-output-attempt', lambda f: f['env'].update(PRODUCER_ATTEMPT='1')),
            ('old-run-attempt', lambda f: f['runs']['11'].update(run_attempt=1)),
            ('failed-needs', lambda f: f['env'].update(PRODUCER_RESULT='failure')),
            ('missing-job', lambda f: f.update(jobs=[])),
            ('duplicate-job', lambda f: f['jobs'].append(dict(f['jobs'][0]))),
            ('running-job', lambda f: f['jobs'][0].update(status='in_progress')),
            ('failed-job', lambda f: f['jobs'][0].update(conclusion='failure')),
            ('cancelled-job', lambda f: f['jobs'][0].update(conclusion='cancelled')),
            ('skipped-job', lambda f: f['jobs'][0].update(conclusion='skipped')),
            ('foreign-job', lambda f: f['jobs'][0].update(run_id=12)),
            ('wrong-job-source', lambda f: f['jobs'][0].update(head_sha='d'*40)),
            ('missing-artifact', lambda f: f.update(artifacts=[])),
            ('duplicate-artifact', lambda f: f['artifacts'].append(dict(f['artifacts'][0]))),
            ('expired-artifact', lambda f: f['artifacts'][0].update(expired=True)),
            ('old-artifact', lambda f: f['artifacts'][0].update(name=f'scaffold-template-qualification-{"a"*40}-11-1')),
            ('wrong-artifact-id', lambda f: f['env'].update(PRODUCER_ARTIFACT_ID='22')),
            ('missing-sha', lambda f: f['env'].update(TRANSPORT_SHA256='')),
            ('current-run-as-ci', lambda f: f['env'].update(CI_RUN='11')),
            ('tag-test-request', lambda f: f['env'].update(RUN_TESTS='true')),
        ]
        for name, change in negatives:
            with self.subTest(rejected=name):
                f = self.release_fixture()
                change(f)
                result = self.release_script(step, f)
                self.assertNotEqual(result.returncode, 0, result.stdout)

    def test_manual_recovery_keeps_completed_run_and_explicit_ci_requirement(self):
        step = 'Authenticate the original CI build and enforce the selected test requirement'
        f = self.release_fixture(manual=True)
        self.assertEqual(self.release_script(step, f).returncode, 0)
        for status, conclusion in [('in_progress', None), ('completed', 'failure'), ('completed', 'cancelled')]:
            with self.subTest(status=status, conclusion=conclusion):
                f = self.release_fixture(manual=True)
                f['runs']['11'].update(status=status, conclusion=conclusion)
                self.assertNotEqual(self.release_script(step, f).returncode, 0)
        f = self.release_fixture(manual=True)
        f['env']['RUN_TESTS'] = 'true'
        self.assertNotEqual(self.release_script(step, f).returncode, 0)
        f['jobs'].append({'name': 'Full CI / CI summary', 'id': 32, 'status': 'completed', 'conclusion': 'success'})
        result = self.release_script(step, f)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)['ci_run'], '11')
        for status in ('failure', 'cancelled', 'skipped'):
            f['jobs'][-1]['conclusion'] = status
            self.assertNotEqual(self.release_script(step, f).returncode, 0)

    def test_release_job_conditions_with_missing_tag_inputs_and_skipped_dependencies(self):
        text = (ROOT/'.github/workflows/scaffold-release.yml').read_text()
        conditions = {}
        for job in ('full-tests', 'templates', 'publish'):
            block = text.split('\n  ' + job + ':\n', 1)[1]
            conditions[job] = re.search(r'    if: \$\{\{ (.+) \}\}', block).group(1).replace('needs.full-tests', 'needs["full-tests"]')
        harness = r'''
const fs = require('node:fs');
const conditions = JSON.parse(fs.readFileSync(0, 'utf8'));
const evaluate = (job, event, inputs, result, full = 'skipped', cancelled = false, repository = 'peanut-business/peanut-admin-code') => {
  const github = {event_name: event, repository, ref: event === 'push' ? 'refs/tags/v1.2.3-rc.1' : 'refs/heads/main', event: {deleted: false}};
  const needs = {templates: {result}, 'full-tests': {result: full}};
  return Boolean(new Function('github', 'inputs', 'needs', 'cancelled', 'startsWith', 'return (' + conditions[job] + ')')(
    github, inputs, needs, () => cancelled, (value, prefix) => value.startsWith(prefix)));
};
const cases = [
  ['full-tests', 'push', {}, 'skipped', 'skipped', false, false],
  ['templates', 'push', {}, 'skipped', 'skipped', false, true],
  ['publish', 'push', {}, 'success', 'skipped', false, true],
  ...['failure', 'cancelled', 'skipped'].map(result => ['publish', 'push', {}, result, 'skipped', false, false]),
  ['templates', 'push', {}, 'skipped', 'skipped', true, false],
  ['templates', 'workflow_dispatch', {run_tests: false}, 'skipped', 'skipped', false, true],
  ['full-tests', 'workflow_dispatch', {run_tests: true}, 'skipped', 'skipped', false, true],
  ['templates', 'workflow_dispatch', {run_tests: true}, 'skipped', 'success', false, true],
  ...['failure', 'cancelled', 'skipped'].map(full => ['templates', 'workflow_dispatch', {run_tests: true}, 'skipped', full, false, false]),
  ['templates', 'workflow_dispatch', {publish: true}, 'skipped', 'skipped', false, false],
  ['publish', 'workflow_dispatch', {publish: true}, 'skipped', 'skipped', false, true],
  ['publish', 'workflow_dispatch', {publish: true, pack_only: true}, 'skipped', 'skipped', false, false],
  ['publish', 'workflow_dispatch', {publish: false}, 'success', 'skipped', false, false],
];
for (const [job, event, inputs, result, full, cancelled, expected] of cases) {
  if (evaluate(job, event, inputs, result, full, cancelled) !== expected) throw new Error(JSON.stringify({job, event, inputs, result, full}));
}
if (evaluate('publish', 'push', {}, 'success', 'skipped', false, 'foreign/repo')) throw new Error('Foreign publication');
process.stdout.write(String(cases.length + 1));
'''
        result = subprocess.run(['node', '-e', harness], input=json.dumps(conditions), text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)

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
                    templates = text.split('\n  templates:\n', 1)[1].split('\n  publish:\n', 1)[0]
                    self.assertIn("github.event_name == 'push' && !github.event.deleted", templates)
                    self.assertIn("github.event_name == 'workflow_dispatch' && !inputs.publish", templates)
                    self.assertIn("!inputs.run_tests && needs.full-tests.result == 'skipped'", templates)
                    self.assertNotIn('dispatch-tag:', text)
                    self.assertNotIn('createWorkflowDispatch', text)
                    self.assertNotIn('actions: write', text)
                    self.assertIn("inputs.workflow || 'runtime'", templates)
                    self.assertEqual(templates.count("inputs.candidate_cli_commit || 'c47ceecf539e817cf023177bb2bcba5f0f16d4a6'"), 3)
                    self.assertIn("RUN_TESTS: ${{ inputs.run_tests && 'true' || 'false' }}", templates)
                    self.assertIn('transport_sha256: ${{ steps.preserve_candidate.outputs.sha256 }}', templates)
                    # Only the publisher writes release content, after its producer succeeds.
                    before_publish, publish = text.split('\n  publish:\n', 1)
                    self.assertNotIn('contents: write', before_publish)
                    self.assertIn('    needs: templates\n', publish)
                    self.assertIn("needs.templates.result == 'success'", publish)
                    self.assertIn("inputs.publish && github.ref == 'refs/heads/main'", publish)
                    self.assertIn('          ref: main\n', publish)
                    self.assertIn('needs.templates.outputs.transport_sha256', publish)
                    self.assertIn('run-id: ${{ steps.candidate.outputs.build_run }}', publish)
                    self.assertIn('--transport-sha256="$TRANSPORT_SHA256" --source-commit="$SOURCE"', publish)
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
