#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
import shutil
import subprocess
import tempfile
import hashlib
import time
import json
import os


SOURCE = Path(__file__).resolve().parents[1] / "project-resource-lease"


def run(args: list[str], cwd: Path, *, ok: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(args, cwd=cwd, text=True, capture_output=True, check=False)
    if ok and result.returncode != 0:
        raise AssertionError(
            f"command failed: {args}\nstdout={result.stdout}\nstderr={result.stderr}"
        )
    if not ok and result.returncode == 0:
        raise AssertionError(f"command unexpectedly succeeded: {args}")
    return result



def source_recovery_guard_contracts(*, workspace_only=False):
    """Isolated source contracts; synthetic authority/installation, no APP/DB/SSH."""
    import sys, hmac, contextlib
    from copy import deepcopy
    source_root = SOURCE.parent.parent
    native = {"__name__": "source_recovery_contract_fixture", "__file__": str(SOURCE.parent / "project-resource-application.py")}
    exec(compile((SOURCE.parent / "project-resource-application.py").read_text(), native["__file__"], "exec"), native)
    provider = {"__name__": "source_recovery_provider_fixture", "__file__": str(source_root / "server/docker/scripts/update-recovery.py")}
    exec(compile(Path(provider["__file__"]).read_text(), provider["__file__"], "exec"), provider)
    count = 0
    def private(path, value):
        path.parent.mkdir(parents=True, exist_ok=True)
        raw = value if isinstance(value, bytes) else json.dumps(value, sort_keys=True, separators=(",", ":")).encode()
        path.write_bytes(raw); path.chmod(0o600)
        return hashlib.sha256(raw).hexdigest()
    def rejected(action, fragment):
        nonlocal count
        try: action()
        except (RuntimeError, ValueError) as error:
            assert fragment in str(error), (fragment, str(error)); count += 1; return
        raise AssertionError("unsafe recovery input accepted: " + fragment)
    for case in ("failed-mutation", "expired-window"):
        with tempfile.TemporaryDirectory(prefix="peanut-source-recovery-") as temporary:
            app = Path(temporary) / "app"; app.mkdir()
            run(["git", "init", "-q"], app)
            run(["git", "config", "user.email", "fixture@example.invalid"], app)
            run(["git", "config", "user.name", "Source Recovery Fixture"], app)
            (app / "seed").write_text("fixed")
            launcher = shutil.which("php")
            assert launcher is not None, "registered PHP launcher is required"
            php = str(Path(run([launcher, "-r", "echo PHP_BINARY;"], app).stdout.strip()).resolve())
            assert Path(php).is_file(), "registered PHP is required for the isolated parent contract"
            (app / "scripts").mkdir(); (app / "server").mkdir()
            (app / "scripts/project-resource-application.py").write_bytes((SOURCE.parent / "project-resource-application.py").read_bytes())
            php_fixture = "<?php $p=proc_open(" + json.dumps([sys.executable, str(app / "scripts/project-resource-application.py"),
                "recovery-info", "--lease", "fixture-source"]) + ",[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__)); exit(proc_close($p));"
            (app / "server/think").write_text(php_fixture)
            run(["git", "add", "scripts/project-resource-application.py", "server/think"], app)
            run(["git", "add", "seed"], app); run(["git", "commit", "-qm", "fixture"], app)
            head = run(["git", "rev-parse", "HEAD"], app).stdout.strip()
            native["ROOT"] = app
            key = b"a" * 64
            private(app / "server/private/maintenance/update-verification.key", key)
            def signed(path, record):
                raw = json.dumps(record, sort_keys=True, separators=(",", ":")).encode()
                return private(path, dict(record, hmac_sha256=hmac.new(key, raw, hashlib.sha256).hexdigest()))
            private(app / ".peanut/application-manifest.json", {"application": {"slug": "fixture"}})
            registry = app / "server/private/resources/project-resources.json"
            registry_sha = private(registry, {"authority": {"role": "application"}, "project_id": "fixture",
                "resources": {"databases": [{"stable_resource_id": "fixture-db", "lease_gate": "permanent-app-development",
                "database": "fixture_schema", "upstream_endpoint": {"host": "fixture.invalid", "port": 3306, "endpoint_id": "fixture-endpoint"},
                "lease_policy": {}}]}})
            private(app / "server/.env", b"FIXTURE_ONLY=1")
            common = app / ".git"; directory = common / "peanut-admin-resource-leases/leases/fixture-source"
            directory.mkdir(parents=True)
            workspace = app / "server/private/maintenance/fixture-backup/application-recovery"
            workspace.parent.mkdir(mode=0o700); workspace.mkdir(mode=0o700)
            rows = {"resource-id": "fixture-db", "endpoint": "fixture-endpoint", "registry-sha256": registry_sha,
                    "mysql-schema": "fixture.invalid:3306/fixture_schema", "mysql-db": "fixture_schema",
                    "database": "fixture_schema", "worktree": str(app), "workflow-session": "wc_sess_fixture",
                    "application-operation-sha256": "f" * 64, "output-dir": str(workspace.parent)}
            resource_bytes = b""
            for kind, item in rows.items():
                digest = hashlib.sha256((kind + chr(9) + item).encode()).hexdigest()
                resource_bytes += (digest + chr(9) + kind + chr(9) + item + chr(10)).encode()
                lock = common / "peanut-admin-resource-leases/resources" / digest
                for name, value in (("lease", "fixture-source"), ("type", kind), ("value", item)):
                    private(lock / name, value.encode())
            private(directory / "resources.tsv", resource_bytes)
            expiry = str(int(time.time()) - 90 if case == "expired-window" else int(time.time()) + 14400)
            metadata = {"lease": "fixture-source", "owner": "fixture-owner", "thread": "fixture-owner",
                        "candidate": head, "candidate_repository": str(app), "worktree": str(app), "status": "ACTIVE",
                        "parent_lease": "", "created_at": str(int(time.time()) - 600),
                        "expires_at": expiry, "gate": "permanent-app-development"}
            private(directory / "metadata.tsv", "".join(k + chr(9) + v + chr(10) for k, v in metadata.items()).encode())
            binding = {"source": {"root": str(app), "head": head, "kind": "generated-application",
                                  "php_binary_sha256": hashlib.sha256(Path(php).read_bytes()).hexdigest()},
                       "registry_sha256": registry_sha, "environment_sha256": hashlib.sha256((app / "server/.env").read_bytes()).hexdigest(),
                       "authority": {"lease": "fixture-source", "owner": "fixture-owner", "thread": "fixture-owner",
                         "session": "wc_sess_fixture", "gate": "permanent-app-development",
                         "expires_at": expiry, "resources_sha256": hashlib.sha256(resource_bytes).hexdigest()}}
            # Ordinary provider derives its workspace from this exact leased backup root.
            plan_path = workspace / "native-plan.json"
            plan_sha = private(plan_path, {"protocol": "peanut.source-development-schema-plan.v1", "binding": binding,
                                           "maintenance_scope": "source-application", "execution_admitted": False})
            tool_path = workspace / "backup-tool.json"; private(tool_path, {"fixture": True})
            journal_path = app / "server/runtime/upgrade/source-application-fixture.json"
            context_path = workspace / "source-application-context.json"
            signed(context_path, {"workspace": str(workspace), "binding": binding,
                   "native_plan": {"path": str(plan_path), "sha256": plan_sha},
                   "backup_tool": {"path": str(tool_path)}, "journal_path": str(journal_path)})
            journal = {"phase": "migrated" if case == "expired-window" else "failed", "activation_started": False, "unknown": case != "expired-window"}
            signed(journal_path, journal)
            private(workspace / "backup.json", {"fixture": True}); private(workspace / "backup.json.hmac", b"fixture")
            # This real child mutates only a disposable file, then exits nonzero.
            child = subprocess.Popen([sys.executable, "-c", "import pathlib,sys,time; pathlib.Path(sys.argv[1]).write_text('afterimage'); time.sleep(.3); sys.exit(7)", str(app / "afterimage")])
            birth = native["process_birth"](child.pid); assert child.wait() == 7
            state = {"protocol": "peanut.application-operation-state.v1", "phase": "source-application",
                     "lease": "fixture-source", "owner": "fixture-owner", "pid": child.pid, "process_birth": birth,
                     "child_pid": None, "status": "UNKNOWN", "binding_expires_at": expiry,
                     "actual_process_terminal": True, "completed_step": "apply", "started_at": int(time.time()) - 600,
                     "operation_sha256": "f" * 64}
            private(directory / native["STATE"], state)
            terminal = workspace / "terminal.json"; private(terminal, {"actual_process_terminal": True, "exit_code": 7})
            approved_paths = [context_path, plan_path, workspace / "backup.json", workspace / "backup.json.hmac", tool_path, terminal, journal_path]
            review = {"protocol": "peanut.source-application-terminal-reconciliation.v1", "verdict": "ORIGINAL_PLAN_RECOVERY_ONLY",
                      "reviewer_thread": "fixture-independent-reviewer", "owner": "fixture-owner", "lease": "fixture-source",
                      "state_sha256": hashlib.sha256((directory / native["STATE"]).read_bytes()).hexdigest(),
                      "metadata_sha256": hashlib.sha256((directory / "metadata.tsv").read_bytes()).hexdigest(),
                      "actual_process_terminal": True, "remote_effects_known": True,
                      "effective_expires_at": int(time.time()) + 900, "workspace": str(workspace), "plan_path": str(plan_path),
                      "terminal_evidence": [{"path": str(terminal)}],
                      "approved_sha256": {str(p): hashlib.sha256(p.read_bytes()).hexdigest() for p in approved_paths}}
            review_path = app / "server/private/maintenance/source-recovery-review-fixture-source.json"
            bad = deepcopy(review); bad["reviewer_thread"] = "fixture-owner"; signed(review_path, bad)
            rejected(lambda: native["admit_recovery"]("fixture-source", "fixture-owner"), "independent terminal")
            assert json.loads((directory / native["STATE"]).read_text()) == state
            signed(review_path, review)
            native["admit_recovery"]("fixture-source", "fixture-owner")
            current = json.loads((directory / native["STATE"]).read_text())
            meta = dict(native["table"](directory / "metadata.tsv", 2))
            proof = native["recovery_check"](directory, meta, current)
            assert current["status"] == "RECOVERY_ADMITTED" and proof["binding_expires_at"] == expiry
            assert time.time() < proof["effective_expires_at"] <= time.time() + 3600
            assert json.loads(plan_path.read_text())["binding"] == binding
            if case == "failed-mutation": assert proof["effective_expires_at"] < int(expiry)
            assert (app / "afterimage").read_text() == "afterimage"
            assert (Path(proof["recovery_continuation"]["path"]).parent / "original-state.json").read_bytes() == json.dumps(state, sort_keys=True, separators=(",", ":")).encode()
            count += 1
            # Exercise the central transition guard on the same isolated proof.
            argv = [php, "server/think", "schema:application", "source-recovery", "--application-recovery=restore",
                    "--scope=source-application", "--owner=fixture-owner", "--thread=fixture-owner",
                    "--session=wc_sess_fixture", "--gate=permanent-app-development",
                    "--source-plan=" + str(plan_path), "--recovery=" + str(workspace)]
            proof_process = run(argv, app)
            assert json.loads(proof_process.stdout)["binding_expires_at"] == expiry
            count += 1
            bad_argv = ["--owner=fixture-other" if item == "--owner=fixture-owner" else item for item in argv]
            refused = run(bad_argv, app, ok=False)
            assert "real native restore" in refused.stderr
            count += 1
            # Its already-admitted lease is a stub, NOT native central permission.
            native["lease"] = lambda lease_id, allow_expired=False: ({"phase": "maintain", "max_seconds": 600}, directory, meta)
            native["verify_native"] = lambda lease_id: None
            current["phase"] = "maintain"; private(directory / native["STATE"], current)
            rejected(lambda: native["window"]("fixture-source", "fixture-owner", "apply"), "forbids fresh migration")
            native["window"]("fixture-source", "fixture-owner", "restore"); count += 1
            # Reload for the next case: restore all real same-source validators.
            exec(compile((SOURCE.parent / "project-resource-application.py").read_text(), native["__file__"], "exec"), native)

    # Full central registration/lease/path admission, isolated and without DB/SSH.
    # Only the PHP command effect is replaced while deriving maintain() outputs.
    with tempfile.TemporaryDirectory(prefix="peanut-central-recovery-output-") as temporary:
        base = Path(temporary).resolve()
        code, project, app = (base / name for name in ("peanut-admin-code", "peanut-admin-project", "peanut-app-multi"))
        for repository in (code, project, app):
            repository.mkdir()
            for arguments in (["git", "init", "-q"], ["git", "config", "user.email", "fixture@example.invalid"],
                              ["git", "config", "user.name", "Workspace Contract Fixture"],
                              ["git", "remote", "add", "origin", "https://github.com/peanut-business/" + repository.name + ".git"]):
                run(arguments, repository)
        (code / "scripts").mkdir()
        for name in ("project-resource-lease", "project-resource-application.py", "project-resource-registry"):
            shutil.copy2(SOURCE.parent / name, code / "scripts" / name)
        run(["git", "add", "."], code); run(["git", "commit", "-qm", "Fixed fixture tools"], code)
        tool_commit = run(["git", "rev-parse", "HEAD"], code).stdout.strip()
        private(app / ".peanut/application-manifest.json", {"schema_version": 2, "protocol": "peanut.application-scaffold.v2",
                "application": {"slug": "peanut-app-multi", "edition": "multi-tenant", "package_identity": "fixture/app"}})
        run(["git", "add", ".peanut/application-manifest.json"], app); run(["git", "commit", "-qm", "Fixed APP identity"], app)
        head = run(["git", "rev-parse", "HEAD"], app).stdout.strip()
        tree = run(["git", "rev-parse", "HEAD^{tree}"], app).stdout.strip()
        private(app / "server/.env", b"FIXTURE_ONLY=1")
        private(app / "server/private/resources/project-resources.json", {"authority": {"role": "application"}, "fixture": True})
        key = b"a" * 64; private(app / "server/private/maintenance/update-verification.key", key)
        installation = {name: private(app / "server/private/installation" / name, {"fixture": True})
                        for name in ("installed.json", "baseline.json")}
        (project / "resources").mkdir()
        (project / "repos.yaml").write_text(
            "schema_version: 3\nproject_id: peanut-admin\nrepositories:\n"
            "  project:\n    repository_id: peanut-admin-project\n"
            "    remote: https://github.com/peanut-business/peanut-admin-project.git\n"
            "  application:\n    repository_id: peanut-admin-code\n"
            "    remote: https://github.com/peanut-business/peanut-admin-code.git\n")
        receipt_root = base / "receipts"; receipt_root.mkdir(mode=0o700)
        review_path = project / "terminal-review.json"
        endpoint = {"endpoint_id": "fixture-app-host", "host": "127.0.0.1", "port": 23306, "consumers": ["host"]}
        descriptor = {"stable_resource_id": "fixture-app-db", "database": "fixture_app", "version": "8.4.10",
            "environment": "development", "environments": ["local-multi-tenant-demo"], "deployment_modes": ["multi-tenant"],
            "upstream_endpoint": endpoint, "application_identity": {"root": str(app)},
            "allocation_evidence": {"server_uuid": "00000000-0000-0000-0000-000000000001"},
            "lease_gate": "permanent-app-development",
            "lease_policy": {"mode": "schema", "physical_endpoint": "127.0.0.1:23306",
                "physical_resource_id": "fixture-mysql", "max_executors": 8,
                "server_resources": ["mysql-resource=fixture-mysql", "docker-container=fixture-mysql", "docker-volume=fixture-volume"]}}
        operation = {"stable_resource_id": "fixture-maintenance", "protocol": "peanut.application-first-install-operation.v1",
            "phase": "maintain", "owner": "fixture-native-owner", "thread": "fixture-native-owner", "max_seconds": 300,
            "php_executable": php, "terminal_review": {"path": str(review_path)},
            "tool_source": {"root": str(code), "common_dir": str(code / ".git"), "commit": tool_commit,
                "files": {"scripts/" + name: hashlib.sha256((code / "scripts" / name).read_bytes()).hexdigest()
                          for name in ("project-resource-lease", "project-resource-application.py", "project-resource-registry")}},
            "application": {"root": str(app), "commit": head, "tree": tree, "edition": "multi-tenant",
                "slug": "peanut-app-multi", "package_identity": "fixture/app",
                "manifest_sha256": hashlib.sha256((app / ".peanut/application-manifest.json").read_bytes()).hexdigest()},
            "configuration": {name: hashlib.sha256((app / name).read_bytes()).hexdigest()
                              for name in ("server/.env", "server/private/resources/project-resources.json")},
            "installation": installation, "database": {"resource_id": "fixture-app-db", "name": "fixture_app",
                "upstream_endpoint": endpoint, "environment": "local-multi-tenant-demo", "consumer": "host",
                "version": "8.4.10", "server_uuid": descriptor["allocation_evidence"]["server_uuid"], "principal": "fixture_user@%"},
            "output_root": str(base / "protected"), "receipt_root": str(receipt_root),
            "maintenance": {"kind": "application-schema", "session": "wc_sess_fixture", "backup_tool": "fixture-backup",
                            "metadata_tool": "", "review": str(project / "maintenance-review.json")}}
        registry = project / "resources/project-resources.json"
        private(registry, {"schema_version": 1, "project_id": "peanut-admin", "authority": {"role": "maintainer"},
                           "resources": {"databases": [descriptor], "application_operations": [operation]}})
        run(["git", "add", "."], project); run(["git", "commit", "-qm", "Registered central operation"], project)
        previous_registry = os.environ.get("PEANUT_RESOURCE_REGISTRY")
        os.environ["PEANUT_RESOURCE_REGISTRY"] = str(registry)
        try:
            tool = ["bash", str(code / "scripts/project-resource-lease")]
            run(tool + ["claim", "--lease", "central-maintenance", "--owner", operation["owner"],
                 "--thread", operation["thread"], "--candidate", head, "--candidate-repository", str(app),
                 "--gate", "permanent-app-development", "--application-operation", operation["stable_resource_id"]], code)
            run(tool + ["show", "--lease", "central-maintenance", "--verify-locks"], code)
            native["__file__"] = str(code / "scripts/project-resource-application.py")
            exec(compile(Path(native["__file__"]).read_text(), native["__file__"], "exec"), native)
            admitted, directory, metadata = native["lease"]("central-maintenance")
            assert admitted == operation
            resources_sha = hashlib.sha256((directory / "resources.tsv").read_bytes()).hexdigest()
            binding = {"source": {"root": str(app), "head": head, "tree": tree, "kind": "generated-application"},
                "registry_sha256": operation["configuration"]["server/private/resources/project-resources.json"],
                "environment_sha256": operation["configuration"]["server/.env"],
                "authority": {"lease": "central-maintenance", "owner": operation["owner"], "thread": operation["thread"],
                    "session": "wc_sess_fixture", "gate": "permanent-app-development",
                    "expires_at": int(time.time()) + 300, "resources_sha256": resources_sha}}
            plan = {"protocol": "peanut.source-development-schema-plan.v1", "maintenance_scope": "source-application",
                    "execution_admitted": False, "binding": binding}
            calls = []
            real_command = native["command"]
            def php_effect_only(operation, directory, value, lease_id, argv, **kwargs):
                calls.append(argv)
                return json.dumps(plan if "--plan=" not in " ".join(argv) else {"fixture_capture": True}).encode()
            native["command"] = php_effect_only
            value = {}
            native["maintain"](admitted, directory, value, "central-maintenance", "plan")
            native["maintain"](admitted, directory, value, "central-maintenance", "capture")
            native["command"] = real_command
            backup_root = Path(next(arg.split("=", 1)[1] for arg in calls[-1] if arg.startswith("--backup-output=")))
            assert backup_root == Path(operation["output_root"]) / "backup"
            assert app not in backup_root.parents and code not in backup_root.parents
            backup_root.mkdir(mode=0o700)
            workspace = backup_root / "application-recovery"; workspace.mkdir(mode=0o700)
            plan_path = Path(next(arg.split("=", 1)[1] for arg in calls[-1] if arg.startswith("--plan=")))
            assert plan_path == Path(operation["output_root"]) / "plan.json"
            native_plan = workspace / "native-plan.json"; plan_sha = private(native_plan, json.loads(plan_path.read_text()))
            tool_path = workspace / "backup-tool.json"; private(tool_path, {"fixture": True})
            journal_path = app / "server/runtime/upgrade/source-application-fixture.json"
            context_path = workspace / "source-application-context.json"
            signed(context_path, {"workspace": str(workspace), "binding": binding,
                "native_plan": {"path": str(native_plan), "sha256": plan_sha},
                "backup_tool": {"path": str(tool_path)}, "journal_path": str(journal_path)})
            signed(journal_path, {"phase": "failed", "activation_started": False, "unknown": True})
            private(workspace / "backup.json", {"fixture": True}); private(workspace / "backup.json.hmac", b"fixture")
            child = subprocess.Popen([sys.executable, "-c", "import time,sys; time.sleep(.3); sys.exit(7)"])
            birth = native["process_birth"](child.pid); assert child.wait() == 7
            state = {"protocol": "peanut.application-operation-state.v1", "phase": "maintain", "lease": "central-maintenance",
                "owner": operation["owner"], "pid": child.pid, "process_birth": birth, "child_pid": None,
                "status": "UNKNOWN", "binding_expires_at": binding["authority"]["expires_at"],
                "actual_process_terminal": True, "completed_step": "rebind", "started_at": int(time.time()) - 60,
                "operation_sha256": next(iter(native["resource_map"](native["table"](directory / "resources.tsv", 3))[native["CONTRACT"]]))}
            original_state_sha = private(directory / native["STATE"], state)
            terminal = workspace / "terminal.json"; private(terminal, {"actual_process_terminal": True, "exit_code": 7})
            approved = [context_path, native_plan, plan_path, tool_path, workspace / "backup.json",
                        workspace / "backup.json.hmac", journal_path, terminal]
            review = {"protocol": "peanut.source-application-terminal-reconciliation.v1", "verdict": "ORIGINAL_PLAN_RECOVERY_ONLY",
                "reviewer_thread": "fixture-independent-reviewer", "owner": operation["owner"], "lease": "central-maintenance",
                "state_sha256": original_state_sha, "metadata_sha256": hashlib.sha256((directory / "metadata.tsv").read_bytes()).hexdigest(),
                "actual_process_terminal": True, "remote_effects_known": True, "effective_expires_at": int(time.time()) + 900,
                "workspace": str(workspace), "plan_path": str(plan_path), "terminal_evidence": [{"path": str(terminal)}],
                "approved_sha256": {str(p): hashlib.sha256(p.read_bytes()).hexdigest() for p in approved}}
            for field, wrong in (("workspace", str(base / "wrong/application-recovery")), ("plan_path", str(native_plan))):
                bad = deepcopy(review); bad[field] = wrong; private(review_path, bad)
                run(["git", "add", "terminal-review.json"], project); run(["git", "commit", "-qm", "One-field wrong-output negative"], project)
                rejected(lambda: native["admit_recovery"]("central-maintenance", operation["owner"]), "registered maintenance producer")
                assert hashlib.sha256((directory / native["STATE"]).read_bytes()).hexdigest() == original_state_sha
            private(review_path, review); run(["git", "add", "terminal-review.json"], project)
            run(["git", "commit", "-qm", "Exact producer output admission fixture"], project)
            native["admit_recovery"]("central-maintenance", operation["owner"]); count += 1
            rebound = native["live_state"](directory)
            proof = native["recovery_check"](directory, dict(native["table"](directory / "metadata.tsv", 2)), rebound)
            assert json.loads(Path(proof["recovery_continuation"]["path"]).read_text())["workspace"] == str(workspace)
            assert json.loads(plan_path.read_text()) == plan and hashlib.sha256(native_plan.read_bytes()).hexdigest() == plan_sha
            # Normal fixture-only terminal release; never fabricate COMPLETED to clean up.
            private(review_path, {"verdict": "SAFE_TO_RELEASE", "lease": "central-maintenance",
                "state_sha256": hashlib.sha256((directory / native["STATE"]).read_bytes()).hexdigest(),
                "reviewer_thread": "fixture-independent-reviewer", "actual_process_terminal": True})
            run(["git", "add", "terminal-review.json"], project); run(["git", "commit", "-qm", "Terminal fixture custody"], project)
            run(tool + ["release", "--lease", "central-maintenance", "--owner", operation["owner"]], code)
        finally:
            if previous_registry is None: os.environ.pop("PEANUT_RESOURCE_REGISTRY", None)
            else: os.environ["PEANUT_RESOURCE_REGISTRY"] = previous_registry

    if workspace_only:
        print(json.dumps({"group": "source-recovery-workspace-only", "assertions": count,
            "central": "actual isolated registration/claim/physical locks/producer output/admit/release; only PHP command effect stubbed",
            "ordinary": "original leased backup-output and original plan/authority source contracts",
            "IH02": "unchanged exact source/High evidence REUSED; preimage group NOT_RERUN",
            "real_APP_DB_SSH_restore_open": "NOT_RUN", "legacy_parent_group": "NOT_RUN; original FAIL retained"}))
        return
    with tempfile.TemporaryDirectory(prefix="peanut-source-preimage-") as temporary:
        server = Path(temporary) / "server"; storage = server / "public/storage"; storage.mkdir(parents=True)
        (server / "private").mkdir()  # Required real parent; the protected storage child remains absent.
        storage.chmod(0o700); (storage / "customer.txt").write_bytes(b"customer")
        workspace = Path(temporary) / "workspace"; (workspace / "recovery").mkdir(parents=True)
        database = Path(temporary) / "simulated-database.sql"; database.write_bytes(b"row=original")
        expected = hashlib.sha256(database.read_bytes()).hexdigest()
        private(workspace / "backup.json", {"registered_evidence": {"raw_dump_sha256": expected}})
        provider["root_client"] = lambda server, workspace, selected, operation, output: (output.write_bytes(database.read_bytes()), {"fixture": True})[1]
        record = {"storage": {"public/storage": {"inventory": provider["storage_inventory"](storage), "root_mode": 0o700},
                              "private/storage": None}}
        provider["assert_source_rehearsal_preimage"](server, workspace, {}, record); count += 1
        database.write_bytes(b"row=changed")
        rejected(lambda: provider["assert_source_rehearsal_preimage"](server, workspace, {}, record), "schema or data differs")
        assert database.read_bytes() == b"row=changed" and (storage / "customer.txt").read_bytes() == b"customer"
        database.write_bytes(b"row=original"); (storage / "customer.txt").write_bytes(b"changed")
        rejected(lambda: provider["assert_source_rehearsal_preimage"](server, workspace, {}, record), "storage differs")
        assert (storage / "customer.txt").read_bytes() == b"changed"
        (storage / "customer.txt").write_bytes(b"customer"); storage.chmod(0o755)
        rejected(lambda: provider["assert_source_rehearsal_preimage"](server, workspace, {}, record), "storage differs")
        storage.chmod(0o700); (server / "private/storage").mkdir(parents=True)
        rejected(lambda: provider["assert_source_rehearsal_preimage"](server, workspace, {}, record), "storage appeared")
    print(json.dumps({"group": "source-recovery-guards-only", "assertions": count,
        "scope": "isolated Python source contracts; simulated DB dump and synthetic independent review; actual child exit7/file mutation",
        "native_APP_DB_SSH_restore_open": "NOT_RUN", "legacy_parent_group": "NOT_RUN; original FAIL retained"}))


if __name__ == "__main__" and __import__("sys").argv[1:] in (["--source-recovery-guards-only"], ["--source-recovery-workspace-only"]):
    source_recovery_guard_contracts(workspace_only=__import__("sys").argv[1] == "--source-recovery-workspace-only")
    raise SystemExit(0)


with tempfile.TemporaryDirectory(prefix="peanut-resource-lease-parent-") as temporary:
    root = Path(temporary) / "repo"
    root.mkdir()
    run(["git", "init", "-q"], root)
    run(["git", "config", "user.email", "lease-test@example.invalid"], root)
    run(["git", "config", "user.name", "Lease Test"], root)
    (root / "seed.txt").write_text("seed\n", encoding="utf-8")
    run(["git", "add", "seed.txt"], root)
    run(["git", "commit", "-qm", "seed"], root)
    candidate = run(["git", "rev-parse", "HEAD"], root).stdout.strip()

    scripts = root / "scripts"
    scripts.mkdir()
    lease_tool = scripts / "project-resource-lease"
    shutil.copy2(SOURCE, lease_tool)
    lease_tool.chmod(0o755)
    tool = [str(lease_tool)]
    owner = "wc_sess_parent_test"
    thread = owner
    worktree = str(root.resolve())

    # An empty historical lease directory is not an active record. It remains
    # untouched; nonempty partial or malformed records must still block claims.
    leases = root / '.git/peanut-admin-resource-leases/leases'
    historical_empty = leases / 'historical-empty'
    historical_empty.mkdir(parents=True)
    historical_archive = leases / 'historical-archive'
    historical_archive.mkdir()
    archive_snapshot = b'{"historical_registry_snapshot":true}\n'
    (historical_archive / 'registry.json').write_bytes(archive_snapshot)

    run(tool + [
        "claim",
        "--lease", "controller-test",
        "--owner", owner,
        "--thread", thread,
        "--candidate", candidate,
        "--gate", "controller",
        "--resource", "controller-task=lease-parent-test",
    ], root)
    assert historical_empty.is_dir() and list(historical_empty.iterdir()) == []
    assert (historical_archive / 'registry.json').read_bytes() == archive_snapshot
    malformed = leases / 'malformed-record'
    malformed.mkdir()
    probe_claim = tool + ['claim', '--lease', 'malformed-probe', '--owner', owner,
                         '--candidate', candidate, '--parent-lease', 'controller-test',
                         '--gate', 'runtime', '--resource', 'runtime-instance=malformed-probe']
    historical_lock = leases.parent / 'resources' / hashlib.sha256(b'runtime-instance\thistorical-reference').hexdigest()
    historical_lock.mkdir()
    (historical_lock / 'type').write_text('runtime-instance\n')
    (historical_lock / 'value').write_text('historical-reference\n')
    for historical in [historical_empty, historical_archive]:
        (historical_lock / 'lease').write_text(historical.name + '\n')
        assert 'cannot establish active lease boundary' in run(probe_claim, root, ok=False).stderr
    shutil.rmtree(historical_lock)  # Only this isolated test-owned lock reference.
    (malformed / 'resources.tsv').write_text('partial\n')
    assert 'cannot establish active lease boundary' in run(probe_claim, root, ok=False).stderr
    (malformed / 'resources.tsv').unlink()
    (malformed / 'partial').write_text('incomplete\n')
    assert 'cannot establish active lease boundary' in run(probe_claim, root, ok=False).stderr
    (malformed / 'metadata.tsv').write_text('unexpected\tvalue\n')
    assert 'cannot establish active lease boundary' in run(probe_claim, root, ok=False).stderr
    (malformed / 'metadata.tsv').write_text('status\tACTIVE\nexpires_at\tnot-a-time\n')
    assert 'cannot establish active lease boundary' in run(probe_claim, root, ok=False).stderr
    active_metadata = (leases / 'controller-test/metadata.tsv').read_text().replace('lease\tcontroller-test\n', 'lease\tmalformed-record\n')
    (malformed / 'metadata.tsv').write_text(active_metadata)
    assert 'cannot establish active lease boundary' in run(probe_claim, root, ok=False).stderr
    shutil.rmtree(malformed)  # Only this isolated test-owned partial record.
    run(tool + [
        "claim",
        "--lease", "runtime-test",
        "--owner", owner,
        "--thread", thread,
        "--candidate", candidate,
        "--parent-lease", "controller-test",
        "--gate", "runtime",
        "--resource", "mysql-db=lease_parent_test",
    ], root)

    child = run(tool + ["show", "--lease", "runtime-test"], root).stdout
    assert "parent_lease\tcontroller-test\n" in child
    assert "mysql-db\tlease_parent_test\n" in child
    assert child.count(f"worktree\t{worktree}\n") == 2, child
    run(tool + ["show", "--lease", "runtime-test", "--verify-locks"], root)
    child_lock = root / ".git/peanut-admin-resource-leases/resources" / hashlib.sha256(b"mysql-db\tlease_parent_test").hexdigest()
    for field in ("lease", "type", "value"):
        path = child_lock / field
        original = path.read_text()
        path.write_text("foreign\n")
        assert "lock holder mismatch" in run(tool + ["show", "--lease", "runtime-test", "--verify-locks"], root, ok=False).stderr
        # Ordinary show remains a read of the recorded lease, without the new opt-in check.
        run(tool + ["show", "--lease", "runtime-test"], root)
        path.write_text(original)

    leases = root / ".git/peanut-admin-resource-leases/leases"
    metadata_path = leases / "controller-test/metadata.tsv"
    original_metadata = metadata_path.read_text()
    parent_expiry = next(row.split("\t")[1] for row in original_metadata.splitlines() if row.startswith("expires_at\t"))
    run(tool + ["renew", "--lease", "runtime-test", "--owner", owner, "--ttl", "86400"], root)
    child_metadata = (leases / "runtime-test/metadata.tsv").read_text()
    assert f"expires_at\t{parent_expiry}\n" in child_metadata
    assert "worktree\t" not in (leases / "runtime-test/resources.tsv").read_text()

    def child_claim(**overrides: str) -> subprocess.CompletedProcess[str]:
        arguments = {"lease": "invalid-child", "owner": owner, "thread": thread,
                     "candidate": candidate, "parent-lease": "controller-test", "gate": "invalid-runtime"}
        arguments.update(overrides)
        options = [item for key, value in arguments.items() for item in ("--" + key, value)]
        return run(tool + ["claim"] + options, root, ok=False)

    assert "direct worktree" in child_claim(resource="worktree=" + worktree).stderr
    assert "nested parent" in child_claim(**{"parent-lease": "runtime-test"}).stderr
    assert "does not exist" in child_claim(**{"parent-lease": "missing-parent"}).stderr
    mutations = {
        "lease": "another-id", "owner": "another-owner", "thread": "another-thread",
        "candidate": "f" * 40, "candidate_repository": "/tmp/another", "worktree": "/tmp/another",
        "status": "RELEASED", "parent_lease": "nested", "created_at": str(int(time.time()) + 100),
        "expires_at": str(int(time.time()) - 1),
    }
    for key, value in mutations.items():
        rows = original_metadata.splitlines()
        metadata_path.write_text("\n".join(value_row if not value_row.startswith(key + "\t") else key + "\t" + value for value_row in rows) + "\n")
        child_claim()
        run(tool + ["renew", "--lease", "runtime-test", "--owner", owner], root, ok=False)
        metadata_path.write_text(original_metadata)
    metadata_path.write_text(original_metadata + "extra\tforbidden\n")
    child_claim()
    metadata_path.write_text(original_metadata)
    lock = root / ".git/peanut-admin-resource-leases/resources" / hashlib.sha256(("worktree\t" + worktree).encode()).hexdigest()
    for key in ("lease", "type", "value"):
        lock_file = lock / key
        original = lock_file.read_text()
        lock_file.write_text("wrong\n")
        child_claim()
        lock_file.write_text(original)
    parent_resources = leases / "controller-test/resources.tsv"
    original_resources = parent_resources.read_text()
    parent_resources.write_text(("0" if original_resources[0] != "0" else "1") + original_resources[1:])
    child_claim()
    parent_resources.write_text(original_resources)
    metadata_backup = metadata_path.with_suffix(".backup")
    metadata_path.rename(metadata_backup)
    metadata_path.symlink_to(metadata_backup)
    child_claim()
    metadata_path.unlink()
    metadata_backup.rename(metadata_path)
    child_metadata_path = leases / "runtime-test/metadata.tsv"
    child_metadata_path.write_text(child_metadata.replace(f"expires_at\t{parent_expiry}", f"expires_at\t{int(time.time()) - 1}"))
    assert "lease is expired" in run(tool + ["renew", "--lease", "runtime-test", "--owner", owner], root, ok=False).stderr
    child_metadata_path.write_text(child_metadata)

    blocked = run(
        tool + ["release", "--lease", "controller-test", "--owner", owner],
        root,
        ok=False,
    )
    assert "active child lease runtime-test" in blocked.stderr

    wrong_owner = run(tool + [
        "claim",
        "--lease", "wrong-owner-test",
        "--owner", "another-owner",
        "--thread", "another-owner",
        "--candidate", candidate,
        "--parent-lease", "controller-test",
        "--gate", "runtime",
        "--resource", "mysql-db=wrong_owner_test",
    ], root, ok=False)
    assert "parent lease belongs to another owner" in wrong_owner.stderr

    run(tool + ["release", "--lease", "runtime-test", "--owner", owner], root)
    run(tool + ["release", "--lease", "controller-test", "--owner", owner], root)

    # Pure filesystem lease contracts: never connect to the registered database.
    physical = "127.0.0.1:23306"
    policy = {"mode": "schema", "physical_endpoint": physical,
              "physical_resource_id": "fixture-physical", "max_executors": 2,
              "server_resources": ["mysql-resource=fixture-physical", "docker-container=fixture-container", "docker-volume=fixture-volume"]}
    descriptor = {"stable_resource_id": "fixture-db", "lease_gate": "schema-test",
                  "database": "schema_one", "synthetic_databases": {"test": ["schema_two", "schema_three"]},
                  "upstream_endpoint": {"endpoint_id": "fixture-host", "host": "127.0.0.1", "port": 23306},
                  "lease_policy": policy}
    alias = dict(descriptor, stable_resource_id="fixture-alias",
                 upstream_endpoint=dict(descriptor["upstream_endpoint"], endpoint_id="alias-host"))
    registry = root / "registry.json"
    registry.write_text(json.dumps({"resources": {"databases": [descriptor, alias]}}))
    os.environ["PEANUT_RESOURCE_REGISTRY"] = str(registry)
    digest = hashlib.sha256(registry.read_bytes()).hexdigest()
    run(tool + ["claim", "--lease", "schema-controller", "--owner", owner, "--candidate", candidate,
                "--gate", "controller", "--resource", "controller-task=schema-test"], root)

    def schema_claim(lease: str, schema_name: str, *, resource="fixture-db", endpoint="fixture-host", **overrides):
        values = {"resource-id": resource, "endpoint": endpoint, "mysql-db": schema_name,
                  "database": schema_name, "registry-sha256": digest, "resource-scope": "same-scope",
                  "mysql-schema": physical + "/" + schema_name}
        values.update(overrides)
        return tool + ["claim", "--lease", lease, "--owner", owner, "--candidate", candidate,
                       "--parent-lease", "schema-controller", "--gate", "schema-test"] + [
            item for k, v in values.items() for item in ("--resource", k + "=" + v)]

    legacy_base = tool + ["claim", "--lease", "legacy-server", "--owner", owner, "--candidate", candidate,
                          "--parent-lease", "schema-controller", "--gate", "legacy",
                          "--resource", "registry-sha256=" + digest]
    management = ["port=23306", "mysql-server=" + physical, *policy["server_resources"]]
    legacy = legacy_base + [item for resource in management for item in ("--resource", resource)]
    run(legacy, root)
    run(tool + ["show", "--lease", "legacy-server", "--verify-locks"], root)
    run(tool + ["renew", "--lease", "legacy-server", "--owner", owner], root)
    assert "server-exclusive" in run(schema_claim("schema-one", "schema_one"), root, ok=False).stderr
    run(tool + ["release", "--lease", "legacy-server", "--owner", owner], root)
    legacy_database = legacy_base + ["--resource", "mysql-db=schema_two"]
    run(legacy_database, root)
    assert "server-exclusive" in run(schema_claim("schema-one", "schema_one"), root, ok=False).stderr
    run(tool + ["release", "--lease", "legacy-server", "--owner", owner], root)
    run(schema_claim("schema-one", "schema_one"), root)
    run(schema_claim("schema-two", "schema_two"), root)
    assert "overlapping/exclusive physical schema access" in run(schema_claim("alias-one", "schema_one", resource="fixture-alias", endpoint="alias-host"), root, ok=False).stderr
    assert "registered maximum schema runtime executors reached" in run(schema_claim("schema-three", "schema_three"), root, ok=False).stderr
    assert "mismatch" in run(schema_claim("wrong-endpoint", "schema_three", endpoint="foreign"), root, ok=False).stderr
    assert "digest" in run(schema_claim("wrong-digest", "schema_three", **{"registry-sha256": "0" * 64}), root, ok=False).stderr
    assert "coverage" in run(schema_claim("wrong-coverage", "schema_three", **{"database": "foreign"}), root, ok=False).stderr
    for resource in ["mysql-db=schema_three", "database=schema_three"]:
        assert "active schema" in run(legacy_base + ["--resource", resource], root, ok=False).stderr
    # Every management alias keeps its negative coverage. Incomplete bundles
    # fail before runtime conflict; the complete legal bundle reaches that gate.
    for resource in management:
        assert "complete registered service/container/volume/port lock set" in run(
            legacy_base + ["--resource", resource], root, ok=False).stderr
    assert "unreturned runtime/management lease" in run(legacy, root, ok=False).stderr
    run(tool + ["show", "--lease", "schema-one", "--verify-locks"], root)
    run(tool + ["renew", "--lease", "schema-one", "--owner", owner], root)
    schema_lock = root / ".git/peanut-admin-resource-leases/resources" / hashlib.sha256(("mysql-schema\t" + physical + "/schema_one").encode()).hexdigest()
    holder = schema_lock / "lease"
    saved = holder.read_text(); holder.write_text("foreign\n")
    # Policy sees the real foreign physical-schema holder before show verifies rows.
    assert "overlapping/exclusive physical schema access: foreign" in run(tool + ["show", "--lease", "schema-one"], root, ok=False).stderr
    holder.write_text(saved)
    run(tool + ["release", "--lease", "schema-one", "--owner", owner], root)
    run(tool + ["release", "--lease", "schema-two", "--owner", owner], root)
    policy["server_resources"] = ["mysql-resource=fixture-physical"]
    registry.write_text(json.dumps({"resources": {"databases": [descriptor, alias]}}))
    digest = hashlib.sha256(registry.read_bytes()).hexdigest()
    assert "management mapping" in run(schema_claim("missing-mapping", "schema_one"), root, ok=False).stderr
    run(tool + ["release", "--lease", "schema-controller", "--owner", owner], root)
    del os.environ["PEANUT_RESOURCE_REGISTRY"]


# Cross-Git admission uses the same central lock registry. These are isolated
# protocol fixtures only: no native install, remote client, DB or service runs.
with tempfile.TemporaryDirectory(prefix="peanut-application-lease-") as temporary:
    import runpy
    import sys

    workspace = Path(temporary).resolve()
    code = workspace / "peanut-admin-code"
    project = workspace / "peanut-admin-project"
    app = workspace / "peanut-app-multi"
    for repository in (code, project, app):
        repository.mkdir()
        run(["git", "init", "-q"], repository)
        run(["git", "config", "user.email", "fixture@example.invalid"], repository)
        run(["git", "config", "user.name", "Lease fixture"], repository)
        run(["git", "remote", "add", "origin",
             "https://github.com/peanut-business/" + repository.name + ".git"], repository)
    (code / "scripts").mkdir()
    for name in ("project-resource-lease", "project-resource-application.py", "project-resource-registry"):
        shutil.copy2(SOURCE.parent / name, code / "scripts" / name)
    run(["git", "add", "scripts"], code)
    run(["git", "commit", "-qm", "Fixed central operation tools"], code)
    tool_commit = run(["git", "rev-parse", "HEAD"], code).stdout.strip()
    (app / ".peanut").mkdir()
    manifest_path = app / ".peanut/application-manifest.json"
    manifest_path.write_text(json.dumps({
        "schema_version": 2, "protocol": "peanut.application-scaffold.v2",
        "application": {"slug": "peanut-app-multi", "package_identity": "fixture/app",
                        "edition": "multi-tenant"},
    }) + "\n")
    run(["git", "add", ".peanut/application-manifest.json"], app)
    run(["git", "commit", "-qm", "Fixed actual APP identity"], app)
    app_commit = run(["git", "rev-parse", "HEAD"], app).stdout.strip()
    app_tree = run(["git", "rev-parse", "HEAD^{tree}"], app).stdout.strip()
    (app / "server/private/resources").mkdir(parents=True)
    configuration = {}
    for name in ("server/.env", "server/private/resources/project-resources.json"):
        path = app / name
        path.write_text("fixture-no-secret\n")
        path.chmod(0o600)
        configuration[name] = hashlib.sha256(path.read_bytes()).hexdigest()
    (project / "resources").mkdir()
    (project / "repos.yaml").write_text(
        "schema_version: 3\nproject_id: peanut-admin\nrepositories:\n"
        "  project:\n    repository_id: peanut-admin-project\n"
        "    remote: https://github.com/peanut-business/peanut-admin-project.git\n"
        "  application:\n    repository_id: peanut-admin-code\n"
        "    remote: https://github.com/peanut-business/peanut-admin-code.git\n")
    receipt_root = workspace / "receipts"
    receipt_root.mkdir(mode=0o700)
    terminal_review = project / "terminal-review.json"
    operation_id = "fixture-first-install-protection"
    physical = "127.0.0.1:23306"
    endpoint = {"endpoint_id": "fixture-app-host", "host": "127.0.0.1", "port": 23306, "consumers": ["host"]}
    descriptor = {
        "stable_resource_id": "fixture-app-db", "database": "fixture_app", "version": "8.4.10",
        "environment": "development", "environments": ["local-multi-tenant-demo"],
        "deployment_modes": ["multi-tenant"], "upstream_endpoint": endpoint,
        "application_identity": {"root": str(app)},
        "allocation_evidence": {"server_uuid": "00000000-0000-0000-0000-000000000001"},
        "lease_gate": "permanent-app-development",
        "lease_policy": {"mode": "schema", "physical_endpoint": physical,
                         "physical_resource_id": "fixture-mysql", "max_executors": 8,
                         "server_resources": ["mysql-resource=fixture-mysql",
                                              "docker-container=fixture-mysql", "docker-volume=fixture-volume"]},
    }
    php = shutil.which("php")
    assert php is not None, "Use the registered project-env with PHP available"
    operation = {
        "stable_resource_id": operation_id, "protocol": "peanut.application-first-install-operation.v1",
        "phase": "protect", "owner": "fixture-native-owner", "thread": "fixture-native-owner",
        "max_seconds": 300, "php_executable": str(Path(php).resolve()),
        "terminal_review": {"path": str(terminal_review)},
        "tool_source": {"root": str(code), "common_dir": str(code / ".git"), "commit": tool_commit,
                        "files": {name: hashlib.sha256((code / name).read_bytes()).hexdigest()
                                  for name in ("scripts/project-resource-lease", "scripts/project-resource-application.py",
                                               "scripts/project-resource-registry")}},
        "application": {"root": str(app), "commit": app_commit, "tree": app_tree,
                        "manifest_sha256": hashlib.sha256(manifest_path.read_bytes()).hexdigest(),
                        "edition": "multi-tenant", "slug": "peanut-app-multi", "package_identity": "fixture/app"},
        "configuration": configuration,
        "database": {"resource_id": "fixture-app-db", "name": "fixture_app", "upstream_endpoint": endpoint,
                     "environment": "local-multi-tenant-demo", "consumer": "host", "version": "8.4.10",
                     "server_uuid": descriptor["allocation_evidence"]["server_uuid"],
                     "principal": "fixture_user@%"},
        "output_root": str(workspace / "protected"),
        "receipt_root": str(receipt_root),
        "backup_client": {"ssh": "/usr/bin/ssh", "ssh_alias": "fixture",
                          "docker": "/fixture/bin/docker", "mysql": "/fixture/bin/mysql",
                          "mysqldump": "/fixture/bin/mysqldump", "container": "fixture-mysql",
                          "credential_path": "/fixture/private.env", "user": "fixture_user"},
    }
    registry = project / "resources/project-resources.json"
    registry.write_text(json.dumps({"schema_version": 1, "project_id": "peanut-admin",
                                   "authority": {"role": "maintainer"},
                                   "resources": {"databases": [descriptor], "application_operations": [operation]}}) + "\n")
    run(["git", "add", "."], project)
    run(["git", "commit", "-qm", "Fixed application operation registration"], project)
    old_registry = os.environ.get("PEANUT_RESOURCE_REGISTRY")
    os.environ["PEANUT_RESOURCE_REGISTRY"] = str(registry)
    try:
        tool = ["bash", str(code / "scripts/project-resource-lease")]
        claim = tool + ["claim", "--lease", "application-protect", "--owner", operation["owner"],
                        "--thread", operation["thread"], "--candidate", app_commit,
                        "--candidate-repository", str(app), "--gate", "permanent-app-development"]
        bad = run(claim + ["--resource", "mysql-schema=" + physical + "/fixture_app"], code, ok=False)
        assert "real candidate worktree" in bad.stderr
        assert not (code / ".git/peanut-admin-resource-leases/leases/application-protect").exists()
        bad = run(claim + ["--application-operation", "unregistered"], code, ok=False)
        assert "not uniquely registered" in bad.stderr
        run(claim + ["--application-operation", operation_id], code)
        proof = run(tool + ["show", "--lease", "application-protect", "--verify-locks"], code).stdout
        assert "candidate\t" + app_commit + "\n" in proof and "worktree\t" + str(app) + "\n" in proof
        assert not (app / ".git/peanut-admin-resource-leases").exists(), "Never split the physical lock registry"
        run(tool + ["renew", "--lease", "application-protect", "--owner", operation["owner"]], code)
        config_path = app / "server/.env"
        previous = config_path.read_bytes()
        config_path.write_bytes(b"fixture-drift\n")
        assert "configuration changed" in run(tool + ["show", "--lease", "application-protect"], code, ok=False).stderr
        config_path.write_bytes(previous)
        helper = runpy.run_path(str(code / "scripts/project-resource-application.py"))
        _, directory, state = helper["window"]("application-protect", operation["owner"])
        assert "still active" in run(tool + ["release", "--lease", "application-protect",
                                            "--owner", operation["owner"]], code, ok=False).stderr
        assert run(tool + ["prune-expired"], code).stdout.strip() == "pruned=0"
        # A live operation does not retain the central mutex: unrelated source
        # claims and its own ordinary renewal remain possible.
        # The fixed tool source itself is protected. A genuinely independent
        # worktree shares the same mutex but may still execute unrelated work.
        assert "resource conflict" in run(tool + ["claim", "--lease", "tool-source-conflict",
            "--owner", "independent-fixture", "--candidate", tool_commit, "--gate", "source-only"], code, ok=False).stderr
        independent = workspace / "independent-code"
        run(["git", "worktree", "add", "--detach", str(independent), tool_commit], code)
        run(tool + ["claim", "--lease", "independent-source", "--owner", "independent-fixture",
                    "--candidate", tool_commit, "--gate", "source-only"], independent)
        run(tool + ["release", "--lease", "independent-source", "--owner", "independent-fixture"], independent)
        run(tool + ["renew", "--lease", "application-protect", "--owner", operation["owner"]], code)
        # Only this isolated fixture synthesizes terminal state. It is not
        # Simulate expiry only in this owned fixture. In-use resources survive
        # expiry, renewal is refused, and no new operation may treat it as free.
        metadata_path = directory / "metadata.tsv"
        metadata_before = metadata_path.read_text()
        metadata_path.write_text("\n".join(
            "expires_at\t" + str(int(time.time())) if row.startswith("expires_at\t") else row
            for row in metadata_before.splitlines()) + "\n")
        assert run(tool + ["prune-expired"], code).stdout.strip() == "pruned=0"
        assert "expired" in run(tool + ["renew", "--lease", "application-protect",
                                        "--owner", operation["owner"]], code, ok=False).stderr
        metadata_path.write_text(metadata_before)
        # evidence that a PHP installer or any database operation completed.
        finished = subprocess.Popen([sys.executable, "-c", "pass"])
        assert finished.wait() == 0
        state.update(status="UNKNOWN", pid=finished.pid, process_birth=None,
                     child_pid=None, actual_process_terminal=True)
        helper["save"](directory / helper["STATE"], state)
        assert "terminal-review.json" in run(
            tool + ["release", "--lease", "application-protect", "--owner", operation["owner"]],
            code, ok=False).stderr
        terminal_review.write_text(json.dumps({
            "verdict": "SAFE_TO_RELEASE", "lease": "application-protect",
            "state_sha256": hashlib.sha256((directory / helper["STATE"]).read_bytes()).hexdigest(),
            "reviewer_thread": "independent-fixture-reviewer", "actual_process_terminal": True,
        }) + "\n")
        terminal_review.chmod(0o600)
        assert "versioned Project result" in run(
            tool + ["release", "--lease", "application-protect", "--owner", operation["owner"]],
            code, ok=False).stderr
        run(["git", "add", "terminal-review.json"], project)
        run(["git", "commit", "-qm", "Independent fixture terminal review"], project)
        run(tool + ["release", "--lease", "application-protect", "--owner", operation["owner"]], code)
        assert not directory.exists() and (receipt_root / "application-protect.json").is_file()
        # Real Git + real PHP parent/child identities, but synthetic installation
        # bytes and no installer/framework/DB/SSH. This checks proof consumption only.
        operation.pop("backup_client")
        operation["phase"] = "maintain"
        operation["maintenance"] = {"kind": "plugin", "operation": "upgrade", "plugin_key": "peanut.workflow",
            "session": "wc_sess_fixture", "backup_tool": "fixture-backup", "metadata_tool": "",
            "review": str(project / "maintenance-review.json")}
        installation = app / "server/private/installation"
        installation.mkdir()
        operation["installation"] = {}
        for name in ("installed.json", "baseline.json"):
            path = installation / name
            path.write_text('{"fixture_only":true}\n'); path.chmod(0o600)
            operation["installation"][name] = hashlib.sha256(path.read_bytes()).hexdigest()
        app_db = json.loads(json.dumps(descriptor))
        app_db["lease_policy"]["native_authority"] = operation["tool_source"]
        app_registry = app / "server/private/resources/project-resources.json"
        app_registry.write_text(json.dumps({"schema_version": 1, "project_id": "peanut-app-multi",
            "authority": {"role": "application"}, "resources": {"databases": [app_db]}}) + "\n")
        app_registry.chmod(0o600)
        operation["configuration"]["server/private/resources/project-resources.json"] = hashlib.sha256(app_registry.read_bytes()).hexdigest()
        registry.write_text(json.dumps({"schema_version": 1, "project_id": "peanut-admin", "authority": {"role": "maintainer"},
            "resources": {"databases": [descriptor], "application_operations": [operation]}}) + "\n")
        run(["git", "add", "resources/project-resources.json"], project)
        run(["git", "commit", "-qm", "Fixed synthetic maintenance proof contract"], project)
        claim = [value if value != "application-protect" else "application-maintain" for value in claim]
        run(claim + ["--application-operation", operation_id], code)
        harness = r"""
import json,os,pathlib,runpy,subprocess,sys
code, app, php, python = map(pathlib.Path, sys.argv[1:])
native=runpy.run_path(str(code/'scripts/project-resource-application.py'))
operation,directory,state=native['window']('application-maintain','fixture-native-owner','plan')
php_program='$gate=$argv[1];for($i=0;!is_file($gate)&&$i<200;$i++){usleep(10000);}if(!is_file($gate)){exit(90);}$p=proc_open(array_slice($argv,2),[0=>["file","/dev/null","r"],1=>STDOUT,2=>STDERR],$pipes);exit(is_resource($p)?proc_close($p):91);'
def consume(label,publish=True):
 gate=code.parent/('gate-'+label)
 args=[str(php),'-r',php_program,'--',str(gate),str(python),str(code/'scripts/project-resource-application.py'),
       'consume','--proof',str(directory),'--application-root',str(app),'--registry',str(app/'server/private/resources/project-resources.json'),
       '--resource-id','fixture-app-db','--endpoint-id','fixture-app-host']
 child=subprocess.Popen(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
 if publish:
  state.update(child_pid=child.pid,child_birth=native['process_birth'](child.pid))
  native['save'](directory/native['STATE'],state)
 gate.write_text('ready')
 output,error=child.communicate(timeout=15)
 return child.returncode,output,error
rc,output,error=consume('valid')
assert rc==0,(rc,error)
assert json.loads(output)['binding_expires_at']==state['binding_expires_at']
# Another PHP child cannot borrow the first child's proof.
rc,_,error=consume('borrow',False)
assert rc!=0 and 'actual native child process' in error,error
state['status']='UNKNOWN'
rc,_,error=consume('unknown')
assert rc!=0 and 'held native APP maintenance window' in error,error
state.update(status='COMPLETED',child_pid=None,actual_process_terminal=True)
native['save'](directory/native['STATE'],state)
print('isolated PHP proof positive + borrower/UNKNOWN refusal; DB NOT_RUN')
"""
        run([sys.executable, "-c", harness, str(code), str(app), str(Path(php).resolve()), sys.executable], code)
        # The actual harness and its PHP children have ended; normal owner release.
        run(tool + ["release", "--lease", "application-maintain", "--owner", operation["owner"]], code)

    finally:
        if old_registry is None:
            os.environ.pop("PEANUT_RESOURCE_REGISTRY", None)
        else:
            os.environ["PEANUT_RESOURCE_REGISTRY"] = old_registry

print("PROJECT-RESOURCE-LEASE-PARENT-001 passed")
