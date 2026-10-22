#!/usr/bin/env python3
"""Closed maintainer admission for one registered APP preinstall/first-install operation.

No command, authority directory, credential value, environment override or runtime
root is accepted from the caller. Ordinary APP installation remains its own CLI.
"""
from __future__ import annotations

import argparse
import contextlib
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import stat
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parent.parent
PROTOCOL = "peanut.application-first-install-operation.v1"
BINDING = "application-operation"
CONTRACT = "application-operation-sha256"
STATE = "application-operation.json"


def need(value, message):
    if not value:
        raise RuntimeError(message)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def regular(path, private=False):
    path = Path(path)
    need(path.is_absolute() and path.resolve() == path and not path.is_symlink(), "unsafe operation file")
    info = path.stat()
    need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1, "operation file must be regular and single-link")
    if private:
        need(info.st_uid == os.getuid() and stat.S_IMODE(info.st_mode) == 0o600, "private operation file must be owner-only")
    return path.read_bytes()


def git(root, *args):
    env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
    env["GIT_OPTIONAL_LOCKS"] = "0"
    result = subprocess.run(["/usr/bin/git", "-C", str(root), *args], capture_output=True, check=False, env=env)
    need(result.returncode == 0 and not result.stderr.strip(), "operation Git identity unavailable")
    return result.stdout


def canonical(value):
    path = Path(value)
    need(path.is_absolute() and path.resolve() == path and not path.is_symlink()
         and not any(ord(char) < 32 for char in str(path)), "operation path is not canonical")
    return path


def json_unique(raw):
    def pairs(rows):
        result = {}
        for key, value in rows:
            need(key not in result, "duplicate operation JSON key")
            result[key] = value
        return result
    return json.loads(raw, object_pairs_hook=pairs)


def table(path, count):
    rows = [line.split("\t") for line in regular(path).decode().splitlines()]
    need(rows and all(len(row) == count for row in rows), "invalid operation lease table")
    need(len({row[0] for row in rows}) == len(rows), "duplicate operation lease table key")
    return rows


def resource_map(rows):
    result = {}
    for digest, key, value in rows:
        need(digest == sha((key + "\t" + value).encode()), "operation lease resource digest mismatch")
        result.setdefault(key, set()).add(value)
    return result


def project_authority():
    """Physical current registry and logical primary Project for review custody."""
    registry = canonical(os.environ.get("PEANUT_RESOURCE_REGISTRY", ""))
    registry_sha = sha(regular(registry))
    resolver = runpy.run_path(str(ROOT / "scripts/project-resource-registry"))
    resolved = resolver["maintainer_source"](registry_sha)
    need(resolved == registry, "operation registry authority mismatch")
    return registry, resolver["maintainer_project"](), resolver, registry_sha


def authority(operation_id):
    need(re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,63}", operation_id), "invalid application operation ID")
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    registry, project, resolver, registry_sha = project_authority()
    document = json_unique(regular(registry))
    operations = document["resources"].get("application_operations", [])
    matches = [row for row in operations if row.get("stable_resource_id") == operation_id]
    need(len(matches) == 1, "application operation is not uniquely registered")
    operation = matches[0]
    need(operation.get("protocol") == PROTOCOL and operation.get("phase") in ("protect", "install", "maintain"),
         "unsupported application operation")
    required_keys = {"stable_resource_id", "protocol", "phase", "tool_source", "application",
                     "owner", "thread", "max_seconds", "database", "configuration", "output_root",
                     "receipt_root", "php_executable", "terminal_review"}
    required_keys |= {"protect": {"backup_client"}, "install": {"protection", "credentials"},
                      "maintain": {"maintenance", "installation"}}[operation["phase"]]
    if "retained_authority" in operation:
        required_keys.add("retained_authority")
    need(set(operation) == required_keys, "application operation fields differ from the closed phase contract")
    php = canonical(operation["php_executable"])
    need(php.name == "php" and php.is_file() and os.access(php, os.X_OK), "registered native PHP unavailable")
    need(set(operation["terminal_review"]) == {"path"}
         and project in canonical(operation["terminal_review"]["path"]).parents,
         "terminal review must be a fixed Project versioned result")
    if operation["phase"] == "protect":
        client = operation["backup_client"]
        need(set(client) == {"ssh", "ssh_alias", "docker", "container", "mysql", "mysqldump",
                             "credential_path", "user"}, "native backup client closure differs")
        canonical(client["ssh"])
        for key in ("docker", "mysql", "mysqldump", "credential_path"):
            path = Path(client[key])
            need(path.is_absolute() and ".." not in path.parts
                 and not any(ord(char) < 32 for char in str(path)), "remote backup client path invalid")
        need(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", client["ssh_alias"])
             and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", client["container"])
             and re.fullmatch(r"[a-z_][a-z0-9_-]*", client["user"]) and client["user"] != "root",
             "native backup client identity invalid")
    elif operation["phase"] == "install":
        need(set(operation["credentials"]) == {"path", "sha256"}
             and set(operation["protection"]) == {"manifest", "manifest_sha256", "review", "review_sha256"},
             "first-install protection/credential closure differs")
        for path_key, digest_key in (("manifest", "manifest_sha256"), ("review", "review_sha256")):
            need(sha(regular(canonical(operation["protection"][path_key]), private=True))
                 == operation["protection"][digest_key], "fixed first-install protection input changed")
        need(sha(regular(canonical(operation["credentials"]["path"]), private=True))
             == operation["credentials"]["sha256"], "fixed installation credential file changed")
    tool = operation["tool_source"]
    need(canonical(tool["root"]) == ROOT and canonical(tool["common_dir"]) == common,
         "registered central source/common-dir mismatch")
    need(common == canonical(str(common.parent / ".git")) and common.parent.name == "peanut-admin-code",
         "application operation must use the central Code lock registry")
    need(git(ROOT, "rev-parse", "HEAD").decode().strip() == tool["commit"], "operation tool commit changed")
    need(not git(ROOT, "status", "--porcelain=v1", "--untracked-files=all"), "operation tool source must be clean")
    required = {"scripts/project-resource-lease", "scripts/project-resource-application.py",
                "scripts/project-resource-registry"}
    need(set(tool["files"]) == required, "operation tool closure differs")
    for name, digest in tool["files"].items():
        raw = regular(ROOT / name)
        need(sha(raw) == digest and git(ROOT, "show", tool["commit"] + ":" + name) == raw,
             "operation tool file changed")
    need(operation["owner"] == operation["thread"], "operation requires its real native owner/thread")
    need(type(operation["max_seconds"]) is int and 60 <= operation["max_seconds"] <= 3600,
         "operation execution bound invalid")
    app = operation["application"]
    app_root = canonical(app["root"])
    need(app_root != ROOT and common.parent not in app_root.parents, "application must be a separate registered Git")
    need(canonical(git(app_root, "rev-parse", "--show-toplevel").decode().strip()) == app_root,
         "application Git root changed")
    need(git(app_root, "rev-parse", "HEAD").decode().strip() == app["commit"]
         and git(app_root, "rev-parse", "HEAD^{tree}").decode().strip() == app["tree"],
         "application commit/tree changed")
    need(not git(app_root, "diff", "--name-only", "HEAD")
         and not git(app_root, "diff", "--cached", "--name-only"), "APP tracked source changed")
    manifest = regular(app_root / ".peanut/application-manifest.json")
    need(sha(manifest) == app["manifest_sha256"], "application manifest changed")
    identity = json_unique(manifest)
    need(identity.get("protocol") == "peanut.application-scaffold.v2"
         and identity["application"]["edition"] == app["edition"]
         and identity["application"]["slug"] == app["slug"]
         and identity["application"]["package_identity"] == app["package_identity"],
         "application identity changed")
    for name, digest in operation["configuration"].items():
        need(name in ("server/.env", "server/private/resources/project-resources.json"),
             "unsupported private configuration binding")
        need(sha(regular(app_root / name, private=True)) == digest, "application private configuration changed")
    need(set(operation["configuration"]) == {"server/.env", "server/private/resources/project-resources.json"},
         "complete private application configuration required")
    if operation["phase"] == "maintain":
        maintenance = operation["maintenance"]
        fields = {"kind", "session", "backup_tool", "metadata_tool", "review"}
        need(maintenance["kind"] in ("plugin", "application-schema"), "unsupported native maintenance kind")
        if maintenance["kind"] == "plugin":
            fields |= {"operation", "plugin_key"}
            need(maintenance["operation"] in ("install", "upgrade")
                 and re.fullmatch(r"[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*", maintenance["plugin_key"]),
                 "native plugin target invalid")
        need(set(maintenance) == fields and re.fullmatch(r"wc_sess_[A-Za-z0-9_-]+", maintenance["session"])
             and re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,127}", maintenance["backup_tool"])
             and (maintenance["metadata_tool"] == "" or re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,127}", maintenance["metadata_tool"])),
             "closed native maintenance target/actor/tool contract differs")
        canonical(maintenance["review"])
        need(project in Path(maintenance["review"]).parents,
             "maintenance review must be a fixed versioned Project result")
        for name, digest in operation["installation"].items():
            need(name in ("installed.json", "baseline.json")
                 and sha(regular(app_root / "server/private/installation" / name, private=True)) == digest,
                 "fixed installed APP identity changed")
        need(set(operation["installation"]) == {"installed.json", "baseline.json"},
             "complete installed APP anchors required")
    db = operation["database"]
    db_matches = [row for row in resolver["load_registry"]()["resources"]["databases"]
                  if row.get("stable_resource_id") == db["resource_id"]]
    need(len(db_matches) == 1, "operation database is not uniquely registered")
    registered = db_matches[0]
    need(registered.get("application_identity", {}).get("root") == str(app_root)
         and registered["database"] == db["name"]
         and registered["upstream_endpoint"] == db["upstream_endpoint"]
         and registered["lease_gate"] == "permanent-app-development",
         "operation database/APP/endpoint registration mismatch")
    need(registered["version"] == db["version"]
         and registered["allocation_evidence"]["server_uuid"] == db["server_uuid"], "database allocation identity changed")
    need(db["environment"] in registered["environments"] and db["consumer"] == "host"
         and registered.get("environment") != "production", "operation is not dedicated development")
    for name in ("output_root", "receipt_root"):
        path = canonical(operation[name])
        need(path.parent.is_dir() and path != app_root and app_root not in path.parents
             and path != ROOT and ROOT not in path.parents, "operation output overlaps source")
    retained_parent(operation, common, require_live=False)
    return operation, sha(json.dumps(operation, sort_keys=True, separators=(",", ":")).encode()), common, registry_sha


