#!/usr/bin/env python3
"""Real Git/file stage control; substituted checker/CLI, no native build/APP/DB/network."""
from __future__ import annotations

import argparse
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import runpy
import subprocess
import sys
import tempfile
import tarfile
import time
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

    def test_cli_origin_from_actions_checkout_and_foreign_remotes(self):
        official = "https://github.com/peanut-business/peanut-cli"
        git = ["git", "-C", str(self.cli), "remote", "set-url", "origin"]
        subprocess.run([*git, official], check=True)
        inputs = stage.candidate_inputs(self.input_value, self.candidate, self.tree)
        self.assertEqual(inputs["cli"]["entry_sha256"], stage.sha256(self.cli / "bin/peanut.js"))
        for foreign in (official + "-fork", official + "/extra", "https://example.com/peanut-business/peanut-cli", str(self.cli)):
            with self.subTest(origin=foreign):
                subprocess.run([*git, foreign], check=True)
                with self.assertRaisesRegex(stage.GateError, "independent CLI source repository differs"):
                    stage.candidate_inputs(self.input_value, self.candidate, self.tree)

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

    def test_runtime_admission_uses_code_native_proof_and_preserves_actor_schema_checks(self):
        actor = self.root / "application"
        actor.mkdir()
        proof = self.root / "native-tool-domain/leases/runtime-proof"
        proof.mkdir(parents=True)
        runtime = {"lease": "runtime-proof", "resource_id": "synthetic-database"}
        required = [("database", "synthetic_schema"), ("mysql-db", "synthetic_schema"),
                    ("mysql-schema", "synthetic-server/synthetic_schema"), ("endpoint", "synthetic-endpoint")]
        metadata = {"owner": "native-owner", "thread": "native-thread", "worktree": str(actor),
                    "candidate_repository": str(actor), "candidate": self.candidate, "gate": "native-gate",
                    "parent_lease": "", "status": "ACTIVE", "expires_at": str(int(time.time()) + 600)}
        state = {"metadata": metadata, "resources": required, "proof": str(proof), "denied": False}
        native_calls = []

        def native_command(arguments, **kwargs):
            native_calls.append((arguments, kwargs))
            if arguments[:2] == [str(stage.LEASE_TOOL), "show"]:
                self.assertEqual(kwargs, {"cwd": actor, "capture": True})
                self.assertEqual(arguments[2:5], ["--lease", "runtime-proof", "--verify-locks"])
                if state["denied"]:
                    raise stage.GateError("native lease denied")
                if arguments[5:] == ["--proof-dir"]:
                    text = state["proof"] + "\n"
                else:
                    self.assertEqual(len(arguments), 5)
                    text = "".join(f"{key}\t{value}\n" for key, value in state["metadata"].items())
                    text += "resources\n" + "".join(f"{key}\t{value}\n" for key, value in state["resources"])
                return subprocess.CompletedProcess(arguments, 0, text, "")
            self.assertEqual(arguments, ["git", "-C", str(actor), "rev-parse", "HEAD"])
            return subprocess.CompletedProcess(arguments, 0, self.candidate + "\n", "")

        with patch.object(stage, "command", side_effect=native_command), \
             patch.object(stage, "database_registration", return_value={"lease_gate": "native-gate"}), \
             patch.object(stage, "database_resources", return_value=required):
            admission = stage.runtime_admission(runtime, str(actor), self.candidate)
            self.assertEqual(admission["proof_dir"], str(proof))
            self.assertFalse((actor / "scripts/project-resource-lease").exists())
            stage.verify_runtime_admission(admission, "native-owner", "native-thread")
            self.assertTrue(all(call[0][0] == str(stage.LEASE_TOOL) for call in native_calls if call[0][0] != "git"))
            self.assertEqual(native_calls[0][0][-1], "--proof-dir")
            with self.assertRaisesRegex(stage.GateError, "directory differs"):
                stage.verify_runtime_admission(admission | {"proof_dir": str(actor / "wrong-domain")}, "native-owner", "native-thread")
            for field in ("owner", "thread", "worktree", "candidate_repository", "candidate", "gate", "parent_lease", "status"):
                with self.subTest(metadata=field):
                    state["metadata"] = metadata | {field: "wrong"}
                    with self.assertRaisesRegex(stage.GateError, "owner/thread/source/gate"):
                        stage.verify_runtime_admission(admission, "native-owner", "native-thread")
            state["metadata"] = metadata
            state["resources"] = required + [("mysql-schema", "another-server/another-schema")]
            with self.assertRaisesRegex(stage.GateError, "another schema"):
                stage.verify_runtime_admission(admission, "native-owner", "native-thread")
            state["denied"] = True
            with self.assertRaisesRegex(stage.GateError, "native lease denied"):
                stage.runtime_admission(runtime, str(actor), self.candidate)

    def fresh_fixture(self):
        app_root = self.root / "fresh-standalone"
        for relative in (".peanut", "resources", "server/private/installation"):
            (app_root / relative).mkdir(parents=True, exist_ok=True)
        endpoint = {"endpoint_id": "fresh-endpoint", "host": "synthetic", "port": 1}
        resource = {"stable_resource_id": "fresh-resource", "environments": ["development"], "application_runtime": False,
                    "lifecycle": "ephemeral", "allowed_scenarios": ["first_install"], "database": "fresh_schema",
                    "upstream_endpoint": endpoint, "deployment_modes": ["standalone"], "server_uuid": "synthetic-server"}
        self.fresh_templates = self.inputs | {"editions": {"standalone": {"archive": "peanut-scaffold-standalone.tgz"}},
            "assets": {"peanut-scaffold-standalone.tgz": {"sha256": "a" * 64}}}
        versions = {"core_php": {"resolved_version": "v5.0.3"}, "core_web": {"source_reference": "b" * 40}}
        (self.source / "release-versions.json").write_text(json.dumps(versions))
        (app_root / "release-versions.json").write_text(json.dumps(versions))
        manifest = {"application": {"edition": "standalone", "slug": "fixture-standalone"}, "edition": {"name": "standalone"},
                    "template": {"source_commit": self.candidate, "source_tree": self.tree, "version": "5.0.2",
                                 "inventory_sha256": self.inputs["source"]["inventory_sha256"]},
                    "template_package": {"protocol": "peanut.template-package.v1", "version": "5.0.2", "archive_sha256": "a" * 64}}
        manifest_file = app_root / ".peanut/application-manifest.json"
        manifest_file.write_text(json.dumps(manifest))
        (app_root / "resources/project-resources.json").write_text(json.dumps({"resources": {"databases": [resource]}}))
        environment = app_root / "server/.env"
        environment.write_text("SYNTHETIC=1\n")
        environment.chmod(0o600)
        receipt = app_root / "server/private/installation/installed.json"
        self.installation_release = {"source_product_version": "5.0.2", "release_sequence_version": "0.1.0", "scaffold_template": "5.0.2"}
        receipt.write_text(json.dumps({"source": {"kind": "generated-application", "application_manifest_sha256": stage.sha256(manifest_file)},
            "application_manifest_sha256": stage.sha256(manifest_file), "release": self.installation_release,
            "deployment_mode": "standalone", "official_modules": ["peanut.admin"], "database": {"migrations": [{"version": "synthetic"}]}}))
        receipt.chmod(0o600)
        app = {"root": str(app_root), "head": "c" * 40, "tree": "d" * 40, "resource_id": "fresh-resource",
               "database": "fresh_schema", "endpoint_id": "fresh-endpoint", "lease": "fresh-lease",
               "database_identity": {"database": "fresh_schema", "server_uuid": "synthetic-server"},
               "manifest_sha256": stage.sha256(manifest_file), "baseline_sha256": "e" * 64,
               "adopted_source": self.inputs["source"], "environment": {"path": str(environment), "sha256": stage.sha256(environment)},
               "first_install": {"path": str(receipt), "sha256": stage.sha256(receipt)}}
        permanent_root = self.root / "permanent"
        permanent_root.mkdir(exist_ok=True)
        permanent = app | {"root": str(permanent_root), "resource_id": "permanent-resource", "database": "permanent_schema"}
        return app, permanent, resource

    def test_fresh_inputs_bind_original_archive_and_isolated_native_resource(self):
        app, permanent, resource = self.fresh_fixture()
        value = self.input_value | {"applications": {"standalone": permanent}, "fresh_applications": {"standalone": app}}
        registry = lambda runtime: resource if runtime["resource_id"] == app["resource_id"] else resource | {"database": "permanent_schema"}
        with patch.object(stage, "database_registration", side_effect=registry), \
             patch.object(stage, "checkout_identity") as checkout, \
             patch.object(stage, "baseline_identity", return_value=app["baseline_sha256"]):
            self.assertEqual(stage.fresh_application_inputs(value, self.candidate, self.tree, self.fresh_templates), value["fresh_applications"])
            checkout.assert_called_once_with(Path(app["root"]), app["head"], app["tree"])
            self.assertEqual(stage.fresh_application_inputs({}, self.candidate, self.tree, self.fresh_templates), {})
            original = json.loads(Path(app["root"], ".peanut/application-manifest.json").read_text())
            for field in ("source_commit", "source_tree", "inventory_sha256"):
                with self.subTest(template=field):
                    changed = json.loads(json.dumps(original))
                    changed["template"][field] = "0" * 40
                    path = Path(app["root"], ".peanut/application-manifest.json")
                    path.write_text(json.dumps(changed)); app["manifest_sha256"] = stage.sha256(path)
                    with self.assertRaisesRegex(stage.GateError, "exact candidate"):
                        stage.fresh_application_inputs(value, self.candidate, self.tree, self.fresh_templates)
            path.write_text(json.dumps(original)); app["manifest_sha256"] = stage.sha256(path)
            changed = json.loads(json.dumps(original)); changed["template_package"]["archive_sha256"] = "0" * 64
            path.write_text(json.dumps(changed)); app["manifest_sha256"] = stage.sha256(path)
            with self.assertRaisesRegex(stage.GateError, "original qualified Edition archive"):
                stage.fresh_application_inputs(value, self.candidate, self.tree, self.fresh_templates)
            path.write_text(json.dumps(original)); app["manifest_sha256"] = stage.sha256(path)
            for patch_value in ({"application_runtime": True}, {"lifecycle": "persistent"}, {"allowed_scenarios": []},
                                {"environments": ["development", "production"]}, {"environments": []}):
                with self.subTest(registry=patch_value), patch.object(stage, "database_registration", return_value=resource | patch_value):
                    with self.assertRaisesRegex(stage.GateError, "isolated ephemeral"):
                        stage.fresh_application_inputs(value, self.candidate, self.tree, self.fresh_templates)
            with patch.object(stage, "database_registration", return_value=resource):
                permanent["database"] = app["database"]
                with self.assertRaisesRegex(stage.GateError, "database identity must be separate"):
                    stage.fresh_application_inputs(value, self.candidate, self.tree, self.fresh_templates)
                permanent["database"] = "permanent_schema"
            app["root"] = permanent["root"]
            with self.assertRaisesRegex(stage.GateError, "roots must be separate"):
                stage.fresh_application_inputs(value, self.candidate, self.tree, self.fresh_templates)

    def test_development_environment_registration_is_exclusive(self):
        for record in ({"environment": "development"}, {"environments": ["development"]},
                       {"environment": "development", "environments": ["development"]}):
            with self.subTest(record=record):
                self.assertTrue(stage.development_only(record))
        for record in ({}, {"environment": "production"}, {"environments": []},
                       {"environments": "development"}, {"environments": ["production"]},
                       {"environments": ["development", "production"]},
                       {"environment": "development", "environments": []},
                       {"environment": "production", "environments": ["development"]}):
            with self.subTest(record=record):
                self.assertFalse(stage.development_only(record))

    def test_browser_checks_listener_and_backend_development_scope(self):
        app, _, _ = self.fresh_fixture()
        route = "http://fixture.localhost:2345/"
        urls = dict.fromkeys(("health_url", "tenant_admin_url", "tenant_beta_url", "platform_url", "docs_url"), route)
        app["runtime"] = urls
        identity = {"observed_HEAD": app["head"], "manifest_sha256": app["manifest_sha256"],
                    "slug": "fixture-standalone", "edition": "standalone"}
        binding = {"service_manager": {"project_id": "p", "environment_id": "e", "service_id": "s"},
                   "allocation_status": "INSTALLED_DEVELOPMENT_RUNTIME_REGISTERED",
                   "application_root": app["root"], "application_identity": identity}
        listener = binding | {"stable_resource_id": "listener", "access_urls": [route], "port": 2345,
                              "backend_resource_id": "backend", "environment": "development"}
        backend = binding | {"stable_resource_id": "backend", "database_resource_id": app["resource_id"],
                             "environments": ["development"]}
        registry = self.root / "registered-routes.json"
        def write(front, back):
            registry.write_text(json.dumps({"resources": {"local_listeners": [front], "optional_services": [back]}}))
        with patch.object(stage, "REGISTRY_PATH", registry):
            write(listener, backend)
            self.assertEqual(set(stage.runtime_registration(app, compose=False)["routes"]), {"listener", "backend"})
            write(listener | {"environments": ["development", "production"]}, backend)
            with self.assertRaisesRegex(stage.NotRun, "development PSM route"):
                stage.runtime_registration(app, compose=False)
            write(listener, backend | {"environment": "production"})
            with self.assertRaisesRegex(stage.GateError, "backend application/environment"):
                stage.runtime_registration(app, compose=False)

    def test_fresh_plan_admits_only_the_selected_fresh_application(self):
        app, permanent, _ = self.fresh_fixture()
        inputs = self.input_value | {"applications": {"standalone": permanent}, "fresh_applications": {"standalone": app},
            "registry_sha256": "f" * 64, "template_artifacts": self.fresh_templates}
        args = argparse.Namespace(candidate=self.candidate, minimum_release=False, groups="standalone-fresh", through_group=None,
            run_id="fresh-plan", output_dir=str(self.root / "fresh-plan-output"), cache_dir=str(self.root / "fresh-plan-cache"),
            lease="native-lease", parent_lease=None, owner="owner", thread="thread")

        def admission(runtime, root, candidate):
            return {"root": root, "candidate": candidate, "resource_id": runtime["resource_id"], "lease": runtime["lease"]}

        with patch.object(stage, "lifecycle_inputs", return_value=inputs), \
             patch.object(stage, "validate_candidate", return_value=(self.candidate, self.tree)), \
             patch.object(stage, "runtime_admission", side_effect=admission), \
             patch.object(stage, "runtime_environment", return_value={}):
            selected = stage.plan(args, require_clean=True)
            self.assertEqual([value["root"] for value in selected["runtime_admissions"]], [permanent["root"], app["root"]])
            args.groups = "generated-application"
            other = stage.plan(args, require_clean=True)
            self.assertEqual([value["root"] for value in other["runtime_admissions"]], [permanent["root"]])
            args.output_dir = str(Path(app["root"]) / "fresh-plan-output")
            with self.assertRaisesRegex(stage.GateError, "must not overlap"):
                stage.plan(args, require_clean=True)

    def test_fresh_group_requires_original_manifest_and_all_permanent_evidence(self):
        app, permanent, _ = self.fresh_fixture()
        output = self.root / "fresh-output"
        (output / "groups").mkdir(parents=True)
        inputs = {"applications": {"standalone": permanent}, "fresh_applications": {"standalone": app},
                  "cli": self.cli_binding, "template_build_root": str(self.templates)}
        args = argparse.Namespace(candidate=self.candidate, run_id="synthetic", lease="synthetic", parent_lease=None)
        runner = stage.Runner(args, {"paths": {"output-dir": str(output), "cache-dir": str(self.root / "cache")},
                                    "lifecycle_inputs": inputs, "candidate_tree": self.tree,
                                    "groups": GROUPS, "template_artifacts": self.fresh_templates})
        missing = set()
        calls = []

        def evidence(reference, bound, kind, final_build=None):
            if bound is permanent and kind in missing:
                raise stage.NotRun("missing historical " + kind)
            return {"path": reference.get("path", "historical"), "sha256": reference.get("sha256", "a" * 64), "completed_at": "2026-01-01T00:00:00Z"}

        def execute(arguments, **kwargs):
            calls.append((arguments, kwargs))
            if arguments[0] == "node":
                self.assertEqual(arguments[2:4], ["upgrade", "--check"])
                result = {"status": "up_to_date"}
            elif arguments[1] == "-r":
                self.assertIn("ApplicationReleaseVersions::load", arguments[2])
                result = self.installation_release
            else:
                self.assertEqual(arguments, ["php", "server/database/install.php", "--status"])
                result = {"state": "installed", "health": {"installed_table_count": 1}, "preflight": {"status": "ready"}}
            return subprocess.CompletedProcess(arguments, 0, json.dumps(result), "")

        with patch.object(stage, "native_evidence", side_effect=evidence), \
             patch.object(stage, "command", side_effect=execute), patch.object(runner, "environment", return_value={}):
            result = runner.fresh("standalone")
            self.assertEqual(result["status"], "passed")
            self.assertFalse(result["reinstalled"])
            self.assertEqual(result["candidate_first_install"]["installation"]["official_modules"], ["peanut.admin"])
            receipt = Path(app["first_install"]["path"])
            original = json.loads(receipt.read_text())
            for patch_value in ({"source": {"kind": "product-source"}}, {"application_manifest_sha256": "0" * 64},
                                {"source": {"kind": "generated-application", "application_manifest_sha256": "0" * 64}},
                                {"release": self.installation_release | {"scaffold_template": "old"}}):
                with self.subTest(receipt=patch_value):
                    receipt.write_text(json.dumps(original | patch_value)); app["first_install"]["sha256"] = stage.sha256(receipt)
                    with self.assertRaises(stage.GateError): runner.fresh("standalone")
            receipt.write_text(json.dumps(original)); app["first_install"]["sha256"] = stage.sha256(receipt)
            missing.add("recovery")
            result = runner.fresh("standalone")
            self.assertEqual(result["status"], "not_run")
            self.assertEqual(result["components"]["candidate_first_install"]["status"], "passed")
            missing.clear(); inputs["fresh_applications"] = {}; calls.clear()
            self.assertEqual(runner.fresh("standalone")["status"], "not_run")
            self.assertFalse(any(call[1].get("cwd") == Path(app["root"]) for call in calls))

    def test_module_runtime_governance_consumes_native_terminal_marker_in_lifecycle_app(self):
        self.assertIn("server/tests/Productization/ModuleRuntimeGovernanceTest.php", stage.TOOL_FILES)
        config = {"reviewed_module_source": {"root": "synthetic-reviewed"}}
        for key in ("lifecycle_application", "failure_recovery_application", "installed_application"):
            root = self.root / key
            root.mkdir()
            config[key] = {"root": str(root), "resource_id": key, "database_identity": {"server_uuid": "synthetic-uuid", "database": key}}
        marker = "MODULE-RUNTIME-GOVERNANCE-D2-001 passed sha256=" + "a" * 64 + " plan_digest=" + "b" * 64 + "\n"
        for index, mode in enumerate(("passed", "nonzero", "missing-marker", "marker-not-final")):
            with self.subTest(mode=mode):
                output = self.root / ("runtime-output-" + str(index))
                (output / "groups").mkdir(parents=True)
                (output / "plan.json").write_text("{}\n")
                args = argparse.Namespace(candidate=self.candidate, run_id="synthetic", lease="synthetic", parent_lease=None)
                runner = stage.Runner(args, {"paths": {"output-dir": str(output), "cache-dir": str(self.root / "cache")},
                    "candidate_tree": self.tree, "groups": GROUPS, "template_artifacts": self.inputs,
                    "lifecycle_inputs": {"applications": {}, "source": {"commit": self.candidate, "tree": self.tree}}})
                events = []

                def execute(arguments, **kwargs):
                    filename = Path(arguments[1]).name
                    events.append(filename)
                    if filename == "ModuleRuntimeGovernanceTest.php":
                        self.assertEqual(kwargs["cwd"], Path(config["lifecycle_application"]["root"]))
                        self.assertIn("--application-root=" + config["lifecycle_application"]["root"], arguments)
                        self.assertIn("--expected-server-uuid=synthetic-uuid", arguments)
                        self.assertEqual(kwargs["env"], {"native": "environment"})
                        kwargs["log"].write_text(marker if mode in ("passed", "nonzero") else (marker + "unexpected trailing output\n" if mode == "marker-not-final" else "no completion\n"))
                        if mode == "nonzero": raise stage.GateError("native nonzero")
                    elif filename in ("ModuleLifecycleMySqlTest.php", "ModuleFailureRecoveryMySqlTest.php"):
                        key = "lifecycle_application" if filename == "ModuleLifecycleMySqlTest.php" else "failure_recovery_application"
                        root = Path(config[key]["root"]) / ".local/module-lifecycle-mysql"
                        root.mkdir(parents=True, exist_ok=True)
                        name = "result.json" if key == "lifecycle_application" else "failure-recovery-result.json"
                        (root / name).write_text(json.dumps({"status": "passed", "assertions": 1,
                            "database_identity": config[key]["database_identity"], "non_case_catalog_sha256": "c" * 64}))
                        kwargs["log"].write_text("synthetic native log\n")
                    else:
                        producer = next(value.split("=", 1)[1] for value in arguments if value.startswith("--action="))
                        installed = config["installed_application"]
                        result = {"status": "passed", "protocol": "peanut.module-qualification-result.v1", "producer": producer,
                            "source": runner.inputs["source"], "input_sha256": stage.sha256(output / "groups/module-lifecycle/input.json"),
                            "plan_sha256": stage.sha256(output / "plan.json"), "application_root": installed["root"],
                            "resource_id": installed["resource_id"], "database_identity": installed["database_identity"],
                            "native_identity": "verified-current-session", "writes_retained": True}
                        return subprocess.CompletedProcess(arguments, 0, json.dumps(result), "")
                    return subprocess.CompletedProcess(arguments, 0, "", "")

                with patch.object(stage, "module_qualification_inputs", return_value=config), \
                     patch.object(stage, "verify_module_context", side_effect=lambda _: events.append("verify")), \
                     patch.object(stage, "command", side_effect=execute), patch.object(runner, "environment", return_value={"native": "environment"}):
                    if mode == "passed":
                        result = runner.module_lifecycle()
                        native = result["producers"]["module-runtime-governance"]
                        self.assertEqual(native["database_identity"], config["lifecycle_application"]["database_identity"])
                        self.assertEqual((native["archive_sha256"], native["plan_digest"]), ("a" * 64, "b" * 64))
                        self.assertNotIn("receipt", native)
                        position = events.index("ModuleRuntimeGovernanceTest.php")
                        self.assertEqual(events[position - 1:position + 2], ["verify", "ModuleRuntimeGovernanceTest.php", "verify"])
                        self.assertLess(position, events.index("ModuleFailureRecoveryMySqlTest.php"))
                    else:
                        with self.assertRaises(stage.GateError): runner.module_lifecycle()
                        self.assertNotIn("ModuleFailureRecoveryMySqlTest.php", events)

    def test_installed_module_plan_is_closed_partial_without_unrelated_applications(self):
        installed = self.root / "installed-module-app"
        reviewed = self.root / "reviewed-module-source"
        installed.mkdir(); reviewed.mkdir()
        config = {"reviewed_module_source": {"root": str(reviewed)}, "installed_application": {
            "root": str(installed), "head": self.candidate, "resource_id": "installed-resource", "lease": "installed-lease"}}
        inputs = {"applications": {}, "template_artifacts": {}, "module_qualification": config, "registry_sha256": "a" * 64}
        args = argparse.Namespace(candidate=self.candidate, minimum_release=False, groups="module-lifecycle", through_group=None,
            module_installed_only=True, run_id="installed", output_dir=str(self.root / "installed-output"),
            cache_dir=str(self.root / "installed-cache"), lease="native-lease", parent_lease=None, owner="owner", thread="thread")
        source_input = {"protocol": "peanut.p0e-lifecycle-inputs.v1", "source": {"commit": self.candidate, "tree": self.tree},
                        "module_qualification": config}
        self.input_path.write_text(json.dumps(source_input))
        args.lifecycle_input, args.lifecycle_input_sha256 = str(self.input_path), stage.sha256(self.input_path)
        registry = self.root / "registry.json"
        registry.write_text(json.dumps({"authority": {"role": "maintainer"}}))
        with patch.dict("os.environ", {"PEANUT_RESOURCE_REGISTRY": str(registry)}), \
             patch.object(stage, "REGISTRY_PATH", registry), patch.object(stage, "command"), \
             patch.object(stage, "candidate_inputs", side_effect=AssertionError("unrelated CLI/templates must not be required")), \
             patch.object(stage, "module_qualification_inputs", return_value=config) as modules:
            parsed = stage.lifecycle_inputs(args, self.tree)
            self.assertEqual(parsed["applications"], {})
            self.assertEqual(parsed["template_artifacts"], {})
            self.assertTrue(modules.call_args.kwargs["installed_only"])
        proof = self.root / "invalid-full-plan.json"
        proof.write_text(json.dumps({"groups": GROUPS, "module_installed_only": True,
                                     "selection_scope": "module-installed-components"}))
        with self.assertRaisesRegex(stage.GateError, "closed partial selection"):
            stage.verify_module_context(argparse.Namespace(plan=str(proof), plan_sha256=stage.sha256(proof)))
        with patch.object(stage, "lifecycle_inputs", return_value=inputs), \
             patch.object(stage, "validate_candidate", return_value=(self.candidate, self.tree)), \
             patch.object(stage, "module_qualification_inputs", return_value=config) as modules, \
             patch.object(stage, "runtime_admission", side_effect=lambda app, root, candidate: {
                 "root": root, "candidate": candidate, "resource_id": app["resource_id"], "lease": app["lease"]}), \
             patch.object(stage, "runtime_environment", return_value={}):
            result = stage.plan(args, require_clean=True)
            self.assertTrue(result["module_installed_only"])
            self.assertEqual(result["selection_scope"], "module-installed-components")
            self.assertEqual(result["groups"], ["module-lifecycle"])
            self.assertEqual(result["full_gate_groups"], GROUPS)
            self.assertEqual([value["root"] for value in result["runtime_admissions"]], [str(installed)])
            self.assertTrue(modules.call_args.kwargs["installed_only"])
            for groups, through in [(None, None), ("generated-application", None), ("module-lifecycle", "module-lifecycle")]:
                args.groups, args.through_group = groups, through
                with self.assertRaisesRegex(stage.GateError, "requires exactly"):
                    stage.plan(args, require_clean=True)

    def test_module_guard_uses_pinned_registry_when_app_environment_is_empty(self):
        registry = self.root / "maintainer-registry.json"
        registry.write_text('{"authority":{"role":"maintainer"}}\n')
        module_input = self.root / "native-module-input.json"
        module_input.write_text("{}\n")
        proof = {"groups": ["module-lifecycle"], "module_installed_only": True,
            "selection_scope": "module-installed-components", "worktree": str(self.source),
            "candidate": self.candidate, "candidate_tree": self.tree, "run_id": "installed", "lease": "native",
            "owner": "owner", "thread": "thread", "parent_lease": None,
            "registry_path": str(registry), "registry_sha256": stage.sha256(registry),
            "lifecycle_inputs": {"input_path": str(module_input), "input_sha256": stage.sha256(module_input)}}
        path = self.root / "native-plan.json"
        path.write_text(json.dumps(proof))
        args = argparse.Namespace(plan=str(path), plan_sha256=stage.sha256(path))

        def native_lease(*_):
            self.assertEqual(stage.REGISTRY_PATH, registry)
            self.assertEqual(os.environ['PEANUT_RESOURCE_REGISTRY'], str(registry))

        with patch.dict("os.environ", {"PEANUT_RESOURCE_REGISTRY": ""}), \
             patch.object(stage, "REGISTRY_PATH", self.source / "resources/project-resources.json"), \
             patch.object(stage, "TOOL_ROOT", self.source), patch.object(stage, "validate_candidate"), \
             patch.object(stage, "module_qualification_inputs") as modules, patch.object(stage, "verify_lease", side_effect=native_lease):
            self.assertEqual(stage.verify_module_context(args)["status"], "verified")
            self.assertTrue(modules.call_args.kwargs["installed_only"])
            registry.write_text("changed\n")
            with self.assertRaisesRegex(stage.GateError, "fixed native file changed"):
                stage.verify_module_context(args)

    def test_installed_module_selection_runs_original_actions_without_full_group_pass(self):
        output = self.root / "installed-components"
        (output / "groups").mkdir(parents=True)
        (output / "plan.json").write_text("{}\n")
        app = {"root": str(self.root / "native-installed"), "resource_id": "native-installed-resource",
               "database_identity": {"database": "synthetic", "server_uuid": "synthetic-uuid"}}
        config = {"reviewed_module_source": {"root": "synthetic-reviewed"}, "installed_application": app}
        args = argparse.Namespace(candidate=self.candidate, run_id="installed", lease="native", parent_lease=None)
        value = {"paths": {"output-dir": str(output), "cache-dir": str(self.root / "cache")},
            "candidate_tree": self.tree, "groups": ["module-lifecycle"], "template_artifacts": {}, "module_installed_only": True,
            "selection_scope": "module-installed-components", "lifecycle_inputs": {"applications": {}, "input_sha256": "a" * 64,
            "source": {"commit": self.candidate, "tree": self.tree}}}
        runner = stage.Runner(args, value)
        called = []

        def execute(arguments, **kwargs):
            self.assertEqual(Path(arguments[1]).name, "run.php", "must not replay Lifecycle, RuntimeGovernance or FailureRecovery")
            producer = next(arg.split("=", 1)[1] for arg in arguments if arg.startswith("--action="))
            called.append(producer)
            result = {"status": "passed", "protocol": "peanut.module-qualification-result.v1", "producer": producer,
                "source": runner.inputs["source"], "input_sha256": stage.sha256(output / "groups/module-lifecycle/input.json"),
                "plan_sha256": stage.sha256(output / "plan.json"), "application_root": app["root"], "resource_id": app["resource_id"],
                "database_identity": app["database_identity"], "native_identity": "verified-current-session", "writes_retained": True}
            return subprocess.CompletedProcess(arguments, 0, json.dumps(result), "")

        with patch.object(stage, "module_qualification_inputs", return_value=config) as modules, \
             patch.object(stage, "verify_module_context"), patch.object(stage, "verify_lease"), \
             patch.object(stage, "command", side_effect=execute), patch.object(runner, "assert_inputs"), \
             patch.object(runner, "environment", return_value={}):
            with self.assertRaises(stage.NotRun): runner.run()
            self.assertEqual(called, ["member-upload", "task-import-export"])
            self.assertTrue(modules.call_args.kwargs["installed_only"])
            result = json.loads((output / "groups/module-lifecycle.json").read_text())
            self.assertEqual(result["status"], "not_run")
            self.assertEqual(result["scope"], "module-installed-components")
            self.assertEqual(set(result["producers"]), set(called))
            self.assertTrue(all(item["status"] == "passed" for item in result["producers"].values()))

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
        self.assertIn('transport_name=scaffold-template-qualification', workflow)
        self.assertIn('sha256sum "$transport_name.tgz"', workflow)
        self.assertIn('scripts/p0e-runtime-qualification candidate', workflow)
        self.assertIn('repository: peanut-business/peanut-cli', workflow)
        self.assertIn('if: ${{ always() }}', workflow)
        self.assertIn("always() && steps.preserve_candidate.outputs.available == 'true'", workflow)
        self.assertIn('for name in scaffold-templates scaffold-rebuild rebuild-comparison.json producer-tools.json producer-tools-actual.json template-qualification candidate-inputs.json', workflow)
        self.assertNotIn('continue-on-error:', workflow)


