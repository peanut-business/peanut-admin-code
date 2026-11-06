#!/usr/bin/env python3
"""Offline checks for captured installer output; never touches an APP or lease."""

import json
from pathlib import Path
import runpy
import shutil
import subprocess
import sys
import tempfile
from unittest import TestCase, main, mock


SCRIPT = Path(__file__).resolve().parents[1] / "project-resource-application.py"


class ApplicationOutputTest(TestCase):
    def setUp(self):
        self.native = runpy.run_path(str(SCRIPT))

    def operation(self, temporary):
        return {"application": {"root": temporary}, "php_executable": sys.executable, "max_seconds": 60,
                "database": {"environment": "development", "resource_id": "fixture", "consumer": "host",
                             "upstream_endpoint": {"endpoint_id": "fixture-host"}}}

    def run_child(self, stdout, exit_code, accepted, stderr=b""):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            operation = self.operation(temporary)
            value = {"owner": "fixture", "started_at": self.native["time"].time()}
            command = [sys.executable, "-c", "import sys; sys.stdout.buffer.write(" + repr(stdout)
                       + "); sys.stderr.buffer.write(" + repr(stderr) + "); sys.exit(" + str(exit_code) + ")"]
            with mock.patch.dict(self.native["command"].__globals__,
                                 verify_native=lambda *_: None,
                                 lease=lambda *_1, **_2: (operation, None, {"expires_at": str(int(self.native["time"].time()) + 120)}),
                                 process_birth=lambda *_: "fixture",
                                 save=lambda *_: None):
                output = self.native["command"](operation, directory, value, "fixture", command,
                                                accepted=accepted)
                return output, value

    def test_status_json_retained_for_st_and_mt(self):
        for edition in ("ST", "MT"):
            with self.subTest(edition=edition):
                payload = json.dumps({"mode": edition, "state": "uninstalled", "code": "INSTALL_READY"}).encode()
                actual, value = self.run_child(payload, 1, (1,), b"status diagnostic")
                status = self.native["pristine_installation_status"](actual)
                self.assertEqual((status["mode"], status["state"], status["code"]),
                                 (edition, "uninstalled", "INSTALL_READY"))
                self.assertEqual(value["child_output"]["stderr_bytes"], len(b"status diagnostic"))
                self.assertEqual(value["child_output"]["stdout_sha256"], self.native["sha"](payload))

    def test_status_abnormal_output_fails_closed(self):
        for payload in (b"", b"not-json", b'{"state":"blocked","code":"INSTALL_BLOCKED"}'):
            with self.subTest(payload=payload):
                with self.assertRaises(RuntimeError):
                    self.native["pristine_installation_status"](self.run_child(payload, 1, (1,))[0])

    def test_failed_installer_exit_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "native first-install phase failed"):
            self.run_child(b'{"status":"failed"}', 1, (0,))

    def test_timeout_renews_then_retains_output(self):
        class SlowChild:
            pid = 123
            returncode = 1
            calls = 0

            def communicate(self, input=None, timeout=None):
                self.calls += 1
                if self.calls == 1:
                    raise subprocess.TimeoutExpired("fixture", timeout)
                return b'{"state":"uninstalled","code":"INSTALL_READY"}', b"diagnostic"

        with tempfile.TemporaryDirectory() as temporary:
            operation = self.operation(temporary)
            value = {"owner": "fixture", "started_at": self.native["time"].time()}
            child = SlowChild()
            with mock.patch.dict(self.native["command"].__globals__,
                                 verify_native=lambda *_: None,
                                 lease=lambda *_1, **_2: (operation, None, {"expires_at": str(int(self.native["time"].time()) + 120)}),
                                 process_birth=lambda *_: "fixture", save=lambda *_: None,
                                 renew_native=lambda *_: None), \
                 mock.patch.object(self.native["subprocess"], "Popen", return_value=child):
                output = self.native["command"](operation, Path(temporary), value, "fixture",
                                                [sys.executable], accepted=(1,))
            self.assertEqual(child.calls, 2)
            self.assertEqual(self.native["pristine_installation_status"](output)["code"], "INSTALL_READY")
            self.assertEqual(value["child_output"]["stderr_bytes"], len(b"diagnostic"))

    def test_status_producer_caught_error_is_json_and_still_fails(self):
        source = SCRIPT.parent.parent / "server"
        with tempfile.TemporaryDirectory() as temporary:
            server = Path(temporary) / "server"
            for relative in ("database/install.php", "database/environment-guard.php",
                             "database/resource-registry.php", "database/InitialInstallationSource.php"):
                target = server / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source / relative, target)
            vendor = server / "vendor/autoload.php"
            vendor.parent.mkdir()
            vendor.write_text("<?php\n")
            environment = server / ".env"
            environment.write_text("")
            environment.chmod(0o600)
            result = subprocess.run(["php", str(server / "database/install.php"), "--status"],
                                    capture_output=True, cwd=temporary, check=False)
            self.assertEqual(result.returncode, 1, result.stderr.decode(errors="replace"))
            self.assertEqual(self.native["json_unique"](result.stdout),
                             {"state": "blocked", "code": "INSTALL_STATUS_UNAVAILABLE"})
            self.assertIn(b"INSTALL_EXECUTION_FAILED", result.stderr)
            environment.unlink()
            missing_env = subprocess.run(["php", str(server / "database/install.php"), "--status"],
                                         capture_output=True, cwd=temporary, check=False)
            self.assertEqual(missing_env.returncode, 1)
            self.assertEqual(self.native["json_unique"](missing_env.stdout)["state"], "blocked")
            vendor.unlink()
            missing_vendor = subprocess.run(["php", str(server / "database/install.php"), "--status"],
                                            capture_output=True, cwd=temporary, check=False)
            self.assertEqual(missing_vendor.returncode, 1)
            self.assertEqual(self.native["json_unique"](missing_vendor.stdout)["state"], "blocked")


