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
    historical_lock = leases.parent / 'resources/historical-reference'
    historical_lock.mkdir()
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

    legacy = tool + ["claim", "--lease", "legacy-server", "--owner", owner, "--candidate", candidate,
                     "--parent-lease", "schema-controller", "--gate", "legacy", "--resource", "port=23306"]
    run(legacy, root)
    assert "server-exclusive" in run(schema_claim("schema-one", "schema_one"), root, ok=False).stderr
    run(tool + ["release", "--lease", "legacy-server", "--owner", owner], root)
    legacy_database = legacy[:-2] + ["--resource", "mysql-db=schema_two"]
    run(legacy_database, root)
    assert "server-exclusive" in run(schema_claim("schema-one", "schema_one"), root, ok=False).stderr
    run(tool + ["release", "--lease", "legacy-server", "--owner", owner], root)
    run(schema_claim("schema-one", "schema_one"), root)
    run(schema_claim("schema-two", "schema_two"), root)
    assert "same physical schema" in run(schema_claim("alias-one", "schema_one", resource="fixture-alias", endpoint="alias-host"), root, ok=False).stderr
    assert "maximum two" in run(schema_claim("schema-three", "schema_three"), root, ok=False).stderr
    assert "mismatch" in run(schema_claim("wrong-endpoint", "schema_three", endpoint="foreign"), root, ok=False).stderr
    assert "digest" in run(schema_claim("wrong-digest", "schema_three", **{"registry-sha256": "0" * 64}), root, ok=False).stderr
    assert "coverage" in run(schema_claim("wrong-coverage", "schema_three", **{"database": "foreign"}), root, ok=False).stderr
    for resource in ["port=23306", "mysql-db=schema_three", "database=schema_three", "mysql-server=" + physical, "mysql-resource=fixture-physical",
                     "docker-container=fixture-container", "docker-volume=fixture-volume"]:
        command = legacy[:-2] + ["--resource", resource, "--resource", "registry-sha256=" + digest]
        assert "active schema" in run(command, root, ok=False).stderr
    run(tool + ["show", "--lease", "schema-one", "--verify-locks"], root)
    run(tool + ["renew", "--lease", "schema-one", "--owner", owner], root)
    schema_lock = root / ".git/peanut-admin-resource-leases/resources" / hashlib.sha256(("mysql-schema\t" + physical + "/schema_one").encode()).hexdigest()
    holder = schema_lock / "lease"
    saved = holder.read_text(); holder.write_text("foreign\n")
    assert "lock holder mismatch" in run(tool + ["show", "--lease", "schema-one"], root, ok=False).stderr
    holder.write_text(saved)
    run(tool + ["release", "--lease", "schema-one", "--owner", owner], root)
    run(tool + ["release", "--lease", "schema-two", "--owner", owner], root)
    policy["server_resources"] = ["mysql-resource=fixture-physical"]
    registry.write_text(json.dumps({"resources": {"databases": [descriptor, alias]}}))
    digest = hashlib.sha256(registry.read_bytes()).hexdigest()
    assert "management mapping" in run(schema_claim("missing-mapping", "schema_one"), root, ok=False).stderr
    run(tool + ["release", "--lease", "schema-controller", "--owner", owner], root)
    del os.environ["PEANUT_RESOURCE_REGISTRY"]

print("PROJECT-RESOURCE-LEASE-PARENT-001 passed")
