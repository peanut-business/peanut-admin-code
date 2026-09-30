#!/usr/bin/env python3
"""Bound MySQL recovery point for the existing server update journal."""

import argparse
import gzip
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys

SNAPSHOT_NAMES = (".env", "docker/.env", "private/installation/installed.json",
                  "private/installation/baseline.json", "private/installation/deployment.json",
                  "private/resources/project-resources.json")
SNAPSHOT_SET = set(SNAPSHOT_NAMES)
HEX64 = re.compile(r"[a-f0-9]{64}\Z")
IMAGE_ID = re.compile(r"sha256:[a-f0-9]{64}\Z")


def sha(path):
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def regular(path, mode=None):
    if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1:
        raise ValueError(f"missing or unsafe recovery input: {path}")
    if mode is not None and (path.stat().st_mode & 0o777) != mode:
        raise ValueError(f"recovery input has unsafe mode: {path}")


def safe_parents(root, name):
    if name not in SNAPSHOT_SET or name.startswith("/") or ".." in Path(name).parts:
        raise ValueError("snapshot path is not in the exact recovery allowlist")
    cursor = root
    for part in name.split("/")[:-1]:
        cursor /= part
        if cursor.is_symlink() or not cursor.is_dir():
            raise ValueError("snapshot parent is linked or unavailable")


def read_json(path):
    regular(path, 0o600)
    return json.loads(path.read_text())


def verification_key(server):
    path = server / "private/installation/update-verification.key"
    regular(path, 0o600)
    key = path.read_text().strip()
    if not HEX64.fullmatch(key):
        raise ValueError("private update verification key is invalid")
    return key.encode()


def signature_for(server, path):
    regular(path, 0o600)
    return hmac.new(verification_key(server), path.read_bytes(), hashlib.sha256).hexdigest()


def check_signature(server, path):
    signature = path.with_name(path.name + ".hmac")
    regular(signature, 0o600)
    if not hmac.compare_digest(signature.read_text().strip(), signature_for(server, path)):
        raise ValueError("recovery record signature differs from instance")


def check_backup_signature(server, workspace):
    check_signature(server, workspace / "backup.json")


