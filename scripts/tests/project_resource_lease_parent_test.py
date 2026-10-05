#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
import shutil
import subprocess
import tempfile
import hashlib
import time


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

    run(tool + [
        "claim",
        "--lease", "controller-test",
        "--owner", owner,
        "--thread", thread,
        "--candidate", candidate,
        "--gate", "controller",
        "--resource", "controller-task=lease-parent-test",
    ], root)
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

print("PROJECT-RESOURCE-LEASE-PARENT-001 passed")
