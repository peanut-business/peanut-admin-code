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
from unittest.mock import Mock, patch

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



class GeneratedRecoveryContractTest(unittest.TestCase):
    """Synthetic private files only. No APP key, service or database is touched."""

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="peanut-generated-recovery-contract-")
        self.root = Path(self.temporary.name).resolve()
        self.addCleanup(self.temporary.cleanup)
        self.workspace = self.root / "evidence"
        self.workspace.mkdir(mode=0o700)
        self.key = b"synthetic-contract-key-32-bytes!!!"

    def private_json(self, path, value):
        stage.recovery_json(path, value)
        return path

    def test_instance_signature_rejects_key_and_content_changes(self):
        stage.recovery_signed(self.workspace, "backup.json", {"kind": "backup"}, self.key)
        self.assertEqual(stage.recovery_check_signature(self.workspace, "backup.json", self.key), {"kind": "backup"})
        with self.assertRaisesRegex(RuntimeError, "signature differs"):
            stage.recovery_check_signature(self.workspace, "backup.json", b"another-instance-key")
        (self.workspace / "backup.json").write_text('{"kind":"completed"}')
        with self.assertRaisesRegex(RuntimeError, "signature differs"):
            stage.recovery_check_signature(self.workspace, "backup.json", self.key)

    def test_signature_domain_rejects_renamed_completed_envelope(self):
        stage.recovery_signed(self.workspace, "backup.json", {"status": "completed"}, self.key)
        (self.workspace / "backup.json").rename(self.workspace / "generated-application-recovery.json")
        (self.workspace / "backup.json.signature.json").rename(self.workspace / "generated-application-recovery.json.signature.json")
        with self.assertRaisesRegex(RuntimeError, "signature differs"):
            stage.recovery_check_signature(self.workspace, "generated-application-recovery.json", self.key)

    def test_private_file_rejects_public_mode_hardlink_and_symlink(self):
        path = self.workspace / "private.json"
        self.private_json(path, {})
        stage.recovery_private(path)
        path.chmod(0o644)
        with self.assertRaisesRegex(RuntimeError, "private regular"):
            stage.recovery_private(path)
        path.chmod(0o600)
        link = self.workspace / "hardlink"
        os.link(path, link)
        with self.assertRaisesRegex(RuntimeError, "private regular"):
            stage.recovery_private(path)
        link.unlink()
        link.symlink_to(path)
        with self.assertRaisesRegex(RuntimeError, "private regular"):
            stage.recovery_private(link)

    def test_namespace_rejects_escape_and_noncanonical_names(self):
        for name in ("../other", "a..b", "/absolute", "Upper", "", "a" * 65):
            with self.subTest(name=name), self.assertRaisesRegex(RuntimeError, "namespace"):
                stage.recovery_key(self.root, name)
        self.assertEqual(stage.recovery_key(self.root, "run-1").parent.name, "run-1")

    def test_unknown_or_completed_execution_is_never_replayed(self):
        plan = {"protocol": stage.GENERATED_RECOVERY_PROTOCOL, "kind": "plan", "workspace": str(self.workspace)}
        path = self.private_json(self.workspace / "plan.json", plan)
        for status in ("UNKNOWN", "completed", "restore-started"):
            self.private_json(self.workspace / "execution.json", {"status": status, "pid": 123})
            with patch.object(stage, "recovery_identity") as identity:
                with self.assertRaisesRegex(RuntimeError, "never automatically replayed"):
                    stage.generated_recovery_execute(argparse.Namespace(plan=str(path), plan_sha256=stage.sha256(path)))
                identity.assert_not_called()
            self.assertEqual(stage.read_json(self.workspace / "execution.json")["pid"], 123)

    def test_unsigned_operator_receipt_is_rejected_before_native_observation(self):
        path = self.private_json(self.workspace / "generated-application-recovery.json",
                                 {"protocol": stage.GENERATED_RECOVERY_PROTOCOL, "status": "completed"})
        self.private_json(self.workspace / "plan.json", {"run_id": "test"})
        with patch.object(stage, "recovery_probe") as observe:
            with self.assertRaisesRegex(RuntimeError, "private regular"):
                stage.generated_recovery_verify(path, {"root": str(self.root)})
            observe.assert_not_called()

    def test_foreign_instance_is_rejected_before_native_observation(self):
        path = self.workspace / "generated-application-recovery.json"
        key = stage.recovery_key(self.root, "test")
        key.parent.mkdir(parents=True)
        key.write_bytes(b"k" * 32)
        key.chmod(0o600)
        self.private_json(self.workspace / "plan.json", {"run_id": "test", "identity": {"root": "/foreign"}})
        completed = {"kind": "completed", "status": "completed", "protocol": stage.GENERATED_RECOVERY_PROTOCOL,
                     "restore_scope": "same-instance-same-schema-native-mysql", "identity": {"root": "/foreign"},
                     "plan_sha256": stage.sha256(self.workspace / "plan.json")}
        backup = {"identity": completed["identity"], "plan_sha256": completed["plan_sha256"]}
        stage.recovery_signed(self.workspace, "backup.json", backup, b"k" * 32)
        completed["backup_sha256"] = stage.sha256(self.workspace / "backup.json")
        stage.recovery_signed(self.workspace, path.name, completed, b"k" * 32)
        with patch.object(stage, "recovery_probe") as observe:
            with self.assertRaisesRegex(RuntimeError, "another source, instance"):
                stage.generated_recovery_verify(path, {"root": str(self.root)})
            observe.assert_not_called()

    def test_ordinary_restore_transports_password_only_on_stdin(self):
        identity = {"client": {"ssh_command": "/usr/bin/ssh", "ssh_alias": "registered",
                    "docker_command": "/usr/bin/docker", "container_name": "registered-mysql",
                    "mysql_command": "/usr/bin/mysql"}}
        argv = stage.recovery_client(identity, "mysql_command",
                                     ["--user=app", "--database=exact_schema"], root=False)
        self.assertIn("IFS= read -r MYSQL_PWD", argv[-1])
        self.assertNotIn("MYSQL_ROOT_PASSWORD", argv[-1])
        self.assertIn("--database=exact_schema", argv[-1])
        self.assertNotIn("--force", argv[-1])

    def test_native_inventory_rejects_escape_and_link(self):
        native, _ = stage.paired_recovery_verifier()
        path = self.root / "link"
        path.symlink_to(self.workspace)
        with patch.object(stage, "paired_recovery_verifier", return_value=(native, "pinned")):
            with self.assertRaisesRegex(RuntimeError, "escapes"):
                stage.recovery_inventory(self.root, ["../outside"], ())
            with self.assertRaises(ValueError):
                stage.recovery_inventory(self.root, [], ("link",))

    def test_sql_attachment_rejects_wrong_schema_and_tampering(self):
        native, helper = stage.paired_recovery_verifier()
        dump = self.workspace / "database.sql"
        dump.write_text("DROP DATABASE IF EXISTS " + chr(96) + "other" + chr(96) + ";\nCREATE DATABASE " +
                        chr(96) + "other" + chr(96) + ";\nUSE " + chr(96) + "other" + chr(96) + ";\n")
        dump.chmod(0o600)
        backup = {"identity": {"database": "exact_schema"}, "database_sha256": stage.sha256(dump),
                  "protected": {"files": {}, "storage": {}}, "storage_archives": {}, "pure_helper_sha256": helper}
        bundle, archive = self.workspace / "source.bundle", self.workspace / "source-and-git.tgz"
        bundle.write_bytes(b"synthetic bundle")
        bundle.chmod(0o600)
        archive.write_bytes(b"synthetic archive")
        archive.chmod(0o600)
        backup["identity"]["root"] = str(self.root)
        backup["source_backup"] = {"dependency_exclusions": list(stage.RECOVERY_DEPENDENCY_EXCLUSIONS),
                                  "bundle_sha256": stage.sha256(bundle), "archive_sha256": stage.sha256(archive),
                                  "inventory": {}}
        with patch.object(stage, "paired_recovery_verifier", return_value=(native, helper)), patch.object(stage, "command"), patch.object(stage, "recovery_source_archive", return_value={}):
            with self.assertRaises(ValueError):
                stage.recovery_backup_attachments(self.workspace, backup)
            dump.write_text("tampered")
            with self.assertRaisesRegex(RuntimeError, "SQL attachment changed"):
                stage.recovery_backup_attachments(self.workspace, backup)



    def test_complete_source_archive_includes_git_and_detects_tampering(self):
        source = self.root / "source"
        source.mkdir()
        (source / "kept.txt").write_text("original source")
        (source / "relative-link").symlink_to("kept.txt")
        (source / "web/node_modules").mkdir(parents=True)
        (source / "web/node_modules/rebuildable").write_text("excluded dependency")
        (source / "web/pnpm-lock.yaml").write_text("original lock")
        subprocess.run(["git", "init", "-q", str(source)], check=True)
        subprocess.run(["git", "-C", str(source), "add", "."], check=True)
        subprocess.run(["git", "-C", str(source), "-c", "user.name=Contract",
                        "-c", "user.email=contract@example.invalid", "commit", "-qm", "synthetic"], check=True)
        snapshot = stage.recovery_source_backup(source, self.workspace)
        self.assertIn(".git/HEAD", snapshot["inventory"])
        self.assertIn("web/pnpm-lock.yaml", snapshot["inventory"])
        self.assertNotIn("web/node_modules", snapshot["inventory"])
        self.assertEqual(snapshot["dependency_exclusions"], list(stage.RECOVERY_DEPENDENCY_EXCLUSIONS))
        self.assertEqual(snapshot["inventory"]["relative-link"][0], "symlink")
        self.assertEqual(stage.recovery_source_archive(self.workspace / "source-and-git.tgz"), snapshot["inventory"])
        (source / "kept.txt").write_text("new source")
        self.assertNotEqual(stage.recovery_source_inventory(source), snapshot["inventory"])

    def test_current_destination_link_is_rejected_before_copy(self):
        native, helper = stage.paired_recovery_verifier()
        destination = self.root / "config.env"
        external = self.root / "outside.env"
        external.write_text("unchanged")
        destination.symlink_to(external)
        entry = {"sha256": stage.sha256(external), "mode": 0o600,
                 "uid": os.geteuid(), "gid": os.getegid()}
        with patch.object(stage, "paired_recovery_verifier", return_value=(native, helper)):
            with self.assertRaises(ValueError):
                stage.recovery_preserve_files(self.root,
                                             {"protected": {"files": {"config.env": entry}, "storage": {}}})
        self.assertEqual(external.read_text(), "unchanged")

    def execution_fixture(self, popen):
        app_root = self.root / "app"
        app_root.mkdir()
        app = {"root": str(app_root), "head": "synthetic", "database": "exact_schema"}
        identity = {"owner": "self", "thread": "self", "root": str(app_root), "database": "exact_schema"}
        plan = {"protocol": stage.GENERATED_RECOVERY_PROTOCOL, "kind": "plan", "run_id": "test",
                "workspace": str(self.workspace), "application": app, "identity": identity,
                "files": [], "storage_names": [], "protected_preimage": {"files": {}, "storage": {}}, "database_preimage": {}, "schema_effects": {}}
        path = self.private_json(self.workspace / "plan.json", plan)
        (self.workspace / "runtime-proof.txt").write_text("synthetic scope")
        self.workspace.chmod(0o700)
        import contextlib
        with contextlib.ExitStack() as stack:
            for name, result in (
                ("recovery_identity", (identity, {})), ("recovery_paths", ([], ())),
                ("recovery_inventory", {"files": {}, "storage": {}}), ("recovery_source_backup", {"inventory": {}}),
                ("recovery_source_inventory", {}), ("recovery_no_writers", {}), ("recovery_effects", {}),
                ("paired_recovery_verifier", ({"validate_dump": lambda *args: None}, "pinned")),
                ("recovery_backup_attachments", None), ("runtime_admission", {}),
                ("verify_runtime_admission", None), ("recovery_client", ["synthetic-native-client"])):
                stack.enter_context(patch.object(stage, name, return_value=result))
            stack.enter_context(patch.object(stage, "recovery_probe",
                side_effect=lambda *args, **kwargs: {"credentials": {"user": "app", "password": "private"}}
                if kwargs.get("credentials") else {}))
            stack.enter_context(patch.object(stage.subprocess, "run", return_value=Mock(returncode=0)))
            stack.enter_context(patch.object(stage.subprocess, "Popen", side_effect=popen))
            with self.assertRaises((RuntimeError, OSError, subprocess.TimeoutExpired)):
                stage.generated_recovery_execute(argparse.Namespace(plan=str(path), plan_sha256=stage.sha256(path)))
        return stage.read_json(self.workspace / "execution.json")

    def test_restore_intent_precedes_dispatch_and_nonzero_terminal_is_preserved(self):
        def dispatch(*args, **kwargs):
            intent = stage.read_json(self.workspace / "execution.json")
            self.assertEqual(intent["status"], "RESTORING")
            self.assertEqual(intent["stage"], "dispatch-intent")
            return Mock(pid=123, wait=Mock(return_value=7))
        journal = self.execution_fixture(dispatch)
        self.assertEqual(journal["status"], "failed")
        self.assertEqual(journal["terminal"]["exit_code"], 7)
        self.assertEqual(journal["terminal"]["pid"], 123)
        self.assertIn("stdout_sha256", journal["terminal"])

    def test_restore_timeout_retains_pid_unknown_and_never_invents_terminal(self):
        journal = self.execution_fixture(lambda *a, **k: Mock(pid=456,
                    wait=Mock(side_effect=subprocess.TimeoutExpired("synthetic", 300))))
        self.assertEqual(journal["status"], "UNKNOWN")
        self.assertEqual(journal["pid"], 456)
        self.assertNotIn("terminal", journal)

    def test_known_native_dispatch_failure_is_failed_without_a_pid(self):
        def not_started(*args, **kwargs):
            raise OSError("synthetic client could not start")
        journal = self.execution_fixture(not_started)
        self.assertEqual(journal["status"], "failed")
        self.assertNotIn("pid", journal)


    def test_complete_source_inventory_rejects_unreadable_subtree(self):
        def unreadable(*args, **kwargs):
            kwargs["onerror"](PermissionError("synthetic unreadable source subtree"))
        with patch.object(stage.os, "walk", side_effect=unreadable):
            with self.assertRaisesRegex(PermissionError, "unreadable source subtree"):
                stage.recovery_source_inventory(self.root)



    def test_post_spawn_journal_failure_retains_dispatched_pid_unknown(self):
        original = stage.recovery_json
        def journal_failure(path, value):
            if path.name == "execution.json" and value.get("status") == "restore-started":
                raise OSError("synthetic post-spawn journal write failure")
            return original(path, value)
        with patch.object(stage, "recovery_json", side_effect=journal_failure):
            journal = self.execution_fixture(lambda *a, **k: Mock(pid=789))
        self.assertEqual(journal["status"], "UNKNOWN")
        self.assertEqual(journal["pid"], 789)
        self.assertNotIn("terminal", journal)

    def test_pinned_effects_reject_external_fk_noninnodb_and_shared_tablespace(self):
        native, _ = stage.paired_recovery_verifier()
        empty = {"tables": [], "tablespaces": [], "views": [], "routines": [],
                 "events": [], "triggers": [], "foreign_keys": []}
        for field, row in (
                ("foreign_keys", {"TABLE_SCHEMA": "outside", "REFERENCED_TABLE_SCHEMA": "exact"}),
                ("foreign_keys", {"TABLE_SCHEMA": "exact", "REFERENCED_TABLE_SCHEMA": "outside"}),
                ("tables", {"TABLE_NAME": "nontransactional", "TABLE_TYPE": "BASE TABLE", "ENGINE": "MyISAM"}),
                ("tablespaces", {"NAME": "shared", "SPACE_TYPE": "General"})):
            value = {name: list(rows) for name, rows in empty.items()}
            value[field] = [row]
            with self.subTest(field=field, row=row), self.assertRaisesRegex(ValueError, "effects NOT_READY"):
                native["assert_whole_schema_effects"]({"identity": {"database": "exact"}, "metadata": value})



    def test_ssh_transport_exit_retains_local_terminal_as_remote_unknown(self):
        for code in (255, -15):
            with self.subTest(code=code):
                process = lambda *a, **k: Mock(pid=990, wait=Mock(return_value=code))
                journal = self.execution_fixture(process)
                self.assertEqual(journal["status"], "UNKNOWN")
                self.assertTrue(journal["remote_terminal_unknown"])
                self.assertEqual(journal["terminal"]["exit_code"], code)
                self.assertEqual(journal["terminal"]["pid"], 990)
                self.assertFalse((self.workspace / "restore-input.private").exists())
                # Each attempt is synthetic and explicitly separated by the test.
                import shutil
                shutil.rmtree(self.root / "app")
                for child in self.workspace.iterdir():
                    if child.is_dir():
                        shutil.rmtree(child)
                    else:
                        child.unlink()

    def test_sql_restore_preserves_matching_files_and_rejects_drift_without_writes(self):
        native, helper = stage.paired_recovery_verifier()
        private = self.root / "private"
        private.mkdir()
        file = private / "keep.env"
        file.write_text("original")
        with patch.object(stage, "paired_recovery_verifier", return_value=(native, helper)):
            protected = stage.recovery_inventory(self.root, ["private/keep.env"], ("private",))
            before = file.stat()
            stage.recovery_preserve_files(self.root, {"protected": protected})
            self.assertEqual(file.stat().st_ino, before.st_ino)
            file.write_text("new current business bytes")
            with self.assertRaisesRegex(RuntimeError, "preserve the current state"):
                stage.recovery_preserve_files(self.root, {"protected": protected})
            self.assertEqual(file.read_text(), "new current business bytes")

class GeneratedClientPublicationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="peanut-native-client-publication-")
        self.root = Path(self.temporary.name).resolve()
        self.addCleanup(self.temporary.cleanup)
        self.old_umask = os.umask(0o022)
        self.addCleanup(os.umask, self.old_umask)
        (self.root / "server/public").mkdir(parents=True)
        for name in ("pc/.output/public", "uniapp/dist/build/h5"):
            source = self.root / name
            (source / "assets").mkdir(parents=True)
            (source / "index.html").write_text("native generated index")
            (source / "assets/app.js").write_text("native generated script")

    def test_both_native_outputs_publish_with_exact_hash_inventory(self):
        for source, destination in (("pc/.output/public", "server/public/pc"),
                                    ("uniapp/dist/build/h5", "server/public/mobile")):
            value = stage.publish_generated_client(self.root, source, destination)
            self.assertEqual(value["source"], str(self.root / source))
            self.assertEqual(value["destination"], str(self.root / destination))
            self.assertEqual(set(value["outputs"]), {"index.html", "assets/app.js"})
            for name, state in value["outputs"].items():
                self.assertEqual(state["sha256"], stage.sha256(self.root / source / name))
                self.assertEqual(state["sha256"], stage.sha256(self.root / destination / name))
                self.assertEqual(state["mode"], 0o644)

    def test_unknown_existing_destination_is_preserved(self):
        destination = self.root / "server/public/pc"
        destination.mkdir()
        (destination / "business.html").write_text("keep existing bytes")
        with self.assertRaisesRegex(RuntimeError, "unknown existing"):
            stage.publish_generated_client(self.root, "pc/.output/public", "server/public/pc")
        self.assertEqual((destination / "business.html").read_text(), "keep existing bytes")
        self.assertFalse((self.root / "server/public/.pc.qualification-publish").exists())

    def test_outside_contract_source_or_destination_is_rejected(self):
        for source, destination in (("../outside", "server/public/pc"),
                                    ("pc/.output/public", "../outside"),
                                    ("pc/.output/public", "server/public/admin")):
            with self.subTest(source=source, destination=destination), self.assertRaisesRegex(RuntimeError, "escapes"):
                stage.publish_generated_client(self.root, source, destination)

    def test_source_link_and_destination_link_are_rejected(self):
        link = self.root / "pc/.output/public/assets/linked.js"
        link.symlink_to(self.root / "uniapp/dist/build/h5/assets/app.js")
        with self.assertRaises(RuntimeError):
            stage.publish_generated_client(self.root, "pc/.output/public", "server/public/pc")
        link.unlink()
        destination = self.root / "server/public/pc"
        destination.symlink_to(self.root / "uniapp/dist/build/h5")
        with self.assertRaises(RuntimeError):
            stage.publish_generated_client(self.root, "pc/.output/public", "server/public/pc")

    def test_missing_index_and_previous_staging_are_rejected(self):
        index = self.root / "pc/.output/public/index.html"
        index.unlink()
        with self.assertRaises(RuntimeError):
            stage.publish_generated_client(self.root, "pc/.output/public", "server/public/pc")
        index.write_text("native index")
        staging = self.root / "server/public/.pc.qualification-publish"
        staging.mkdir()
        (staging / "original").write_text("retain failed attempt")
        with self.assertRaisesRegex(RuntimeError, "previous.*staging"):
            stage.publish_generated_client(self.root, "pc/.output/public", "server/public/pc")
        self.assertEqual((staging / "original").read_text(), "retain failed attempt")

    def test_generated_state_contains_actual_pc_and_mobile_outputs(self):
        stage.publish_generated_client(self.root, "pc/.output/public", "server/public/pc")
        stage.publish_generated_client(self.root, "uniapp/dist/build/h5", "server/public/mobile")
        content = {".peanut/application-manifest.json": json.dumps({"application": {"edition": "standalone"}}),
                   "modules.lock": "native lock", "server/public/admin/index.html": "native admin",
                   "server/generated/openapi.json": "{}", "web/src/generated/openapi.d.ts": "native types"}
        for relative, text in content.items():
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text)
        catalog = {"schema_version": 1, "inputs": [{"path": "modules.lock", "sha256": stage.sha256(self.root / "modules.lock")}]}
        (self.root / "server/generated/api-catalog.json").write_text(json.dumps(catalog))
        value = stage.generated_state(self.root)
        for relative in ("server/public/pc/index.html", "server/public/pc/assets/app.js",
                         "server/public/mobile/index.html", "server/public/mobile/assets/app.js"):
            self.assertEqual(value["outputs"][relative]["sha256"], stage.sha256(self.root / relative))
            self.assertEqual(value["outputs"][relative]["mode"], 0o644)



    def test_unreadable_native_output_is_rejected_before_publication(self):
        def unreadable(*args, **kwargs):
            kwargs["onerror"](PermissionError("synthetic unreadable native output"))
        with patch.object(stage.os, "walk", side_effect=unreadable):
            with self.assertRaises(PermissionError):
                stage.publish_generated_client(self.root, "pc/.output/public", "server/public/pc")
        self.assertFalse((self.root / "server/public/pc").exists())