def durable(path, data):
    if path.exists() or path.is_symlink():
        raise ValueError(f"recovery record already exists: {path}")
    with path.open("xb") as stream:
        os.fchmod(stream.fileno(), 0o600)
        stream.write(data)
        stream.flush()
        os.fsync(stream.fileno())
    fd = os.open(path.parent, os.O_RDONLY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def env(path):
    regular(path, 0o600)
    result = {}
    for line in path.read_text().splitlines():
        if not line or line.startswith("#"):
            continue
        key, separator, value = line.partition("=")
        if not separator or key in result:
            raise ValueError("instance environment contains a missing or duplicated key")
        result[key] = value
    return result


def docker(*args, cwd=None, input=None, input_file=None, output=None, timeout=60):
    try:
        process = subprocess.run(["docker", *args], cwd=cwd, input=input, stdin=input_file,
                                 stdout=output or subprocess.PIPE,
                                 stderr=subprocess.PIPE, check=False, timeout=timeout)
    except subprocess.TimeoutExpired as error:
        raise RuntimeError(f"docker command timed out after {timeout}s") from error
    if process.returncode:
        raise RuntimeError(f"docker command exited {process.returncode}; stderr_sha256={hashlib.sha256(process.stderr).hexdigest()}; stderr_bytes={len(process.stderr)}")
    return process.stdout.decode().strip() if output is None else ""


def compose(docker_dir, *args, output=None, input=None, input_file=None, timeout=60):
    return docker("compose", "--env-file", ".env", "-f", "compose.yaml", *args,
                  cwd=docker_dir, output=output, input=input, input_file=input_file, timeout=timeout)


def instance(args):
    server, workspace = args.server, args.workspace
    if not server.is_absolute() or not workspace.is_absolute() or server.resolve() != server or workspace.resolve() != workspace:
        raise ValueError("server and workspace must be canonical ordinary directories")
    plan_path = workspace / "plan.json"
    plan = read_json(plan_path)
    journal = read_json(workspace / "journal.json")
    plan_sha = sha(plan_path)
    if plan.get("protocol") != "peanut.server-update-plan.v1" or journal.get("plan_sha256") != plan_sha or journal.get("update_id") != plan.get("update_id"):
        raise ValueError("recovery point differs from active update plan")
    pointer = read_json(server / "runtime/upgrade/current-update.json")
    if pointer.get("update_id") != plan.get("update_id") or pointer.get("workspace") != str(workspace):
        raise ValueError("active instance pointer differs from recovery workspace")
    marker = read_json(server / "runtime/upgrade/maintenance.json")
    if marker.get("update_id") != plan["update_id"] or marker.get("status") != "active":
        raise ValueError("matching maintenance marker is required")
    backend = env(server / ".env")
    database = backend.get("DB_NAME", "")
    if not re.fullmatch(r"[A-Za-z0-9_]{1,64}", database):
        raise ValueError("DB_NAME is invalid")
    identity = {"resource_id": backend.get("PEANUT_DATABASE_RESOURCE_ID"),
                "endpoint_id": backend.get("PEANUT_DATABASE_ENDPOINT_ID"), "database": database}
    if not all(identity.values()):
        raise ValueError("database resource identity is incomplete")
    registry_path = server / "private/resources/project-resources.json"
    if not registry_path.exists() and not registry_path.is_symlink():
        registry_path = server / "resources/project-resources.json"
    registry = json.loads(registry_path.read_text()) if registry_path.is_file() and not registry_path.is_symlink() else None
    rows = registry.get("resources", {}).get("databases") if isinstance(registry, dict) else None
    selected = [row for row in rows if isinstance(row, dict) and row.get("stable_resource_id") == identity["resource_id"]] if isinstance(rows, list) else []
    endpoint = selected[0].get("container_endpoint", {}) if len(selected) == 1 else {}
    if len(selected) != 1 or selected[0].get("database") != database or selected[0].get("service_type") != "mysql" or selected[0].get("fallback") != "none" \
        or endpoint.get("endpoint_id") != identity["endpoint_id"] or str(endpoint.get("host")) != backend.get("DB_HOST") or str(endpoint.get("port")) != backend.get("DB_PORT"):
        raise ValueError("database identity differs from the instance registry")
    installed = server / "private/installation/installed.json"
    baseline = server / "private/installation/baseline.json"
    regular(installed)
    regular(baseline)
    if sha(installed) != plan.get("source", {}).get("installed_receipt_sha256") or sha(baseline) != plan.get("source", {}).get("baseline_sha256"):
        raise ValueError("installed instance identity differs from update source")
    release = server / ".peanut/release-identity.json"
    regular(release)
    if sha(release) not in {plan.get("source", {}).get("identity_sha256"), plan.get("target", {}).get("identity_sha256")}:
        raise ValueError("current release identity differs from update")
    return server, workspace, plan, journal, plan_sha, identity


def assert_stopped(server):
    docker_dir = server / "docker"
    project = env(docker_dir / ".env").get("COMPOSE_PROJECT_NAME", "")
    if not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,62}", project):
        raise ValueError("Compose project identity is invalid")
    services = compose(docker_dir, "ps", "--status", "running", "--services").splitlines()
    if services != ["mysql"]:
        raise ValueError("business writers are not drained")
    images = {}
    for service in ("php", "nginx", "mysql"):
        ids = compose(docker_dir, "ps", "-a", "-q", service).splitlines()
        discovered = docker("ps", "-a", "--no-trunc", "--filter", f"label=com.docker.compose.project={project}",
                            "--filter", f"label=com.docker.compose.service={service}", "--format", "{{.ID}}").splitlines()
        if len(ids) != 1 or discovered != ids:
            raise ValueError("Compose service does not have exactly one bound container")
        details = json.loads(docker("inspect", "--format", "{{json .}}", ids[0]))
        labels = details.get("Config", {}).get("Labels", {})
        mounts = details.get("Mounts", [])
        status = details.get("State", {}).get("Status")
        if details.get("Id") != ids[0] or labels.get("com.docker.compose.project") != project \
            or labels.get("com.docker.compose.service") != service \
            or labels.get("com.docker.compose.project.working_dir") != str(docker_dir) \
            or status != ("running" if service == "mysql" else "exited"):
            raise ValueError("Compose container identity, working directory or state changed")
        expected = {"php": (server, "/var/www/peanut-admin/server"),
                    "nginx": (server / "public", "/var/www/peanut-admin/server/public"),
                    "mysql": (docker_dir / "mysql", "/var/lib/mysql")}[service]
        if sum(m.get("Type") == "bind" and m.get("Source") == str(expected[0]) and m.get("Destination") == expected[1] for m in mounts) != 1:
            raise ValueError("Compose container mount belongs to another instance")
        image = details.get("Image", "")
        if not IMAGE_ID.fullmatch(image):
            raise ValueError("Compose image ID is invalid")
        images[service] = image
    running = docker("ps", "--no-trunc", "--filter", f"label=com.docker.compose.project={project}", "--format", "{{.ID}}").splitlines()
    mysql_id = compose(docker_dir, "ps", "-a", "-q", "mysql")
    if running != [mysql_id]:
        raise ValueError("another Compose writer is running")
    return {"project": project, "images": images}


