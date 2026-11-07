#!/usr/bin/env python3
"""Exercise the current internal Core input boundary of the native Edition builder."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
VERSION = json.loads((ROOT / "release-versions.json").read_text())["scaffold_template"]


class InternalCandidateTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="internal-edition-")
        self.addCleanup(temporary.cleanup)
        self.work = Path(temporary.name).resolve()
        self.inputs = self.work / "release-core-inputs.json"
        self.inputs.write_text("{}\n")
        self.output = self.work / "output"
        self.output.mkdir()
        self.retained = self.output / "existing"
        self.retained.write_text("preserve me")

    def assert_refused(self, inputs, expected, edition="multi-tenant"):
        result = subprocess.run(
            ["php", str(ROOT / "scripts/build-scaffold-release"), "--version=" + VERSION,
             "--source-commit=" + "a" * 40, "--edition=" + edition, "--workflow=full",
             "--internal-core-inputs=" + str(inputs), "--output=" + str(self.output)],
            cwd=ROOT, capture_output=True, text=True, timeout=30,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(expected, result.stdout + result.stderr)
        self.assertEqual(list(self.output.iterdir()), [self.retained])
        self.assertEqual(self.retained.read_text(), "preserve me")

    def test_regular_inputs_reach_the_existing_output_guard_for_both_editions(self):
        for edition in ("standalone", "multi-tenant"):
            with self.subTest(edition=edition):
                self.assert_refused(self.inputs, "SCAFFOLD_RELEASE_OUTPUT_EXISTS", edition)

    def test_missing_or_noncanonical_input_cannot_start_a_build(self):
        self.assert_refused(self.work / "absent", "SCAFFOLD_RELEASE_PATH_MISSING")
        self.assert_refused(str(self.work) + "/../" + self.work.name + "/" + self.inputs.name,
                            "SCAFFOLD_RELEASE_PATH_INVALID")
        self.assert_refused(self.work, "SCAFFOLD_RELEASE_INPUT_FILE_INVALID")

    def test_input_aliases_and_parent_links_are_rejected_before_output(self):
        linked = self.work / "linked"
        linked.symlink_to(self.inputs)
        self.assert_refused(linked, "SCAFFOLD_RELEASE_PATH_LINK_REJECTED")
        parent = self.work / "parent"
        parent.symlink_to(self.work, target_is_directory=True)
        self.assert_refused(parent / self.inputs.name, "SCAFFOLD_RELEASE_PATH_LINK_REJECTED")
        os.link(self.inputs, self.work / "hard-link")
        self.assert_refused(self.inputs, "SCAFFOLD_RELEASE_INPUT_FILE_INVALID")


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(InternalCandidateTest)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    if not result.wasSuccessful():
        raise SystemExit(1)
    # The native producer suites own source/lock/archive tamper and publication identity.
    subprocess.run(["python3", str(ROOT / "scripts/tests/freeze-core-release-test.py")], cwd=ROOT, check=True)
    subprocess.run(["node", "--test", str(ROOT / "scripts/tests/release-template-artifacts-test.mjs")], cwd=ROOT, check=True)
    print("INTERNAL-EDITION-INPUT-BOUNDARIES passed")
