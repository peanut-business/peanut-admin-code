"""Regression: source S, same-tree PR merge M, immutable tag and non-mutating checks."""
import json
import pathlib
import runpy
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parents[2]
VERIFY = runpy.run_path(str(ROOT / "scripts/ensure-qualified-release-tag"))["verify"]
SOURCE = "a" * 40
MAIN = "b" * 40
TAGOBJ = "c" * 40
REPO = "peanut-business/peanut-admin-code"


class QualifiedTagTest(unittest.TestCase):
    def fixture(self, *, target=SOURCE, tree="d" * 40, pr_good=True):
        paths = {
            "refs/heads/main": MAIN,
            "refs/tags/v1.2.3": TAGOBJ,
            "refs/tags/v1.2.3^{}": target,
        }
        metadata = json.dumps({"expected_tag": "v1.2.3", "source_product_version": "1.2.3"})
        versions = json.dumps({"source_product_version": "1.2.3", "scaffold_template": "1.2.3"})

        def git(*args):
            if args == ("rev-parse", SOURCE + "^{commit}"): return SOURCE
            if args == ("rev-parse", MAIN + "^{commit}"): return MAIN
            if args == ("rev-parse", SOURCE + "^{tree}"): return "d" * 40
            if args == ("rev-parse", MAIN + "^{tree}"): return tree
            if args == ("show", SOURCE + ":release-versions.json"): return versions
            if args == ("show", SOURCE + ":RELEASE_METADATA.json"): return metadata
            if args == ("show", "-s", "--format=%P", MAIN): return "f" * 40 + " " + SOURCE
            raise AssertionError("unexpected git " + repr(args))

        def api(path):
            if path == f"repos/{REPO}/commits/{SOURCE}/pulls":
                return [{"merge_commit_sha": MAIN if pr_good else SOURCE,
                         "head": {"sha": SOURCE}, "number": 481}]
            if path == f"repos/{REPO}/pulls/481":
                return {"merged": True, "state": "closed", "merge_commit_sha": MAIN,
                        "head": {"sha": SOURCE, "ref": "dev", "repo": {"full_name": REPO}},
                        "base": {"ref": "main", "repo": {"full_name": REPO}}}
            if path == f"repos/{REPO}/git/tags/{TAGOBJ}":
                return {"tag": "v1.2.3", "object": {"type": "commit", "sha": target}}
            raise AssertionError("unexpected gh " + path)

        return {"git": git, "api": api, "remote_ref": paths.get}

    def test_exact_qualified_source_tag_and_merged_main(self):
        with patch.dict(VERIFY.__globals__, self.fixture()):
            result = VERIFY("1.2.3", SOURCE)
        self.assertEqual(result["tag_action"], "reuse")
        self.assertEqual(result["source"], SOURCE)
        self.assertEqual(result["main"], MAIN)

    def test_wrong_merge_commit_tag_is_rejected_even_when_tree_matches(self):
        with patch.dict(VERIFY.__globals__, self.fixture(target=MAIN)):
            with self.assertRaisesRegex(ValueError, "EXISTING_TAG_WRONG_SOURCE"):
                VERIFY("1.2.3", SOURCE)

    def test_pr_or_content_drift_fails_before_tag_creation(self):
        for overrides in ({"pr_good": False}, {"tree": "e" * 40}):
            with self.subTest(overrides=overrides), patch.dict(VERIFY.__globals__, self.fixture(**overrides)):
                with self.assertRaises(ValueError):
                    VERIFY("1.2.3", SOURCE)

    def test_workflow_requires_original_bytes_before_tag_and_publisher(self):
        workflow = (ROOT / ".github/workflows/scaffold-release.yml").read_text()
        names = ["Verify the original candidate and template bytes without rebuilding",
                 "Verify source-to-main PR and create or reuse only the exact annotated source tag",
                 "Publish the verified native templates through the sole publisher"]
        locations = [workflow.index("      - name: " + name) for name in names]
        self.assertEqual(locations, sorted(locations))
        self.assertIn("python3 scripts/ensure-qualified-release-tag --version", workflow)


if __name__ == "__main__":
    unittest.main()