def snapshot_paths(server):
    return [(name, server / name) for name in SNAPSHOT_NAMES]


def db_probe(server, database):
    output = compose(server / "docker", "exec", "-T", "mysql", "sh", "-c",
                     'MYSQL_PWD="$(cat /run/secrets/mysql-root-password)" exec mysql -N -B -uroot --database="$1" -e "SELECT @@server_uuid,DATABASE(),@@character_set_database,@@collation_database,(SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE())"',
                     "sh", database)
    parts = output.split("\t")
    if len(parts) != 5 or not re.fullmatch(r"[a-fA-F0-9-]{36}", parts[0]) or parts[1] != database \
        or not re.fullmatch(r"[A-Za-z0-9_]{1,64}", parts[2]) or not re.fullmatch(r"[A-Za-z0-9_]{1,64}", parts[3]) \
        or not parts[4].isdigit():
        raise ValueError("MySQL server or schema identity probe is invalid")
    return {"server_uuid": parts[0].lower(), "database": parts[1], "charset": parts[2],
            "collation": parts[3], "table_count": int(parts[4])}


def backup(args):
    server, workspace, plan, journal, plan_sha, identity = instance(args)
    if journal.get("status") != "applying" or any(row.get("status") != "pending" for row in journal["operations"]):
        raise ValueError("recovery point must precede program changes")
    verification_key(server)
    runtime = assert_stopped(server)
    probe = db_probe(server, identity["database"])
    recovery = workspace / "recovery"
    if recovery.exists() or recovery.is_symlink() or (workspace / "backup.json").exists():
        raise ValueError("recovery point already exists")
    recovery.mkdir(mode=0o700)
    snapshots = {}
    for name, source in snapshot_paths(server):
        safe_parents(server, name)
        if not source.exists() and not source.is_symlink():
            snapshots[name] = None
            continue
        regular(source, None if name == "private/resources/project-resources.json" else 0o600)
        target = recovery / name
        target.parent.mkdir(parents=True, exist_ok=True)
        with source.open("rb") as src, target.open("xb") as dst:
            os.fchmod(dst.fileno(), 0o600)
            shutil.copyfileobj(src, dst)
            dst.flush()
            os.fsync(dst.fileno())
        if sha(source) != sha(target):
            raise ValueError("recovery snapshot changed during copy")
        snapshots[name] = sha(target)
    if snapshots["private/installation/installed.json"] != plan["source"]["installed_receipt_sha256"] or snapshots["private/installation/baseline.json"] != plan["source"]["baseline_sha256"]:
        raise ValueError("installation identity changed after planning")
    docker_dir = server / "docker"
    raw = recovery / "database.sql"
    with raw.open("xb") as output:
        os.fchmod(output.fileno(), 0o600)
        compose(docker_dir, "exec", "-T", "mysql", "sh", "-c",
                'MYSQL_PWD="$(cat /run/secrets/mysql-root-password)" exec mysqldump --single-transaction --routines --triggers --events --hex-blob --databases --add-drop-database -uroot -- "$1"',
                "sh", identity["database"], output=output, timeout=600)
        output.flush()
        os.fsync(output.fileno())
    if raw.stat().st_size == 0:
        raise ValueError("database dump is empty")
    validate_dump(raw, identity["database"])
    compressed = recovery / "database.sql.gz"
    with raw.open("rb") as src, compressed.open("xb") as dst:
        os.fchmod(dst.fileno(), 0o600)
        with gzip.GzipFile(fileobj=dst, mode="wb", mtime=0) as zipper:
            shutil.copyfileobj(src, zipper)
        dst.flush()
        os.fsync(dst.fileno())
    with gzip.open(compressed, "rb") as check:
        if hashlib.sha256(check.read()).hexdigest() != sha(raw):
            raise ValueError("compressed database dump failed round-trip verification")
    raw.unlink()
    record = {"protocol": "peanut.server-update-backup.v1", "update_id": plan["update_id"],
              "plan_sha256": plan_sha, "database_identity": identity, "database_dump_sha256": sha(compressed),
              "source_images": runtime["images"], "compose_project": runtime["project"],
              "mysql_identity": probe, "snapshots": snapshots}
    backup_bytes = json.dumps(record, sort_keys=True).encode()
    durable(workspace / "backup.json", backup_bytes)
    durable(workspace / "backup.json.hmac", (hmac.new(verification_key(server), backup_bytes, hashlib.sha256).hexdigest() + "\n").encode())
    print(json.dumps({"status": "complete", "update_id": plan["update_id"], "database_dump_sha256": record["database_dump_sha256"]}))


