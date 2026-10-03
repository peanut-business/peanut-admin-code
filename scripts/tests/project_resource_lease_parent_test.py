#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
import shutil
import subprocess
import tempfile


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
