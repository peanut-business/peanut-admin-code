#!/usr/bin/env python3
"""Release-channel contracts using temporary Git graphs and stub API; no real releases."""
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
TMP = ROOT / '.local/tmp/release-channel-test'


class PublishGithubReleaseChannelTest(unittest.TestCase):
    def test_main_integration_exact_or_proven_merge(self):
        with tempfile.TemporaryDirectory(prefix='release-integration-') as directory:
            case = Path(directory)
            env = os.environ.copy()
            env.update(GIT_AUTHOR_NAME='Fixture', GIT_AUTHOR_EMAIL='fixture@example.invalid',
                       GIT_COMMITTER_NAME='Fixture', GIT_COMMITTER_EMAIL='fixture@example.invalid')
            real_git = shutil.which('git')
            def git(*args, input=None):
                return subprocess.check_output([real_git, *args], cwd=case, env=env, text=True, input=input).strip()
            git('init', '-q')
            old_tree = git('mktree', input='')
            blob = git('hash-object', '-w', '--stdin', input='qualified bytes\n')
            tree = git('mktree', input=f'100644 blob {blob}\tproduct.txt\n')
            commit_count = 0
            def commit(tree_id, *parents):
                nonlocal commit_count
                commit_count += 1
                args = ['commit-tree', tree_id]
                for parent in parents:
                    args += ['-p', parent]
                return git(*args, input=f'fixture {commit_count}\n')
            base = commit(old_tree)
            source = commit(tree, base)
            merge = commit(tree, base, source)
            foreign = commit(tree)
            git('tag', '-a', 'v1.2.3', source, '-m', 'qualified source')
            tag_object = git('rev-parse', 'v1.2.3^{tag}')
            repository = 'peanut-business/peanut-admin-code'
            receipt = {'schema_version': 1, 'repository': repository, 'source_commit': source,
                       'base_commit': base, 'ready_for_stable': True, 'readiness_at': '2026-10-05T01:00:00Z',
                       'premerge_observed_at': '2026-10-05T01:01:00Z'}
            pr = {'number': 7, 'html_url': f'https://github.com/{repository}/pull/7',
                  'merged': True, 'state': 'closed', 'merged_at': '2026-10-05T01:02:00Z',
                  'merge_commit_sha': merge, 'head': {'sha': source, 'ref': 'dev', 'repo': {'full_name': repository}},
                  'base': {'sha': merge, 'ref': 'main', 'repo': {'full_name': repository}}}
            bins = case / 'bin'
            bins.mkdir()
            state_file = case / 'stub.json'
            stub = '''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
s=json.loads(Path(os.environ['INTEGRATION_STUB']).read_text()); a=sys.argv[1:]
if Path(sys.argv[0]).name=='gh':
 if s.get('api_error'): sys.exit(1)
 print(json.dumps(s['associated'] if '/commits/' in a[1] else s['pr'])); sys.exit(0)
if a[:3]==['remote','get-url','origin']: print(s.get('origin','git@github.com:peanut-business/peanut-admin-code.git')); sys.exit(0)
if a[:2]==['ls-remote','--heads']:
 s['head_calls']=s.get('head_calls',0)+1; Path(os.environ['INTEGRATION_STUB']).write_text(json.dumps(s))
 print((s['source'] if s.get('drift') and s['head_calls']>1 else s['main'])+'\\trefs/heads/main'); sys.exit(0)
if a[:2]==['ls-remote','--tags']:
 print(s['tag_object']+'\\trefs/tags/v1.2.3'); print(s.get('tag_source',s['source'])+'\\trefs/tags/v1.2.3^{}'); sys.exit(0)
os.execv(os.environ['INTEGRATION_GIT'],[os.environ['INTEGRATION_GIT'],*a])
'''
            for name in ['git', 'gh']:
                path = bins / name
                path.write_text(stub)
                path.chmod(0o755)
            env.update(PATH=str(bins) + os.pathsep + env['PATH'], INTEGRATION_STUB=str(state_file), INTEGRATION_GIT=real_git)
            publisher = (ROOT / 'scripts/publish-github-release').read_text()
            function = publisher[publisher.index('verify_main_integration() {'):publisher.index('\nif [[ "$PREPARE_ONLY" != 1 ]]; then', publisher.index('verify_main_integration() {'))]
            def check(main=merge, change=None, receipt_change=None, local=None, expected='', absent=False):
                state = {'main': main, 'source': source, 'tag_object': tag_object, 'pr': json.loads(json.dumps(pr)),
                         'associated': [{'number': 7, 'merge_commit_sha': main, 'head': {'sha': source}}]}
                if change:
                    change(state)
                state_file.write_text(json.dumps(state))
                data = dict(receipt)
                if receipt_change:
                    receipt_change(data)
                receipt_path = case / 'premerge.json'
                receipt_path.write_text(json.dumps(data))
                git('update-ref', 'refs/remotes/origin/main', local or main)
                proof = case / 'proof.json'
                proof.unlink(missing_ok=True)
                command = function + '\nverify_main_integration "$PROOF" "$EXPECTED"\n'
                run_env = dict(env, REPOSITORY=repository, COMMIT=source, TAG='v1.2.3', TAG_OBJECT=tag_object,
                               MAIN_INTEGRATION='' if absent else str(receipt_path), PROOF=str(proof), EXPECTED=expected)
                result = subprocess.run(['bash', '-Eeuo', 'pipefail', '-c', command], cwd=case, env=run_env,
                                        text=True, capture_output=True, timeout=10)
                return result, json.loads(proof.read_text()) if proof.exists() else None
            for main, mode in [(source, 'exact-source'), (merge, 'merged-dev-main')]:
                with self.subTest(positive=mode):
                    result, proof = check(main=main, absent=main == source)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertEqual(proof['source_commit'], source)
                    self.assertEqual(proof['main_commit'], main)
                    self.assertEqual(proof['mode'], mode)
                    if main == merge:
                        self.assertEqual(proof['parents'], [base, source])
                        self.assertEqual(proof['pr_number'], 7)
                        self.assertRegex(proof['premerge_receipt_sha256'], r'^[0-9a-f]{64}$')
            negatives = {
                'wrong-tree': {'main': commit(old_tree, base, source)},
                'reverse-parents': {'main': commit(tree, source, base)},
                'squash-rebase': {'main': commit(tree, base)},
                'extra-parent': {'main': commit(tree, base, source, foreign)},
                'later-same-tree': {'main': commit(tree, merge)},
                'nonancestor-base': {'main': commit(tree, foreign, source)},
                'stale-main': {'local': source}, 'fresh-main-drift': {'expected': source},
                'drift-during-api': {'change': lambda s: s.update(drift=True)},
                'missing-receipt': {'absent': True},
                'wrong-receipt-base': {'receipt_change': lambda r: r.update(base_commit=foreign)},
                'not-ready': {'receipt_change': lambda r: r.update(ready_for_stable=False)},
                'readiness-order': {'receipt_change': lambda r: r.update(readiness_at='2026-10-05T01:01:01Z')},
                'merge-order': {'receipt_change': lambda r: r.update(premerge_observed_at='2026-10-05T01:02:00Z')},
                'wrong-origin': {'change': lambda s: s.update(origin='git@github.com:foreign/peanut-admin-code.git')},
                'wrong-tag': {'change': lambda s: s.update(tag_source=foreign)},
                'api-unavailable': {'change': lambda s: s.update(api_error=True)},
                'api-missing': {'change': lambda s: s.update(associated=[])},
                'api-ambiguous': {'change': lambda s: s['associated'].append(s['associated'][0])},
            }
            for field, value in [('merged', False), ('state', 'open'), ('merged_at', None), ('merge_commit_sha', source)]:
                negatives['pr-' + field] = {'change': lambda s, f=field, v=value: s['pr'].update({f: v})}
            for side, field, value in [('head', 'sha', foreign), ('head', 'ref', 'feature'), ('base', 'ref', 'dev')]:
                negatives[side + '-' + field] = {'change': lambda s, p=side, f=field, v=value: s['pr'][p].update({f: v})}
            for side in ['head', 'base']:
                negatives[side + '-repository'] = {'change': lambda s, p=side: s['pr'][p]['repo'].update(full_name='foreign/repo')}
            for name, options in negatives.items():
                with self.subTest(rejected=name):
                    result, proof = check(**options)
                    self.assertNotEqual(result.returncode, 0, name)
                    self.assertIsNone(proof, name)

    def publish(self, version, *extra):
        TMP.mkdir(parents=True, exist_ok=True)
        case = Path(tempfile.mkdtemp(prefix='case-', dir=TMP))
        self.addCleanup(lambda: shutil.rmtree(case, ignore_errors=True))
        qualification = case / 'summary.json'
        artifacts = case / 'artifacts'
        output = case / 'out'
        qualification.write_text('{}\n')
        artifacts.mkdir()
        output.mkdir()
        return subprocess.run(
            [
                'bash',
                str(ROOT / 'scripts/publish-github-release'),
                version,
                '--qualification',
                str(qualification),
                '--edition-artifacts',
                str(artifacts),
                '--output',
                str(output),
                '--prepare-only',
                *extra,
            ],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=10,
            check=False,
        )

    def test_stable_release_channel_rejects_prerelease_product_versions(self):
        result = self.publish('1.2.3-rc.1')
        self.assertEqual(result.returncode, 2, result.stderr)
        self.assertRegex(result.stderr, r'stable semver')

    def test_prerelease_channel_rejects_stable_product_versions(self):
        result = self.publish('1.2.3', '--prerelease')
        self.assertEqual(result.returncode, 2, result.stderr)
        self.assertRegex(result.stderr, r'prerelease version')

    def test_prerelease_channel_rejects_illegal_semver_identifiers(self):
        for version in ['1.2.3-', '1.2.3-01', '1.2.3-rc..1', '01.2.3-rc.1']:
            with self.subTest(version=version):
                result = self.publish(version, '--prerelease')
                self.assertEqual(result.returncode, 2, result.stderr)
                self.assertRegex(result.stderr, r'prerelease version')

    def test_prerelease_channel_accepts_legal_shape_before_annotated_tag_gate(self):
        result = self.publish('1.2.3-rc.1', '--prerelease')
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertRegex(result.stderr, r'annotated tag is missing: v1\.2\.3-rc\.1')

    def test_consistency_gate_keeps_stable_and_prerelease_tag_modes_explicit(self):
        source = (ROOT / 'scripts/check-release-consistency').read_text()
        self.assertIn("if (options.prerelease && !options.tag && !options.candidate)", source)
        self.assertIn('Stable tag mode accepts only vX.Y.Z', source)
        self.assertIn("!options.prerelease && !stableTagPattern.test(options.tag)", source)
        self.assertIn("options.prerelease && !prereleaseTagPattern.test(options.tag)", source)
        self.assertIn("options.prerelease ? prereleaseReleaseVersion : stableReleaseVersion", source)

    def test_github_prerelease_create_path_cannot_also_mark_the_release_latest(self):
        source = (ROOT / 'scripts/publish-github-release').read_text()
        self.assertIn('release_flags+=(--prerelease)', source)
        self.assertIn('release_flags+=(--latest)', source)
        create_command = source[source.index('gh release create'):]
        self.assertNotIn('--latest', create_command)


if __name__ == '__main__':
    unittest.main(verbosity=2)