class RecoveryGlobalGrantsTest(unittest.TestCase):
    STATIC = ("SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, RELOAD, SHUTDOWN, PROCESS, FILE, "
              "REFERENCES, INDEX, ALTER, SHOW DATABASES, SUPER, CREATE TEMPORARY TABLES, LOCK TABLES, "
              "EXECUTE, REPLICATION SLAVE, REPLICATION CLIENT, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, "
              "ALTER ROUTINE, CREATE USER, EVENT, TRIGGER, CREATE TABLESPACE, CREATE ROLE, DROP ROLE")

    def test_direct_all_and_complete_native_enumeration(self):
        self.assertTrue(stage.recovery_global_metadata_grants(
            ["GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION"]))
        self.assertTrue(stage.recovery_global_metadata_grants([
            "GRANT " + self.STATIC + " ON *.* TO `root`@`localhost` WITH GRANT OPTION",
            "GRANT BACKUP_ADMIN,SHOW_ROUTINE,SYSTEM_USER ON *.* TO `root`@`localhost`",
            "GRANT PROXY ON ''@'' TO `root`@`localhost` WITH GRANT OPTION"]))

    def test_missing_static_or_routine_visibility_is_rejected(self):
        for removed in ("PROCESS, ", "SELECT, ", "SHOW DATABASES, ", "TRIGGER, "):
            with self.subTest(removed=removed):
                self.assertFalse(stage.recovery_global_metadata_grants([
                    "GRANT " + self.STATIC.replace(removed, "") + " ON *.* TO 'root'@'localhost'",
                    "GRANT SHOW_ROUTINE ON *.* TO 'root'@'localhost'"]))
        self.assertFalse(stage.recovery_global_metadata_grants(
            ["GRANT " + self.STATIC + " ON *.* TO 'root'@'localhost'"]))

    def test_local_schema_role_only_and_other_principal_do_not_prove_visibility(self):
        for row in ("GRANT ALL PRIVILEGES ON exact_schema.* TO 'root'@'localhost'",
                    "GRANT 'admin_role'@'%' TO 'root'@'localhost'",
                    "GRANT ALL PRIVILEGES ON *.* TO 'another'@'localhost'"):
            with self.subTest(row=row):
                self.assertFalse(stage.recovery_global_metadata_grants([row]))

    def test_partial_revoke_is_rejected_even_with_global_all(self):
        self.assertFalse(stage.recovery_global_metadata_grants([
            "GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost'",
            "REVOKE SELECT ON private_schema.* FROM 'root'@'localhost'"]))



