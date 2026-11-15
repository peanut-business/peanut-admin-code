#!/usr/bin/env python3
"""Read-only paired recovery receipt verifier for ServerUpdateGateTest."""
from __future__ import annotations

import gzip
import hashlib
import hmac
import inspect
import json
import os
from pathlib import Path
import re
import tarfile
from typing import Any


def _build_paired_recovery_verifier() -> dict[str, Any]:
    """Build read-only verifier helpers for bundled and registered paired recovery evidence."""
    snapshot_names = (
        ".env",
        "docker/.env",
        "private/installation/installed.json",
        "private/installation/baseline.json",
        "private/installation/deployment.json",
        "private/resources/project-resources.json",
        "docker/collaboration.env",
    )
    snapshot_set = set(snapshot_names)
    storage_names = (
        "public/storage",
        "public/uploads",
        "private/storage",
        "private/installation",
        "private/collaboration",
        "docker/tls",
    )
    hex64 = re.compile(r"[a-f0-9]{64}\Z")

    def rec_sha(path: Path) -> str:
        digest = hashlib.sha256()
        with path.open("rb") as stream:
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()

    def regular(path: Path, mode: int | None = None) -> None:
        if path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1:
            raise ValueError(f"missing or unsafe recovery input: {path}")
        if mode is not None and (path.stat().st_mode & 0o777) != mode:
            raise ValueError(f"recovery input has unsafe mode: {path}")

    def safe_parents(root: Path, name: str) -> None:
        if name not in snapshot_set | set(storage_names) or name.startswith("/") or ".." in Path(name).parts:
            raise ValueError("snapshot path is not in the exact recovery allowlist")
        cursor = root
        for part in name.split("/")[:-1]:
            cursor /= part
            if cursor.is_symlink() or not cursor.is_dir():
                raise ValueError("snapshot parent is linked or unavailable")

    def rec_json(path: Path) -> dict[str, Any]:
        regular(path, 0o600)
        return json.loads(path.read_text())

    def verification_key(server: Path) -> bytes:
        legacy = server / "private/installation/update-verification.key"
        if legacy.exists() or legacy.is_symlink():
            raise ValueError(
                "OWNER_MAINTENANCE_REQUIRED: previous fixed-tool transaction and legacy verification key require their original owner"
            )
        parent = server / "private"
        if parent.is_symlink() or not parent.is_dir() or parent.stat().st_mode & 0o022 or parent.stat().st_uid not in (0, server.stat().st_uid):
            raise ValueError("private maintenance parent requires its deployment owner")
        directory = parent / "maintenance"
        if directory.is_symlink() or not directory.is_dir() or directory.stat().st_mode & 0o777 != 0o700:
            raise ValueError("private maintenance directory is unsafe")
        if directory.stat().st_uid not in (0, server.stat().st_uid):
            raise ValueError("private maintenance directory requires its deployment owner")
        path = directory / "update-verification.key"
        regular(path, 0o600)
        key = path.read_text().strip()
        if not hex64.fullmatch(key):
            raise ValueError("private update verification key is invalid")
        return key.encode()

    def check_signature(server: Path, path: Path) -> None:
        signature = path.with_name(path.name + ".hmac")
        regular(signature, 0o600)
        regular(path, 0o600)
        expected_hmac = hmac.new(verification_key(server), path.read_bytes(), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(signature.read_text().strip(), expected_hmac):
            raise ValueError("recovery record signature differs from instance")

    def check_backup_signature(server: Path, workspace: Path) -> None:
        check_signature(server, workspace / "backup.json")

    def parse_env(path: Path) -> dict[str, str]:
        regular(path, 0o600)
        result: dict[str, str] = {}
        for line in path.read_text().splitlines():
            if not line or line.startswith("#"):
                continue
            key, separator, value = line.partition("=")
            if not separator or key in result:
                raise ValueError("instance environment contains a missing or duplicated key")
            result[key] = value
        return result

    def product_binding(workspace: Path) -> dict[str, Any]:
        binding = rec_json(workspace / "product-binding.json")
        product = rec_json(Path(binding["plan_path"]))
        digest = product.pop("plan_sha256", None)
        encode = lambda value: json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()
        if (
            product.get("protocol") != "peanut.product-upgrade-plan.v2"
            or product.get("workspace") != str(workspace)
            or digest != binding.get("plan_sha256")
            or digest != "sha256:" + hashlib.sha256(encode(product)).hexdigest()
        ):
            raise ValueError("phase workspace is not bound to the product plan")
        state = rec_json(Path(product["state_path"]))
        state_digest = state.pop("state_sha256", None)
        if (
            state.get("protocol") != "peanut.product-upgrade-state.v1"
            or state.get("candidate") != product["candidate"]
            or state.get("plan_sha256") != digest
            or state_digest != "sha256:" + hashlib.sha256(encode(state)).hexdigest()
        ):
            raise ValueError("product state digest differs from phase workspace")
        product["plan_sha256"] = digest
        return product

    def storage_inventory(root: Path) -> dict[str, list[Any]]:
        if root.is_symlink() or not root.is_dir():
            raise ValueError("protected storage root is unsafe")
        rows: dict[str, list[Any]] = {}
        for base, dirs, files in os.walk(root, followlinks=False):
            for name in dirs + files:
                path = Path(base) / name
                if path.is_symlink() or (not path.is_dir() and not path.is_file()) or "\\" in name or any(ord(c) < 32 for c in name):
                    raise ValueError("protected storage contains links or special paths")
                if path.is_file():
                    regular(path)
                rows[path.relative_to(root).as_posix()] = [rec_sha(path) if path.is_file() else None, path.stat().st_mode & 0o777]
        return rows

    def storage_archive(path: Path) -> Path:
        regular(path, 0o600)
        with tarfile.open(path, "r:gz") as archive:
            seen: set[str] = set()
            for member in archive.getmembers():
                name = member.name.rstrip("/")
                if (
                    name in seen
                    or not name
                    or name.startswith("/")
                    or "\\" in name
                    or any(ord(c) < 32 for c in name)
                    or any(part in ("", ".", "..") for part in name.split("/"))
                    or not (member.isdir() or member.isfile())
                ):
                    raise ValueError("protected storage archive contains unsafe paths")
                seen.add(name)
        return path

    def validate_dump(path: Path, database: str) -> None:
        seen_use = seen_create = seen_drop = False
        with gzip.open(path, "rb") as stream:
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

    def verify_backup_attachments(server: Path, workspace: Path, plan: dict[str, Any], plan_sha: str, identity: dict[str, Any]) -> dict[str, Any]:
        check_backup_signature(server, workspace)
        record = rec_json(workspace / "backup.json")
        if (
            record.get("protocol") != "peanut.server-update-backup.v1"
            or record.get("update_id") != plan["update_id"]
            or record.get("plan_sha256") != plan_sha
            or record.get("database_identity") != identity
        ):
            raise ValueError("recovery point is bound to another plan or database")
        product = product_binding(workspace)
        if record.get("product_plan_sha256") != product["plan_sha256"] or set(record.get("storage", {})) != set(storage_names):
            raise ValueError("paired storage belongs to another product plan")
        for name, entry in record["storage"].items():
            if entry is None:
                continue
            expected_name = name.replace("/", "-") + ".tar.gz"
            if entry.get("filename") != expected_name or rec_sha(storage_archive(workspace / "recovery" / expected_name)) != entry.get("sha256"):
                raise ValueError("paired storage archive changed")
        snapshots = record.get("snapshots")
        if not isinstance(snapshots, dict) or set(snapshots) != snapshot_set:
            raise ValueError("snapshot set differs from the exact recovery allowlist")
        for name in snapshot_names:
            safe_parents(workspace / "recovery", name)
            expected = snapshots[name]
            if expected is not None and (not isinstance(expected, str) or not hex64.fullmatch(expected)):
                raise ValueError("snapshot digest is invalid")
        compressed = workspace / "recovery/database.sql.gz"
        if compressed.parent.is_symlink() or not compressed.parent.is_dir():
            raise ValueError("recovery directory is unsafe")
        regular(compressed, 0o600)
        if rec_sha(compressed) != record.get("database_dump_sha256"):
            raise ValueError("database recovery bytes changed")
        validate_dump(compressed, identity["database"])
        for name in snapshot_names:
            expected = snapshots[name]
            target = workspace / "recovery" / name
            if expected is None:
                if target.exists() or target.is_symlink():
                    raise ValueError("unexpected recovery snapshot exists")
                continue
            regular(target, 0o600)
            if rec_sha(target) != expected:
                raise ValueError("installation or configuration recovery bytes changed")
        if (
            snapshots["private/installation/installed.json"] != plan["source"]["installed_receipt_sha256"]
            or snapshots["private/installation/baseline.json"] != plan["source"]["baseline_sha256"]
            or snapshots["private/installation/deployment.json"] != plan["source"]["deployment_state_sha256"]
        ):
            raise ValueError("recovery snapshot differs from original installation identity")
        values = parse_env(server / ".env")
        source = values.get("PEANUT_DATABASE_SOURCE", "")
        if source.startswith('"'):
            source = json.loads(source)

        def env_value(key: str) -> str:
            raw = values.get(key, "")
            if raw.startswith('"'):
                raw = json.loads(raw)
            return raw

        def ordinary_program_identity() -> dict[str, Any]:
            if server.resolve() != server or workspace.resolve() != workspace:
                raise ValueError("ordinary identity roots must be canonical")
            binding = rec_json(workspace / "product-binding.json")
            if binding.get("plan_sha256") != product["plan_sha256"]:
                raise ValueError("product binding plan digest mismatch")
            update_plan = rec_json(workspace / "plan.json")
            plan_digest = rec_sha(workspace / "plan.json")
            if (
                product.get("instance_root") != str(server.parent)
                or product.get("inputs_sha256") != plan_digest
                or update_plan.get("protocol") != "peanut.server-release-inputs.v1"
                or update_plan.get("update_id") != product.get("candidate")
            ):
                raise ValueError("ordinary identity differs from the bound product inputs")
            old = update_plan.get("source", {}).get("identity_path", "server/.peanut/release-identity.json")
            allowed = {
                "server/.peanut/release-identity.json",
                "server/.peanut/development-runtime.json",
                ".peanut/application-manifest.json",
            }
            if old not in allowed:
                raise ValueError("unrecognized original program identity path")

            def check_parents(base: Path, relative: str, allow_missing: bool = False) -> None:
                cursor = base
                for part in Path(relative).parts[:-1]:
                    cursor /= part
                    if cursor.is_symlink() or (not cursor.is_dir() and (cursor.exists() or not allow_missing)):
                        raise ValueError("ordinary identity parent is unsafe")

            def checked_json(base: Path, relative: str, expected: Any, mode: int = 0o644) -> dict[str, Any]:
                check_parents(base, relative)
                path = base / relative
                regular(path, mode)
                digests = expected if isinstance(expected, tuple) else (expected,)
                if any(not isinstance(v, str) or not hex64.fullmatch(v) for v in digests) or rec_sha(path) not in digests:
                    raise ValueError("ordinary identity digest differs from bound inputs")
                return json.loads(path.read_text())

            release_path = server / ".peanut/release-identity.json"
            check_parents(server.parent, "server/.peanut/release-identity.json", allow_missing=True)
            check_parents(server.parent, old)
            if release_path.exists() or release_path.is_symlink():
                return checked_json(
                    server.parent,
                    "server/.peanut/release-identity.json",
                    (update_plan["source"]["identity_sha256"], update_plan["target"]["identity_sha256"]),
                )
            original = server.parent / old
            if original.exists() or original.is_symlink():
                return checked_json(server.parent, old, update_plan["source"]["identity_sha256"])
            raise ValueError("ordinary application identity is missing")

        def verify_source_database_binding() -> dict[str, Any]:
            manifest = ordinary_program_identity()
            registry_path = server / "private/resources/project-resources.json"
            registry = rec_json(registry_path)
            rows = [
                row
                for row in registry.get("resources", {}).get("databases", [])
                if isinstance(row, dict) and row.get("stable_resource_id") == env_value("PEANUT_DATABASE_RESOURCE_ID")
            ]
            if (
                registry.get("authority", {}).get("role") != "application"
                or registry.get("project_id") != manifest.get("application", {}).get("slug")
                or len(rows) != 1
            ):
                raise ValueError("source database is not owned by this APP")
            resource = rows[0]
            consumer = env_value("PEANUT_DATABASE_CONSUMER")
            endpoints = [
                row
                for row in (resource.get("upstream_endpoint"), resource.get("container_endpoint"))
                if isinstance(row, dict) and consumer in row.get("consumers", [])
            ]
            if len(endpoints) != 1 or consumer != "container":
                raise ValueError("source admission requires its declared container endpoint")
            endpoint = endpoints[0]
            if (
                resource.get("service_type") != "mysql"
                or resource.get("fallback") != "none"
                or resource.get("application_runtime") is not True
                or resource.get("database") != env_value("DB_NAME")
                or resource.get("namespace") != env_value("DB_NAME")
                or endpoint.get("endpoint_id") != env_value("PEANUT_DATABASE_ENDPOINT_ID")
                or endpoint.get("host") != env_value("DB_HOST")
                or endpoint.get("port") != int(env_value("DB_PORT"))
            ):
                raise ValueError("source registered database binding changed")
            return {
                "source": "registered",
                "resource_id": resource["stable_resource_id"],
                "endpoint": endpoint,
                "database": resource["database"],
                "registry_sha256": rec_sha(registry_path),
                "environment_sha256": rec_sha(server / ".env"),
            }

        def verify_backup_tool(binding: dict[str, Any]) -> dict[str, Any]:
            tool_meta = product.get("backup_tool")
            if not isinstance(tool_meta, dict) or set(tool_meta) != {"path", "sha256", "owner_uid", "mode"}:
                raise ValueError("registered recovery requires its frozen ordinary-plan backup tool")
            if tool_meta["mode"] != 0o600 or tool_meta["owner_uid"] not in (0, server.stat().st_uid):
                raise ValueError("registered backup_tool ownership or mode is unsafe")
            tool_path = Path(str(tool_meta["path"]))
            regular(tool_path, 0o600)
            if (
                tool_path.resolve(strict=True) != tool_path
                or tool_path.stat().st_uid != tool_meta["owner_uid"]
                or tool_path.stat().st_size > 65536
                or rec_sha(tool_path) != tool_meta["sha256"]
            ):
                raise ValueError("frozen registered backup tool changed")
            tool = rec_json(tool_path)
            schema = tool.get("source_schema_backup", {})
            if (
                tool.get("transport") != "ssh-docker-exec"
                or not isinstance(schema, dict)
                or schema.get("whole_schema") is not True
                or schema.get("database_resource_id") != binding["resource_id"]
                or schema.get("database") != binding["database"]
                or not re.fullmatch(r"[A-Za-z0-9_]{1,64}", binding["database"])
                or not re.fullmatch(r"8\.4\.[0-9]+", str(schema.get("mysql_version", "")))
                or not re.fullmatch(r"[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}", str(schema.get("server_uuid", "")))
                or type(schema.get("timeout_seconds")) is not int
                or not 30 <= schema["timeout_seconds"] <= 600
            ):
                raise ValueError("registered whole-schema provider identity or lifetime is invalid")
            for cmd_key in ("ssh_command", "docker_command", "mysql_command", "mysqldump_command", "timeout_command", "digest_command"):
                cmd_val = tool.get(cmd_key)
                if not isinstance(cmd_val, str) or not re.fullmatch(r"/[A-Za-z0-9_./-]+", cmd_val) or ".." in cmd_val:
                    raise ValueError(f"registered provider command is invalid: {cmd_key}")
            for host_key in ("ssh_alias", "container_name"):
                if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", str(tool.get(host_key, ""))):
                    raise ValueError(f"registered provider transport identity is invalid: {host_key}")
            return tool_meta

        def assert_whole_schema_effects(observation: dict[str, Any]) -> None:
            metadata = observation["metadata"]
            database = observation["identity"]["database"]
            if any(metadata[k] for k in ("views", "routines", "events", "triggers")):
                raise ValueError("registered schema contains unsupported non-table objects")
            if any(row["TABLE_SCHEMA"] != database or row["REFERENCED_TABLE_SCHEMA"] != database for row in metadata["foreign_keys"]):
                raise ValueError("registered schema contains cross-schema foreign keys")
            if any(row["TABLE_TYPE"] != "BASE TABLE" or row["ENGINE"] != "InnoDB" for row in metadata["tables"]):
                raise ValueError("registered schema contains non-InnoDB or non-base-table objects")
            if any(row["SPACE_TYPE"] != "Single" for row in metadata["tablespaces"]):
                raise ValueError("registered schema contains non-file-per-table objects")

        def verify_process_ref(reference: Any, expected_op: str, expected_provider: dict[str, Any]) -> dict[str, Any]:
            if not isinstance(reference, dict) or not hex64.fullmatch(str(reference.get("sha256", ""))):
                raise ValueError("privileged process reference is missing or invalid")
            process_path = Path(str(reference.get("path", "")))
            if process_path.parent != workspace / "root-client":
                raise ValueError("privileged process escaped its workspace")
            regular(process_path, 0o600)
            if rec_sha(process_path) != reference["sha256"]:
                raise ValueError("privileged process receipt changed")
            receipt = rec_json(process_path)
            if (
                receipt.get("operation") != expected_op
                or receipt.get("provider") != expected_provider
                or receipt.get("product_plan_sha256") != record["product_plan_sha256"]
                or receipt.get("status") != "terminal"
                or receipt.get("rc") != 0
                or receipt.get("remote_success_terminal_confirmed") is not True
            ):
                raise ValueError("privileged command has no bound successful terminal")
            for stream_key in ("stdout", "stderr"):
                stream = receipt.get(stream_key)
                if not isinstance(stream, dict) or not hex64.fullmatch(str(stream.get("sha256", ""))):
                    raise ValueError(f"privileged command {stream_key} digest is invalid")
                stream_path = Path(str(stream.get("path", "")))
                if receipt["operation"] == "capture" and stream_key == "stdout":
                    if stream_path != workspace / "recovery/database.sql":
                        raise ValueError("capture payload path changed")
                    raw_digest = hashlib.sha256()
                    with gzip.open(compressed, "rb") as gz_stream:
                        for chunk in iter(lambda: gz_stream.read(1024 * 1024), b""):
                            raw_digest.update(chunk)
                    if raw_digest.hexdigest() != stream["sha256"]:
                        raise ValueError("archived dump differs from actual terminal capture")
                    continue
                if stream_path.parent != workspace / "root-client":
                    raise ValueError("privileged output escaped its workspace")
                regular(stream_path, 0o600)
                if rec_sha(stream_path) != stream["sha256"]:
                    raise ValueError("privileged command output changed")
            return receipt

        if source in ("", "bundled"):
            if product.get("backup_tool") is not None:
                raise ValueError("bundled provider must not define registered backup_tool")
            if record.get("database_provider") != {"source": "bundled"}:
                raise ValueError("signed backup provider differs from the frozen ordinary plan")
            return record

        if source != "registered":
            raise ValueError("unsupported database provider for paired recovery verification")

        binding = verify_source_database_binding()
        tool_meta = verify_backup_tool(binding)
        expected_provider = {
            "source": "registered",
            "database_binding": binding,
            "backup_tool": tool_meta,
        }
        if record.get("database_provider") != expected_provider:
            raise ValueError("signed backup provider differs from the frozen ordinary plan")
        evidence = record.get("registered_evidence")
        if not isinstance(evidence, dict):
            raise ValueError("registered recovery evidence is missing")
        meta_before = workspace / "recovery/database-metadata.json"
        meta_after = workspace / "recovery/database-metadata-after.json"
        for meta_path, digest_key in ((meta_before, "metadata_sha256"), (meta_after, "metadata_after_sha256")):
            regular(meta_path, 0o600)
            if rec_sha(meta_path) != evidence.get(digest_key):
                raise ValueError("signed privileged metadata changed")
        observation = rec_json(meta_before)
        after = rec_json(meta_after)
        for key in ("identity", "metadata", "mysql_sha256", "mysqldump_sha256"):
            if observation.get(key) != after.get(key):
                raise ValueError("paired metadata differs across native capture")
        assert_whole_schema_effects(observation)
        assert_whole_schema_effects(after)
        verify_process_ref(observation.get("process"), "metadata", expected_provider)
        verify_process_ref(after.get("process"), "metadata", expected_provider)
        capture_receipt = verify_process_ref(evidence.get("capture"), "capture", expected_provider)
        if capture_receipt["stdout"]["sha256"] != evidence.get("raw_dump_sha256"):
            raise ValueError("archived dump differs from registered raw_dump_sha256")
        return record

    return {
        "SNAPSHOT_NAMES": snapshot_names,
        "SNAPSHOT_SET": snapshot_set,
        "STORAGE_NAMES": storage_names,
        "storage_inventory": storage_inventory,
        "check_signature": check_signature,
        "check_backup_signature": check_backup_signature,
        "verify_backup_attachments": verify_backup_attachments,
    }


def paired_recovery_verifier() -> tuple[dict[str, Any], str]:
    """Return local read-only verifier helpers for bundled and registered paired recovery receipts."""
    digest = hashlib.sha256(inspect.getsource(_build_paired_recovery_verifier).encode("utf-8")).hexdigest()
    return _build_paired_recovery_verifier(), digest


class GateError(RuntimeError):
    pass


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_json(path: Path) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise GateError(f"invalid JSON {path}: {exc}") from exc


def _canonical(raw: str) -> Path:
    if not raw or not os.path.isabs(raw):
        raise GateError(f"absolute canonical path required: {raw!r}")
    candidate = Path(raw)
    if candidate.is_symlink():
        raise GateError(f"symlinked path disallowed: {candidate}")
    resolved = candidate.resolve(strict=True)
    if resolved != candidate or not resolved.is_file():
        raise GateError(f"canonical file required: {candidate}")
    return resolved


def _file_reference(record: dict[str, Any]) -> Path:
    if not isinstance(record, dict) or not re.fullmatch(r"[a-f0-9]{64}", str(record.get("sha256", ""))):
        raise GateError("fixed native file reference and sha256 are required")
    path = _canonical(str(record.get("path", "")))
    if _sha256(path) != record["sha256"]:
        raise GateError(f"fixed native file changed: {path}")
    return path


def native_evidence(reference: dict[str, Any], app: dict[str, Any], kind: str, final_build: dict[str, Any] | None = None) -> dict[str, Any]:
    path = _file_reference(reference)
    state = read_json(path)
    server = Path(app["root"]) / "server"
    if kind != "recovery" or path.name != "database-recovery.json":
        raise GateError("recovery reference must be the original native database-recovery.json")
    workspace = path.parent
    native, producer_digest = paired_recovery_verifier()
    native["check_signature"](server, path)
    native["check_backup_signature"](server, workspace)
    backup = read_json(workspace / "backup.json")
    plan_path = _canonical(str(workspace / "plan.json"))
    update = read_json(plan_path)
    identity = {k: app[k] for k in ("resource_id", "endpoint_id", "database")}
    if (
        state.get("status") != "completed"
        or state.get("backup_sha256") != _sha256(workspace / "backup.json")
        or backup.get("protocol") != "peanut.server-update-backup.v1"
        or state.get("update_id") != backup.get("update_id")
        or state.get("update_id") != update.get("update_id")
        or backup.get("plan_sha256") != _sha256(plan_path)
        or backup.get("database_identity") != identity
        or not state.get("mysql_identity")
        or state["mysql_identity"] != backup.get("mysql_identity")
    ):
        raise GateError("authenticated native database recovery belongs to another plan/APP/database")
    if set(backup.get("snapshots", {})) != native["SNAPSHOT_SET"] or set(backup.get("storage", {})) != set(native["STORAGE_NAMES"]):
        raise GateError("native recovery omits protected configuration, uploads or installation identity")
    native["verify_backup_attachments"](server, workspace, update, _sha256(plan_path), identity)
    for name, digest in backup["snapshots"].items():
        target = server / name
        if digest is None:
            if target.exists() or target.is_symlink():
                raise GateError("previously absent protected installation/configuration appeared")
        elif _sha256(_canonical(str(target))) != digest:
            raise GateError("protected native installation/configuration identity changed")
    for name, entry in backup["storage"].items():
        target = server / name
        if entry is None:
            if target.exists() or target.is_symlink():
                raise GateError("previously absent protected storage appeared")
        elif native["storage_inventory"](target) != entry["inventory"]:
            raise GateError("protected uploads/storage differs from the native recovery point")
    return {
        "path": str(path),
        "sha256": _sha256(path),
        "backup_sha256": state["backup_sha256"],
        "native_producer_sha256": producer_digest,
    }