def retained_parent(operation, common, *, require_live=True):
    """Read the original authority; renewal may change time, never its identity/scope."""
    if "retained_authority" not in operation:
        return None
    binding = operation["retained_authority"]
    need(isinstance(binding, dict) and set(binding) == {"common_dir", "lease", "gate", "resources_sha256"}
         and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", binding["lease"])
         and re.fullmatch(r"[a-z0-9][a-z0-9._-]{0,63}", binding["gate"]),
         "retained parent binding differs from closed contract")
    app = operation["application"]
    app_common = canonical(git(app["root"], "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    parent_common = canonical(binding["common_dir"])
    need(parent_common in (common, app_common), "retained parent crosses an unrelated lock domain")
    directory = parent_common / "peanut-admin-resource-leases/leases" / binding["lease"]
    metadata = dict(table(directory / "metadata.tsv", 2))
    need(set(metadata) == {"lease", "owner", "thread", "candidate", "candidate_repository", "parent_lease",
                           "gate", "worktree", "created_at", "expires_at", "status"},
         "retained parent metadata schema differs")
    now = time.time()
    need(metadata["lease"] == binding["lease"] and metadata["owner"] == operation["owner"]
         and metadata["thread"] == operation["thread"] and metadata["status"] == "ACTIVE"
         and metadata["parent_lease"] == "" and metadata["gate"] == binding["gate"]
         and metadata["candidate"] == app["commit"]
         and metadata["candidate_repository"] == metadata["worktree"] == app["root"]
         and int(metadata["created_at"]) <= now
         and int(metadata["expires_at"]) > int(metadata["created_at"])
         and (not require_live or now < int(metadata["expires_at"])),
         "retained parent owner/APP/candidate/gate/expiry differs")
    need(sha(regular(directory / "resources.tsv")) == binding["resources_sha256"],
         "retained parent resource scope changed")
    rows = table(directory / "resources.tsv", 3)
    resources = resource_map(rows)
    need(resources.get("worktree") == {app["root"]}
         and resources.get("application-root") == {app["root"]}
         and resources.get("gate") == {binding["gate"]}, "retained parent lacks exact APP protection")
    # Only these two exact physical resources may be retained. The operation
    # acquires its source, DB, outputs and all other authority independently.
    retained = {(kind, app["root"]) for kind in ("worktree", "application-root")}
    for digest, kind, value in rows:
        lock = parent_common / "peanut-admin-resource-leases/resources" / digest
        for field, expected in (("lease", binding["lease"]), ("type", kind), ("value", value)):
            need(regular(lock / field).decode().strip() == expected, "retained parent physical holder changed")
    return parent_common, metadata, retained


def retained_guard_value(operation, common, lease_id):
    return json.dumps({"common_dir": str(common), "lease": lease_id,
                       "operation": operation["stable_resource_id"]}, sort_keys=True, separators=(",", ":"))


def retained_guard(operation, common, lease_id, action="verify", expires=None, *, allow_expired_parent=False):
    """A native APP child protects the other lock domain; it grants no DB rights.

    Central mutex is always acquired first. A partial/crashed reservation remains
    an explicit native child for normal reconciliation, including after expiry.
    """
    parent = retained_parent(operation, common, require_live=not allow_expired_parent)
    if parent is None or parent[0] == common:
        return
    parent_common, metadata, _ = parent
    binding = operation["retained_authority"]
    root = parent_common / "peanut-admin-resource-leases"
    directory = root / "leases" / lease_id
    value = retained_guard_value(operation, common, lease_id)
    resources = {"retained-operation": value, "gate": "retained-application-operation"}
    expected = dict(metadata, lease=lease_id, parent_lease=binding["lease"], gate="retained-application-operation")
    with mutex(parent_common):
        retained_parent(operation, common, require_live=not allow_expired_parent)
        if action == "reserve":
            need(not directory.exists() and not directory.is_symlink(), "retained operation child already exists; reconcile")
            # Each reservation is an ordinary exact native resource, with its
            # actual holder. No parent lock is transferred, removed or relabelled.
            locks = [(root / "resources" / sha((kind + "\t" + item).encode()), kind, item)
                     for kind, item in resources.items()]
            need(all(not lock.exists() and not lock.is_symlink() for lock, _, _ in locks),
                 "retained operation child resource conflict")
            directory.mkdir(mode=0o700)
            expected.update(created_at=str(int(time.time())), expires_at=str(expires))
            for lock, kind, item in locks:
                lock.mkdir(mode=0o700)
                for field, text in (("lease", lease_id), ("type", kind), ("value", item)):
                    (lock / field).write_text(text + "\n")
            (directory / "resources.tsv").write_text("".join(
                sha((kind + "\t" + item).encode()) + "\t" + kind + "\t" + item + "\n"
                for kind, item in resources.items()))
            (directory / "metadata.tsv").write_text("".join(key + "\t" + item + "\n" for key, item in expected.items()))
        actual = dict(table(directory / "metadata.tsv", 2))
        need(all(actual.get(key) == item for key, item in expected.items() if key not in ("created_at", "expires_at"))
             and int(actual["created_at"]) <= time.time()
             and int(actual["expires_at"]) <= int(metadata["expires_at"]), "retained operation child identity differs")
        if action == "verify" and expires is not None and not allow_expired_parent:
            need(int(actual["expires_at"]) >= expires, "retained operation child window is shorter than operation")
        rows = table(directory / "resources.tsv", 3)
        need(resource_map(rows) == {key: {item} for key, item in resources.items()}, "retained operation child scope differs")
        for digest, kind, item in rows:
            lock = root / "resources" / digest
            for field, text in (("lease", lease_id), ("type", kind), ("value", item)):
                need(regular(lock / field).decode().strip() == text, "retained operation child physical holder changed")
        if action == "renew":
            actual["expires_at"] = str(expires)
            need(expires <= int(metadata["expires_at"]), "retained operation child TTL exceeds parent")
            temporary = directory / (".metadata." + str(os.getpid()))
            temporary.write_text("".join(key + "\t" + item + "\n" for key, item in actual.items()))
            temporary.replace(directory / "metadata.tsv")


def physical_holder(operation, common, lease_id, kind, value):
    # Custody mapping grants no execution rights; its caller admits execution.
    parent = retained_parent(operation, common, require_live=False)
    if parent and parent[0] == common and (kind, value) in parent[2]:
        return operation["retained_authority"]["lease"]
    return lease_id


def expected_resources(operation, digest, registry_sha):
    app, db = operation["application"], operation["database"]
    endpoint = db["upstream_endpoint"]
    scope = endpoint["host"] + ":" + str(endpoint["port"]) + "/" + db["name"]
    access = json.dumps({"schema": scope, "operation": "exclusive", "complete": True, "tables": []},
                        sort_keys=True, separators=(",", ":"))
    result = {BINDING: {operation["stable_resource_id"]}, CONTRACT: {digest},
            "registry-sha256": {registry_sha}, "resource-id": {db["resource_id"]},
            "endpoint": {endpoint["endpoint_id"]}, "database": {db["name"]},
            "mysql-db": {db["name"]}, "mysql-schema": {scope}, "mysql-access": {access},
            "environment": {db["environment"]}, "consumer": {"host"},
            "application-root": {app["root"]}, "worktree": {app["root"], operation["tool_source"]["root"]},
            "output-dir": {operation["output_root"], operation["receipt_root"]},
            "gate": {"permanent-app-development"},
            "application-registry-sha256": {operation["configuration"]["server/private/resources/project-resources.json"]},
            "application-manifest-sha256": {app["manifest_sha256"]},
            "application-env-sha256": {operation["configuration"]["server/.env"]}}
    if "retained_authority" in operation:
        result["retained-parent"] = {json.dumps(operation["retained_authority"], sort_keys=True, separators=(",", ":"))}
    if operation["phase"] == "maintain":
        m = operation["maintenance"]
        result["workflow-session"] = {m["session"]}
        result["tooling-resource-id"] = {item for item in (m["backup_tool"], m["metadata_tool"]) if item}
        result["output-dir"].add(str(Path(operation["output_root"]) / "backup"))
        if m["metadata_tool"]:
            result["output-dir"].add(str(Path(app["root"]) / "server/private/installation/source-development/effect-metadata"))

    return result


def validate(metadata, resources, *, allow_expired_parent=False):
    ids = resources.get(BINDING, set())
    need(len(ids) == 1, "application operation binding is ambiguous")
    operation, digest, common, registry_sha = authority(next(iter(ids)))
    app = operation["application"]
    need(metadata["candidate"] == app["commit"] and metadata["candidate_repository"] == app["root"]
         and metadata["worktree"] == app["root"] and metadata["owner"] == operation["owner"]
         and metadata["thread"] == operation["thread"]
         and metadata["parent_lease"] == operation.get("retained_authority", {}).get("lease", "")
         and metadata["gate"] == "permanent-app-development", "operation lease identities changed")
    need(resources == expected_resources(operation, digest, registry_sha), "operation exclusive resource closure differs")
    parent = retained_parent(operation, common, require_live=not allow_expired_parent)
    if parent and "expires_at" in metadata and not allow_expired_parent:
        need(int(metadata["expires_at"]) <= int(parent[1]["expires_at"]), "operation TTL exceeds retained parent")
    return operation, common


def lease(lease_id, allow_expired=False, *, recovery_step=None):
    need(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", lease_id), "invalid operation lease ID")
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    directory = common / "peanut-admin-resource-leases/leases" / lease_id
    metadata = dict(table(directory / "metadata.tsv", 2))
    resources = resource_map(table(directory / "resources.tsv", 3))
    state = live_state(directory) if recovery_step in ("restore", "open") else None
    recovering = bool(state and state.get("recovery"))
    operation, expected_common = validate(metadata, resources, allow_expired_parent=allow_expired or recovering)
    need(expected_common == common and metadata["lease"] == lease_id and metadata["status"] == "ACTIVE",
         "operation lease storage mismatch")
    retained_guard(operation, common, lease_id, expires=int(metadata["expires_at"]),
                   allow_expired_parent=allow_expired or recovering)
    need(allow_expired or int(metadata["expires_at"]) > time.time(), "operation lease expired")
    identity_fields = {"resource-id", "database-resource", "endpoint", "resource-scope", "scope", "gate",
                       "mysql-db", "database", "mysql-access", "registry-sha256", "registry-tool-sha256", "application-registry-sha256", "application-manifest-sha256", "application-env-sha256",
                       "environment", "consumer", "host", "version"}
    for digest, kind, item in table(directory / "resources.tsv", 3):
        if kind in identity_fields:
            continue
        lock = common / "peanut-admin-resource-leases/resources" / digest
        need(canonical(str(lock)).is_dir(), "operation resource lock unavailable")
        for field, expected in (("lease", physical_holder(operation, common, lease_id, kind, item)), ("type", kind), ("value", item)):
            need(regular(lock / field).decode().strip() == expected, "operation resource lock holder mismatch")
    if recovering:
        need(operation["phase"] == state.get("phase") == "maintain"
             and state.get("lease") == lease_id and state.get("owner") == metadata["owner"],
             "recovery operation identity differs")
        recovery = recovery_check(directory, metadata, state)
        need(recovery_step == ("open" if recovery["restored"] else "restore"),
             "recovery authority forbids fresh migration or rehearsal")
    return operation, directory, metadata


def live_state(directory):
    path = directory / STATE
    if not path.exists():
        return None
    value = json_unique(regular(path, private=True))
    need(value.get("protocol") == "peanut.application-operation-state.v1", "operation state is unknown")
    return value


@contextlib.contextmanager
def mutex(common):
    directory = common / "peanut-admin-resource-leases/mutex"
    acquired = False
    for _ in range(100):
        try:
            directory.mkdir()
            (directory / "pid").write_text(str(os.getpid()) + "\n")
            acquired = True
            break
        except FileExistsError:
            time.sleep(0.05)
    need(acquired, "central resource mutex busy")
    try:
        yield
    finally:
        need((directory / "pid").read_text().strip() == str(os.getpid()), "central resource mutex owner changed")
        (directory / "pid").unlink()
        directory.rmdir()


def save(path, value):
    temporary = path.with_name(path.name + "." + str(os.getpid()))
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w") as stream:
        stream.write(json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n")
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)


def verify_native(lease_id, recovery_step=None):
    arguments = ["bash", str(ROOT / "scripts/project-resource-lease"), "show",
                 "--lease", lease_id, "--verify-locks"]
    if recovery_step in ("restore", "open"):
        _, directory, _ = lease(lease_id, recovery_step=recovery_step)
        if (live_state(directory) or {}).get("recovery"):
            arguments += ["--application-recovery", recovery_step]
    result = subprocess.run(arguments, cwd=ROOT, capture_output=True)
    need(result.returncode == 0, "native application lease verification rejected")


def process_birth(pid):
    result = subprocess.run(["/bin/ps", "-p", str(pid), "-o", "lstart="], capture_output=True, text=True)
    need(result.returncode in (0, 1) and not result.stderr.strip(), "native process identity unavailable")
    return result.stdout.strip() if result.returncode == 0 else None


def original_process_alive(pid, birth):
    current = process_birth(pid)
    if current is None:
        return False
    need(birth is not None, "original process birth was not observed")
    return current == birth



def native_source_record(app, path):
    """Read the existing APP instance authenticated record, never a new signer."""
    import hmac
    value = json_unique(regular(canonical(str(path)), private=True))
    signature = value.pop("hmac_sha256", None)
    key = regular(app / "server/private/maintenance/update-verification.key", private=True).strip()
    need(re.fullmatch(b"[a-f0-9]{64}", key) is not None, "native APP verification key invalid")
    raw = json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()
    need(isinstance(signature, str) and hmac.compare_digest(signature, hmac.new(key, raw, hashlib.sha256).hexdigest()),
         "native APP recovery record authentication failed")
    return value


def recovery_check(directory, metadata, state, *, parent=None):
    """A continuation retains the original binding; only its separate authority is new."""
    rebind = state.get("recovery")
    need(isinstance(rebind, dict), "original-plan recovery admission is absent")
    app = canonical(metadata["worktree"])
    reference = rebind["record"]
    raw = regular(canonical(reference["path"]), private=True)
    need(sha(raw) == reference["sha256"], "recovery rebind record changed")
    record = json_unique(raw)
    need(record["lease"] == directory.name and record["owner"] == metadata["owner"] == metadata["thread"]
         and record["resources_sha256"] == sha(regular(directory / "resources.tsv"))
         and record["binding_expires_at"] == state["binding_expires_at"]
         and int(metadata["expires_at"]) >= record["effective_expires_at"] > time.time() + 1,
         "new recovery authority changed or expired")
    original_meta = dict(table(canonical(record["metadata_path"]), 2))
    need(all(metadata[k] == v for k, v in original_meta.items() if k != "expires_at"),
         "recovery authority changed fields other than normal renewal")
    for item in record["attachments"]:
        need(sha(regular(canonical(item["path"]), private=True)) == item["sha256"],
             "original recovery attachment changed")
    context = native_source_record(app, canonical(record["workspace"]) / "source-application-context.json")
    journal_path = canonical(context["journal_path"])
    journal = native_source_record(app, journal_path)
    restored = (journal.get("phase") == "restored" and journal.get("unknown") is False
                and (journal.get("recovery_continuation") == reference
                     or (record["journal_phase"] == "restored" and sha(regular(journal_path, private=True)) == record["journal_sha256"])))
    need(journal.get("activation_started") is False and (restored or sha(regular(journal_path, private=True)) == record["journal_sha256"]),
         "original journal changed without the authorized restore")
    if parent is not None:
        import shlex
        result = subprocess.run(["/bin/ps", "-p", str(parent), "-o", "command="], capture_output=True, text=True)
        argv = shlex.split(result.stdout.strip())
        expected = "open" if restored else "restore"
        comm = subprocess.run(["/bin/ps", "-p", str(parent), "-o", "comm="], capture_output=True, text=True)
        executable = canonical(str(Path(comm.stdout.strip()).resolve()))
        need(comm.returncode == 0 and not comm.stderr and executable.name == "php"
             and sha(regular(executable)) == context["binding"]["source"]["php_binary_sha256"]
             and len(argv) >= 4 and argv[1] in ("server/think", str(app / "server/think"))
             and argv[2:4] == (["schema:application", "source-recovery"] if restored else record["native_command"]),
             "recovery authority requires the fixed native PHP/think entry and original operation")
        need(result.returncode == 0 and not result.stderr and argv
             and "--application-recovery=" + expected in argv
             and "--scope=source-application" in argv
             and all("--" + k + "=" + record["actor"][k] in argv for k in ("owner", "thread", "session", "gate")),
             "rebound authority permits only its real native restore or post-restore open")
        if not restored:
            need("--source-plan=" + record["plan_path"] in argv and "--recovery=" + record["workspace"] in argv,
                 "rebound PHP command must consume the fixed original plan/workspace")
    return {"binding_expires_at": state["binding_expires_at"],
            "effective_expires_at": record["effective_expires_at"],
            "recovery_continuation": reference, "restored": restored,
            "journal_sha256": record["journal_sha256"]}


def admit_recovery(lease_id, owner):
    """No release/reclaim: reconcile a terminal window on its normally renewed lease."""
    need(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", lease_id), "invalid original recovery lease ID")
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    directory = common / "peanut-admin-resource-leases/leases" / lease_id
    state = live_state(directory)
    need(state is not None, "original recovery window absent")
    if state.get("status") == "RECOVERY_ADMITTED":
        prior_raw = regular(canonical(state["recovery"]["record"]["path"]), private=True)
        need(sha(prior_raw) == state["recovery"]["record"]["sha256"], "previous recovery authority changed")
        prior = json_unique(prior_raw)
        need(prior["effective_expires_at"] <= time.time(), "unexpired recovery admission already pending")
    if state.get("phase") == "maintain":
        operation, directory, metadata = lease(lease_id, allow_expired=True)
        review_path = canonical(operation["terminal_review"]["path"])
        review_raw = regular(review_path, private=True)
        project = project_authority()[1]
        need(git(project, "show", "HEAD:" + str(review_path.relative_to(project))) == review_raw,
             "central recovery review must be its fixed versioned Project result")
    else:
        common, directory, metadata = same_source_lease(lease_id, allow_expired=True)
        need(state.get("phase") == "source-application", "only source APP maintenance can rebind")
        review_path = ROOT / "server/private/maintenance" / ("source-recovery-review-" + lease_id + ".json")
        review_raw = regular(review_path, private=True)
        native_source_record(ROOT, review_path)  # Authored by the independent native owner, never this adapter.
    review = json_unique(review_raw)
    need(owner == metadata["owner"] == metadata["thread"] == state["owner"]
         and review.get("protocol") == "peanut.source-application-terminal-reconciliation.v1"
         and review.get("verdict") == "ORIGINAL_PLAN_RECOVERY_ONLY"
         and review.get("reviewer_thread") not in (None, "", owner)
         and review.get("owner") == owner and review.get("lease") == lease_id
         and review.get("state_sha256") == sha(regular(directory / STATE, private=True))
         and review.get("metadata_sha256") == sha(regular(directory / "metadata.tsv"))
         and review.get("actual_process_terminal") is True and review.get("remote_effects_known") is True
         and isinstance(review.get("terminal_evidence"), list) and review["terminal_evidence"],
         "independent terminal reconciliation does not bind this exact window/new authority")
    need(not original_process_alive(state["pid"], state.get("process_birth"))
         and (state.get("child_pid") is None or not original_process_alive(state["child_pid"], state.get("child_birth"))),
         "original maintenance process remains active")
    expires = review.get("effective_expires_at")
    need(type(expires) is int and time.time() + 60 < expires <= time.time() + 3600,
         "recovery requires independently admitted current bounded authority")
    app = canonical(metadata["worktree"])
    workspace = canonical(review["workspace"])
    resources = resource_map(table(directory / "resources.tsv", 3))
    plan_path = canonical(review["plan_path"])
    backup_root = workspace.parent
    if state["phase"] == "maintain":
        output_root = canonical(operation["output_root"])
        need(workspace == output_root / "backup/application-recovery"
             and plan_path == output_root / "plan.json"
             and {str(output_root), str(backup_root)} <= resources.get("output-dir", set()),
             "central recovery workspace/plan differs from the registered maintenance producer")
        need(output_root.is_dir() and output_root.stat().st_uid == os.getuid()
             and stat.S_IMODE(output_root.stat().st_mode) == 0o700,
             "central maintenance output owner/mode changed")
    else:
        need(workspace.name == "application-recovery" and str(backup_root) in resources.get("output-dir", set()),
             "ordinary recovery workspace differs from its original leased backup output")
    for path in (backup_root, workspace):
        need(canonical(str(path)).is_dir() and path.stat().st_uid == os.getuid()
             and stat.S_IMODE(path.stat().st_mode) == 0o700, "recovery workspace owner/mode changed")
    context_path = workspace / "source-application-context.json"
    context = native_source_record(app, context_path)
    binding = context["binding"]
    native_plan = canonical(context["native_plan"]["path"])
    need(context.get("workspace") == str(workspace) and native_plan == workspace / "native-plan.json"
         and canonical(context["backup_tool"]["path"]) == workspace / "backup-tool.json",
         "recovery context/native plan/tool escaped the fixed producer workspace")
    plan = json_unique(regular(plan_path, private=True))
    original = json_unique(regular(native_plan, private=True))
    need(original.get("protocol") in ("peanut.source-development-plugin-plan.v1", "peanut.source-development-schema-plan.v1")
         and original.get("maintenance_scope") == "source-application" and original.get("execution_admitted") is False,
         "recovery requires its original native source APP plan")
    need(sha(regular(native_plan, private=True)) == context["native_plan"]["sha256"]
         and all(plan.get(k) == v for k, v in original.items())
         and original["binding"] == binding
         and binding["source"]["root"] == str(app)
         and binding["source"]["head"] == metadata["candidate"]
         and binding["authority"]["lease"] == lease_id and binding["authority"]["owner"] == owner
         and binding["authority"]["thread"] == owner and binding["authority"]["resources_sha256"] == sha(regular(directory / "resources.tsv"))
         and binding["authority"]["expires_at"] == state["binding_expires_at"],
         "recovery must retain its exact original plan/actor/APP/source/resources/expiry")
    need(git(app, "rev-parse", "HEAD").decode().strip() == binding["source"]["head"]
         and not git(app, "diff", "--name-only", "HEAD")
         and sha(regular(app / "server/.env", private=True)) == binding["environment_sha256"]
         and sha(regular(app / "server/private/resources/project-resources.json", private=True)) == binding["registry_sha256"],
         "original source/configuration changed before recovery rebind")
    journal_path = canonical(context["journal_path"])
    journal = native_source_record(app, journal_path)
    need(journal.get("activation_started") is False and journal.get("phase") in ("failed", "migrated", "applying", "restored"),
         "only an original closed preactivation mutation/restoration can reconcile")
    completed_path = workspace / "database-recovery.json"
    completed_items = []
    if completed_path.exists() or completed_path.is_symlink():
        import hmac
        completed_raw = regular(completed_path, private=True)
        key = regular(app / "server/private/maintenance/update-verification.key", private=True).strip()
        need(hmac.compare_digest(regular(completed_path.with_name(completed_path.name + ".hmac"), private=True).decode().strip(),
             hmac.new(key, completed_raw, hashlib.sha256).hexdigest()), "existing restore record authentication failed")
        completed = json_unique(completed_raw)
        process_ref = completed.get("registered_restore_process", {})
        terminal_path = canonical(process_ref["path"])
        terminal = json_unique(regular(terminal_path, private=True))
        need(completed.get("status") == "completed"
             and completed.get("backup_sha256") == sha(regular(workspace / "backup.json", private=True))
             and sha(regular(terminal_path, private=True)) == process_ref["sha256"]
             and terminal.get("operation") == "restore" and terminal.get("status") == "terminal"
             and terminal.get("rc") == 0 and terminal.get("remote_success_terminal_confirmed") is True,
             "interrupted/unknown restore cannot be replayed; require its real native terminal reconciliation")
        completed_items = [completed_path, completed_path.with_name(completed_path.name + ".hmac"), terminal_path]
    need(journal.get("phase") != "restored" or completed_items, "restored journal requires its actual provider completion")

    items = [context_path, native_plan, plan_path, workspace / "backup.json", workspace / "backup.json.hmac",
             canonical(context["backup_tool"]["path"]), review_path]
    items += [canonical(item["path"]) for item in review["terminal_evidence"]]
    items += completed_items
    if state.get("recovery"):
        items.append(canonical(state["recovery"]["record"]["path"]))
    attachments = [{"path": str(p), "sha256": sha(regular(p, private=True))} for p in items]
    # Each critical digest is explicitly approved, rather than inferred from a filename.
    approved = review.get("approved_sha256", {})
    need(all(approved.get(item["path"]) == item["sha256"] for item in attachments if item["path"] != str(review_path))
         and approved.get(str(journal_path)) == sha(regular(journal_path, private=True)),
         "independent review did not approve the actual original plan/backup/journal/evidence")
    with mutex(common):
        need(sha(regular(directory / STATE, private=True)) == review["state_sha256"]
             and sha(regular(directory / "metadata.tsv")) == review["metadata_sha256"],
             "window or new authority changed during independent admission")
        if state["phase"] == "maintain":
            lease(lease_id, allow_expired=True)
        else:
            same_source_lease(lease_id, allow_expired=True)
        archive = workspace / ("authority-rebind-" + sha(review_raw))
        need(not archive.exists(), "recovery reconciliation already consumed")
        archive.mkdir(mode=0o700)
        for name, data in (("original-state.json", regular(directory / STATE, private=True)),
                           ("metadata.tsv", regular(directory / "metadata.tsv")),
                           ("resources.tsv", regular(directory / "resources.tsv")),
                           ("original-journal.json", regular(journal_path, private=True)), ("review.json", review_raw)):
            fd = os.open(archive / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "wb") as stream: stream.write(data); stream.flush(); os.fsync(stream.fileno())
        attachments = [item for item in attachments if item["path"] != str(review_path)]
        attachments += [{"path": str(archive / name), "sha256": sha(regular(archive / name, private=True))}
                        for name in ("original-state.json", "metadata.tsv", "resources.tsv", "original-journal.json", "review.json")]
        record = {"protocol": "peanut.source-application-recovery-rebind.v1", "lease": lease_id, "owner": owner,
                  "admitted_at": int(time.time()),
                  "native_command": (["plugin:" + original["operation"], original["plugin_key"]]
                     if original["protocol"] == "peanut.source-development-plugin-plan.v1" else ["schema:application", "source-recovery"]),
                  "actor": {key: binding["authority"][key] for key in ("owner", "thread", "session", "gate")},
                  "workspace": str(workspace), "plan_path": str(plan_path), "attachments": attachments,
                  "journal_sha256": sha(regular(journal_path, private=True)), "journal_phase": journal["phase"],
                  "metadata_path": str(archive / "metadata.tsv"),
                  "resources_sha256": sha(regular(directory / "resources.tsv")),
                  "binding_expires_at": state["binding_expires_at"], "effective_expires_at": expires,
                  "original_state_sha256": review["state_sha256"], "review_sha256": sha(review_raw)}
        save(archive / "rebind.json", record)
        # This explicit native reconciliation issues new recovery-only authority;
        # ordinary renew still refuses expired leases. Preserve the exact old metadata.
        if expires > int(metadata["expires_at"]):
            replacement = "".join(k + chr(9) + (str(expires) if k == "expires_at" else v) + chr(10)
                                  for k, v in table(directory / "metadata.tsv", 2))
            temporary = directory / ("metadata.recovery-" + str(os.getpid()))
            fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w") as stream: stream.write(replacement); stream.flush(); os.fsync(stream.fileno())
            os.replace(temporary, directory / "metadata.tsv")
        state.update(status="RECOVERY_ADMITTED", recovery={"record": {"path": str(archive / "rebind.json"),
                     "sha256": sha(regular(archive / "rebind.json", private=True))}})
        # Never fabricate COMPLETED, mutate the original plan/journal or clear its old failure.
        save(directory / STATE, state)


def window(lease_id, owner, step=None):
    """In-use state survives failures; the global mutex is held only for transitions."""
    operation, directory, metadata = lease(lease_id, recovery_step=step)
    need(owner == metadata["owner"], "operation owner mismatch")
    verify_native(lease_id, step)
    common = directory.parent.parent.parent
    with mutex(common):
        operation, directory, metadata = lease(lease_id, recovery_step=step)
        previous = live_state(directory)
        recovery = recovery_check(directory, metadata, previous) if previous and previous.get("recovery") else None
        if recovery:
            need(step == ("open" if recovery["restored"] else "restore"), "recovery authority forbids fresh migration or rehearsal")
        if previous is not None:
            need(operation["phase"] == "maintain" and previous.get("status") in (("RECOVERY_ADMITTED", "COMPLETED") if recovery else ("COMPLETED",))
                 and (recovery is not None or previous.get("actual_process_terminal") is True)
                 and previous.get("owner") == owner and previous.get("phase") == "maintain"
                 and not original_process_alive(previous["pid"], previous.get("process_birth"))
                 and (previous.get("child_pid") is None or (recovery is not None and not original_process_alive(previous["child_pid"], previous.get("child_birth"))))
                 and previous.get("operation_sha256") == next(iter(resource_map(table(directory / "resources.tsv", 3))[CONTRACT])),
                 "operation already started; reconcile its real terminal state")
        value = {"protocol": "peanut.application-operation-state.v1", "phase": operation["phase"],
                 "lease": lease_id, "owner": owner, "pid": os.getpid(),
                 "process_birth": process_birth(os.getpid()), "started_at": int(time.time()),
                 "status": "RUNNING", "actual_process_terminal": False, "child_pid": None,
                 "operation_sha256": next(iter(resource_map(table(directory / "resources.tsv", 3))[CONTRACT])),
                 "binding_expires_at": min(int(metadata["expires_at"]), int(time.time()) + operation["max_seconds"]),
                 "step": step}
        if previous is not None:
            for key in ("started_at", "binding_expires_at", "completed_step"):
                value[key] = previous[key]
        if recovery:
            value.update(recovery=previous["recovery"], started_at=int(time.time()), child_pid=None)
        need((recovery["effective_expires_at"] if recovery else value["binding_expires_at"]) > time.time() + 60,
             "original operation window expired; independent recovery rebind required")
        save(directory / STATE, value)
    return operation, directory, value


def renew_native(lease_id, owner, recovery_step=None):
    _, directory, metadata = lease(lease_id, recovery_step=recovery_step)
    need(metadata["owner"] == owner, "operation renewal owner mismatch")
    if recovery_step in ("restore", "open") and (live_state(directory) or {}).get("recovery"):
        # The existing independent recovery record is bounded. Never extend it
        # by renewing the expired original parent or its no-permission APP guard.
        return
    ttl = max(300, int(metadata["expires_at"]) - int(time.time()))
    result = subprocess.run(["bash", str(ROOT / "scripts/project-resource-lease"), "renew",
                             "--lease", lease_id, "--owner", owner, "--ttl", str(ttl)],
                            cwd=ROOT, capture_output=True, timeout=15)
    need(result.returncode == 0, "operation renewal rejected")
    lease(lease_id)


def command(operation, directory, value, lease_id, argv, *, accepted=(0,), environment=None, input_bytes=None):
    """Only fixed phase callers below construct argv; no caller-provided executable."""
    recovery_step = value.get("step") if value.get("recovery") else None
    verify_native(lease_id, recovery_step)
    current, _, metadata = lease(lease_id, recovery_step=recovery_step)
    need(current == operation, "operation changed before dispatch")
    need(int(metadata["expires_at"]) - time.time() >= 60, "operation needs renewal before dispatch")
    need(time.time() < value["started_at"] + operation["max_seconds"], "operation dispatch deadline expired")
    env = {key: item for key, item in os.environ.items()
           if key in ("HOME", "USER", "LOGNAME", "LANG", "LC_ALL", "TZ")}
    env["PATH"] = str(Path(sys.executable).parent) + ":" + str(Path(operation["php_executable"]).parent) + ":/usr/bin:/bin:/usr/sbin:/sbin"
    if environment:
        env.update(environment)
    child = subprocess.Popen(argv, cwd=operation["application"]["root"], env=env,
                             stdin=subprocess.PIPE if input_bytes is not None else subprocess.DEVNULL,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    value["child_pid"] = child.pid
    value["child_birth"] = process_birth(child.pid)
    save(directory / STATE, value)
    started = time.monotonic()
    unknown = False
    while child.poll() is None:
        try:
            child.communicate(input=input_bytes, timeout=10)
        except subprocess.TimeoutExpired:
            input_bytes = None
            try:
                # Existing resource semantics renew this exact lease, under the
                # short native mutex. No global mutex spans the child process.
                renew_native(lease_id, value["owner"], recovery_step)
            except Exception:
                unknown = True
                value["status"] = "UNKNOWN"
                save(directory / STATE, value)
            if time.time() > value["started_at"] + operation["max_seconds"]:
                unknown = True
                value["status"] = "UNKNOWN"
                save(directory / STATE, value)
            # Never force-stop a non-interruptible native install. Keep its real
            # process observed and its in-use resource boundary unreleasable.
    stdout, stderr = child.communicate()
    value["child_pid"] = None
    value["child_exit_code"] = child.returncode
    save(directory / STATE, value)
    need(not unknown, "operation window became UNKNOWN; retain lease and native journals")
    verify_native(lease_id, recovery_step)
    need(child.returncode in accepted and len(stdout) <= 4 * 1024 * 1024
         and len(stderr) <= 4 * 1024 * 1024, "native first-install phase failed")
    return stdout



def application_metadata(operation, directory, value, lease_id):
    app, db = operation["application"], operation["database"]
    # This is the original APP database guard and its ordinary credentials.
    # Project registry/proofs never enter the APP process environment.
    php = r"""require 'server/database/install.php';
$c=loadConfig(__DIR__.'/server');
$p=new PDO('mysql:host='.$c['DB_HOST'].';port='.$c['DB_PORT'].';dbname='.$c['DB_NAME'].';charset=utf8mb4',
$c['DB_USER'],$c['DB_PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>10]);
$v=json_decode($p->query("SELECT JSON_OBJECT('database',DATABASE(),'version',VERSION(),'server_uuid',@@server_uuid,'principal',CURRENT_USER(),'tables_views',(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()),'triggers',(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()),'routines',(SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()),'events',(SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()))")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
$v['host']=$c['DB_HOST'];$v['port']=(string)$c['DB_PORT'];echo json_encode($v),PHP_EOL;"""
    result = json_unique(command(operation, directory, value, lease_id,
                                 [operation["php_executable"], "-r", php]))
    endpoint = db["upstream_endpoint"]
    need(result["database"] == db["name"] and result["server_uuid"] == db["server_uuid"]
         and result["version"] == db["version"] and result["principal"] == db["principal"]
         and result["host"] == endpoint["host"] and result["port"] == str(endpoint["port"]),
         "native APP database identity changed")
    need(all(result[key] == 0 for key in ("tables_views", "triggers", "routines", "events")),
         "first-install requires the exact pristine schema; never clear existing objects")
    return result


def protect(operation, directory, value, lease_id):
    import shlex
    import tarfile
    app = canonical(operation["application"]["root"])
    metadata = application_metadata(operation, directory, value, lease_id)
    output = canonical(operation["output_root"])
    need(not output.exists(), "preinstall output already exists; reconcile, never overwrite")
    output.mkdir(mode=0o700)
    client = operation["backup_client"]
    need(set(client) == {"ssh", "ssh_alias", "docker", "container", "mysql", "mysqldump",
                         "credential_path", "user"}, "native backup client closure differs")
    need(client["user"] == operation["database"]["principal"].split("@", 1)[0]
         and client["user"] != "root", "native backup requires the same ordinary principal")
    ref = Path(client["credential_path"])  # Registered remote path; never resolve it on this host.
    # Only the registered remote ordinary credential reference is read. Neither
    # its value nor MYSQL_PWD is placed in command arguments or receipts.
    identity_sql = "SELECT CONCAT(@@server_uuid,'|',DATABASE(),'|',VERSION(),'|',CURRENT_USER())"
    mysql = [client["docker"], "exec", "--env", "MYSQL_PWD", client["container"], client["mysql"],
             "--user=" + client["user"], "--batch", "--skip-column-names",
             "--database=" + operation["database"]["name"], "-e", identity_sql]
    dump = [client["docker"], "exec", "--env", "MYSQL_PWD", client["container"], client["mysqldump"],
            "--user=" + client["user"], "--single-transaction", "--no-tablespaces", "--skip-triggers",
            "--set-gtid-purged=OFF", "--databases", operation["database"]["name"]]
    script = ("set -euo pipefail\n"
              "test -f " + shlex.quote(str(ref)) + "\n"
              "test ! -L " + shlex.quote(str(ref)) + "\n"
              "test \"$(/usr/bin/stat -f %Lp " + shlex.quote(str(ref)) + ")\" = 600\n"
              "set -a\n. " + shlex.quote(str(ref)) + "\nset +a\n"
              "test \"$DB_USER\" = " + shlex.quote(client["user"]) + "\n"
              "MYSQL_PWD=\"$DB_PASS\"; export MYSQL_PWD\n"
              + shlex.join(mysql) + "\n" + shlex.join(dump) + "\n")
    raw = command(operation, directory, value, lease_id,
                  [client["ssh"], "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes",
                   "-o", "ConnectTimeout=5", client["ssh_alias"], "/bin/bash", "-s"],
                  input_bytes=script.encode())
    identity, sql = raw.split(b"\n", 1)
    need(identity.decode() == "|".join(metadata[key] for key in
                                      ("server_uuid", "database", "version", "principal")),
         "native dump endpoint differs from the APP database")
    need(b"Dump completed" in sql and b"CREATE TABLE" not in sql
         and operation["database"]["name"].encode() in sql, "native empty preinstall dump incomplete")
    dump_path = output / "preinstall.sql"
    with os.fdopen(os.open(dump_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "wb") as stream:
        stream.write(sql)
    excluded = {".local", ".git/peanut-admin-resource-leases", "server/vendor",
                "server/runtime/cache", "server/runtime/log", "server/runtime/session",
                "server/runtime/temp", "server/runtime/generator",
                "web/node_modules", "platform/node_modules", "pc/node_modules", "uniapp/node_modules"}
    records = {}
    def identity_of(path):
        info = path.lstat()
        if stat.S_ISREG(info.st_mode):
            return {"kind": "file", "mode": info.st_mode & 0o777, "sha256": sha(path.read_bytes())}
        if stat.S_ISLNK(info.st_mode):
            return {"kind": "link", "mode": info.st_mode & 0o777, "target": os.readlink(path)}
        need(stat.S_ISDIR(info.st_mode), "unsupported protected file type")
        return {"kind": "directory", "mode": info.st_mode & 0o777}
    archive_path = output / "application-and-git.tar.gz"
    last_renewed = time.monotonic()
    with os.fdopen(os.open(archive_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), "wb") as stream:
        with tarfile.open(fileobj=stream, mode="w:gz", dereference=False) as archive:
            for here, dirs, files in os.walk(app, followlinks=False):
                relative = Path(here).relative_to(app)
                dirs[:] = sorted(name for name in dirs if str(relative / name) not in excluded)
                for name in dirs + sorted(files):
                    path = Path(here) / name
                    key = path.relative_to(app).as_posix()
                    records[key] = identity_of(path)
                    archive.add(path, arcname=key, recursive=False)
                    need(time.time() < value["started_at"] + operation["max_seconds"], "protection capture deadline expired")
                    if time.monotonic() - last_renewed >= 10:
                        renew_native(lease_id, value["owner"])
                        last_renewed = time.monotonic()
    for key, record in records.items():
        need(identity_of(app / key) == record, "APP changed during preinstall capture")
    with tarfile.open(archive_path, "r:gz") as archive:
        members = archive.getmembers()
        need(len(members) == len(records) and {item.name for item in members} == set(records),
             "protected archive membership differs")
        for member in members:
            record = records[member.name]
            need(member.mode == record["mode"], "protected archive mode differs")
            if member.isfile():
                need(sha(archive.extractfile(member).read()) == record["sha256"], "protected archive bytes differ")
            if member.issym():
                need(member.linkname == record["target"], "protected archive link differs")
    need(application_metadata(operation, directory, value, lease_id) == metadata,
         "pristine database changed during preinstall capture")
    manifest = {"protocol": "peanut.application-preinstall-protection.v1",
                "status": "CAPTURE_VERIFIED_NOT_RESTORE_QUALIFIED", "app_root": str(app),
                "app_head": operation["application"]["commit"], "database": operation["database"]["name"],
                "database_metadata": metadata, "source_files": records,
                "excluded_reproducible_or_runtime": sorted(excluded),
                "archive": {"path": str(archive_path), "sha256": sha(archive_path.read_bytes())},
                "database_dump": {"path": str(dump_path), "sha256": sha(sql)}}
    path = output / "protection-manifest.json"
    save(path, manifest)
    return {"manifest": str(path), "manifest_sha256": sha(regular(path, private=True)),
            "covered_items": len(records), "restore_qualified": False}



def maintenance_review(operation, directory, step, plan_path):
    """A real versioned independent review, never an untracked success assertion."""
    path = canonical(operation["maintenance"]["review"])
    project = project_authority()[1]
    raw = regular(path, private=True)
    relative = str(path.relative_to(project))
    need(git(project, "show", "HEAD:" + relative) == raw, "maintenance review must be the exact versioned Project result")
    proof = json_unique(raw)
    need(proof.get("verdict") == "SOURCE_APP_MAINTENANCE_ADMITTED"
         and proof.get("owner") == operation["owner"] and proof.get("lease") == directory.name
         and proof.get("plan_sha256") == sha(regular(plan_path, private=True))
         and step in proof.get("steps", [])
         and proof.get("reviewer_thread") not in (None, "", operation["owner"]),
         "fixed maintenance step lacks its actual independent review")


def maintain(operation, directory, value, lease_id, step):
    contract = operation["maintenance"]
    root = canonical(operation["output_root"])
    root.mkdir(mode=0o700, exist_ok=True)
    need(root.stat().st_uid == os.getuid() and stat.S_IMODE(root.stat().st_mode) == 0o700,
         "maintenance output owner/mode changed")
    plan_path = root / "plan.json"
    previous = value.get("completed_step")
    allowed = {None: {"plan"}, "plan": {"capture"} if contract["kind"] == "application-schema" else {"rehearse"},
               "capture": {"rehearse"}, "rehearse": {"rebind"} if contract["kind"] == "application-schema" else {"apply"},
               "rebind": {"apply"}, "apply": {"verify", "restore"},
               "restore": set(), "verify": {"open"}, "open": set()}
    rebound = recovery_check(directory, dict(table(directory / "metadata.tsv", 2)), value) if value.get("recovery") else None
    need(step in ({"open" if rebound["restored"] else "restore"} if rebound else allowed.get(previous, set())),
         "maintenance step was already executed or is out of order")
    if not rebound and step in ("rehearse", "apply", "restore", "open"):
        reviewed_plan = root / "admitted-plan.json" if step == "apply" and contract["kind"] == "application-schema" else plan_path
        maintenance_review(operation, directory, step, reviewed_plan)
    base = [operation["php_executable"], "server/think"]
    actor = ["--scope=source-application", "--owner=" + operation["owner"],
             "--thread=" + operation["thread"], "--session=" + contract["session"],
             "--gate=permanent-app-development"]
    metadata = ["--metadata-tool=" + contract["metadata_tool"]] if contract["metadata_tool"] else []
    backup = root / "backup"
    workspace = backup / "application-recovery"
    if step == "open":
        argv = base + ["schema:application", "source-recovery", *actor, "--application-recovery=open"]
    elif step in ("rehearse", "restore"):
        name = "plugin:" + contract["operation"] if contract["kind"] == "plugin" else "schema:application"
        target = [contract["plugin_key"]] if contract["kind"] == "plugin" else ["source-recovery"]
        argv = base + [name, *target, *actor, "--application-recovery=" + step,
                       "--source-plan=" + str(plan_path), "--recovery=" + str(workspace)]
    elif contract["kind"] == "plugin":
        argv = base + ["plugin:" + contract["operation"], contract["plugin_key"], *actor, *metadata]
        if step == "plan":
            argv += ["--dry-run", "--backup-tool=" + contract["backup_tool"], "--backup-output=" + str(backup)]
        elif step == "apply":
            argv += ["--source-plan=" + str(plan_path), "--maintenance=" + str(backup / "maintenance.json"),
                     "--recovery=" + str(backup / "recovery.json")]
        else:
            result = json_unique(regular(root / "apply.json", private=True))
            argv += ["--dry-run", "--verify-receipt=" + result["result_path"]]
            # Receipt verification has no metadata/capture arguments.
            argv = [arg for arg in argv if not arg.startswith("--metadata-tool=")]
    else:
        native = {"plan": "preflight", "capture": "backup", "rebind": "preflight", "apply": "apply", "verify": "verify"}[step]
        argv = base + ["schema:application", native, *actor, *metadata]
        if step in ("capture", "apply"):
            argv += ["--plan=" + str(root / "admitted-plan.json" if step == "apply" else plan_path)]
        if step == "capture":
            argv += ["--backup-tool=" + contract["backup_tool"], "--backup-output=" + str(backup)]
        if step in ("rebind", "apply"):
            argv += ["--maintenance=" + str(backup / "maintenance.json"), "--recovery=" + str(backup / "recovery.json")]
        if step == "verify":
            result = json_unique(regular(root / "apply.json", private=True))
            argv += ["--result=" + result["result_path"]]
    output = command(operation, directory, value, lease_id, argv,
                     environment={"PEANUT_RESOURCE_LEASE_PROOF": str(directory)})
    result = json_unique(output)
    target = plan_path if step == "plan" else root / ("admitted-plan.json" if step == "rebind" else step + ".json")
    need(not target.exists() and not target.is_symlink(), "maintenance result already exists; no replay")
    save(target, result)
    value.update(completed_step=step, status="COMPLETED", actual_process_terminal=True,
                 result={"path": str(target), "sha256": sha(regular(target, private=True))})
    return value["result"]

def execute(lease_id, owner, phase, step=None):
    selected, _, _ = lease(lease_id, recovery_step=step)
    need(selected["phase"] == phase, "closed application phase differs from registration")
    operation, directory, value = window(lease_id, owner, step)
    try:
        receipt_root = canonical(operation["receipt_root"])
        receipt_root.mkdir(mode=0o700, exist_ok=True)
        need(receipt_root.stat().st_uid == os.getuid()
             and stat.S_IMODE(receipt_root.stat().st_mode) == 0o700, "private operation receipt root required")
        need(operation["phase"] == phase, "closed application phase differs from registration")
        if phase == "maintain":
            maintain(operation, directory, value, lease_id, step)
            save(directory / STATE, value)
            print(json.dumps(value["result"]))
            return
        if phase == "protect":
            value.update(result=protect(operation, directory, value, lease_id),
                         status="COMPLETED", actual_process_terminal=True)
            save(directory / STATE, value)
            print(json.dumps({"status": "COMPLETED", "lease": lease_id, "result": value["result"]}))
            return
        # Phases stay closed: no arbitrary program, shell, hook or environment.
        protection = operation["protection"]
        manifest = json_unique(regular(canonical(protection["manifest"]), private=True))
        need(sha(regular(canonical(protection["manifest"]), private=True)) == protection["manifest_sha256"],
             "preinstall protection manifest changed")
        need(sha(regular(canonical(protection["review"]), private=True)) == protection["review_sha256"],
             "independent protection review changed")
        need(manifest["app_root"] == operation["application"]["root"]
             and manifest["app_head"] == operation["application"]["commit"]
             and manifest["database"] == operation["database"]["name"], "protection belongs to another APP")
        app = canonical(operation["application"]["root"])
        for name, record in manifest["source_files"].items():
            relative = Path(name)
            need(name and not relative.is_absolute() and ".." not in relative.parts,
                 "protected APP member path is unsafe")
            path = app / relative
            info = path.lstat()
            need(info.st_mode & 0o777 == record["mode"], "protected APP member mode changed")
            if record["kind"] == "file":
                need(sha(regular(path)) == record["sha256"], "protected APP file changed before first-install")
            elif record["kind"] == "link":
                need(path.is_symlink() and os.readlink(path) == record["target"], "protected APP link changed")
            else:
                need(record["kind"] == "directory" and path.is_dir() and not path.is_symlink(),
                     "protected APP directory changed")
        application_metadata(operation, directory, value, lease_id)
        for name in ("archive", "database_dump"):
            item = manifest[name]
            need(sha(regular(canonical(item["path"]), private=True)) == item["sha256"],
                 "preinstall protection artifact changed")
        credentials = operation["credentials"]
        need(sha(regular(canonical(credentials["path"]), private=True)) == credentials["sha256"],
             "one-time installation credentials changed")
        app = canonical(operation["application"]["root"])
        for name in ("installed.json", "executing.json", "baseline.json", "migration.json"):
            path = app / "server/private/installation" / name
            need(not path.exists() and not path.is_symlink(), "installation state requires reconciliation")
        php = canonical(operation["php_executable"])
        need(php.is_file() and os.access(php, os.X_OK), "registered PHP executable unavailable")
        preflight = json_unique(command(operation, directory, value, lease_id,
                                        [str(php), "server/database/install.php", "--preflight"]))
        need(preflight.get("status") == "ready", "native first-install preflight not ready")
        status = json_unique(command(operation, directory, value, lease_id,
                                    [str(php), "server/database/install.php", "--status"], accepted=(1,)))
        need(status.get("state") == "uninstalled" and status.get("code") == "INSTALL_READY",
             "APP is not a pristine native first-install target")
        output = command(operation, directory, value, lease_id, [str(php), "server/database/install.php"],
                         environment={"PEANUT_INSTALLATION_ENV_FILE": credentials["path"]})
        result = json_unique(output)
        need((app / "server/private/installation/installed.json").is_file(), "native installed receipt missing")
        value.update(status="COMPLETED", actual_process_terminal=True, result=result)
    except BaseException as error:
        value.update(status="UNKNOWN", failure=type(error).__name__,
                     actual_process_terminal=value.get("child_pid") is None)
        save(directory / STATE, value)
        raise
    save(directory / STATE, value)
    print(json.dumps({"status": value["status"], "lease": lease_id,
                      "actual_process_terminal": True, "state_path": str(directory / STATE)}))




def same_source_lease(lease_id, allow_expired=False):
    """The ordinary APP owns its Git/resource domain; never select a foreign lock store."""
    common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
    directory = common / "peanut-admin-resource-leases/leases" / lease_id
    metadata = dict(table(directory / "metadata.tsv", 2))
    rows = table(directory / "resources.tsv", 3)
    resources = resource_map(rows)
    registry = ROOT / "server/private/resources/project-resources.json"
    document = json_unique(regular(registry, private=True))
    manifest = json_unique(regular(ROOT / ".peanut/application-manifest.json"))
    selected = [row for row in document["resources"]["databases"]
                if row.get("stable_resource_id") in resources.get("resource-id", set())]
    need(document.get("authority", {}).get("role") == "application"
         and document.get("project_id") == manifest["application"]["slug"] and len(selected) == 1,
         "ordinary source APP database authority unavailable")
    db = selected[0]
    need(not db.get("lease_policy", {}).get("native_authority"),
         "registered central authority forbids an APP-local lock store")
    need(metadata["lease"] == lease_id and metadata["status"] == "ACTIVE"
         and metadata["parent_lease"] == "" and metadata["candidate_repository"] == metadata["worktree"] == str(ROOT)
         and metadata["candidate"] == git(ROOT, "rev-parse", "HEAD").decode().strip()
         and metadata["gate"] == db["lease_gate"] and int(metadata["created_at"]) <= time.time()
         and (allow_expired or int(metadata["expires_at"]) > time.time())
         and resources.get("registry-sha256") == {sha(regular(registry, private=True))},
         "ordinary source APP lease binding changed")
    endpoint = db["upstream_endpoint"]
    schema = str(endpoint["host"]) + ":" + str(endpoint["port"]) + "/" + db["database"]
    need(resources.get("mysql-schema") == {schema} and resources.get("endpoint") == {endpoint["endpoint_id"]}
         and resources.get("mysql-db") == resources.get("database") == {db["database"]},
         "ordinary source APP exact schema lease required")
    access = resources.get("mysql-access")
    if access:
        need(len(access) == 1 and json_unique(next(iter(access))) ==
             {"schema": schema, "operation": "exclusive", "complete": True, "tables": []},
             "ordinary source APP whole-schema exclusive access required")
    else:
        need("shared_access" not in db.get("lease_policy", {}), "shared APP needs a complete access declaration")
    identity = {"resource-id", "database-resource", "endpoint", "resource-scope", "scope", "gate", "mysql-db",
                "database", "mysql-access", "registry-sha256", "registry-tool-sha256", "environment", "consumer", "host", "version",
                "application-registry-sha256", "application-manifest-sha256", "application-env-sha256"}
    for digest, kind, item in rows:
        if kind in identity:
            continue
        lock = common / "peanut-admin-resource-leases/resources" / digest
        for field, expected in (("lease", lease_id), ("type", kind), ("value", item)):
            need(regular(lock / field).decode().strip() == expected, "ordinary APP physical lock changed")
    return common, directory, metadata


def same_source_window(lease_id, owner, close=False):
    env = dict(os.environ, PEANUT_RESOURCE_REGISTRY=str(ROOT / "server/private/resources/project-resources.json"))
    result = subprocess.run(["bash", str(ROOT / "scripts/project-resource-lease"), "show",
                             "--lease", lease_id, "--verify-locks"], cwd=ROOT, env=env, capture_output=True)
    need(result.returncode == 0, "ordinary APP native lease verification rejected")
    common, directory, metadata = same_source_lease(lease_id)
    parent = os.getppid()
    command = subprocess.run(["/bin/ps", "-p", str(parent), "-o", "comm="], capture_output=True, text=True)
    need(command.returncode == 0 and Path(command.stdout.strip()).name == "php"
         and owner == metadata["owner"], "source window requires its actual native PHP owner")
    with mutex(common):
        _, directory, metadata = same_source_lease(lease_id)
        previous = live_state(directory)
        if close:
            need(previous is not None and previous.get("phase") == "source-application"
                 and previous.get("status") == "RUNNING" and previous.get("pid") == parent
                 and original_process_alive(parent, previous.get("process_birth")),
                 "ordinary APP source window is not owned by this PHP process")
            previous.update(status="WINDOW_CLOSED", operation_terminal=True)
            save(directory / STATE, previous)
            return
        rebound = recovery_check(directory, metadata, previous, parent=parent) if previous and previous.get("recovery") else None
        need(previous is None or (previous.get("phase") == "source-application"
             and previous.get("status") in (("RECOVERY_ADMITTED", "WINDOW_CLOSED") if rebound else ("WINDOW_CLOSED",))
             and not original_process_alive(previous["pid"], previous.get("process_birth"))),
             "prior APP source window requires real terminal reconciliation")
        need(previous is None or int(rebound["effective_expires_at"] if rebound else previous["binding_expires_at"]) > time.time() + 60,
             "original source APP window expired; independent recovery rebind required")
        save(directory / STATE, {"protocol": "peanut.application-operation-state.v1", "phase": "source-application",
            "lease": lease_id, "owner": owner, "pid": parent, "process_birth": process_birth(parent),
            "child_pid": None, "status": "RUNNING", "operation_terminal": False,
            "binding_expires_at": metadata["expires_at"] if previous is None else previous["binding_expires_at"],
            **({"recovery": previous["recovery"]} if rebound else {})})


def consume_same_source(lease_id):
    common, directory, metadata = same_source_lease(lease_id)
    with mutex(common):
        same_source_lease(lease_id)
        state = live_state(directory)
        rebound = recovery_check(directory, metadata, state, parent=os.getppid()) if state and state.get("recovery") else None
        need(state is not None and state.get("phase") == "source-application"
             and state.get("status") == "RUNNING" and state.get("pid") == os.getppid()
             and original_process_alive(state["pid"], state.get("process_birth"))
             and int(state["binding_expires_at"]) <= int(metadata["expires_at"])
             and int(rebound["effective_expires_at"] if rebound else state["binding_expires_at"]) > time.time(),
             "ordinary APP recovery requires its actual live PHP window")
        return rebound or {"binding_expires_at": state["binding_expires_at"]}

def consume_application(proof, application_root, registry_path, resource_id, endpoint_id):
    """APP-owned read-only proof consumer. Never resolves or reads private Project."""
    app = canonical(application_root)
    registry_path = canonical(registry_path)
    need(registry_path == app / "server/private/resources/project-resources.json",
         "application consumer requires its own private registry")
    registry_raw = regular(registry_path, private=True)
    document = json_unique(registry_raw)
    need(document.get("authority", {}).get("role") == "application", "application registry authority required")
    databases = [row for row in document.get("resources", {}).get("databases", [])
                 if row.get("stable_resource_id") == resource_id]
    need(len(databases) == 1, "application database registration ambiguous")
    db = databases[0]
    policy = db.get("lease_policy", {})
    tool = policy.get("native_authority", {})
    common = canonical(tool["common_dir"])
    need(canonical(tool["root"]) == ROOT
         and canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip()) == common
         and git(ROOT, "rev-parse", "HEAD").decode().strip() == tool["commit"],
         "application native tool source/common-dir changed")
    closure = {"scripts/project-resource-lease", "scripts/project-resource-application.py",
               "scripts/project-resource-registry"}
    need(set(tool["files"]) == closure, "application native tool closure incomplete")
    for name, digest in tool["files"].items():
        raw = regular(ROOT / name)
        need(sha(raw) == digest and git(ROOT, "show", tool["commit"] + ":" + name) == raw,
             "application native tool bytes changed")
    need(not git(ROOT, "status", "--porcelain=v1", "--untracked-files=all"),
         "application native tool source dirty")
    proof = canonical(proof)
    need(proof.parent == common / "peanut-admin-resource-leases/leases",
         "application proof uses another lock registry")
    # A Popen child may reach its guard before the parent publishes child_pid.
    # This bounded read-only wait grants nothing; UNKNOWN is never accepted.
    for attempt in range(20):
        with mutex(common):
            state = live_state(proof)
            need(state is not None and state.get("status") == "RUNNING"
                 and state.get("phase") == "maintain", "held native APP maintenance window required")
            if state.get("child_pid") is not None:
                break
        time.sleep(0.05)
    else:
        raise RuntimeError("native APP child identity was not published")
    with mutex(common):
        state = live_state(proof)
        meta = dict(table(proof / "metadata.tsv", 2))
        rows = table(proof / "resources.tsv", 3)
        resources = resource_map(rows)
        now = time.time()
        need(state.get("status") == "RUNNING" and state.get("phase") == "maintain"
             and state.get("lease") == proof.name and state.get("owner") == meta["owner"]
             and state.get("child_pid") == os.getppid()
             and original_process_alive(state["child_pid"], state.get("child_birth"))
             and original_process_alive(state["pid"], state.get("process_birth")),
             "application verifier is outside the actual native child process")
        rebound = recovery_check(proof, meta, state, parent=os.getppid()) if state.get("recovery") else None
        need(meta["status"] == "ACTIVE" and meta["lease"] == proof.name
             and meta["owner"] == meta["thread"]
             and meta["gate"] == db["lease_gate"] == "permanent-app-development"
             and meta["candidate_repository"] == meta["worktree"] == str(app)
             and canonical(git(app, "rev-parse", "--show-toplevel").decode().strip()) == app
             and git(app, "rev-parse", "HEAD").decode().strip() == meta["candidate"]
             and int(meta["created_at"]) <= now < int(meta["expires_at"])
             and int(meta["expires_at"]) >= (rebound["effective_expires_at"] if rebound else state["binding_expires_at"]) > now,
             "application native lease identity/expiry changed")
        need(not git(app, "diff", "--name-only", "HEAD") and not git(app, "diff", "--cached", "--name-only"),
             "application tracked source changed")
        endpoint = db["upstream_endpoint"]
        schema = str(endpoint["host"]) + ":" + str(endpoint["port"]) + "/" + db["database"]
        need(endpoint["endpoint_id"] == endpoint_id and policy.get("mode") == "schema"
             and policy.get("physical_endpoint") == schema.rpartition("/")[0],
             "application exact schema endpoint changed")
        required = {"resource-id": {resource_id}, "endpoint": {endpoint_id},
                    "application-root": {str(app)}, "worktree": {str(app), str(ROOT)},
                    "mysql-schema": {schema}, "mysql-db": {db["database"]}, "database": {db["database"]},
                    "application-registry-sha256": {sha(registry_raw)},
                    "application-manifest-sha256": {sha(regular(app / ".peanut/application-manifest.json"))},
                    "application-env-sha256": {sha(regular(app / "server/.env", private=True))},
                    "mysql-access": {json.dumps({"schema": schema, "operation": "exclusive",
                                                 "complete": True, "tables": []},
                                                sort_keys=True, separators=(",", ":"))}}
        need(all(resources.get(key) == values for key, values in required.items())
             and len(resources.get(BINDING, set())) == 1
             and resources.get(CONTRACT) == {state["operation_sha256"]},
             "application native resource declaration changed")
        # Consume only native APP/lock facts, without reading private Project.
        retained = resources.get("retained-parent", set())
        need(len(retained) <= 1, "application retained parent is ambiguous")
        operation = {"application": {"root": str(app), "commit": meta["candidate"]},
                     "owner": meta["owner"], "thread": meta["thread"],
                     "stable_resource_id": next(iter(resources[BINDING]))}
        if retained:
            operation["retained_authority"] = json_unique(next(iter(retained)))
        need(meta["parent_lease"] == operation.get("retained_authority", {}).get("lease", ""),
             "application retained parent declaration changed")
        parent = retained_parent(operation, common, require_live=rebound is None)
        if parent:
            need(rebound is not None or int(meta["expires_at"]) <= int(parent[1]["expires_at"]), "application TTL exceeds retained parent")
            retained_guard(operation, common, proof.name, expires=int(meta["expires_at"]),
                           allow_expired_parent=rebound is not None)
        # The central producer admits this exclusive schema using its complete
        # policy. The APP consumes the actual physical locks under that same mutex.
        identity = {"resource-id", "database-resource", "endpoint", "resource-scope", "scope", "gate",
                    "mysql-db", "database", "mysql-access", "registry-sha256", "registry-tool-sha256", "application-registry-sha256", "application-manifest-sha256", "application-env-sha256",
                    "environment", "consumer", "host", "version"}
        for digest, kind, item in rows:
            if kind in identity:
                continue
            lock = common / "peanut-admin-resource-leases/resources" / digest
            for field, expected in (("lease", physical_holder(operation, common, proof.name, kind, item)), ("type", kind), ("value", item)):
                need(regular(lock / field).decode().strip() == expected, "application physical lock changed")
        return (rebound or {"binding_expires_at": state["binding_expires_at"]}) | {"operation_sha256": state["operation_sha256"]}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("resolve", "verify", "release-check", "execute", "consume", "recovery-admit", "recovery-info", "source-window-enter", "source-window-close", "retained-reserve", "retained-verify", "retained-renew", "parent-verify"))
    parser.add_argument("--operation")
    parser.add_argument("--lease")
    parser.add_argument("--owner")
    parser.add_argument("--phase", choices=("protect", "install", "maintain"))
    parser.add_argument("--step", choices=("plan", "capture", "rehearse", "rebind", "apply", "verify", "restore", "open"))
    parser.add_argument("--metadata")
    parser.add_argument("--resources")
    parser.add_argument("--parent-lease")
    parser.add_argument("--candidate")
    parser.add_argument("--candidate-repository")
    parser.add_argument("--thread")
    parser.add_argument("--expires", type=int)
    for key in ("proof", "application-root", "registry", "resource-id", "endpoint-id"):
        parser.add_argument("--" + key)
    args = parser.parse_args()
    if args.action in ("retained-reserve", "retained-verify", "retained-renew", "parent-verify"):
        need(args.step is None or (args.action in ("retained-verify", "parent-verify")
                                  and args.step in ("restore", "open")), "retained inspection step invalid")
        if args.step:
            operation, directory, _ = lease(args.lease, recovery_step=args.step)
            common = directory.parent.parent.parent
            need(operation["stable_resource_id"] == args.operation and live_state(directory).get("recovery"),
                 "retained inspection requires exact admitted original recovery")
        else:
            operation, _, common, _ = authority(args.operation)
        need(args.owner == operation["owner"], "retained operation owner mismatch")
        parent = retained_parent(operation, common, require_live=args.step is None)
        need(parent is not None, "operation has no registered retained parent")
        if args.action == "parent-verify":
            need(args.parent_lease == operation["retained_authority"]["lease"]
                 and args.thread == operation["thread"]
                 and args.candidate == operation["application"]["commit"]
                 and args.candidate_repository == args.application_root == operation["application"]["root"],
                 "retained operation parent identities differ")
            print("same-domain" if parent[0] == common else "app-domain")
        else:
            need(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,63}", args.lease), "invalid retained child ID")
            if args.action != "retained-verify":
                need(regular(common / "peanut-admin-resource-leases/mutex/pid").decode().strip() == str(os.getppid()),
                     "retained child mutation requires actual native central mutex")
                need(time.time() < args.expires <= int(parent[1]["expires_at"]), "retained child TTL exceeds parent")
            retained_guard(operation, common, args.lease, args.action.removeprefix("retained-"), args.expires,
                           allow_expired_parent=args.step is not None)
    elif args.action == "recovery-admit":
        admit_recovery(args.lease, args.owner)
    elif args.action == "recovery-info":
        common, directory, metadata = same_source_lease(args.lease)
        with mutex(common):
            print(json.dumps(recovery_check(directory, metadata, live_state(directory), parent=os.getppid())))
    elif args.action in ("source-window-enter", "source-window-close"):
        same_source_window(args.lease, args.owner, args.action == "source-window-close")
    elif args.action == "consume":
        print(json.dumps(consume_application(args.proof, args.application_root, args.registry, args.resource_id, args.endpoint_id)))
    elif args.action == "resolve":
        operation, digest, common, registry_sha = authority(args.operation)
        need(args.owner == operation["owner"], "operation owner mismatch")
        print(json.dumps({"worktree": operation["application"]["root"],
                          "candidate": operation["application"]["commit"],
                          "parent_lease": operation.get("retained_authority", {}).get("lease", ""),
                          "parent_expires_at": int(retained_parent(operation, common)[1]["expires_at"]) if "retained_authority" in operation else None,
                          "resources": {key: sorted(values) for key, values in
                                        expected_resources(operation, digest, registry_sha).items()}}))
    elif args.action == "verify":
        validate(json_unique(args.metadata), {key: set(values) for key, values in json_unique(args.resources).items()})
    elif args.action == "release-check":
        common = canonical(git(ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir").decode().strip())
        directory = common / "peanut-admin-resource-leases/leases" / args.lease
        value = live_state(directory)
        if value is not None:
            metadata = dict(table(directory / "metadata.tsv", 2))
            need(args.owner == metadata["owner"] == value["owner"], "operation release owner mismatch")
            need(not original_process_alive(value["pid"], value.get("process_birth")),
                 "operation owner process is still active")
            if value.get("child_pid") is not None:
                need(not original_process_alive(value["child_pid"], value.get("child_birth")),
                     "native operation child is still active")
            if value.get("phase") == "source-application":
                need(value.get("status") == "WINDOW_CLOSED" and value.get("operation_terminal") is True,
                     "unknown source APP window requires independent native reconciliation")
                same_source_lease(args.lease, allow_expired=True)
                receipt = ROOT / "server/private/maintenance" / ("source-window-" + args.lease + "-" + str(time.time_ns()) + ".json")
                save(receipt, dict(value, actual_process_terminal=True))
                (directory / STATE).unlink()
                return
            operation, _, _ = lease(args.lease, allow_expired=True)
            if value["status"] != "COMPLETED" or value["actual_process_terminal"] is not True:
                review = operation.get("terminal_review")
                need(isinstance(review, dict), "UNKNOWN needs a registered independent terminal review")
                raw = regular(canonical(review["path"]), private=True)
                project = project_authority()[1]
                path = canonical(review["path"])
                need(project in path.parents, "terminal review must belong to the registered Project")
                ref = git(project, "rev-parse", "HEAD").decode().strip()
                relative = str(path.relative_to(project))
                need(git(project, "ls-tree", "--name-only", ref, "--", relative).decode().strip() == relative,
                     "terminal review must be the exact versioned Project result")
                need(git(project, "show", ref + ":" + str(path.relative_to(project))) == raw,
                     "terminal review must be the exact versioned Project result")
                proof = json_unique(raw)
                need(proof.get("verdict") == "SAFE_TO_RELEASE"
                     and proof.get("lease") == args.lease
                     and proof.get("state_sha256") == sha(regular(directory / STATE, private=True))
                     and proof.get("reviewer_thread") not in (None, "", value["owner"])
                     and proof.get("actual_process_terminal") is True,
                     "independent terminal review does not bind this exact stopped operation")
                value["terminal_review"] = {"sha256": sha(raw), "reviewer_thread": proof["reviewer_thread"],
                                            "project_commit": ref}
            receipt = canonical(operation["receipt_root"]) / (args.lease + ".json")
            need(receipt.parent.is_dir(), "operation terminal receipt parent unavailable")
            need(not receipt.exists(), "operation terminal receipt already exists; reconcile before release")
            save(receipt, value)
            need(json_unique(regular(receipt, private=True)) == value, "terminal receipt copy differs")
            (directory / STATE).unlink()
    else:
        execute(args.lease, args.owner, args.phase, args.step)


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as error:
        print("resource-application: " + str(error), file=sys.stderr)
        sys.exit(1)
