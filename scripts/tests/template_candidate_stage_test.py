#!/usr/bin/env python3
"""Contract tests for scripts/verify-template-candidate."""
from __future__ import annotations

import argparse
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "verify-template-candidate"
loader = importlib.machinery.SourceFileLoader("verify_template_candidate", str(SCRIPT))
spec = importlib.util.spec_from_loader(loader.name, loader)
stage = importlib.util.module_from_spec(spec)
loader.exec_module(stage)


class TemplateCandidateStageTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="peanut-template-candidate-")
        self.root = Path(self.temporary.name).resolve()
        self.source = self.root / "source"
        self.source.mkdir()
        for name, text in {
            "release-versions.json": json.dumps({"scaffold_template": "5.1.13", "source_product_version": "5.1.13"}),
            "scaffold/application-template-inventory.json": "{}\n",
            "scripts/release-template-artifacts.mjs": "// synthetic checker\n",
        }.items():
            path = self.source / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text, encoding="utf-8")
        self.commit_fixture(self.source)
        self.candidate = self.git(self.source, "rev-parse", "HEAD")
        self.tree = self.git(self.source, "rev-parse", "HEAD^{tree}")

        self.cli = self.root / "cli"
        (self.cli / "bin").mkdir(parents=True)
        (self.cli / "package.json").write_text('{"name":"@peanut-admin/cli"}\n', encoding="utf-8")
        (self.cli / "bin/peanut.js").write_text("// synthetic CLI\n", encoding="utf-8")
        self.commit_fixture(self.cli)
        subprocess.run(
            ["git", "-C", str(self.cli), "remote", "add", "origin", "https://github.com/peanut-business/peanut-cli.git"],
            check=True,
        )
        self.cli_binding = {
            "root": str(self.cli),
            "commit": self.git(self.cli, "rev-parse", "HEAD"),
            "tree": self.git(self.cli, "rev-parse", "HEAD^{tree}"),
        }

        self.templates = self.root / "templates"
        self.templates.mkdir()
        for edition in stage.EDITIONS:
            ed_dir = self.templates / edition
            ed_dir.mkdir()
            (ed_dir / f"peanut-scaffold-{edition}.tgz").write_bytes(b"synthetic archive")
            (ed_dir / f"peanut-scaffold-{edition}.json").write_text("{}\n", encoding="utf-8")

        self.output = self.root / "stage-out"
        self.input_path = self.root / "candidate-inputs.json"
        self.input_value = {
            "protocol": stage.INPUT_PROTOCOL,
            "source": {"commit": self.candidate, "tree": self.tree},
            "cli": self.cli_binding,
            "template_build_root": str(self.templates),
        }
        self.args = argparse.Namespace(
            candidate=self.candidate,
            run_id="ci-run-1",
            candidate_input=str(self.input_path),
            candidate_input_sha256="",
            output_dir=str(self.output),
        )
        self.write_input()

        self.checked_payload = {
            "protocol": "peanut.template-release-inputs.v1",
            "version": "5.1.13",
            "source": {
                "commit": self.candidate,
                "tree": self.tree,
                "inventory_sha256": stage.sha256(self.source / "scaffold/application-template-inventory.json"),
            },
        }
        self.original_run_command = stage.run_command
        self.checker_rc = 0
        self.inject_node_modules = False
        self.patches = [
            patch.object(stage, "ROOT", self.source),
            patch.object(stage, "run_command", side_effect=self.fake_run_command),
            patch.object(stage, "documentation_contract", return_value={"delivery": "synthetic"}),
        ]
        for p in self.patches:
            p.start()

    def tearDown(self) -> None:
        for p in reversed(self.patches):
            p.stop()
        self.temporary.cleanup()

    @staticmethod
    def commit_fixture(root: Path) -> None:
        for argv in (
            ["init", "-q"],
            ["config", "user.name", "Candidate Test"],
            ["config", "user.email", "test@example.com"],
            ["add", "."],
            ["commit", "-qm", "init"],
        ):
            subprocess.run(["git", "-C", str(root), *argv], check=True)

    @staticmethod
    def git(root: Path, *args: str) -> str:
        return subprocess.check_output(["git", "-C", str(root), *args], text=True).strip()

    def write_input(self) -> None:
        self.input_path.write_text(json.dumps(self.input_value, indent=2) + "\n", encoding="utf-8")
        self.args.candidate_input_sha256 = stage.sha256(self.input_path)

    def fake_run_command(
        self,
        arguments: list[str],
        *,
        cwd: Path | None = None,
        capture: bool = False,
        check: bool = True,
    ) -> subprocess.CompletedProcess[str]:
        if arguments[0] == "git":
            return self.original_run_command(arguments, cwd=cwd, capture=capture, check=check)
        if arguments[0] == "node" and Path(arguments[1]).name == "release-template-artifacts.mjs":
            stdout = json.dumps(self.checked_payload) if self.checker_rc == 0 else ""
            stderr = "" if self.checker_rc == 0 else "checker error\n"
            if check and self.checker_rc != 0:
                raise SystemExit(1)
            return subprocess.CompletedProcess(arguments, self.checker_rc, stdout=stdout, stderr=stderr)
        if arguments[0] == "node" and Path(arguments[1]).name == "peanut.js":
            target = Path(arguments[3])
            edition = arguments[arguments.index("--edition") + 1]
            archive = Path(arguments[arguments.index("--archive") + 1])
            (target / ".peanut").mkdir(parents=True, exist_ok=True)
            manifest = {
                "template": {"source_commit": self.candidate, "source_tree": self.tree},
                "template_package": {"archive_sha256": stage.sha256(archive)},
                "application": {"edition": edition},
                "edition": {"name": edition},
            }
            (target / ".peanut/application-manifest.json").write_text(json.dumps(manifest), encoding="utf-8")
            if self.inject_node_modules and edition == "standalone":
                (target / "web/node_modules").mkdir(parents=True, exist_ok=True)
            created = {"status": "created", "target": str(target)}
            return subprocess.CompletedProcess(arguments, 0, stdout=json.dumps(created), stderr="")
        raise AssertionError(f"unexpected command: {arguments!r}")

    def test_verify_candidate_succeeds_and_writes_clean_receipt(self) -> None:
        result = stage.verify_candidate(self.args)
        self.assertEqual(result["status"], "candidate-passed")
        receipt = json.loads((self.output / "candidate-stage.json").read_text(encoding="utf-8"))
        self.assertEqual(receipt["protocol"], stage.STAGE_PROTOCOL)
        self.assertEqual(receipt["producer"]["path"], "scripts/verify-template-candidate")
        self.assertEqual(sorted(receipt["cli_created_applications"]), ["multi-tenant", "standalone"])
        self.assertNotIn("groups", receipt)
        self.assertNotIn("full_gate_groups", receipt)

    def test_rejects_dirty_worktree(self) -> None:
        (self.source / "dirty.txt").write_text("dirty\n", encoding="utf-8")
        with self.assertRaises(SystemExit):
            stage.verify_candidate(self.args)
        self.assertFalse(self.output.exists())

    def test_rejects_implicit_dependency_installation_and_preserves_failure(self) -> None:
        self.inject_node_modules = True
        with self.assertRaises(SystemExit):
            stage.verify_candidate(self.args)
        receipt = json.loads((self.output / "candidate-stage.json").read_text(encoding="utf-8"))
        self.assertEqual(receipt["status"], "failed-preserved")

    def test_checker_failure_preserves_native_log(self) -> None:
        self.checker_rc = 2
        with patch.object(stage, "template_inputs", return_value=self.checked_payload):
            with self.assertRaises(SystemExit):
                stage.verify_candidate(self.args)
        receipt = json.loads((self.output / "candidate-stage.json").read_text(encoding="utf-8"))
        self.assertEqual(receipt["status"], "failed-preserved")
        self.assertEqual(receipt["native_check"]["rc"], 2)
        self.assertTrue((self.output / "native-template-check.log").is_file())


if __name__ == "__main__":
    unittest.main(verbosity=2)