class IsolatedFirstInstallTest(TestCase):
    def setUp(self):
        self.native = runpy.run_path(str(SCRIPT))
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name).resolve()
        self.source = {"kind": "git", "commit": self.native["git"](SCRIPT.parent, "rev-parse", "HEAD").decode().strip(),
                       "tree": self.native["git"](SCRIPT.parent, "rev-parse", "HEAD^{tree}").decode().strip()}

    def fixture(self, group="059", mode="st"):
        resource, name = "peanut-" + group + "-" + mode + "-mysql84", "peanut_" + group + "_" + mode
        gate, scenario = ("059-security-runtime", "security_http") if group == "059" else ("postrelease-download-runtime", "post_release_download")
        edition = "standalone" if mode == "st" else "multi-tenant"
        endpoint = {"endpoint_id": resource + "-host-direct", "host": "192.0.2.1", "port": 3000}
        principal, version, uuid = name + "@%", "8.4.10", "fixture-uuid"
        db = {"resource_id": resource, "name": name, "principal": principal, "version": version, "server_uuid": uuid,
              "environment": "development", "consumer": "host", "upstream_endpoint": endpoint}
        archive = self.root / "template.tgz"
        archive.write_bytes(b"offline fixture")
        app = {"edition": edition, "root": str(self.root / "app")}
        fixed = {"source_identity": dict(self.source), "archive_path": str(archive), "archive_sha256": self.native["sha"](archive.read_bytes()),
                 "listener_resource_id": "fixture-http", "port": 39059}
        op = {"phase": "install", "owner": "current", "thread": "current", "database": db, "application": app,
              "isolated_first_install": fixed}
        manifest = {"template": {"source_identity": dict(self.source)}, "generation_source": {"source_identity": dict(self.source)},
                    "template_package": {"protocol": "peanut.template-package.v1", "archive_sha256": fixed["archive_sha256"]}}
        credential = str(self.root / "ordinary.env")
        allocated = {"protocol": "peanut.schema-allocation.v1", "stable_resource_id": "allocation", "lease_gate": gate,
                     "database_resource_id": resource, "endpoint_id": endpoint["endpoint_id"], "server_uuid": uuid,
                     "version": version, "credentials": {"path": credential, "sha256": "c" * 64},
                     "owner": "original", "thread": "original"}
        login = {"database": name, "principal": principal, "uuid": uuid, "version": version}
        receipt = self.root / "allocation.json"
        receipt.write_text(json.dumps({"status": "COMPLETED", "lease": "allocated", "owner": "original",
                                      "targets": {"allocation": {"status": "COMPLETED", "login": login}}}))
        receipt.chmod(0o600)
        evidence = {"path": str(receipt), "sha256": self.native["sha"](receipt.read_bytes()), "native_lease": "allocated"}
        registered = {"stable_resource_id": resource, "lease_gate": gate, "application_root": app["root"], "owner": "original",
                      "environments": ["development"], "deployment_modes": [edition], "lifecycle": "ephemeral",
                      "application_runtime": False, "fallback": "none", "service_type": "mysql",
                      "allowed_scenarios": ["first_install", scenario], "lease_policy": {"mode": "schema"}, "database": name,
                      "schema": name, "namespace": name, "username": name, "account_host": "%", "server_uuid": uuid,
                      "upstream_endpoint": dict(endpoint), "version": version, "credential_ref": credential, "allocation_evidence": evidence}
        listener = {"stable_resource_id": fixed["listener_resource_id"], "port": fixed["port"], "host": "127.0.0.1",
                    "owner": "current", "application_root": app["root"], "database_resource_id": resource, "environments": ["development"]}
        document = {"resources": {"schema_allocations": [allocated], "local_listeners": [listener]}}
        return op, document, registered, manifest

    def test_four_registered_scopes_and_permanent_gate(self):
        for group in ("059", "postrelease"):
            for mode in ("st", "mt"):
                with self.subTest(group=group, mode=mode):
                    op, doc, database, manifest = self.fixture(group, mode)
                    self.native["isolated_install"](op, doc, database, manifest)
                    self.assertEqual(self.native["operation_gate"](op), database["lease_gate"])
        self.assertEqual(self.native["operation_gate"]({"phase": "maintain"}), "permanent-app-development")

    def test_scope_drift_is_rejected(self):
        for section, field, wrong in [
            ("op", "owner", "original"), ("op", "thread", "foreign"), ("op", "phase", "maintain"),
            ("op", "retained_authority", {}), ("db", "resource_id", "peanut-058-st-mysql84"),
            ("db", "environment", "production"), ("registered", "environment", "production"),
            ("registered", "lease_gate", "permanent-app-development"), ("registered", "application_root", "/elsewhere"),
            ("registered", "owner", "current"), ("registered", "deployment_modes", ["multi-tenant"]), ("registered", "schema", "foreign"),
            ("registered", "environments", ["development","production"]), ("registered", "application_runtime", True),
            ("registered", "lease_policy", {"mode": "schema","shared_access": {}}), ("db", "principal", "root@localhost"),
            ("db", "server_uuid", "foreign"), ("fixed", "archive_sha256", "1"*64),
            ("template", "source_identity", {"kind": "local","snapshot_sha256": "a"*64}),
            ("fixed", "source_identity", dict(kind="git",commit="0"*40,tree="1"*40)),
            ("listener", "owner", "foreign"), ("listener", "host", "0.0.0.0"),
            ("listener", "database_resource_id", "foreign"), ("fixed", "port", 39060),
            ("allocation", "thread", "foreign"), ("allocation", "server_uuid", "foreign"),
            ("evidence", "sha256", "f"*64)]:
            with self.subTest(section=section, field=field):
                op, doc, db, manifest = self.fixture()
                target = {"op": op, "db": op["database"], "registered": db, "fixed": op["isolated_first_install"],
                          "template": manifest["template"], "listener": doc["resources"]["local_listeners"][0],
                          "allocation": doc["resources"]["schema_allocations"][0], "evidence": db["allocation_evidence"]}[section]
                target[field] = wrong
                with self.assertRaises(RuntimeError):
                    self.native["isolated_install"](op, doc, db, manifest)
        op, doc, db, manifest = self.fixture()
        listener = doc["resources"]["local_listeners"][0]
        doc["resources"]["local_listeners"].append(dict(listener,stable_resource_id="another-service"))
        with self.assertRaisesRegex(RuntimeError, "port differs"):
            self.native["isolated_install"](op, doc, db, manifest)

    def test_local_ordinary_password_enters_only_fixed_ssh_stdin(self):
        import shlex
        op, doc, db, manifest = self.fixture()
        key = "x" * 48
        credential = Path(db["credential_ref"])
        credential.write_text("DB_HOST=192.0.2.1\nDB_PORT=3000\nDB_NAME=" + db["database"]
                              + "\nDB_USER=" + db["username"] + "\nDB_PASS=" + key + "\n")
        credential.chmod(0o600)
        digest = self.native["sha"](credential.read_bytes())
        docker = self.root / "docker"
        docker.write_text("#!/bin/sh\nset -eu\n[ \"${MYSQL_PWD:?}\" = " + key + " ]\n"
                          "case \"$5\" in\n/usr/bin/mysql) printf '%s\\n' 'fixture-uuid|peanut_059_st|8.4.10|peanut_059_st@%' ;;\n"
                          "/usr/bin/mysqldump) printf '%s\\n' 'peanut_059_st' '-- Dump completed' ;;\n*) exit 2;;\nesac\n")
        docker.chmod(0o700)
        transport = {"ssh_command": "/usr/bin/ssh", "ssh_alias": "fixture", "docker_command": str(docker),
                     "container_name": "mysql", "mysql_command": "/usr/bin/mysql"}
        allocation = doc["resources"]["schema_allocations"][0]
        allocation.update(transport=transport)
        allocation["credentials"]["sha256"] = digest
        client = {"ssh": "/usr/bin/ssh", "ssh_alias": "fixture", "docker": str(docker), "container": "mysql",
                  "mysql": "/usr/bin/mysql", "mysqldump": "/usr/bin/mysqldump", "credential_path": str(credential),
                  "credential_sha256": digest, "user": db["username"]}
        op.update(phase="protect", backup_client=client, output_root=str(self.root / "protect"), max_seconds=60)
        op["application"]["commit"] = self.source["commit"]
        Path(op["application"]["root"]).mkdir()
        self.native["isolated_install"](op, doc, db, manifest)
        with mock.patch.dict(client, user="root"):
            with self.assertRaisesRegex(RuntimeError, "backup client"):
                self.native["isolated_install"](op, doc, db, manifest)
        def run_fixed(_op, _directory, _value, _lease, argv, *, input_bytes):
            self.assertEqual(argv[:-1], ["/usr/bin/ssh", "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes",
                                         "-o", "ConnectTimeout=5", "fixture"])
            self.assertNotIn(key, repr(argv))
            self.assertNotIn(str(credential), argv[-1])
            self.assertEqual(input_bytes, (key + "\n").encode())
            return subprocess.run(shlex.split(argv[-1]), input=input_bytes, capture_output=True, check=True).stdout
        metadata = dict(database=db["database"], server_uuid=db["server_uuid"], version=db["version"], principal=client["user"] + "@%")
        state = {"owner": "current", "started_at": self.native["time"].time()}
        with mock.patch.dict(self.native["protect"].__globals__, application_metadata=lambda *_: metadata, command=run_fixed):
            result = self.native["protect"](op, self.root, state, "fixture")
            self.assertFalse(result["restore_qualified"])
            protection = json.loads(Path(result["manifest"]).read_text())
            self.assertNotIn(key, Path(protection["database_dump"]["path"]).read_text())
            op["output_root"] = str(self.root / "refuse")
            credential.write_text(credential.read_text().replace(key, "Z" * len(key)))
            with self.assertRaisesRegex(RuntimeError, "credential changed"):
                self.native["protect"](op, self.root, state, "fixture")

    def test_root_metadata_requires_empty_objects_and_full_visibility(self):
        op, doc, db, _ = self.fixture()
        op.update(stable_resource_id="fixture", receipt_root=str(self.root))
        doc["resources"]["databases"] = [db]
        transport = dict(mysql_command="/usr/bin/mysql", docker_command="/usr/bin/docker", ssh_command="/usr/bin/ssh",
                         container_name="mysql", ssh_alias="fixture")
        sql = self.native["install_metadata_sql"](db["database"])
        base = dict(database=db["database"], server_uuid=db["server_uuid"], version=db["version"], server_port=3306,
                    schema_rows=1, principal="root@localhost", active_roles="NONE", partial_revokes=0,
                    tables_views=0, triggers=0, routines=0, events=0)
        cases = [(None, None)] + [(key, 1) for key in ("tables_views", "triggers", "routines", "events", "partial_revokes")]
        cases += [("server_uuid", "foreign"), ("principal", "restricted@%"), ("active_roles", "other"), ("grants","SELECT")]
        for i, (key, wrong) in enumerate(cases):
            with self.subTest(field=key):
                row = dict(base)
                grants = "SELECT, TRIGGER, EVENT, EXECUTE"
                if key == "grants": grants = wrong
                elif key: row[key] = wrong
                def observe(_op, _directory, _value, _lease, argv, *, input_bytes):
                    self.assertIn("-uroot", argv[-1])
                    self.assertEqual(input_bytes, sql.encode())
                    return (json.dumps(row) + "\nGRANT " + grants + " ON *.* TO " + "'root'@'localhost'\n").encode()
                overrides = dict(authority=lambda *_: (None,None,None,"registry"),
                                 project_authority=lambda: (None,None,{"load_registry": lambda: doc},"registry"),
                                 install_metadata_contract=lambda *_: (transport,sql), command=observe)
                state = {"owner": "current", "operation_sha256": "operation"}
                with mock.patch.dict(self.native["install_metadata"].__globals__, overrides):
                    if key:
                        with self.assertRaises(RuntimeError):
                            self.native["install_metadata"](op, self.root, state, "metadata-" + str(i))
                        self.assertNotEqual(state["metadata_readonly"]["status"], "COMPLETED")
                    else:
                        self.native["install_metadata"](op, self.root, state, "metadata-" + str(i))
                        self.assertEqual(state["metadata_readonly"]["result"], row)

    def test_pre_dispatch_rejects_tampered_archive_and_occupied_listener(self):
        import socket
        op, _, _, _ = self.fixture()
        op.update(phase="protect", receipt_root=str(self.root / "receipts"))
        fixed = op["isolated_first_install"]
        mocked_protect = mock.Mock(return_value={})
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0)); listener.listen()
            fixed["port"] = listener.getsockname()[1]
            overrides = dict(lease=lambda *a, **kw: (op,None,None), window=lambda *a: (op,self.root,{}),
                             save=lambda *a: None, protect=mocked_protect)
            with mock.patch.dict(self.native["execute"].__globals__, overrides):
                with self.assertRaises(OSError):
                    self.native["execute"]("fixture","current","protect")
                fixed["archive_sha256"] = "0" * 64
                with self.assertRaisesRegex(RuntimeError, "archive bytes changed"):
                    self.native["execute"]("fixture","current","protect")
                mocked_protect.assert_not_called()


