#!/usr/bin/env python3
"""Real Git/file stage control; substituted checker, no native build/APP/DB/network."""
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
        self.temporary = tempfile.TemporaryDirectory(prefix="peanut-template-stage-contract-")
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
        self.git("init", "-q")
        self.git("config", "user.name", "Template contract")
        self.git("config", "user.email", "template-contract@example.invalid")
        self.git("add", ".")
        self.git("commit", "-qm", "synthetic fixed source")
        self.candidate, self.tree = self.git("rev-parse", "HEAD"), self.git("rev-parse", "HEAD^{tree}")
        self.templates, self.output = self.root / "templates", self.root / "qualification"
        self.templates.mkdir()
        self.args = argparse.Namespace(candidate=self.candidate, run_id="contract-061", template_artifacts=str(self.templates), output_dir=str(self.output))
        self.inputs = {"protocol": "peanut.template-release-inputs.v1", "version": "5.0.2", "source": {
            "commit": self.candidate, "tree": self.tree, "inventory_sha256": stage.sha256(self.source / "scaffold/application-template-inventory.json")}}
        self.original_command, self.calls, self.rc, self.result = stage.command, [], 0, self.inputs
        self.patches = [patch.object(stage, "ROOT", self.source), patch.object(stage, "FIXTURE_PATH", self.source / "matrix.json"),
                        patch.object(stage, "command", side_effect=self.command)]
        for item in self.patches:
            item.start()

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
        return subprocess.CompletedProcess(arguments, self.rc, json.dumps(self.result), "synthetic checker only\n")

    def receipt(self):
        return json.loads((self.output / "template-stage.json").read_text())

    def test_exact_inputs_never_grant_full_group_or_publication(self):
        result = stage.qualify_templates(self.args)
        self.assertEqual(result["status"], "partial-passed")
        self.assertFalse(result["publication_qualified"])
        self.assertEqual(self.calls, [["node", str(self.source / "scripts/release-template-artifacts.mjs"), "check", str(self.templates),
                                      "5.0.2", self.candidate, self.tree, self.inputs["source"]["inventory_sha256"], str(self.source)]])
        receipt = self.receipt()
        self.assertEqual(receipt["template_artifacts"], self.inputs)
        self.assertEqual(receipt["full_gate_groups"], GROUPS)
        self.assertEqual(receipt["groups"]["generated-application"]["status"], "partial")
        self.assertTrue(all(v["status"] == "not_run" for k, v in receipt["groups"].items() if k != "generated-application"))
        self.assertTrue(all(v is False for v in receipt["runtime"].values()))

    def test_failure_is_preserved_and_not_replayed(self):
        self.rc = 17
        with self.assertRaises(stage.GateError): stage.qualify_templates(self.args)
        self.assertEqual(self.receipt()["status"], "failed-preserved")
        self.assertNotIn("template_artifacts", self.receipt())
        with self.assertRaises(stage.GateError): stage.qualify_templates(self.args)
        self.assertEqual(len(self.calls), 1)

    def test_foreign_checker_identity_has_no_success(self):
        self.result = self.inputs | {"version": "other"}
        with self.assertRaises(stage.GateError): stage.qualify_templates(self.args)
        self.assertEqual(self.receipt()["status"], "failed-preserved")

    def test_dirty_or_wrong_candidate_rejected_before_checker(self):
        file = self.source / "uncommitted"
        file.write_text("preserve me")
        with self.assertRaises(stage.GateError): stage.qualify_templates(self.args)
        self.assertFalse(self.output.exists())
        file.unlink()
        self.args.candidate = "0" * 40
        with self.assertRaises(stage.GateError): stage.qualify_templates(self.args)
        self.assertEqual(self.calls, [])

    def test_linked_input_and_nested_output_rejected(self):
        link = self.root / "linked"
        link.symlink_to(self.templates, target_is_directory=True)
        self.args.template_artifacts = str(link)
        with self.assertRaises(stage.GateError): stage.qualify_templates(self.args)
        self.args.template_artifacts, self.args.output_dir = str(self.templates), str(self.templates / "output")
        with self.assertRaises(stage.GateError): stage.qualify_templates(self.args)
        self.assertEqual(self.calls, [])

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
        (self.output / "template-stage.json").write_text("{}\n")
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


if __name__ == "__main__":
    unittest.main()