def verify(args):
    server, workspace, plan, journal, plan_sha, identity = instance(args)
    check_backup_signature(server, workspace)
    record = read_json(workspace / "backup.json")
    if record.get("protocol") != "peanut.server-update-backup.v1" or record.get("update_id") != plan["update_id"] or record.get("plan_sha256") != plan_sha or record.get("database_identity") != identity:
        raise ValueError("recovery point is bound to another plan or database")
    snapshots = record.get("snapshots")
    if not isinstance(snapshots, dict) or set(snapshots) != SNAPSHOT_SET:
        raise ValueError("snapshot set differs from the exact recovery allowlist")
    for name in SNAPSHOT_NAMES:
        safe_parents(workspace / "recovery", name)
        expected = snapshots[name]
        if expected is not None and (not isinstance(expected, str) or not HEX64.fullmatch(expected)):
            raise ValueError("snapshot digest is invalid")
    compressed = workspace / "recovery/database.sql.gz"
    if compressed.parent.is_symlink() or not compressed.parent.is_dir():
        raise ValueError("recovery directory is unsafe")
    regular(compressed, 0o600)
    if sha(compressed) != record.get("database_dump_sha256"):
        raise ValueError("database recovery bytes changed")
    validate_dump(compressed, identity["database"], compressed=True)
    for name in SNAPSHOT_NAMES:
        expected = snapshots[name]
        if expected is None:
            if (workspace / "recovery" / name).exists() or (workspace / "recovery" / name).is_symlink():
                raise ValueError("unexpected recovery snapshot exists")
            continue
        path = workspace / "recovery" / name
        regular(path, 0o600)
        if sha(path) != expected:
            raise ValueError("installation or configuration recovery bytes changed")
    if snapshots["private/installation/installed.json"] != plan["source"]["installed_receipt_sha256"] \
        or snapshots["private/installation/baseline.json"] != plan["source"]["baseline_sha256"] \
        or snapshots["private/installation/deployment.json"] != plan["source"]["deployment_state_sha256"]:
        raise ValueError("recovery snapshot differs from original installation identity")
    runtime = assert_stopped(server)
    if record.get("source_images") != runtime["images"] or record.get("compose_project") != runtime["project"]:
        raise ValueError("source image or Compose project changed after backup")
    current_probe = db_probe(server, identity["database"])
    if current_probe["server_uuid"] != record.get("mysql_identity", {}).get("server_uuid") \
        or current_probe["database"] != record.get("mysql_identity", {}).get("database"):
        raise ValueError("MySQL server or schema changed after backup")
    return server, workspace, plan, journal, record