class InstalledAdminReviewTest(TestCase):
    def setUp(self):
        self.native = runpy.run_path(str(SCRIPT))
        self.sha = self.native["sha"]
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name).resolve()
        self.app, receipts = root / "app", root / "evidence/receipts"
        self.app.mkdir()
        receipts.mkdir(parents=True)
        self.asset = self.app / "server/public/admin/index.html"
        self.asset.parent.mkdir(parents=True)
        self.asset.write_bytes(b"before")
        (self.app / ".gitignore").write_text("server/private/\n")
        def git(*args):
            return subprocess.check_output(["git", "-C", str(self.app), *args], stderr=subprocess.DEVNULL).decode().strip()
        git("init", "-q")
        git("add", ".")
        git("-c", "user.name=fixture", "-c", "user.email=fixture@example.test", "commit", "-qm", "input")
        head = git("rev-parse", "HEAD")
        self.asset.write_bytes(b"after")
        self.asset.chmod(0o600)
        self.report_path = receipts.parent / "reviewed.json"
        marker = self.app / "server/private/installation/installed.json"
        marker.parent.mkdir(parents=True)
        def private_json(path, value):
            path.write_text(json.dumps(value))
            path.chmod(0o600)
            return self.sha(path.read_bytes())
        manifest = self.sha(b"fixed manifest")
        marker_sha = private_json(marker, {"protocol": "peanut.installation-receipt.v2", "state": "installed",
            "source": {"kind": "generated-application", "application_manifest_sha256": manifest},
            "deployment_mode": "multi-tenant", "official_modules": ["peanut.identity"],
            "completed_at": "2026-01-01T00:00:01+00:00", "application_manifest_sha256": manifest})
        witness = receipts / "metadata.json"
        witness_sha = private_json(witness, {"schema": "fixture"})
        self.op = {"phase": "install", "application": {"root": str(self.app), "commit": head,
                   "manifest_sha256": manifest, "edition": "multi-tenant"}, "configuration": {},
                   "database": {"name": "fixture", "server_uuid": "fixture-uuid"}, "receipt_root": str(receipts),
                   "isolated_first_install": {"source_identity": {"commit": "1" * 40}}}
        self.state = {"phase": "install", "status": "UNKNOWN", "failure": "RuntimeError",
                      "actual_process_terminal": True, "child_pid": None, "child_exit_code": 0,
                      "started_at": 1, "child_output": {"stdout_bytes": 2, "stdout_sha256": self.sha(b"{}"),
                      "stderr_bytes": 0, "stderr_sha256": self.sha(b"")}, "metadata_readonly": {
                          "status": "COMPLETED", "witness": str(witness), "witness_sha256": witness_sha,
                          "result": dict(database="fixture", server_uuid="fixture-uuid", tables_views=0,
                                         triggers=0, routines=0, events=0)}}
        self.rows = {"server/public/admin/index.html": {"original_sha256": self.sha(b"before"),
                     "current_sha256": self.sha(b"after"), "current_bytes": 5, "mode": 0o600}}
        self.inventory = {"app": str(self.app), "head": head, "candidate": "1"*40, "installed_sha256": marker_sha,
                          "git_tracked_changed": 1, "git_untracked": 0, "files": self.rows}
        self.proof = {"verdict": "INSTALLED_UNKNOWN_RELEASE_ONLY", "frontend_accepted": False, "release_only": True,
                      "unknown_preserved": True, "no_pending_process": True, "child_output": dict(self.state["child_output"]),
                      "installed_admin_diff": {"path": str(self.report_path),
                                               "sha256": private_json(self.report_path, self.inventory)}}

    def review(self, **proof):
        return self.native["installed_admin_review"](self.op, self.state, {**self.proof, **proof})

    def test_accepts_only_reviewed_bytes_and_preserves_unknown(self):
        self.assertFalse(self.review()["frontend_accepted"])
        self.assertEqual(self.state["status"], "UNKNOWN")
        for changes in ({"release_only": False}, {"unknown_preserved": False}, {"frontend_accepted": True},
                        {"no_pending_process": False}, {"verdict": "SAFE_TO_RELEASE"}, {"child_output": {}}):
            with self.subTest(changes=changes), self.assertRaises(RuntimeError):
                self.review(**changes)
        for changes in ({"status": "RUNNING"}, {"child_pid": 1}, {"child_exit_code": 1},
                        {"actual_process_terminal": False}, {"phase": "maintain"}):
            with self.subTest(changes=changes), mock.patch.dict(self.state, changes), self.assertRaises(RuntimeError):
                self.review()
        for changes in ({"server_uuid": "wrong"}, {"tables_views": 1}):
            with mock.patch.dict(self.state["metadata_readonly"]["result"], changes), self.assertRaises(RuntimeError):
                self.review()

    def test_rejects_source_change_unreviewed_path_and_tampered_static(self):
        self.asset.write_bytes(b"other")
        with self.assertRaises(RuntimeError):
            self.review()
        self.asset.write_bytes(b"after")
        (self.app / "unknown.php").touch()
        with self.assertRaises(RuntimeError):
            self.review()
        (self.app / "unknown.php").unlink()
        self.asset.unlink()
        self.asset.symlink_to(self.report_path)
        with self.assertRaises(RuntimeError):
            self.review()

    def test_rejects_unfinished_execution_marker_including_dangling_link(self):
        marker = self.app / "server/private/installation/executing.json"
        marker.write_text("{}")
        with self.assertRaisesRegex(RuntimeError, "execution marker remains"):
            self.review()
        marker.unlink()
        marker.symlink_to("missing-worker-state")
        with self.assertRaisesRegex(RuntimeError, "execution marker remains"):
            self.review()

    def test_rejects_marker_evidence_and_staged_mutations(self):
        with self.assertRaises(RuntimeError):
            self.review(installed_admin_diff={"path": str(self.report_path), "sha256": self.sha(b"wrong")})
        subprocess.run(["git", "-C", str(self.app), "add", "--", "server/public/admin/index.html"], check=True)
        with self.assertRaises(RuntimeError):
            self.review()


if __name__ == "__main__":
    main()
