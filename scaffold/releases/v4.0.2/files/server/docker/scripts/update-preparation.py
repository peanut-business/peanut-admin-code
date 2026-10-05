#!/usr/bin/env python3
"""Bind a checked target Composer installation to the existing update plan."""

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys


def ordinary(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1:
        raise ValueError("dependency input is linked, missing or multiply linked")


def durable(path, data, replace=False):
    temporary = path.with_name(path.name + ".next") if replace else path
    with temporary.open("xb") as output:
        os.fchmod(output.fileno(), 0o600)
        output.write(json.dumps(data, sort_keys=True).encode())
        output.flush()
        os.fsync(output.fileno())
    if replace:
        os.replace(temporary, path)
    directory = os.open(path.parent, os.O_RDONLY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def sync_dir(path):
    descriptor = os.open(path, os.O_RDONLY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def move(source, target):
    os.replace(source, target)
    sync_dir(source.parent)
    if source.parent != target.parent:
        sync_dir(target.parent)


def tree(root):
    if root.is_symlink() or not root.is_dir():
        raise ValueError("vendor tree is missing or linked")
    rows = []
    for base, dirs, files in os.walk(root, followlinks=False):
        for name in dirs:
            path = Path(base) / name
            if path.is_symlink() or not path.is_dir():
                raise ValueError("vendor parent is unsafe")
        for name in files:
            path = Path(base) / name
            ordinary(path)
            rows.append([path.relative_to(root).as_posix(), sha(path), path.stat().st_mode & 0o777])
    rows.sort()
    return hashlib.sha256(json.dumps(rows, separators=(",", ":")).encode()).hexdigest()


def read(path):
    ordinary(path)
    if path.stat().st_mode & 0o777 != 0o600:
        raise ValueError("dependency record mode is unsafe")
    return json.loads(path.read_text())


def sha(path):
    ordinary(path)
    return hashlib.sha256(path.read_bytes()).hexdigest()


def verify_recovery_point(server, workspace):
    helper = Path(__file__).with_name("update-recovery.py")
    ordinary(helper)
    spec = importlib.util.spec_from_file_location("bound_update_recovery", helper)
    if spec is None or spec.loader is None:
        raise ValueError("trusted recovery helper is unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    arguments = argparse.Namespace(server=server, workspace=workspace)
    module.verify(arguments)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=("record", "check", "switch", "recover"))
    parser.add_argument("--server", type=Path, required=True)
    parser.add_argument("--workspace", type=Path, required=True)
    args = parser.parse_args()
    server, workspace = args.server, args.workspace
    if not server.is_absolute() or not workspace.is_absolute() or server.resolve() != server or workspace.resolve() != workspace:
        raise ValueError("server and workspace must be canonical ordinary directories")
    plan_path = workspace / "plan.json"
    plan = json.loads(plan_path.read_text())
    if plan.get("protocol") not in ('peanut.server-release-inputs.v1',):
        raise ValueError("server update plan is invalid")
    recovery_helper = Path(__file__).with_name('update-recovery.py')
    spec = importlib.util.spec_from_file_location('product_binding', recovery_helper)
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    product, state = module.product_binding(workspace)
    if plan.get('update_id') != product['candidate'] or (product.get('inputs_sha256') is not None and product['inputs_sha256'] != sha(plan_path)):
        raise ValueError('dependency inputs differ from product plan')
    if args.operation in ('switch', 'recover') and state.get('activation_started') is not False:
        raise ValueError('activation has started; vendor rollback or switch is forbidden')
    target = workspace / "prepared/server"
    source_lock, target_lock = server / "composer.lock", target / "composer.lock"
    plan_sha = sha(plan_path)
    source_lock_sha = plan["source"].get("composer_lock_sha256")
    if source_lock_sha is None:
        source_lock_sha = sha(source_lock)
    target_lock_sha = sha(target_lock)
    same_lock = sha(source_lock) == target_lock_sha and sha(server / "composer.json") == sha(target / "composer.json")
    helper = Path(__file__).with_name("vendor-state.py")
    switch_path = workspace / "dependency-switch.json"
    switched = switch_path.exists() or switch_path.is_symlink()
    source_check = subprocess.run([sys.executable, str(helper), "check", "--server", str(server)],
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120).returncode == 0
    if args.operation != "record":
        recorded = read(workspace / "preparation.json")
        mode = recorded["vendor_mode"]
    else:
        mode = "reuse" if same_lock and source_check else "prepared"
    root = server if mode == "reuse" else target
    if mode == "prepared" and switched and (server / "vendor").is_dir():
        root = server
    if args.operation == "record":
        if not source_check:
            raise ValueError("original installed vendor is incomplete; an intact rollback source is required")
        subprocess.run([sys.executable, str(helper), "check", "--server", str(root)], check=True, timeout=120)
        source_vendor = server / "vendor"
        data = {"protocol": "peanut.server-update-preparation.v1", "update_id": plan["update_id"],
                "plan_sha256": plan_sha, "source_identity_sha256": plan["source"]["identity_sha256"],
                "target_identity_sha256": plan["target"]["identity_sha256"],
        "vendor_mode": mode, "source_lock_sha256": source_lock_sha, "target_lock_sha256": target_lock_sha,
                "source_present": source_vendor.is_dir() and not source_vendor.is_symlink(),
                "source_tree_sha256": tree(source_vendor) if source_vendor.is_dir() and not source_vendor.is_symlink() else None,
                "target_tree_sha256": tree(root / "vendor"),
                "vendor_receipt_sha256": sha(root / "vendor/.peanut-complete.json")}
    else:
        data = recorded
        if data.get("protocol") != "peanut.server-update-preparation.v1" or data.get("update_id") != plan["update_id"] \
            or data.get("plan_sha256") != plan_sha or data.get("source_identity_sha256") != plan["source"]["identity_sha256"] \
            or data.get("target_identity_sha256") != plan["target"]["identity_sha256"] \
            or data.get("target_lock_sha256") != target_lock_sha or mode not in ("reuse", "prepared"):
            raise ValueError("dependency preparation differs from update identity or lock")
        if mode == "reuse" and (not same_lock or not source_check):
            raise ValueError("reused vendor changed")
    path = workspace / "preparation.json"
    if args.operation in ("check", "switch", "recover"):
        if read(path) != data:
            raise ValueError("prepared dependencies changed after planning")
    elif path.exists() or path.is_symlink():
        if read(path) != data:
            raise ValueError("existing dependency preparation differs from current inputs")
    else:
        durable(path, data)
    if args.operation == "check" and mode == "prepared":
        if not switched and tree(target / "vendor") != data["target_tree_sha256"]:
            raise ValueError("prepared target vendor changed")
        if switched:
            switch = read(switch_path)
            if switch.get("status") == "completed" and tree(server / "vendor") != data["target_tree_sha256"]:
                raise ValueError("switched target vendor changed")
    if args.operation == "switch" and mode == "prepared":
        verify_recovery_point(server, workspace)
        backup = read(workspace / "backup.json")
        backup_sha = sha(workspace / "backup.json")
        if backup.get("update_id") != plan["update_id"] or backup.get("plan_sha256") != plan_sha:
            raise ValueError("dependency switch recovery point differs from plan")
        old = workspace / "recovery/old-vendor"
        current = server / "vendor"
        staged = target / "vendor"
        if not switched:
            if data["source_present"] != current.is_dir() or (current.is_dir() and tree(current) != data["source_tree_sha256"]) \
                or tree(staged) != data["target_tree_sha256"] or old.exists() or old.is_symlink():
                raise ValueError("vendor changed before switch")
            durable(switch_path, {"status": "started", "update_id": plan["update_id"], "plan_sha256": plan_sha,
                                  "backup_sha256": backup_sha, "source_present": data["source_present"],
                                  "source_tree_sha256": data["source_tree_sha256"],
                                  "target_tree_sha256": data["target_tree_sha256"]})
        switch = read(switch_path)
        if switch.get("update_id") != plan["update_id"] or switch.get("plan_sha256") != plan_sha or switch.get("backup_sha256") != backup_sha:
            raise ValueError("dependency switch intent differs from recovery point")
        if switch["status"] == "started":
            if data["source_present"] and current.is_dir() and not old.exists() and tree(current) == data["source_tree_sha256"] and tree(staged) == data["target_tree_sha256"]:
                move(current, old)
            if (old.is_dir() or not data["source_present"]) and not current.exists() and staged.is_dir():
                if data["source_present"] and tree(old) != data["source_tree_sha256"]:
                    raise ValueError("old vendor changed during switch")
                if tree(staged) != data["target_tree_sha256"]:
                    raise ValueError("target vendor changed during switch")
                move(staged, current)
            if tree(current) != data["target_tree_sha256"] or (data["source_present"] and tree(old) != data["source_tree_sha256"]):
                raise ValueError("vendor switch has an unknown physical state")
            switch["status"] = "completed"
            durable(switch_path, switch, replace=True)
        elif switch["status"] != "completed" or tree(current) != data["target_tree_sha256"]:
            raise ValueError("completed vendor switch differs from target")
    if args.operation == "recover" and mode == "prepared":
        verify_recovery_point(server, workspace)
        if not switched:
            print(json.dumps({"status": "not_switched", "update_id": plan["update_id"]}))
            return
        switch = read(switch_path)
        if switch.get("status") not in ("started", "completed", "recovered") or switch.get("update_id") != plan["update_id"] \
            or switch.get("backup_sha256") != sha(workspace / "backup.json"):
            raise ValueError("dependency switch has an unknown result")
        if switch["status"] in ("started", "completed"):
            current = server / "vendor"
            new = workspace / "recovery/new-vendor"
            old = workspace / "recovery/old-vendor"
            if current.is_dir() and tree(current) == data["target_tree_sha256"]:
                if new.exists() or new.is_symlink():
                    raise ValueError("target vendor recovery path is unsafe")
                move(current, new)
            elif current.exists() or current.is_symlink():
                if not (data["source_present"] and tree(current) == data["source_tree_sha256"]):
                    raise ValueError("vendor recovery has an unknown current tree")
            if switch["source_present"]:
                if old.is_dir():
                    if tree(old) != data["source_tree_sha256"] or current.exists():
                        raise ValueError("old vendor recovery source changed")
                    move(old, current)
                if tree(current) != data["source_tree_sha256"]:
                    raise ValueError("old vendor was not restored")
            elif current.exists():
                raise ValueError("previously absent vendor appeared during recovery")
            switch["status"] = "recovered"
            durable(switch_path, switch, replace=True)
    print(json.dumps({"status": "complete", "vendor_mode": mode, "update_id": plan["update_id"]}))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, json.JSONDecodeError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
        print(f"update-preparation: {error}", file=sys.stderr)
        sys.exit(1)