def validate_dump(path, database, compressed=False):
    seen_use = seen_create = seen_drop = False
    opener = gzip.open if compressed else open
    with opener(path, "rb") as stream:
        for raw in stream:
            line = re.sub(r"/\*![0-9]+\s*|\*/", " ", raw.decode("utf-8", errors="replace"))
            for action in ("USE", "CREATE DATABASE", "DROP DATABASE"):
                match = re.search(r"\b" + action + r"\b(?:\s+IF\s+(?:NOT\s+)?EXISTS)?\s+`([^`]+)`", line, re.IGNORECASE)
                if match:
                    if match.group(1) != database:
                        raise ValueError("database dump names another schema")
                    seen_use |= action == "USE"
                    seen_create |= action == "CREATE DATABASE"
                    seen_drop |= action == "DROP DATABASE"
    if not (seen_use and seen_create and seen_drop):
        raise ValueError("database dump lacks a complete same-schema restore contract")


def restore(args):
    server, workspace, plan, journal, record = verify(args)
    if journal.get("activation_started") is True or journal.get("status") == "completed":
        raise ValueError("activation started; automatic old database recovery is forbidden")
    state_path = workspace / "database-recovery.json"
    if state_path.exists() or state_path.is_symlink():
        state = read_json(state_path)
        if state.get("status") == "completed" and state.get("backup_sha256") == sha(workspace / "backup.json"):
            check_signature(server, state_path)
            if db_probe(server, record["database_identity"]["database"]) != record["mysql_identity"]:
                raise ValueError("already restored database no longer matches recovery point")
            print(json.dumps({"status": "already_restored", "update_id": plan["update_id"]}))
            return
        raise ValueError("database restore has an unknown or interrupted result; inspect before retry")
    durable(state_path, json.dumps({"status": "started", "update_id": plan["update_id"],
                                   "backup_sha256": sha(workspace / "backup.json")}).encode())
    dump = workspace / "recovery/database.sql.gz"
    docker_dir = server / "docker"
    database = record["database_identity"]["database"]
    raw = workspace / "recovery/database-restore.sql"
    if raw.exists() or raw.is_symlink():
        raise ValueError("restore staging file already exists")
    try:
        with gzip.open(dump, "rb") as source, raw.open("xb") as target:
            os.fchmod(target.fileno(), 0o600)
            shutil.copyfileobj(source, target)
            target.flush()
            os.fsync(target.fileno())
        with raw.open("rb") as statement:
            compose(docker_dir, "exec", "-T", "mysql", "sh", "-c",
                    'MYSQL_PWD="$(cat /run/secrets/mysql-root-password)" exec mysql -uroot',
                    input_file=statement, timeout=600)
    finally:
        raw.unlink(missing_ok=True)
    restored = db_probe(server, database)
    if restored != record["mysql_identity"]:
        raise ValueError("restored schema identity or table count differs from recovery point")
    for name, expected in record["snapshots"].items():
        path = server / name
        if expected is None:
            if path.exists() or path.is_symlink():
                raise ValueError("previously absent installation state appeared during recovery")
            continue
        regular(path)
        if sha(path) != expected:
            raise ValueError("protected installation or configuration state changed during update")
    temporary = workspace / "database-recovery.complete"
    durable(temporary, json.dumps({"status": "completed", "update_id": plan["update_id"],
                                   "backup_sha256": sha(workspace / "backup.json"),
                                   "mysql_identity": restored}).encode())
    os.replace(temporary, state_path)
    durable(state_path.with_name(state_path.name + ".hmac"), (signature_for(server, state_path) + "\n").encode())
    print(json.dumps({"status": "completed", "update_id": plan["update_id"]}))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=("backup", "verify", "restore"))
    parser.add_argument("--server", type=Path, required=True)
    parser.add_argument("--workspace", type=Path, required=True)
    args = parser.parse_args()
    {"backup": backup, "verify": lambda a: (verify(a), print('{"status":"verified"}')),
     "restore": restore}[args.operation](args)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError, KeyError, json.JSONDecodeError, gzip.BadGzipFile) as error:
        print(f"update-recovery: {error}", file=sys.stderr)
        sys.exit(1)