class NativeIdentityDeliveryTest(unittest.TestCase):
    """Protocol/control tests substitute the PHP issuer. No real login or DB writes."""

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="peanut-native-identity-contract-")
        self.root = Path(self.temporary.name).resolve()
        self.addCleanup(self.temporary.cleanup)
        self.output = self.root / "output"
        self.output.mkdir(mode=0o700)
        self.app = self.root / "installed"
        self.app.mkdir()
        self.runtime = {"root": str(self.app), "head": "a" * 40, "lease": "own-mi",
                        "resource_id": "mi", "database_identity": {"database": "exact", "server_uuid": "synthetic"}}
        self.config = {"installed_application": self.runtime, "identity_credentials": {"source": "synthetic"},
                       "reviewed_module_source": {"root": str(self.root / "reviewed")}}
        self.plan = {"owner": "self", "thread": "self", "groups": GROUPS, "candidate_tree": "b" * 40,
                     "module_installed_only": True}
        (self.output / "plan.json").write_text(json.dumps(self.plan))
        self.config_path = self.output / "config.json"
        self.config_path.write_text(json.dumps(self.config))
        self.runner = stage.Runner.__new__(stage.Runner)
        self.runner.output = self.output
        self.runner.inputs = {"module_qualification": self.config, "source": {"commit": "a" * 40, "tree": "b" * 40}}
        self.runner.plan = self.plan
        self.runner.args = argparse.Namespace(candidate="a" * 40)
        self.runner.environment = lambda app: {}
        self.runner.checkpoint = {"groups": {}}
        self.runner.save_checkpoint = Mock()
        self.runner.cleanup_success = Mock()

    def issuer(self, argv, **kwargs):
        phase = next(x.split("=", 1)[1] for x in argv if x.startswith("--consumer="))
        path = Path(next(x.split("=", 1)[1] for x in argv if x.startswith("--context-output=")))
        config_path = Path(next(x.split("=", 1)[1] for x in argv if x.startswith("--input=")))
        binding = {"plan_sha256": stage.sha256(self.output / "plan.json"), "input_sha256": stage.sha256(config_path),
                   "owner": "self", "thread": "self", "runtime_lease": "own-mi",
                   "application_root": str(self.app), "application_head": "a" * 40, "resource_id": "mi",
                   "database_identity": self.runtime["database_identity"],
                   "producer_sha256": stage.sha256(stage.TOOL_ROOT / "server/fixtures/module-lifecycle/run.php"),
                   "credentials": self.config["identity_credentials"], "consumer": phase}
        facts = {"synthetic_actor": {"issued_at": 100, "expires_at": 1000, "session_key_sha256": "c" * 64}}
        value = {"protocol": "peanut.module-native-identity-context.v1", "status": "issued",
                 "binding": binding, "facts": facts, "tokens": {"synthetic": "not-a-real-token"}}
        stage.recovery_json(path, value)
        result = {"protocol": "peanut.module-native-identity-delivery.v1", "status": "issued",
                  "binding": binding, "facts": facts, "context": {"path": str(path), "sha256": stage.sha256(path)}}
        return Mock(returncode=0, stdout=json.dumps(result), stderr="")

    def test_native_delivery_binds_current_plan_actor_sources_and_consumer(self):
        with patch.object(stage, "verify_module_context"), patch.object(stage, "command", side_effect=self.issuer):
            value = self.runner.issue_module_identity("member-upload", self.config_path)
        self.assertEqual(value["binding"]["runtime_lease"], "own-mi")
        self.assertEqual(value["binding"]["credentials"], self.config["identity_credentials"])
        self.assertEqual(value["binding"]["consumer"], "member-upload")
        self.assertEqual(Path(value["context"]["path"]).stat().st_mode & 0o777, 0o600)
        self.assertNotIn("tokens", value)

    def test_foreign_owner_or_plan_result_is_rejected(self):
        def foreign(argv, **kwargs):
            result = self.issuer(argv, **kwargs)
            value = json.loads(result.stdout)
            value["binding"]["owner"] = "another-owner"
            result.stdout = json.dumps(value)
            return result
        with patch.object(stage, "verify_module_context"), patch.object(stage, "command", side_effect=foreign):
            with self.assertRaisesRegex(RuntimeError, "provenance differs"):
                self.runner.issue_module_identity("task-import-export", self.config_path)
        self.assertTrue((self.output / "identity-contexts/task-import-export.json").exists())

    def test_retained_issuance_is_not_automatically_replayed(self):
        directory = self.output / "identity-contexts"
        directory.mkdir(mode=0o700)
        (directory / "preflight.json").write_text("preserve partial original")
        with patch.object(stage, "command") as issue:
            with self.assertRaisesRegex(RuntimeError, "do not automatically replay"):
                self.runner.issue_module_identity("preflight", self.config_path)
            issue.assert_not_called()

    def test_failed_preflight_precedes_every_group_and_preserves_eight_group_plan(self):
        self.runner.run_group = Mock()
        with patch.object(self.runner, "issue_module_identity", side_effect=RuntimeError("native login refused")):
            with self.assertRaisesRegex(RuntimeError, "native login refused"):
                self.runner.run()
        self.runner.run_group.assert_not_called()
        self.assertEqual(self.runner.plan["groups"], GROUPS)
        self.assertEqual(self.runner.checkpoint["groups"], {})
        self.assertTrue((self.output / "identity-preflight-input.json").exists())

    def test_successful_preflight_is_before_generated_application(self):
        events = []
        def issued(*args):
            events.append("identity-preflight")
            return {"context": {"path": "synthetic", "sha256": "synthetic"}}
        def group(name, operation):
            events.append(name)
            self.runner.checkpoint["groups"][name] = {"status": "passed"}
        self.runner.run_group = group
        with patch.object(self.runner, "issue_module_identity", side_effect=issued):
            self.runner.run()
        self.assertEqual(events, ["identity-preflight", *GROUPS])
        self.runner.cleanup_success.assert_called_once()

    def test_each_installed_producer_receives_a_separate_just_issued_context(self):
        (self.output / "groups").mkdir()
        events = []
        def issue(phase, path):
            events.append("issue-" + phase)
            return {"context": {"path": str(self.output / (phase + ".json")), "sha256": phase}}
        def consume(argv, **kwargs):
            phase = next(x.split("=", 1)[1] for x in argv if x.startswith("--action="))
            events.append("consume-" + phase)
            self.assertIn("--identity-context-sha256=" + phase, argv)
            value = {"status": "passed", "protocol": "peanut.module-qualification-result.v1", "producer": phase,
                     "source": self.runner.inputs["source"],
                     "input_sha256": stage.sha256(self.output / "groups/module-lifecycle/input.json"),
                     "plan_sha256": stage.sha256(self.output / "plan.json"),
                     "application_root": str(self.app), "resource_id": "mi",
                     "database_identity": self.runtime["database_identity"],
                     "native_identity": "verified-current-session", "writes_retained": True}
            return Mock(returncode=0, stdout=json.dumps(value), stderr="")
        with patch.object(stage, "module_qualification_inputs", return_value=self.config), \
                patch.object(stage, "verify_module_context"), \
                patch.object(self.runner, "issue_module_identity", side_effect=issue), \
                patch.object(stage, "command", side_effect=consume):
            result = self.runner.module_lifecycle()
        self.assertEqual(events, ["issue-member-upload", "consume-member-upload",
                                  "issue-task-import-export", "consume-task-import-export"])
        self.assertEqual(result["scope"], "module-installed-components")
        self.assertEqual(result["status"], "not_run")

    def test_legacy_raw_context_does_not_trigger_new_identity_login(self):
        self.runner.inputs["module_qualification"] = {"identity_context": {"path": "historical", "sha256": "historical"}}
        with patch.object(self.runner, "issue_module_identity") as issue:
            self.runner.preflight_module_identity()
            issue.assert_not_called()