class FixedRuntimeContractTest(unittest.TestCase):
    """Exercise the runtime controller with private temporary receipts and no live resources."""

    def setUp(self):
        temp = tempfile.TemporaryDirectory(prefix="p0e-runtime-contract-")
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name).resolve()
        self.args = argparse.Namespace(candidate="a" * 40, owner="controller", thread="controller",
            run_id="review", lease="review-lease", parent_lease=None,
            lifecycle_input=str(self.root / "inputs.json"), lifecycle_input_sha256="c" * 64)
        self.apps = {edition: {"root": str(self.root / edition)} for edition in stage.EDITIONS}
        self.inputs = {"applications": self.apps, "input_sha256": self.args.lifecycle_input_sha256,
            "consumer_identity_seed": {"path": str(self.root / "existing-identity.env")}}
        self.plan = {"candidate_tree": "b" * 40, "template_artifacts": {"standalone": "st", "multi-tenant": "mt"},
            "paths": {"output-dir": str(self.root / "output"), "cache-dir": str(self.root / "cache")},
            "lifecycle_inputs": self.inputs, "groups": GROUPS, "full_gate_groups": GROUPS,
            "selection_scope": "full", "runtime_admissions": [{"lease": "external-app"}]}
        self.runner = stage.Runner(self.args, self.plan)

    def test_recovery_without_original_in_place_verifier_is_not_qualified(self):
        receipt = self.root / "database-recovery.json"
        receipt.write_text(json.dumps({"status": "completed"}))
        ref = {"path": str(receipt), "sha256": stage.sha256(receipt)}
        with patch.object(stage, "TOOL_ROOT", self.root), patch.object(stage.subprocess, "run", return_value=subprocess.CompletedProcess([], 1)), \
             patch.object(stage.urllib.request, "urlopen", side_effect=OSError("offline")), self.assertRaisesRegex(stage.NotRun, "in-place paired"):
            stage.native_evidence(ref, {"root": str(self.root)}, "recovery")
        self.assertEqual(stage.sha256(receipt), ref["sha256"])

    def test_consumer_cli_binds_both_permanent_apps_and_all_result_gates(self):
        parse = runpy.run_path(str(stage.CONSUMER_MODULE_CHAIN))["parse_arguments"]
        gates = ("check_v1_v2", "canonical_pack_v1_v2", "install_update", "tenant_package_rbac_separation",
                 "disable_reactivate", "retire_purge", "app_owned_preservation")
        receipt = {"status": "passed", "candidate": self.args.candidate, "candidate_tree": self.plan["candidate_tree"],
            "template_artifacts": self.plan["template_artifacts"], "lifecycle_input_sha256": self.args.lifecycle_input_sha256,
            "gates": dict.fromkeys(gates, "passed")}

        def consumer(argv, **kwargs):
            with patch.object(sys, "argv", argv):
                args = parse()
            self.assertEqual(args.author_app, self.apps["standalone"]["root"])
            self.assertEqual(args.consumer_app, self.apps["multi-tenant"]["root"])
            self.assertEqual(args.qualification_plan, str(self.runner.output / "plan.json"))
            self.assertEqual(args.output, str(self.runner.cache / "consumer-module-lifecycle"))
            self.assertEqual(args.identity_seed_file, self.inputs["consumer_identity_seed"]["path"])
            self.assertEqual((args.candidate, args.lease, args.lifecycle_input, args.lifecycle_input_sha256),
                             (self.args.candidate, self.args.lease, self.args.lifecycle_input, self.args.lifecycle_input_sha256))
            return subprocess.CompletedProcess(argv, 0, json.dumps(receipt), "")

        with patch.object(stage, "command", side_effect=consumer):
            self.assertEqual(self.runner.consumer_module_lifecycle(), receipt)
            for field in ("status", "candidate", "candidate_tree", "template_artifacts", "lifecycle_input_sha256"):
                with self.subTest(field=field), patch.dict(receipt, {field: "foreign"}), self.assertRaises(stage.GateError):
                    self.runner.consumer_module_lifecycle()
            for gate in gates:
                with self.subTest(gate=gate), patch.dict(receipt["gates"], {gate: "not_run"}), self.assertRaises(stage.GateError):
                    self.runner.consumer_module_lifecycle()

    def test_failed_group_preserves_original_receipts_and_rejects_replay(self):
        self.runner.initialize()
        group = "consumer-module-lifecycle"
        with patch.object(self.runner, "assert_inputs"), patch.object(stage, "verify_lease"), \
             patch.object(stage, "command") as command:
            with self.assertRaisesRegex(stage.GateError, "original failure") as failure:
                self.runner.run_group(group, lambda: stage.fail("original failure"))
            self.runner.preserve_failure(failure.exception)
            before = self.runner.checkpoint_path.read_bytes()
            summary = stage.read_json(self.runner.summary_path)
            self.assertEqual(summary["status"], "failed-preserved")
            self.assertFalse(summary["cleanup"]["lease_released"])
            self.assertEqual(summary["groups"][group]["error"], "original failure")
            self.runner.resume()
            with self.assertRaisesRegex(stage.GateError, "reconcile its original"):
                self.runner.run_group(group, lambda: self.fail("replayed failed native operation"))
            self.assertEqual(self.runner.checkpoint_path.read_bytes(), before)
            self.runner.plan = self.plan | {"candidate_tree": "d" * 40}
            with self.assertRaisesRegex(stage.GateError, "original lifecycle plan"):
                self.runner.resume()
            command.assert_not_called()

    def test_cleanup_preserves_evidence_and_resumes_after_release_refusal(self):
        group = "consumer-module-lifecycle"
        self.plan.update(groups=[group], selection_scope="selected-groups")
        runner = stage.Runner(self.args, self.plan)
        runner.initialize()
        evidence = runner.cache / group
        evidence.mkdir()
        (evidence / "retire-plan.json").write_bytes(b"original evidence")
        extra = runner.cache / "unknown"
        extra.write_bytes(b"must remain")
        with patch.object(stage.Runner, "assert_inputs"), patch.object(stage, "verify_lease") as verify, \
             patch.object(stage, "command") as release, patch.object(stage.Runner, "consumer_module_lifecycle", return_value={}) as business:
            verify.side_effect = stage.GateError("wrong native owner")
            with self.assertRaisesRegex(stage.GateError, "wrong native owner"):
                runner.cleanup_success()
            self.assertTrue(evidence.is_dir())
            release.assert_not_called()
            verify.side_effect = None
            with self.assertRaisesRegex(stage.GateError, "unknown temporary objects"):
                runner.cleanup_success()
            self.assertEqual(extra.read_bytes(), b"must remain")
            self.assertTrue(evidence.is_dir())
            self.assertFalse(runner.summary_path.exists())
            release.assert_not_called()
            extra.unlink()

            release.side_effect = stage.GateError("native release refused")
            with self.assertRaisesRegex(stage.GateError, "native release refused") as refused:
                runner.run()
            runner.preserve_failure(refused.exception)
            checkpoint = stage.read_json(runner.checkpoint_path)
            self.assertEqual(checkpoint["groups"][group]["status"], "passed")
            self.assertEqual(checkpoint["closeout_failure"]["error"], "native release refused")
            self.assertEqual(stage.read_json(runner.summary_path)["status"], "failed-preserved")
            self.assertTrue(runner.cache.is_dir())
            saved = runner.output / group / "retire-plan.json"
            self.assertEqual(saved.read_bytes(), b"original evidence")

            resumed = stage.Runner(self.args, self.plan)
            resumed.resume()
            release.side_effect = None
            resumed.run()
            business.assert_called_once()
            release_args = [str(stage.LEASE_TOOL), "release", "--lease", self.args.lease, "--owner", self.args.owner]
            self.assertEqual([c.args[0] for c in release.call_args_list], [release_args, release_args])
            summary = stage.read_json(resumed.summary_path)
            self.assertEqual(summary["status"], "partial-passed")
            self.assertEqual(summary["cleanup"]["external_runtime_leases_retained"], ["external-app"])
            self.assertEqual(stage.read_json(resumed.checkpoint_path)["closeout_failure"], checkpoint["closeout_failure"])
            self.assertEqual(saved.read_bytes(), b"original evidence")
            self.assertFalse(resumed.cache.exists())

    def test_run_requires_every_planned_group_to_pass(self):
        calls = []

        def observe(group, operation):
            calls.append(group)
            self.runner.checkpoint["groups"][group] = {"status": "not_run" if group == "standalone-browser" else "passed"}

        with patch.object(self.runner, "run_group", side_effect=observe), patch.object(self.runner, "cleanup_success") as close:
            with self.assertRaisesRegex(stage.NotRun, "closure is incomplete"):
                self.runner.run()
            self.assertEqual(calls, GROUPS)
            close.assert_not_called()


if __name__ == "__main__":
    unittest.main()
