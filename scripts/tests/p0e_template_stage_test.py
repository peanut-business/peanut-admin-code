#!/usr/bin/env python3
"""Real Git/file stage control; substituted checker/CLI, no native build/APP/DB/network."""
from __future__ import annotations

import argparse
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import tarfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "p0e-runtime-qualification"
loader = importlib.machinery.SourceFileLoader("p0e_template_stage", str(SCRIPT))
spec = importlib.util.spec_from_loader(loader.name, loader)
stage = importlib.util.module_from_spec(spec)
loader.exec_module(stage)
GROUPS = json.loads(stage.FIXTURE_PATH.read_text())["groups"]


class TemplateStageTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="peanut-candidate-stage-contract-")
        self.root = Path(self.temporary.name).resolve()
        self.source = self.root / "source"
        self.source.mkdir()
        for name, text in {"release-versions.json": json.dumps({"scaffold_template": "5.0.2"}),
                           "scaffold/application-template-inventory.json": "{}\n",
                           "scripts/release-template-artifacts.mjs": "// synthetic checker identity\n",
                           "matrix.json": json.dumps({"groups": GROUPS})}.items():
            path = self.source / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text)
        self.commit_fixture(self.source)
        self.candidate, self.tree = self.git("rev-parse", "HEAD"), self.git("rev-parse", "HEAD^{tree}")
        self.cli = self.root / "cli"
        (self.cli / "bin").mkdir(parents=True)
        (self.cli / "package.json").write_text('{"name":"@peanut/cli"}\n')
        (self.cli / "bin/peanut.js").write_text("// synthetic independent CLI identity\n")
        self.commit_fixture(self.cli)
        subprocess.run(["git", "-C", str(self.cli), "remote", "add", "origin",
                        "https://github.com/peanut-business/peanut-cli.git"], check=True)
        self.cli_binding = {"root": str(self.cli),
                            "commit": subprocess.check_output(["git", "-C", str(self.cli), "rev-parse", "HEAD"], text=True).strip(),
                            "tree": subprocess.check_output(["git", "-C", str(self.cli), "rev-parse", "HEAD^{tree}"], text=True).strip()}
        self.templates, self.output = self.root / "templates", self.root / "qualification"
        self.templates.mkdir()
        for edition in stage.EDITIONS:
            root = self.templates / edition
            root.mkdir()
            (root / f"peanut-scaffold-{edition}.tgz").write_bytes(b"synthetic template archive")
            (root / f"peanut-scaffold-{edition}.json").write_text("{}\n")
        self.input_path = self.root / "candidate-inputs.json"
        self.input_value = {"protocol": "peanut.p0e-candidate-inputs.v1",
                            "source": {"commit": self.candidate, "tree": self.tree},
                            "cli": self.cli_binding, "template_build_root": str(self.templates)}
        self.args = argparse.Namespace(candidate=self.candidate, run_id="contract-candidate",
                                       candidate_input=str(self.input_path), candidate_input_sha256="",
                                       output_dir=str(self.output))
        self.write_input()
        self.inputs = {"protocol": "peanut.template-release-inputs.v1", "version": "5.0.2", "source": {
            "commit": self.candidate, "tree": self.tree, "inventory_sha256": stage.sha256(self.source / "scaffold/application-template-inventory.json")}}
        self.original_command, self.calls, self.rc, self.result = stage.command, [], 0, self.inputs
        self.bad_cli_identity = False
        self.patches = [patch.object(stage, "ROOT", self.source), patch.object(stage, "FIXTURE_PATH", self.source / "matrix.json"),
                        patch.object(stage, "command", side_effect=self.command),
                        patch.object(stage, "documentation_contract", return_value={"synthetic": True})]
        for item in self.patches:
            item.start()

    @staticmethod
    def commit_fixture(root):
        for argv in (["init", "-q"], ["config", "user.name", "Candidate contract"],
                     ["config", "user.email", "candidate-contract@example.invalid"], ["add", "."],
                     ["commit", "-qm", "synthetic fixed source"]):
            subprocess.run(["git", "-C", str(root), *argv], check=True)

    def write_input(self):
        self.input_path.write_text(json.dumps(self.input_value))
        self.args.candidate_input_sha256 = stage.sha256(self.input_path)

    def tearDown(self):
        for item in reversed(self.patches):
            item.stop()
        self.temporary.cleanup()

    def git(self, *args):
        return subprocess.check_output(["git", "-C", str(self.source), *args], text=True).strip()

    def command(self, arguments, **kwargs):
        if arguments[0] != "node":
            return self.original_command(arguments, **kwargs)
        self.calls.append(arguments)
        if arguments[1] == str(self.cli / "bin/peanut.js"):
            target = Path(arguments[3])
            edition = arguments[arguments.index("--edition") + 1]
            archive = Path(arguments[arguments.index("--archive") + 1])
            (target / ".peanut").mkdir(parents=True)
            identity = {"template": {"source_commit": "0" * 40 if self.bad_cli_identity else self.candidate, "source_tree": self.tree},
                        "template_package": {"archive_sha256": stage.sha256(archive)},
                        "application": {"edition": edition}, "edition": {"name": edition}}
            (target / ".peanut/application-manifest.json").write_text(json.dumps(identity))
            return subprocess.CompletedProcess(arguments, 0, json.dumps({"status": "created", "target": str(target)}), "")
        result = self.result if arguments[2] == "check" else self.inputs
        return subprocess.CompletedProcess(arguments, self.rc if arguments[2] == "check" else 0,
                                           json.dumps(result), "synthetic checker only\n")

    def receipt(self):
        return json.loads((self.output / "candidate-stage.json").read_text())

    def test_exact_inputs_never_grant_full_group_or_publication(self):
        result = stage.qualify_candidate(self.args)
        self.assertEqual(result["status"], "candidate-passed")
        self.assertFalse(result["publication_qualified"])
        checks = [call for call in self.calls if call[2] == "check"]
        self.assertEqual(checks, [["node", str(self.source / "scripts/release-template-artifacts.mjs"), "check", str(self.templates),
                                  "5.0.2", self.candidate, self.tree, self.inputs["source"]["inventory_sha256"], str(self.source)]])
        self.assertEqual(len([call for call in self.calls if call[2] == "create"]), 2)
        receipt = self.receipt()
        self.assertEqual(receipt["template_artifacts"], self.inputs)
        self.assertEqual(receipt["full_gate_groups"], GROUPS)
        self.assertTrue(all(v["status"] == "not_run" for v in receipt["groups"].values()))
        self.assertEqual(receipt["groups"]["generated-application"]["components"],
                         {"native_template_contract": "passed", "cli_create_and_app_identity": "passed", "source_build": "not_run"})
        self.assertEqual(set(receipt["cli_created_applications"]), set(stage.EDITIONS))
        self.assertTrue(all(v is False for v in receipt["runtime"].values()))

    def test_failure_is_preserved_and_not_replayed(self):
        self.rc = 17
        with self.assertRaises(stage.GateError): stage.qualify_candidate(self.args)
        self.assertEqual(self.receipt()["status"], "failed-preserved")
        self.assertNotIn("template_artifacts", self.receipt())
        with self.assertRaises(stage.GateError): stage.qualify_candidate(self.args)
        self.assertEqual(len([call for call in self.calls if call[2] == "check"]), 1)
        self.assertFalse(any(call[2] == "create" for call in self.calls))

    def test_foreign_checker_identity_has_no_success(self):
        self.result = self.inputs | {"version": "other"}
        with self.assertRaises(stage.GateError): stage.qualify_candidate(self.args)
        self.assertEqual(self.receipt()["status"], "failed-preserved")

    def test_dirty_or_wrong_candidate_rejected_before_checker(self):
        file = self.source / "uncommitted"
        file.write_text("preserve me")
        with self.assertRaises(stage.GateError): stage.qualify_candidate(self.args)
        self.assertFalse(self.output.exists())
        file.unlink()
        self.args.candidate = "0" * 40
        with self.assertRaises(stage.GateError): stage.qualify_candidate(self.args)
        self.assertEqual(self.calls, [])

    def test_linked_input_and_nested_output_rejected(self):
        link = self.root / "linked"
        link.symlink_to(self.templates, target_is_directory=True)
        self.input_value["template_build_root"] = str(link)
        self.write_input()
        with self.assertRaises(stage.GateError): stage.qualify_candidate(self.args)
        self.input_value["template_build_root"] = str(self.templates)
        self.write_input()
        self.args.output_dir = str(self.templates / "output")
        with self.assertRaises(stage.GateError): stage.qualify_candidate(self.args)
        self.assertFalse(any(call[2] in ("create", "check") for call in self.calls))

    def test_runtime_inputs_and_bad_cli_creation_are_rejected(self):
        self.input_value["applications"] = {}
        self.write_input()
        with self.assertRaises(stage.GateError): stage.qualify_candidate(self.args)
        self.assertEqual(self.calls, [])
        del self.input_value["applications"]
        self.write_input()
        self.bad_cli_identity = True
        with self.assertRaises(stage.GateError): stage.qualify_candidate(self.args)
        self.assertEqual(self.receipt()["status"], "failed-preserved")
        self.assertEqual(len([call for call in self.calls if call[2] == "create"]), 1)

    def test_ci_transport_preserves_hidden_defaults_and_executable_modes(self):
        file = self.templates / "standalone/defaults/.env.example"
        file.parent.mkdir(parents=True)
        file.write_text("synthetic placeholder, no credentials\n")
        file.chmod(0o640)
        executable = self.templates / "multi-tenant/tooling/native-entry"
        executable.parent.mkdir(parents=True)
        executable.write_text("synthetic executable payload\n")
        executable.chmod(0o755)
        self.output.mkdir()
        (self.output / "candidate-stage.json").write_text("{}\n")
        archive = self.root / "transport.tgz"
        subprocess.run(["tar", "-czf", str(archive), "-C", str(self.root), "templates", "qualification"], check=True)
        with tarfile.open(archive, "r:gz") as package:
            hidden = package.getmember("templates/standalone/defaults/.env.example")
            script = package.getmember("templates/multi-tenant/tooling/native-entry")
            self.assertEqual(hidden.mode, 0o640)
            self.assertEqual(script.mode, 0o755)
            self.assertEqual(package.extractfile(hidden).read(), file.read_bytes())
            self.assertEqual(package.extractfile(script).read(), executable.read_bytes())
        workflow = (SCRIPT.parent.parent / ".github/workflows/scaffold-release.yml").read_text()
        self.assertIn('scaffold-templates template-qualification', workflow)
        self.assertIn('sha256sum scaffold-template-qualification.tgz', workflow)
        self.assertIn('scripts/p0e-runtime-qualification candidate', workflow)
        self.assertIn('repository: peanut-business/peanut-cli', workflow)
        self.assertIn('if: ${{ always() }}', workflow)
        self.assertIn("always() && steps.preserve_candidate.outputs.available == 'true'", workflow)
        self.assertIn('for name in scaffold-templates template-qualification candidate-inputs.json', workflow)
        self.assertNotIn('continue-on-error:', workflow)


if __name__ == "__main__":
    unittest.main()