class BrowserCredentialContractTest(unittest.TestCase):
    def test_baseline_shell_requires_only_edition_credentials(self):
        source = (SCRIPT.parent / "p0e-browser-smoke").read_text()
        selection = source[source.index("required_values=("):source.index('  [[ "$QUALIFICATION_PLAN"')]
        expected = {
            "standalone": ["P0E_ADMIN_INITIAL_EMAIL", "P0E_ADMIN_INITIAL_PASSWORD",
                           "P0E_TENANT_RESTRICTED_EMAIL", "P0E_TENANT_RESTRICTED_PASSWORD"],
            "multi-tenant": ["P0E_ADMIN_INITIAL_EMAIL", "P0E_ADMIN_INITIAL_PASSWORD",
                             "P0E_PLATFORM_INITIAL_EMAIL", "P0E_PLATFORM_INITIAL_PASSWORD",
                             "P0E_TENANT_RESTRICTED_EMAIL", "P0E_TENANT_RESTRICTED_PASSWORD",
                             "P0E_TENANT_BETA_EMAIL", "P0E_TENANT_BETA_PASSWORD"],
        }
        for mode, names in expected.items():
            with self.subTest(mode=mode):
                script = ('local_url() { :; }\nPROFILE=baseline\nMODE=' + mode + '\n'
                          + selection + '\nfi\nprintf "%s\\n" "$' + '{required_values[@]}"')
                result = subprocess.run(["bash", "-c", script], text=True, capture_output=True, check=True)
                self.assertEqual(result.stdout.splitlines(), names)

    def test_baseline_javascript_reads_platform_credentials_only_for_multi_tenant(self):
        source = (SCRIPT.parent.parent / "server/tests/fixtures/p0e-runtime-qualification/browser-smoke.js").read_text()
        declarations = "\n".join(line.strip() for line in source.splitlines()
                                 if line.strip().startswith(("const platformEmail =", "const platformPassword =")))
        for line in declarations.splitlines():
            for mode in ("standalone", "multi-tenant"):
                with self.subTest(mode=mode, declaration=line):
                    script = ("const mode = " + json.dumps(mode) + "; let calls = []; "
                              "const required = (name) => { calls.push(name); return name; }; "
                              + line + " console.log(JSON.stringify(calls));")
                    result = subprocess.run(["node", "-e", script],
                                            text=True, capture_output=True, check=True)
                    calls = json.loads(result.stdout)
                    self.assertEqual(len(calls), 0 if mode == "standalone" else 1)


class ComposeNetworkOverrideTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="peanut-compose-network-")
        self.root = Path(self.temporary.name).resolve()
        self.path = self.root / "network.json"
        self.value = {"networks": {"default": {"ipam": {"config": [{"subnet": "172.16.58.0/24"}]}}}}
        self.write_override()
        self.runtime = {"resource_id": "test-runtime", "root": str(self.root), "port": 24882,
                        "compose_project": "test-network", "compose_environment": self.reference,
                        "compose_network_override": self.reference}
        for field in ("health_url", "tenant_admin_url", "tenant_beta_url", "platform_url", "docs_url"):
            self.runtime[field] = "http://127.0.0.1:24882"
        self.app = {"root": str(self.root), "resource_id": "test-database", "endpoint_id": "test-endpoint",
                    "database": "test_schema", "runtime": self.runtime}
        self.registered = {**self.runtime, "stable_resource_id": "test-runtime", "application_root": str(self.root),
                           "database_binding": {key: self.app[key] for key in ("resource_id", "endpoint_id", "database")},
                           "compose_config_sha256": "a" * 64, "persistent_mounts": [{"type": "bind"}], "owner": "test"}
        self.registry = self.root / "registry.json"
        self.write_registry()

    def tearDown(self):
        self.temporary.cleanup()

    def write_override(self):
        self.path.write_text(json.dumps(self.value))
        self.path.chmod(0o600)
        self.reference = {"path": str(self.path), "sha256": stage.sha256(self.path)}

    def write_registry(self):
        self.registry.write_text(json.dumps({"resources": {"runtime": [self.registered]}}))

    def test_private_network_only_reference(self):
        self.assertEqual(stage.compose_network_override(self.reference), self.path)

    def test_rejects_changed_digest_and_unsafe_metadata(self):
        for change in ("digest", "mode", "link"):
            with self.subTest(change=change):
                self.write_override()
                reference = dict(self.reference)
                if change == "digest":
                    reference["sha256"] = "0" * 64
                elif change == "mode":
                    self.path.chmod(0o644)
                else:
                    link = self.root / "linked.json"
                    link.symlink_to(self.path)
                    reference["path"] = str(link)
                with self.assertRaises(stage.GateError):
                    stage.compose_network_override(reference)

    def test_rejects_service_injection_extra_network_keys_and_nonprivate_subnets(self):
        mutations = [
            {**self.value, "services": {"php": {"command": ["other"]}}},
            {"networks": {"default": {"external": True, **self.value["networks"]["default"]}}},
            {"networks": {"default": {"ipam": {"config": [{"subnet": "8.8.8.0/24"}]}}}},
            {"networks": {"default": {"ipam": {"config": [{"subnet": "172.16.58.1/24"}]}}}},
            {"networks": {"default": {"ipam": {"config": [{"subnet": "172.16.0.0/16"}]}}}},
        ]
        for value in mutations:
            with self.subTest(value=value):
                self.path.write_text(json.dumps(value))
                reference = {"path": str(self.path), "sha256": stage.sha256(self.path)}
                with self.assertRaises(stage.GateError):
                    stage.compose_network_override(reference)

    def test_rejects_duplicate_json_keys(self):
        self.path.write_text('{"networks":{},"networks":{"default":{"ipam":{"config":[{"subnet":"172.16.58.0/24"}]}}}}')
        with self.assertRaises(stage.GateError):
            stage.compose_network_override({"path": str(self.path), "sha256": stage.sha256(self.path)})

    def test_rejects_unregistered_or_different_override(self):
        for registered in (None, {**self.reference, "sha256": "0" * 64}):
            with self.subTest(registered=registered):
                self.registered.pop("compose_network_override", None)
                if registered is not None:
                    self.registered["compose_network_override"] = registered
                self.write_registry()
                with patch.object(stage, "REGISTRY_PATH", self.registry), self.assertRaises(stage.GateError):
                    stage.runtime_registration(self.app)

    def test_command_uses_registered_override_in_same_base_order(self):
        runner = object.__new__(stage.Runner)
        runner.apps = {"standalone": self.app}
        with patch.object(stage, "REGISTRY_PATH", self.registry), patch.object(stage, "command") as command:
            runner.compose("standalone", "config", "--format", "json", capture=True)
        argv = command.call_args.args[0]
        self.assertEqual(argv, ["docker", "compose", "--env-file", str(self.path),
                                "-f", str(self.root / "server/docker/compose.yaml"),
                                "-f", str(self.path), "--project-name", "test-network", "config", "--format", "json"])

    def test_resolved_configuration_may_only_change_default_ipam(self):
        base = {"networks": {"default": {"name": "test-network_default"}}, "services": {"php": {"image": "fixed"}}}
        resolved = json.loads(json.dumps(base))
        resolved["networks"]["default"]["ipam"] = self.value["networks"]["default"]["ipam"]
        stage.compose_network_config(base, resolved, self.reference)
        resolved["services"]["php"]["image"] = "different"
        with self.assertRaises(stage.GateError):
            stage.compose_network_config(base, resolved, self.reference)

    def test_old_no_override_registration_still_works(self):
        self.runtime.pop("compose_network_override")
        self.registered.pop("compose_network_override")
        self.write_registry()
        with patch.object(stage, "REGISTRY_PATH", self.registry):
            self.assertEqual(stage.runtime_registration(self.app), self.registered)

if __name__ == "__main__":
    unittest.main()
