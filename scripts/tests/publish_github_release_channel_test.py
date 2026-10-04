#!/usr/bin/env python3
"""Focused release-channel tests that do not create tags or GitHub releases."""
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
TMP = ROOT / '.local/tmp/release-channel-test'


class PublishGithubReleaseChannelTest(unittest.TestCase):
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

    def test_prerelease_gate_has_explicit_minimum_groups_without_weakening_stable_gate(self):
        source = (ROOT / 'scripts/check-release-consistency').read_text()
        for group in [
            'generated-application',
            'standalone-fresh',
            'multi-tenant-fresh',
            'production-compose',
        ]:
            self.assertIn(f"'{group}'", source)
        self.assertIn("options.prerelease ? prereleaseRequiredGroups : (fixture?.groups ?? [])", source)
        self.assertIn("options.prerelease ? ['passed', 'partial-passed'] : ['passed']", source)
        self.assertIn("qualification.scope !== 'full'", source)
        self.assertIn("qualification.groups?.[group]?.status ?? 'not-run'", source)
        self.assertIn("minimum.ready_for_first_prerelease !== true", source)
        self.assertIn("minimum.full_p0e_passed !== false", source)

    def test_github_prerelease_create_path_cannot_also_mark_the_release_latest(self):
        source = (ROOT / 'scripts/publish-github-release').read_text()
        self.assertIn('release_flags+=(--prerelease)', source)
        self.assertIn('release_flags+=(--latest)', source)
        create_command = source[source.index('gh release create'):]
        self.assertNotIn('--latest', create_command)


if __name__ == '__main__':
    unittest.main(verbosity=2)
